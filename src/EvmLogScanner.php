<?php

namespace App;

use Closure;
use InvalidArgumentException;

/**
 * Adaptive, resumable ERC-20 Transfer log scanner: token DISCOVERY for one wallet on one chain
 * (MULTICHAIN-HOLDINGS.md section 3, MULTICHAIN-RECONSTRUCTION-PLAN.md delivery 3). It is the
 * single shared place for this logic; nearly every bug found while testing the approach was a
 * flaw in exactly this code, so each rule below exists because a real run broke without it.
 *
 * WHAT IT DOES: walks eth_getLogs over [lastScannedBlock + 1, toBlock] in adaptive chunks. Each
 * chunk issues both directions (Transfer(from = wallet) and Transfer(to = wallet)); both must
 * succeed for the chunk to count. Contracts that emitted ERC-20-shaped Transfers (3 topics) go to
 * the state's token set; 4-topic Transfer-signature contracts (ERC-721-like) are kept apart, not
 * dropped, so nothing is silently lost and the snapshot builder decides what to do with them.
 *
 * THE FAILURE CLASSES (EvmRpcError) AND THE REACTION TO EACH:
 *  1. range cap (deterministic): shrink IMMEDIATELY, spend no backoff, retry the SAME `from`.
 *     Waiting is pure waste, the same range fails the same way. The scanner then bisects between
 *     the largest size that worked and the smallest that failed, so it converges on the cap in a
 *     handful of requests and stays there instead of re-probing it on every growth step (the cap
 *     estimate is relaxed slowly, because result-size caps move with log density).
 *  2. transient (rate limit, "Request timeout on the free plan", dropped connection, empty or
 *     malformed body): back off and retry the SAME range. That backoff is EvmRpcClient's own
 *     bounded exponential retry (configure it on the clients you pass in); on top of it the
 *     scanner tries the next provider. A transient is NEVER a reason to shrink on its own.
 *  3. auth (bad key, archive refused): the provider is unusable, it is dropped for the rest of
 *     the call and the next one is used. Never retried, never shrunk (that was a real bug).
 *
 * WHEN EVERY PROVIDER STILL FAILS TRANSIENTLY AFTER ITS BOUNDED RETRIES: first a liveness check
 * (eth_blockNumber); if no provider answers at all, the scan stops with PROVIDER_ERROR, resumable,
 * and nothing is recorded as a gap. If providers are alive, the range is split to isolate the
 * block that cannot be served (the dense Base blocks that time out on every attempt, even alone).
 * That is the only case where a transient leads to shrinking.
 *
 * SHRINK FLOOR IS 1 BLOCK, and a single block that cannot be fetched is recorded as an
 * unresolved gap (onGap callback) and the scan continues: one bad block must never halt a
 * multi-day scan. To keep an outage from being mistaken for a bad block, a gap is recorded only
 * if the same query shape succeeds on a nearby block (proving the failure is specific to that
 * block, and tolerating adjacent bad blocks like the Base pairs); otherwise the scan stops with
 * PROVIDER_ERROR. More than MAX_CONSECUTIVE_GAPS gaps in a row also stops it.
 *
 * SKIPPED BLOCKS (EvmReconstructionChains::SKIPPED_BLOCKS): never requested. Chunks are split
 * around them and they count as covered.
 *
 * NO HOLE, EVER: the state only advances past a chunk after all its calls succeeded (or the
 * chunk is a skipped range, or a recorded gap). A failure retries the exact same `from`.
 * Strict response validation is EvmRpcClient's job: an empty/malformed body is a failure there,
 * never an empty success.
 */
class EvmLogScanner
{
    /** keccak256("Transfer(address,address,uint256)") */
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public const DEFAULT_MAX_STEP = 40000;

    /** Consecutive recorded gaps after which the scan stops: that many bad blocks in a row is a provider problem. */
    public const MAX_CONSECUTIVE_GAPS = 5;

    /** Successes sitting right at the learned size cap before it is probed upwards again. */
    private const CAP_RELAX_AFTER_SUCCESSES = 200;

    /** Offsets (blocks) tried, forward then backward, to prove a failing block is block-specific. */
    private const NEIGHBOUR_PROBE_OFFSETS = [1, 2, 4, 8, 16, 32, 64];

