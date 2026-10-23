<?php

namespace App;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Reconstructs the historical multichain holdings of a wallet, day by day, up to a requested past
 * date (MULTICHAIN-RECONSTRUCTION-PLAN.md section 1). Called on a cache miss for a past date; every
 * day it walks gets its own `multichain_holdings_cache` row (source "reconstructed"), so any later
 * request inside that range is a pure cache hit. No background job: a first request for an old date may
 * run for hours and be cut off; the caller retries and each retry resumes from the cursor.
 *
 * THE WALK, in lockstep across the supported chains:
 *  1. Locks: one per (chain, provider), all taken up front, non-blocking. Any one unavailable: BUSY.
 *  2. end_block(D) per chain (EvmBlockResolver, cached in evm_block_by_date).
 *  3. Phase 0, for a chain whose first activity is not known yet: scan logs forward from the chain's
 *     history start until the first Transfer touching the wallet, and look for the native-coin genesis
 *     (coarse balance samples, then a bisection). If nothing is found by end_block(D), the chain is empty
 *     for D. The first activity is stored in the cursor. No supported chain active by D: BEFORE_GENESIS.
 *  4. For each day X from the earliest unfinished day to D, for each chain:
 *       a. scan logs from last_scanned_block + 1 to end_block(X), extending the token set (progress is
 *          saved to the cursor while scanning, so a multi-hour scan survives a cut-off);
 *       b. unless the day already has ANY cache row, snapshot at end_block(X) (EvmHoldingsSnapshotBuilder);
 *     then write the day's combined row, and ONLY THEN advance every chain's cursor_date to X.
 *  5. Return the row for D; none means INCONSISTENT.
 *
 * RULES THAT EXIST BECAUSE SOMETHING BROKE (or would have):
 *  - A cursor never advances past a day whose row is not written (a cursor ahead of its rows was the exact
 *    bug found on the SUI side). A crash between the row and the cursors is harmless: on resume the row
 *    exists, so the day is not snapshotted again, its logs are still scanned, and the cursors advance.
 *  - Existing rows win (decision 4): a day that already has a row (a live /holdings-now row, or one written
 *    by an interrupted earlier walk) is never overwritten; getCacheForDate() returns the newest cached_at,
 *    so a reconstructed row would shadow the live one. The cursors still move past that day.
 *  - No partial days: a snapshot that cannot be built (EvmHoldingsSnapshotResult::failed) stops the walk
 *    with PROVIDER_ERROR; nothing is written for that day.
 *  - Phase 0 never persists a progress row past a log it has seen (EvmLogScanner returns before its progress
 *    callback), so a crash cannot lose the first activity.
 *  - A discovered token set may include tokens first seen AFTER the day being snapshotted (a chunk can span
 *    days). That is harmless: balances are read with balanceOf at the day's block, which is 0 or a not-yet-
 *    deployed contract before the token existed.
 *
 * KNOWN LIMITATIONS (documented, not bugs): a native-only wallet whose funds came and went entirely between
 * two coarse samples (500,000 blocks) and left no token log is not seen; tokens received before a chain's
 * history start (Polygon: 80,406,107) and never touched again are not discovered; blocks in
 * EvmReconstructionChains::SKIPPED_BLOCKS or recorded as unresolved are known holes.
 */
class MultichainHoldingsReconstructionService
{
    /** Seconds suggested to a caller that got BUSY. */
    public const BUSY_RETRY_AFTER_SECONDS = 60;

    /**
     * Minimum seconds between two mid-scan progress rows of one chain. The scanner offers a progress callback every
     * 20 chunks, which with a provider capped at ~101 blocks per request is one database row every few seconds (about
     * 64,000 rows for Ethereum's first segment alone). A crash then costs at most this much scanning, which is nothing.
     */
    public const PROGRESS_SAVE_INTERVAL_SECONDS = 60;

    /** Blocks between two native-balance samples when looking for a chain's native genesis. */
    public const NATIVE_COARSE_STEP = 500000;

