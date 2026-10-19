<?php

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Resolves, for one chain, "the end block of a UTC day": the last block whose timestamp is
 * strictly before (day + 1) 00:00:00 UTC. That is the state at the END of the day, the block
 * the daily snapshot is taken at (MULTICHAIN-RECONSTRUCTION-PLAN.md, decision 1).
 *
 * Wallet-independent, so the answer is cached per (chain, day) through the optional
 * EvmBlockByDateRepository, and only for completed days.
 *
 * ALGORITHM: a search over block timestamps (monotonic in block number) between the chain's
 * history-start block (EvmReconstructionChains) and the chain head, keeping the invariant
 * "timestamp(lo) < end of day <= timestamp(hi)". Steps alternate between an interpolation
 * guess (fast on chains with regular block times: a handful of reads) and a plain bisection
 * step (guaranteed halving), so the worst case stays about twice a bisection however
 * irregular the block times are. A caller walking days in order can pass the previous day's
 * end block as $lowerBoundHint to shrink the range further.
 *
 * RESILIENCE: it takes the chain's providers in priority order. Every timestamp read tries
 * them in turn (each client applies its own bounded retries for transient errors, see
 * EvmRpcClient), so a provider that is unusable for the call (auth, or still failing after
 * its retries) is skipped, and a "block not found" from a lagging provider falls through to
 * the next. When every provider fails, the result is STATUS_ERROR: never a guess.
 */
class EvmBlockResolver
{
    /**
     * block number => timestamp, kept for the life of this instance, so a day-by-day walk
     * never re-reads a block it already saw.
     *
     * @var array<int, int>
     */
    private array $timestamps = [];

    /**
     * @param list<EvmRpcClient> $clients providers for $chain, in priority order (at least one)
     */
    public function __construct(
        private string $chain,
        private array $clients,
        private ?EvmBlockByDateRepository $repository = null,
    ) {
        if ($clients === []) {
            throw new InvalidArgumentException("No RPC client for chain \"{$chain}\"");
        }

        // Throws for a chain the reconstruction does not support.
        EvmReconstructionChains::historyStartBlock($chain);
    }

    /**
     * @param string   $date           YYYY-MM-DD (UTC)
     * @param int|null $lowerBoundHint A block known to be at or before the answer (typically
     *                                 the previous day's end block). Verified before use, and
     *                                 silently ignored when it does not hold.
     *
     * @throws InvalidArgumentException when $date is not a valid YYYY-MM-DD date.
     */
    public function endBlockForDate(string $date, ?int $lowerBoundHint = null): EvmBlockResolution
    {
        $endOfDay = self::endOfDayTimestamp($date);

        if ($this->repository !== null) {
            $cached = $this->repository->find($this->chain, $date);

            if ($cached !== null) {
                return EvmBlockResolution::resolved($cached);
            }
        }

        $head = $this->headBlock();

        if ($head instanceof EvmRpcError) {
            return EvmBlockResolution::error($head);
        }

        $headTimestamp = $this->timestamp($head);

        if ($headTimestamp instanceof EvmRpcError) {
            return EvmBlockResolution::error($headTimestamp);
        }

        if ($headTimestamp < $endOfDay) {
            // The chain has not reached the end of that day: today or a future date.
            return EvmBlockResolution::dayNotComplete();
        }

        $start = EvmReconstructionChains::historyStartBlock($this->chain);
        $lo = $start;

        if ($lowerBoundHint !== null && $lowerBoundHint > $start && $lowerBoundHint < $head) {
            $hintTimestamp = $this->timestamp($lowerBoundHint);

            if ($hintTimestamp instanceof EvmRpcError) {
                return EvmBlockResolution::error($hintTimestamp);
            }

            if ($hintTimestamp < $endOfDay) {
                $lo = $lowerBoundHint;
            }
        }

        $loTimestamp = $this->timestamp($lo);

        if ($loTimestamp instanceof EvmRpcError) {
            return EvmBlockResolution::error($loTimestamp);
        }

        if ($loTimestamp >= $endOfDay) {
            // Not even the first scanned block is before the end of that day.
            return EvmBlockResolution::beforeStart();
        }

        $hi = $head;
        $hiTimestamp = $headTimestamp;
        $step = 0;

        while ($hi - $lo > 1) {
            $guess = $lo + intdiv($hi - $lo, 2);

            // Even steps interpolate, odd steps bisect (guaranteed halving).
            if ($step % 2 === 0 && $hiTimestamp > $loTimestamp) {
                $interpolated = $lo + intdiv(($endOfDay - $loTimestamp) * ($hi - $lo), $hiTimestamp - $loTimestamp);
                $guess = max($lo + 1, min($hi - 1, $interpolated));
            }

            $step++;
            $guessTimestamp = $this->timestamp($guess);

            if ($guessTimestamp instanceof EvmRpcError) {
                return EvmBlockResolution::error($guessTimestamp);
            }

            if ($guessTimestamp < $endOfDay) {
                $lo = $guess;
                $loTimestamp = $guessTimestamp;
            } else {
                $hi = $guess;
                $hiTimestamp = $guessTimestamp;
            }
        }

        $this->repository?->store($this->chain, $date, $lo);

        return EvmBlockResolution::resolved($lo);
    }

    /**
     * Unix timestamp of (date + 1 day) 00:00:00 UTC.
     *
     * @throws InvalidArgumentException
     */
    public static function endOfDayTimestamp(string $date): int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Invalid date \"{$date}\", expected YYYY-MM-DD");
        }

        return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))
            ->modify('+1 day')
            ->getTimestamp();
    }

    /**
     * Timestamp (unix seconds) of a block, over the same provider failover and cache as the search.
     */
    public function timestampOf(int $block): int|EvmRpcError
    {
        return $this->timestamp($block);
    }

    /**
     * The UTC calendar date (YYYY-MM-DD) a block belongs to.
     */
    public function dateOfBlock(int $block): string|EvmRpcError
    {
        $timestamp = $this->timestamp($block);

        return $timestamp instanceof EvmRpcError ? $timestamp : gmdate('Y-m-d', $timestamp);
    }

    /**
     * The highest head any provider reports. Not the first answer: a provider lagging behind
     * would report an older head, and a day that has actually ended would then look
     * incomplete. Fails only when no provider answers at all.
     */
    private function headBlock(): int|EvmRpcError
    {
        $best = null;
        $error = null;

        foreach ($this->clients as $client) {
            $head = $client->blockNumber();

            if ($head instanceof EvmRpcError) {
                $error = $head;

                continue;
            }

            $best = $best === null ? $head : max($best, $head);
        }

        return $best ?? $error;
    }

    private function timestamp(int $block): int|EvmRpcError
    {
        if (isset($this->timestamps[$block])) {
            return $this->timestamps[$block];
        }

        $error = null;

        foreach ($this->clients as $client) {
            $result = $client->getBlockByNumber($block);

            if (! ($result instanceof EvmRpcError)) {
                return $this->timestamps[$block] = $result['timestamp'];
            }

            $error = $result;
        }

        return $error;
    }
}