    private const OUTCOME_OK = 'ok';
    private const OUTCOME_RANGE_CAP = 'range_cap';
    private const OUTCOME_TRANSIENT = 'transient';
    private const OUTCOME_NO_PROVIDER = 'no_provider';

    /** @var array<int, true> indexes of providers dropped (auth) during the current scan() call */
    private array $disabled = [];

    private int $preferred = 0;

    /**
     * @param list<EvmRpcClient> $clients  providers for $chain in priority order. Configure
     *                                     each one's transient retries (EvmRpcClient constructor).
     * @param Closure|null $onProgress     fn(EvmLogScanState $state): void, every $progressEveryChunks
     *                                     chunks: persist the cursor so a cut-off loses little.
     * @param Closure|null $onGap          fn(int $block, string $lastError): void, a block recorded as unresolved.
     * @param Closure|null $onLogs         fn(list<array> $logs): void, the deduplicated logs of each
     *                                     successful chunk (used by the acceptance check).
     * @param Closure|null $clock          fn(): float, seconds; injectable for tests.
     */
    public function __construct(
        private string $chain,
        private array $clients,
        private int $maxStep = self::DEFAULT_MAX_STEP,
        private int $progressEveryChunks = 20,
        private ?Closure $onProgress = null,
        private ?Closure $onGap = null,
        private ?Closure $onLogs = null,
        private ?Closure $clock = null,
    ) {
        if ($clients === []) {
            throw new InvalidArgumentException("No RPC client for chain \"{$chain}\"");
        }

        if ($maxStep < 1) {
            throw new InvalidArgumentException('maxStep must be at least 1');
        }

        // Throws for a chain the reconstruction does not support.
        EvmReconstructionChains::historyStartBlock($chain);
    }

