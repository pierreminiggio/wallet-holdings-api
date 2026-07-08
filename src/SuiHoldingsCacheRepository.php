<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

/**
 * Stores successive wallet-report.json snapshots for SUI addresses (see
 * App::handleSuiHoldingsNow()). Deliberately append-only -- every fetch inserts a new
 * row rather than overwriting the previous one -- so a history of snapshots builds up
 * per address over time instead of only ever keeping the latest one.
 */
class SuiHoldingsCacheRepository
{
    private const TABLE = 'sui_holdings_cache';

    // A cached snapshot younger than this is served as-is instead of triggering a
    // fresh (slow, ~10-20s+ GitHub Actions run) fetch.
    public const MAX_CACHE_AGE_SECONDS = 2 * 60 * 60;

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * Returns the most recently cached report for this address if one exists and is
     * younger than MAX_CACHE_AGE_SECONDS, or null otherwise (no cache at all yet, or
     * the newest one is too old). "Most recent" is picked in PHP rather than via a SQL
     * ORDER BY/LIMIT, since that support isn't confirmed to exist in the query builder
     * used here (same reasoning as the in-PHP aggregation in WalletDataRepository).
     *
     * @return array{reportJson: string, cachedAt: int}|null
     */
    public function getFreshCache(string $address): ?array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('report_json, cached_at')
                ->where('address = :address')
            ,
            ['address' => $address]
        );

        $latest = null;

        foreach ($rows as $row) {
            $cachedAt = (int) $row['cached_at'];

            if ($latest === null || $cachedAt > $latest['cachedAt']) {
                $latest = ['reportJson' => $row['report_json'], 'cachedAt' => $cachedAt];
            }
        }

        if ($latest === null || (time() - $latest['cachedAt']) > self::MAX_CACHE_AGE_SECONDS) {
            return null;
        }

        return $latest;
    }

    /**
     * Returns the most recently cached report for this address whose cached_at falls
     * within the given UTC calendar day (inclusive of both ends), or null if no cached
     * snapshot exists for that day. "Most recent within the day" is picked in PHP for
     * the same reason as getFreshCache() above: no confirmed SQL ORDER BY/LIMIT support
     * in the query builder used here.
     *
     * @return array{reportJson: string, cachedAt: int}|null
     */
    public function getCacheForDate(string $address, string $date): ?array
    {
        $dayStart = \DateTime::createFromFormat('Y-m-d H:i:s', $date . ' 00:00:00', new \DateTimeZone('UTC'))
            ->getTimestamp();
        $dayEnd = \DateTime::createFromFormat('Y-m-d H:i:s', $date . ' 23:59:59', new \DateTimeZone('UTC'))
            ->getTimestamp();

        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('report_json, cached_at')
                ->where('address = :address')
            ,
            ['address' => $address]
        );

        $latest = null;

        foreach ($rows as $row) {
            $cachedAt = (int) $row['cached_at'];

            if ($cachedAt < $dayStart || $cachedAt > $dayEnd) {
                continue;
            }

            if ($latest === null || $cachedAt > $latest['cachedAt']) {
                $latest = ['reportJson' => $row['report_json'], 'cachedAt' => $cachedAt];
            }
        }

        return $latest;
    }

    public function store(string $address, string $reportJson): void

    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'address, report_json, cached_at',
                    ':address, :report_json, :cached_at'
                )
            ,
            [
                'address' => $address,
                'report_json' => $reportJson,
                'cached_at' => time()
            ]
        );
    }
}
