<?php

namespace Tests;

use App\EvmBlockByDateRepository;
use App\EvmLogScanState;
use App\EvmUnresolvedBlockRepository;
use App\HoldingsNowCacheRepository;
use App\MultichainReconstructionCursorRepository;

/*
 * In-memory stand-ins for the database repositories. Each subclasses the real repository and skips its
 * constructor (which needs a DatabaseFetcher), overriding only the public methods the code under test uses.
 * Shared by the unit tests and by bin/check-5-service.php (real RPCs, no database needed).
 */

/** The date-to-block cache. */
final class MemoryBlockRepository extends EvmBlockByDateRepository
{
    public array $rows = [];

    public int $finds = 0;

    public function __construct()
    {
    }

    public function find(string $chain, string $date): ?int
    {
        $this->finds++;

        return $this->rows["{$chain}|{$date}"] ?? null;
    }

    public function store(string $chain, string $date, int $endBlock): void
    {
        $this->rows["{$chain}|{$date}"] = $endBlock;
    }
}

/** multichain_holdings_cache: append-only, newest cached_at wins, like the real one. */
final class MemoryCacheRepository extends HoldingsNowCacheRepository
{
    /** @var list<array{address: string, date: string, json: string, source: string, cachedAt: int}> */
    public array $rows = [];

    private int $tick = 1000;

    public function __construct()
    {
    }

    public function getCacheForDate(string $address, string $date): ?array
    {
        $latest = null;

        foreach ($this->rows as $row) {
            if ($row['address'] === strtolower($address) && $row['date'] === $date && ($latest === null || $row['cachedAt'] >= $latest['cachedAt'])) {
                $latest = $row;
            }
        }

        return $latest === null ? null : ['responseJson' => $latest['json'], 'cachedAt' => $latest['cachedAt'], 'source' => $latest['source']];
    }

    public function store(string $address, string $responseJson, string $asOfDate, string $source): void
    {
        $this->rows[] = ['address' => strtolower($address), 'date' => $asOfDate, 'json' => $responseJson, 'source' => $source, 'cachedAt' => $this->tick++];
    }

    /** @return list<string> the dates that have a row of this source, in insertion order */
    public function datesOf(string $source): array
    {
        return array_values(array_map(static fn (array $r): string => $r['date'], array_filter($this->rows, static fn (array $r): bool => $r['source'] === $source)));
    }
}

/**
 * multichain_reconstruction_cursor: append-only history, newest wins. Also an invariant checker: it records a
 * violation whenever a cursor_date is saved for a day that has no cache row yet ("a cursor never advances without
 * its rows", the exact bug found on the SUI side).
 */
final class MemoryCursorRepository extends MultichainReconstructionCursorRepository
{
    /** @var list<array{address: string, chain: string, state: array, cursorDate: ?string, firstActivityBlock: ?int}> */
    public array $rows = [];

    /** @var list<string> */
    public array $violations = [];

    /** fn(string $chain, ?string $cursorDate): void, may throw to simulate a crash. */
    public ?\Closure $beforeSave = null;

    public function __construct(private ?MemoryCacheRepository $cache = null)
    {
    }

    public function find(string $address, string $chain): ?array
    {
        $latest = null;

        foreach ($this->rows as $row) {
            if ($row['address'] === strtolower($address) && $row['chain'] === $chain) {
                $latest = $row;
            }
        }

        return $latest === null ? null : [
            'state' => EvmLogScanState::fromArray($latest['state']),
            'cursorDate' => $latest['cursorDate'],
            'firstActivityBlock' => $latest['firstActivityBlock'],
        ];
    }

    public function save(string $address, string $chain, EvmLogScanState $state, ?string $cursorDate, ?int $firstActivityBlock): void
    {
        if ($this->beforeSave !== null) {
            ($this->beforeSave)($chain, $cursorDate);
        }

        if ($cursorDate !== null && $this->cache !== null && $this->cache->getCacheForDate($address, $cursorDate) === null) {
            $this->violations[] = "cursor_date {$cursorDate} saved for {$chain} but no cache row exists for it";
        }

        $this->rows[] = [
            'address' => strtolower($address),
            'chain' => $chain,
            'state' => $state->toArray(),
            'cursorDate' => $cursorDate,
            'firstActivityBlock' => $firstActivityBlock,
        ];
    }
}

/** multichain_reconstruction_unresolved_block. */
final class MemoryUnresolvedRepository extends EvmUnresolvedBlockRepository
{
    /** @var list<array{chain: string, block: int, address: string, error: string}> */
    public array $recorded = [];

    public function __construct()
    {
    }

    public function record(string $chain, int $block, string $address, string $lastError): void
    {
        $this->recorded[] = ['chain' => $chain, 'block' => $block, 'address' => strtolower($address), 'error' => $lastError];
    }

    public function findByChain(string $chain): array
    {
        return [];
    }
}