    /**
     * @param float|null $deadline      Unix time (seconds) after which no new chunk is started.
     * @param bool       $stopAtFirstLog Stop right after the first chunk containing any Transfer log
     *                                  touching the wallet (Phase 0: find the first activity).
     */
    public function scan(
        string $wallet,
        EvmLogScanState $state,
        int $toBlock,
        ?float $deadline = null,
        bool $stopAtFirstLog = false
    ): EvmLogScanResult {
        $walletTopic = self::walletTopic($wallet);
        $skipped = EvmReconstructionChains::skippedRanges($this->chain);

        $this->disabled = [];
        $this->preferred = 0;

        $gaps = [];
        $chunks = 0;
        $sinceProgress = 0;
        $consecutiveGaps = 0;
        $good = 0;             // largest chunk length known to have worked
        $bad = null;           // smallest chunk length known to hit a range cap (null: none learned)
        $streakAtCap = 0;

        while ($state->getLastScannedBlock() < $toBlock) {
            if ($deadline !== null && $this->now() >= $deadline) {
                return new EvmLogScanResult(EvmLogScanResult::STATUS_TIME_BUDGET, $state, $gaps, $chunks);
            }

            $from = $state->getLastScannedBlock() + 1;

            // A skipped block is never requested: it counts as covered.
            foreach ($skipped as [$skipFrom, $skipTo]) {
                if ($from >= $skipFrom && $from <= $skipTo) {
                    $state->advanceTo(min($skipTo, $toBlock));

                    continue 2;
                }
            }

            $step = max(1, min($state->getStepSize(), $this->maxStep));
            $end = min($toBlock, $from + $step - 1);

            foreach ($skipped as [$skipFrom]) {
                if ($skipFrom > $from) {
                    $end = min($end, $skipFrom - 1);

                    break; // ranges are sorted: the first one after $from is the nearest
                }
            }

            $length = $end - $from + 1;
            $fetched = $this->fetch($walletTopic, $from, $end);

            if ($fetched['outcome'] === self::OUTCOME_OK) {
                $this->absorb($state, $fetched['logs']);
                $state->advanceTo($end);
                $chunks++;
                $sinceProgress++;
                $consecutiveGaps = 0;

                if ($this->onLogs !== null && $fetched['logs'] !== []) {
                    ($this->onLogs)($fetched['logs']);
                }

                $good = max($good, $length);
                $next = min($this->maxStep, max($step + 1, intdiv($step * 3, 2)));

                if ($bad !== null) {
                    if ($bad - $good <= 1) {
                        // Converged on the cap: stay there, and only probe slightly above it now and then.
                        $next = $good;

                        if (++$streakAtCap >= self::CAP_RELAX_AFTER_SUCCESSES) {
                            $bad = $good + max(2, intdiv($good, 8));
                            $streakAtCap = 0;
                        }
                    } else {
                        // Bisect between what worked and what failed.
                        $next = min($next, $good + max(1, intdiv($bad - $good, 2)));
                    }
                }

                $state->setStepSize(max(1, $next));

                if ($this->onProgress !== null && $sinceProgress >= $this->progressEveryChunks) {
                    ($this->onProgress)($state);
                    $sinceProgress = 0;
                }

                if ($stopAtFirstLog && $fetched['logs'] !== []) {
                    return new EvmLogScanResult(EvmLogScanResult::STATUS_FIRST_ACTIVITY, $state, $gaps, $chunks);
                }

                continue;
            }

            if ($fetched['outcome'] === self::OUTCOME_NO_PROVIDER) {
                return new EvmLogScanResult(
                    EvmLogScanResult::STATUS_PROVIDER_ERROR,
                    $state,
                    $gaps,
                    $chunks,
                    'every configured provider is unusable for this scan: ' . $fetched['error']
                );
            }

            $streakAtCap = 0;

            if ($fetched['outcome'] === self::OUTCOME_RANGE_CAP) {
                // Deterministic: shrink at once, no backoff, retry the same `from`.
                if ($length > 1) {
                    $bad = min($bad ?? $length, $length);
                    $good = min($good, $bad - 1);
                    $state->setStepSize(max(1, intdiv($length, 2)));

                    continue;
                }
            } else {
                // Transient on every provider after their bounded retries.
                if (! $this->anyProviderAlive()) {
                    return new EvmLogScanResult(
                        EvmLogScanResult::STATUS_PROVIDER_ERROR,
                        $state,
                        $gaps,
                        $chunks,
                        'no provider answers: ' . $fetched['error']
                    );
                }

                if ($length > 1) {
                    // Providers are alive: isolate the block that cannot be served.
                    $state->setStepSize(max(1, intdiv($length, 2)));

                    continue;
                }
            }

            // One block that cannot be fetched even alone, on every provider.
            if ($consecutiveGaps >= self::MAX_CONSECUTIVE_GAPS) {
                return new EvmLogScanResult(
                    EvmLogScanResult::STATUS_PROVIDER_ERROR,
                    $state,
                    $gaps,
                    $chunks,
                    'too many unresolvable blocks in a row (' . self::MAX_CONSECUTIVE_GAPS . '), likely a provider problem: ' . $fetched['error']
                );
            }

            if (! $this->blockSpecific($walletTopic, $from, $toBlock)) {
                return new EvmLogScanResult(
                    EvmLogScanResult::STATUS_PROVIDER_ERROR,
                    $state,
                    $gaps,
                    $chunks,
                    "block {$from} failed and so did every nearby block, so this is not a block-specific failure: " . $fetched['error']
                );
            }

            // Block-specific: record it and move on. Never halt a multi-day scan for one block.
            $gaps[] = ['block' => $from, 'error' => $fetched['error']];

            if ($this->onGap !== null) {
                ($this->onGap)($from, $fetched['error']);
            }

            $consecutiveGaps++;
            $state->advanceTo($from);
        }

        return new EvmLogScanResult(EvmLogScanResult::STATUS_COMPLETE, $state, $gaps, $chunks);
    }

