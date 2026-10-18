<?php

namespace App;

use InvalidArgumentException;

/**
 * Per-chain constants of the multichain historical reconstruction
 * (MULTICHAIN-RECONSTRUCTION-PLAN.md, decisions 2, 3 and 7). Kept in code, reviewed and
 * edited by hand in a normal commit: not config.php, not the database, and not baked into
 * the log scanner, which receives them as parameters.
 *
 * Chain keys are the live cache's keys (the Zerion chain ids), used everywhere: response
 * bodies, the date-to-block table, the cursor table, config.
 */
final class EvmReconstructionChains
{
    public const ETHEREUM = 'ethereum';
    public const BASE = 'base';
    public const POLYGON = 'polygon';
    public const BSC = 'binance-smart-chain';

    /**
     * Chains whose history can be reconstructed (decision 2): the four that were tested
     * end to end.
     */
    public const SUPPORTED_CHAINS = [self::ETHEREUM, self::BASE, self::POLYGON, self::BSC];

    /**
     * Chains the live cache has returned that reconstruction does not cover (decision 3).
     * A reconstructed row carries an entry for each of them, shaped like an empty chain
     * plus "reconstruction_supported": false.
     */
    public const UNSUPPORTED_CHAIN_KEYS = ['avalanche', 'xdai'];

    /**
     * First block the log scan covers, per chain (decision 7).
     *
     * Polygon starts at its native-token genesis (80,406,107) by decision (plan item 4): a
     * documented limitation, tokens received before it and never touched again are not
     * discovered. Everywhere else the scan starts at block 0.
     */
    public const HISTORY_START_BLOCK = [
        self::ETHEREUM => 0,
        self::BASE => 0,
        self::POLYGON => 80406107,
        self::BSC => 0,
    ];

    /**
     * Blocks the scanner must never query, per chain: some blocks cannot be fetched through
     * free-tier providers even at 1-block granularity (dense blocks that time out on every
     * attempt), and without this list every wallet's scan would re-discover the same dead
     * block and burn its whole retry budget on it. Each entry is a block number or an
     * inclusive [from, to] range.
     *
     * HONEST LIMITATION: a skipped block is a known, accepted hole. A transfer TO the
     * wallet from a third party inside a skipped block is only visible in event logs, and
     * is missed if that token appears nowhere else in the history. Keep a comment next to
     * every entry saying what was verified.
     *
     * SCOPE: the Base entries were found during one wallet's scan. The cause looks
     * block-level (provider-side density), so they likely fail for other wallets too, but
     * that was not tested: re-check a listed block the first time another wallet's scan
     * reaches it. A block the scanner finds unresolvable at runtime is recorded in
     * multichain_reconstruction_unresolved_block (delivery 3), verified by hand on a block
     * explorer, then promoted into this constant.
     *
     * @var array<string, list<int|array{0: int, 1: int}>>
     */
    public const SKIPPED_BLOCKS = [
        self::ETHEREUM => [],
        self::BASE => [
            // Zone that stalled a scan for ~16 hours; fails even as a single block.
            // Checked on BaseScan: no direct wallet transaction; incoming-only transfers unchecked.
            44661819,
            44661820,
            // Second zone, same behaviour at 1-block granularity.
            // Checked on BaseScan: no direct wallet transaction; incoming-only transfers unchecked.
            44692818,
            44692819,
        ],
        self::POLYGON => [],
        self::BSC => [],
    ];

    public static function isSupported(string $chain): bool
    {
        return in_array($chain, self::SUPPORTED_CHAINS, true);
    }

    public static function historyStartBlock(string $chain): int
    {
        self::assertSupported($chain);

        return self::HISTORY_START_BLOCK[$chain];
    }

    /**
     * The chain's skipped blocks as sorted, merged, inclusive [from, to] ranges.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function skippedRanges(string $chain): array
    {
        self::assertSupported($chain);

        return self::normalizeRanges(self::SKIPPED_BLOCKS[$chain] ?? []);
    }

    public static function isSkipped(string $chain, int $block): bool
    {
        foreach (self::skippedRanges($chain) as [$from, $to]) {
            if ($block >= $from && $block <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int|array{0: int, 1: int}> $entries
     *
     * @return list<array{0: int, 1: int}> sorted, with overlapping and adjacent ranges merged
     */
    public static function normalizeRanges(array $entries): array
    {
        $ranges = [];

        foreach ($entries as $entry) {
            $range = is_int($entry) ? [$entry, $entry] : [(int) $entry[0], (int) $entry[1]];

            if ($range[0] < 0 || $range[1] < $range[0]) {
                throw new InvalidArgumentException('Invalid skipped block entry ' . json_encode($entry));
            }

            $ranges[] = $range;
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($ranges as $range) {
            $lastIndex = count($merged) - 1;

            if ($lastIndex >= 0 && $range[0] <= $merged[$lastIndex][1] + 1) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $range[1]);

                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }

    private static function assertSupported(string $chain): void
    {
        if (! self::isSupported($chain)) {
            throw new InvalidArgumentException("Chain \"{$chain}\" is not supported by the reconstruction");
        }
    }
}