    /**
     * @param Closure|null $rpcFactory fn(string $url): EvmRpcClient. Defaults to a client with a 60s timeout and
     *                                 six bounded transient retries (2s base backoff, 30s cap).
     * @param list<string> $chains     Chains to walk. Defaults to all supported chains. A chain with no configured
     *                                 provider makes the whole walk fail loudly (a silently skipped chain would be wrong data).
     * @param Closure|null $clock      fn(): int unix time, injectable for tests.
     * @param int $progressSaveIntervalSeconds minimum seconds between two mid-scan progress rows per chain.
     * @param Closure|null $logger     fn(string $message): void, told what a multi-hour walk is doing (the CLI scripts print it).
     */
    public function __construct(
        private EvmReconstructionConfig $config,
        private HoldingsNowCacheRepository $cache,
        private MultichainReconstructionCursorRepository $cursors,
        private EvmBlockByDateRepository $blocks,
        private EvmUnresolvedBlockRepository $unresolved,
        private ?Closure $rpcFactory = null,
        private array $chains = EvmReconstructionChains::SUPPORTED_CHAINS,
        private ?Closure $clock = null,
        private ?EvmReconstructionLock $lock = null,
        private int $progressSaveIntervalSeconds = self::PROGRESS_SAVE_INTERVAL_SECONDS,
        private ?Closure $logger = null,
    ) {
        foreach ($chains as $chain) {
            if (! EvmReconstructionChains::isSupported($chain)) {
                throw new InvalidArgumentException("Chain \"{$chain}\" is not supported by the reconstruction");
            }
        }
    }