    /**
     * eth_getLogs topic for an address: 32 bytes, left-padded, lower-case.
     */
    public static function walletTopic(string $wallet): string
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $wallet) !== 1) {
            throw new InvalidArgumentException("Invalid wallet address \"{$wallet}\"");
        }

        return '0x' . str_repeat('0', 24) . strtolower(substr($wallet, 2));
    }

    /**
     * Both directions for one chunk, on the first provider that serves it.
     *
     * @return array{outcome: string, logs: list<array<string, mixed>>, error: string}
     */
    private function fetch(string $walletTopic, int $from, int $to): array
    {
        $count = count($this->clients);
        $lastTransient = null;
        $sawUsable = false;

        for ($offset = 0; $offset < $count; $offset++) {
            $index = ($this->preferred + $offset) % $count;

            if (isset($this->disabled[$index])) {
                continue;
            }

            $sawUsable = true;
            $client = $this->clients[$index];
            $logs = [];
            $failure = null;

            foreach ([[self::TRANSFER_TOPIC, null, $walletTopic], [self::TRANSFER_TOPIC, $walletTopic, null]] as $topics) {
                $result = $client->getLogs($from, $to, $topics);

                if ($result instanceof EvmRpcError) {
                    $failure = $result;

                    break;
                }

                foreach ($result as $log) {
                    $logs[self::logKey($log)] = $log;
                }
            }

            if ($failure === null) {
                $this->preferred = $index; // stick with a provider that works

                return ['outcome' => self::OUTCOME_OK, 'logs' => array_values($logs), 'error' => ''];
            }

            if ($failure->isRangeCap()) {
                return ['outcome' => self::OUTCOME_RANGE_CAP, 'logs' => [], 'error' => (string) $failure];
            }

            if ($failure->isAuth()) {
                $this->disabled[$index] = true; // unusable for this call: neither wait nor shrink

                $lastTransient ??= $failure;

                continue;
            }

            $lastTransient = $failure;
        }

        if (! $sawUsable) {
            return ['outcome' => self::OUTCOME_NO_PROVIDER, 'logs' => [], 'error' => (string) ($lastTransient ?? 'no provider')];
        }

        // Every usable provider failed: if some were dropped for auth meanwhile and none is
        // left, that is "no provider"; otherwise it is a transient exhaustion.
        if (count($this->disabled) === $count) {
            return ['outcome' => self::OUTCOME_NO_PROVIDER, 'logs' => [], 'error' => (string) $lastTransient];
        }

        return ['outcome' => self::OUTCOME_TRANSIENT, 'logs' => [], 'error' => (string) $lastTransient];
    }

    /**
     * Whether a failure on $block is specific to it: the same query shape must succeed on SOME
     * nearby block (forward first, then backward). Adjacent bad blocks (the Base pairs) are
     * tolerated; a provider that fails everything is not mistaken for a bad block.
     */
    private function blockSpecific(string $walletTopic, int $block, int $toBlock): bool
    {
        $candidates = [];

        foreach (self::NEIGHBOUR_PROBE_OFFSETS as $offset) {
            if ($block + $offset <= $toBlock) {
                $candidates[] = $block + $offset;
            }
        }

        // Backward only when the scan is about to end and forward has little room: a block just
        // behind us was already fetched fine, which proves little about the failing zone ahead.
        if (count($candidates) < 3) {
            foreach (self::NEIGHBOUR_PROBE_OFFSETS as $offset) {
                if ($block - $offset >= 0) {
                    $candidates[] = $block - $offset;
                }
            }
        }

        foreach (array_slice($candidates, 0, 8) as $candidate) {
            if ($this->fetch($walletTopic, $candidate, $candidate)['outcome'] === self::OUTCOME_OK) {
                return true;
            }
        }

        return false;
    }

    private function anyProviderAlive(): bool
    {
        foreach ($this->clients as $index => $client) {
            if (isset($this->disabled[$index])) {
                continue;
            }

            if (! ($client->blockNumber() instanceof EvmRpcError)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $logs
     */
    private function absorb(EvmLogScanState $state, array $logs): void
    {
        foreach ($logs as $log) {
            $block = self::hexToInt($log['blockNumber']);

            if ($block !== null) {
                $state->noteLogBlock($block);
            }

            if (count($log['topics']) === 3) {
                $state->addToken($log['address']);
            } else {
                $state->addOtherTransferContract($log['address']);
            }
        }
    }

    /**
     * @param array<string, mixed> $log
     */
    private static function logKey(array $log): string
    {
        return ($log['blockNumber'] ?? '') . '|' . ($log['transactionHash'] ?? '') . '|' . ($log['logIndex'] ?? json_encode($log));
    }

    private static function hexToInt(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^0x[0-9a-fA-F]{1,15}$/', $value) !== 1) {
            return null;
        }

        return (int) hexdec(substr($value, 2));
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