    /**
     * @param string $date YYYY-MM-DD (UTC), strictly before today: today goes through /holdings-now.
     *
     * @throws InvalidArgumentException for a malformed address, a malformed date, or a date that is not in the past.
     */
    public function reconstruct(string $address, string $date): MultichainReconstructionOutcome
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $address) !== 1) {
            throw new InvalidArgumentException("Invalid address \"{$address}\"");
        }

        EvmBlockResolver::endOfDayTimestamp($date); // validates the format

        if ($date >= gmdate('Y-m-d', $this->now())) {
            throw new InvalidArgumentException("Date {$date} is not in the past: today goes through /holdings-now");
        }

        // A client disconnect must not kill a day half-way, and a walk can last hours.
        @ignore_user_abort(true);
        @set_time_limit(0);

        $lock = $this->lock ?? new EvmReconstructionLock($this->config->lockDirectory());

        try {
            foreach ($this->chains as $chain) {
                if ($this->config->providers($chain) === []) {
                    return MultichainReconstructionOutcome::providerError("No archive RPC provider is configured for {$chain} (see config.example.php, \"reconstruction\")");
                }

                foreach ($this->config->providers($chain) as $provider) {
                    if (! $lock->acquire($chain, $provider['name'])) {
                        return MultichainReconstructionOutcome::busy(
                            "Another reconstruction is using {$chain} ({$provider['name']}); retry shortly",
                            self::BUSY_RETRY_AFTER_SECONDS
                        );
                    }
                }
            }

            return $this->walk(strtolower($address), $date);
        } catch (Throwable $e) {
            error_log('MultichainHoldingsReconstructionService failed: ' . $e->getMessage());

            return MultichainReconstructionOutcome::storageError($e->getMessage());
        } finally {
            $lock->releaseAll();
        }
    }

    private function walk(string $address, string $date): MultichainReconstructionOutcome
    {
        /** @var array<string, MultichainChainWalk> $ctx per chain */
        $ctx = [];

        foreach ($this->chains as $chain) {
            $ctx[$chain] = $this->openChain($address, $chain);
        }

        // ---- every configured provider must serve the chain it is configured for ----------------------
        foreach ($this->chains as $chain) {
            $problem = $this->verifyChainIds($chain, $ctx[$chain]);

            if ($problem !== null) {
                return MultichainReconstructionOutcome::providerError($problem, $this->walkedThrough($ctx));
            }
        }

        // ---- end block of the target date, per chain -------------------------------------------------
        foreach ($this->chains as $chain) {
            $end = $this->resolveEnd($ctx[$chain], $date);

            if (is_string($end)) {
                return MultichainReconstructionOutcome::providerError("{$chain}: {$end}", $this->walkedThrough($ctx));
            }

            $ctx[$chain]->endTarget = $end;
        }

        // ---- Phase 0: first activity, per chain -----------------------------------------------------
        foreach ($this->chains as $chain) {
            $problem = $this->findFirstActivity($address, $chain, $ctx[$chain]);

            if ($problem !== null) {
                return MultichainReconstructionOutcome::providerError("{$chain}: {$problem}", $this->walkedThrough($ctx));
            }
        }

        $firstDay = null;

        foreach ($this->chains as $chain) {
            $c = $ctx[$chain];

            if ($c->endTarget === null || $c->firstActivity === null || $c->firstActivity > $c->endTarget) {
                continue; // nothing on this chain by the requested date
            }

            $day = $c->resolver->dateOfBlock($c->firstActivity);

            if ($day instanceof EvmRpcError) {
                return MultichainReconstructionOutcome::providerError("{$chain}: cannot date the first activity block: {$day}", $this->walkedThrough($ctx));
            }

            $firstDay = $firstDay === null || $day < $firstDay ? $day : $firstDay;
        }

        if ($firstDay === null) {
            return MultichainReconstructionOutcome::beforeGenesis("No supported chain has any activity for {$address} by {$date}");
        }

        // ---- the day loop -------------------------------------------------------------------------------
        $start = null;

        foreach ($this->chains as $chain) {
            $next = $ctx[$chain]->cursorDate !== null ? self::addDay($ctx[$chain]->cursorDate) : $firstDay;
            $start = $start === null || $next < $start ? $next : $start;
        }

        for ($day = $start; $day <= $date; $day = self::addDay($day)) {
            $problem = $this->walkDay($address, $day, $ctx);

            if ($problem instanceof MultichainReconstructionOutcome) {
                return $problem;
            }
        }

        $row = $this->cache->getCacheForDate($address, $date);

        if ($row === null) {
            return MultichainReconstructionOutcome::inconsistent("The walk is complete through {$date} but no cache row exists for it");
        }

        return MultichainReconstructionOutcome::found($row['responseJson'], $row['source']);
    }

    /**
     * One day, all chains. Returns null on success, or the outcome that stops the walk.
     *
     * @param array<string, MultichainChainWalk> $ctx
     */
    private function walkDay(string $address, string $day, array $ctx): ?MultichainReconstructionOutcome
    {
        EvmRpcClient::takeStats(); // reset: the day's log line then reports this day's RPC cost only

        $existing = $this->cache->getCacheForDate($address, $day) !== null;
        $holdings = [];
        $skipped = [];
        $processed = [];

        foreach ($this->chains as $chain) {
            $c = $ctx[$chain];

            if ($c->cursorDate !== null && $c->cursorDate >= $day) {
                // Cursors only advance after the day's row is written, so this chain being ahead means the row exists.
                if (! $existing) {
                    return MultichainReconstructionOutcome::inconsistent("{$chain} says {$day} is done but no cache row exists for it");
                }

                continue;
            }

            $end = $this->resolveEnd($c, $day);

            if (is_string($end)) {
                return MultichainReconstructionOutcome::providerError("{$chain}: {$end}", $this->walkedThrough($ctx));
            }

            if ($end !== null && $c->state->getLastScannedBlock() < $end) {
                $result = $c->scanner->scan($address, $c->state, $end);

                if (! $result->isComplete()) {
                    $this->cursors->save($address, $chain, $c->state, $c->cursorDate, $c->firstActivity);

                    return MultichainReconstructionOutcome::providerError("{$chain}: log scan stopped at block {$c->state->getLastScannedBlock()}: {$result->getError()}", $this->walkedThrough($ctx));
                }
            }

            if (! $existing && $end !== null && $c->firstActivity !== null && $end >= $c->firstActivity) {
                $snapshot = $this->snapshot($c, $address, $chain, $end);

                if (! $snapshot->isOk()) {
                    return MultichainReconstructionOutcome::providerError("{$chain}: snapshot at block {$end} failed: {$snapshot->getError()}", $this->walkedThrough($ctx));
                }

                if (! $snapshot->isEmpty()) {
                    $holdings[$chain] = $snapshot->getHoldings();
                }

                if ($snapshot->getSkippedTokens() !== []) {
                    $skipped[$chain] = $snapshot->getSkippedTokens();
                }
            }

            $processed[] = $chain;
        }

        if (! $existing) {
            foreach (EvmReconstructionChains::UNSUPPORTED_CHAIN_KEYS as $key) {
                $holdings[$key] = EvmHoldingsSnapshotBuilder::unsupportedChainShape();
            }

            $row = [
                'address' => $address,
                'as_of' => $day,
                'cache' => ['hit' => false, 'fetched_at' => gmdate('Y-m-d H:i:s', $this->now())],
                'holdings' => $holdings,
            ];

            if ($skipped !== []) {
                $row['reconstruction'] = ['skipped_tokens' => $skipped];
            }

            $this->cache->store($address, json_encode($row), $day, 'reconstructed');
        }

        // ONLY NOW: the row exists (just written, or it already did), so the cursors may move past this day.
        foreach ($processed as $chain) {
            $ctx[$chain]->cursorDate = $day;
            $this->cursors->save($address, $chain, $ctx[$chain]->state, $day, $ctx[$chain]->firstActivity);
        }

        $this->log("{$day}: " . ($existing ? 'a row already existed (kept)' : 'row written') . ', cursors advanced; ' . EvmRpcClient::describeStats(EvmRpcClient::takeStats()));

        return null;
    }

    /**
     * Loads the cursor of one chain and builds its providers, resolver, scanner and snapshot builders.
     */
    private function openChain(string $address, string $chain): MultichainChainWalk
    {
        $stored = $this->cursors->find($address, $chain);

        $walk = new MultichainChainWalk($stored['state'] ?? EvmLogScanState::startingAt(EvmReconstructionChains::historyStartBlock($chain)));
        $walk->cursorDate = $stored['cursorDate'] ?? null;
        $walk->firstActivity = $stored['firstActivityBlock'] ?? null;

        foreach ($this->config->providers($chain) as $provider) {
            $client = $this->rpcFactory !== null
                ? ($this->rpcFactory)($provider['url'])
                : new EvmRpcClient($provider['url'], null, 60, 6, 2000, null, $provider['batch_size'] ?? 1);

            $walk->clients[] = $client;
            $walk->builders[] = new EvmHoldingsSnapshotBuilder([$chain => $client]);
        }

        $walk->resolver = new EvmBlockResolver($chain, $walk->clients, $this->blocks);

        // Progress is saved while scanning so a cut-off loses little. It carries the CURRENT cursor date (the last
        // day fully completed), never a newer one, and the first activity only once it is known.
        $walk->scanner = new EvmLogScanner(
            $chain,
            $walk->clients,
            EvmLogScanner::DEFAULT_MAX_STEP,
            20,
            function (EvmLogScanState $state) use ($address, $chain, $walk): void {
                // Throttled by time, not by chunk count: see PROGRESS_SAVE_INTERVAL_SECONDS.
                if (time() - $walk->lastProgressSave < $this->progressSaveIntervalSeconds) {
                    return;
                }

                $walk->lastProgressSave = time();
                $this->cursors->save($address, $chain, $state, $walk->cursorDate, $walk->firstActivity);
                $this->log("{$chain}: logs scanned through block {$state->getLastScannedBlock()} (step {$state->getStepSize()}), "
                    . count($state->tokens()) . ' token(s) so far' . ($walk->firstActivity === null ? ', first activity not found yet' : ''));
            },
            function (int $block, string $error) use ($address, $chain): void {
                $this->unresolved->record($chain, $block, $address, $error);
            },
        );

        return $walk;
    }

    /**
     * A URL pasted under the wrong chain (a BNB Smart Chain endpoint under "base") answers every read with the wrong chain's
     * data and looks perfectly healthy: it produced a wrong end block that was cached for Base. So every provider is asked for
     * its chain id once per request and the walk REFUSES to run when one disagrees. A provider whose chain id cannot be read is
     * left to the normal failover (the reads that follow fail the same way).
     */
    private function verifyChainIds(string $chain, MultichainChainWalk $c): ?string
    {
        $expected = EvmReconstructionChains::CHAIN_IDS[$chain];

        foreach ($c->clients as $i => $client) {
            $reported = $client->chainId();

            if ($reported instanceof EvmRpcError) {
                continue;
            }

            if ($reported !== $expected) {
                $name = $this->config->providers($chain)[$i]['name'] ?? (string) $i;

                return "{$chain}: provider \"{$name}\" reports chain id {$reported} but {$chain} is chain id {$expected}: "
                    . 'that URL belongs to another chain, fix reconstruction.rpc in config.php';
            }
        }

        return null;
    }

    /**
     * end_block(day) for a chain, using the previous end as a lower-bound hint.
     *
     * @return int|string|null the block; null when the day is before the chain's history start; a string is an error
     */
    private function resolveEnd(MultichainChainWalk $c, string $day): int|string|null
    {
        $result = $c->resolver->endBlockForDate($day, $c->lastEnd);

        if ($result->isError()) {
            return 'cannot resolve the end block of ' . $day . ': ' . $result->getError();
        }

        if ($result->getStatus() === EvmBlockResolution::STATUS_DAY_NOT_COMPLETE) {
            return "the provider's head has not reached the end of {$day} yet";
        }

        if ($result->getStatus() === EvmBlockResolution::STATUS_BEFORE_START) {
            return null;
        }

        $c->lastEnd = $result->getBlock();

        return $result->getBlock();
    }

    /**
     * Phase 0 for one chain. Returns null on success, or a problem description (the walk then stops with
     * PROVIDER_ERROR, its progress saved).
     */
    private function findFirstActivity(string $address, string $chain, MultichainChainWalk $c): ?string
    {
        if ($c->firstActivity !== null || $c->endTarget === null) {
            return null;
        }

        $endTarget = $c->endTarget;
        $logBlock = null;

        if ($c->state->getLastScannedBlock() < $endTarget) {
            $this->log("{$chain}: looking for the first activity, scanning logs from block " . ($c->state->getLastScannedBlock() + 1) . " to {$endTarget}");
            $result = $c->scanner->scan($address, $c->state, $endTarget, null, true);

            if ($result->getStatus() === EvmLogScanResult::STATUS_PROVIDER_ERROR) {
                $this->cursors->save($address, $chain, $c->state, $c->cursorDate, null);

                return "log scan stopped at block {$c->state->getLastScannedBlock()}: {$result->getError()}";
            }

            if ($result->getStatus() === EvmLogScanResult::STATUS_FIRST_ACTIVITY) {
                $logBlock = $c->state->getFirstLogBlock();
            }
        }

        $native = $this->findNativeGenesis($c->clients, $address, EvmReconstructionChains::historyStartBlock($chain), $logBlock ?? $endTarget);

        if (is_string($native)) {
            // With a first log in hand nothing is persisted: it is re-found on the retry (a cursor row past it would lose it).
            if ($logBlock === null) {
                $this->cursors->save($address, $chain, $c->state, $c->cursorDate, null);
            }

            return $native;
        }

        $first = $logBlock === null ? $native : ($native === null ? $logBlock : min($logBlock, $native));

        $c->firstActivity = $first;
        $this->cursors->save($address, $chain, $c->state, $c->cursorDate, $first);
        $this->log("{$chain}: " . ($first === null ? "no activity found up to block {$endTarget}" : "first activity at block {$first}"));

        return null;
    }

    /**
     * The first block where the native balance is non-zero in [$from, $to]: coarse samples every
     * NATIVE_COARSE_STEP blocks (the balance is not monotonic, so no blind bisection) and the target
     * itself, then a bisection inside the first window that goes from zero to non-zero.
     *
     * @param list<EvmRpcClient> $clients
     *
     * @return int|string|null the block; null when no sample is non-zero; a string is an error
     */
    private function findNativeGenesis(array $clients, string $address, int $from, int $to): int|string|null
    {
        $samples = [];

        for ($block = $from; $block < $to; $block += self::NATIVE_COARSE_STEP) {
            $samples[] = $block;
        }

        $samples[] = $to;

        $previous = null;

        foreach ($samples as $sample) {
            $nonZero = $this->nonZeroBalance($clients, $address, $sample);

            if (is_string($nonZero)) {
                return $nonZero;
            }

            if (! $nonZero) {
                $previous = $sample;

                continue;
            }

            if ($previous === null) {
                return $sample;
            }

            $low = $previous;
            $high = $sample;

            while ($high - $low > 1) {
                $middle = $low + intdiv($high - $low, 2);
                $middleNonZero = $this->nonZeroBalance($clients, $address, $middle);

                if (is_string($middleNonZero)) {
                    return $middleNonZero;
                }

                if ($middleNonZero) {
                    $high = $middle;
                } else {
                    $low = $middle;
                }
            }

            return $high;
        }

        return null;
    }

    /**
     * @param list<EvmRpcClient> $clients
     *
     * @return bool|string a string is an error
     */
    private function nonZeroBalance(array $clients, string $address, int $block): bool|string
    {
        $error = null;

        foreach ($clients as $client) {
            $balance = $client->getBalance($address, $block);

            if (! ($balance instanceof EvmRpcError)) {
                return ! AbiCodec::isZero(AbiCodec::hexToDec(AbiCodec::strip0x($balance)));
            }

            $error = $balance;
        }

        return "eth_getBalance failed at block {$block}: {$error}";
    }

    /**
     */
    private function snapshot(MultichainChainWalk $c, string $address, string $chain, int $block): EvmHoldingsSnapshotResult
    {
        $failure = EvmHoldingsSnapshotResult::failed('no provider');

        foreach ($c->builders as $builder) {
            $result = $builder->snapshot($address, $chain, $block, $c->state->tokens());

            if ($result->isOk()) {
                return $result;
            }

            $failure = $result;
        }

        return $failure;
    }

    /**
     * The last day fully completed on every chain (the oldest cursor date), for error messages.
     *
     * @param array<string, MultichainChainWalk> $ctx
     */
    private function walkedThrough(array $ctx): ?string
    {
        $oldest = null;

        foreach ($ctx as $c) {
            if ($c->cursorDate === null) {
                return null;
            }

            $oldest = $oldest === null || $c->cursorDate < $oldest ? $c->cursorDate : $oldest;
        }

        return $oldest;
    }

    private static function addDay(string $date): string
    {
        return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
