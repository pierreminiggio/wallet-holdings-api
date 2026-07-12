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
     * used here (same "aggregate in PHP, not SQL" reasoning used throughout this project
     * wherever the query builder's capabilities aren't confirmed).
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
     * Returns the cached report for this address whose as_of_date exactly matches the given
     * date, or null if none exists. Unlike the old cached_at-derived version, this is now a
     * genuine exact-day match, not a "most recent within the day" aggregation -- as_of_date
     * is written explicitly at store() time (see the README's "Historical reconstruction"
     * note), so there's nothing to aggregate: at most one write path (a live
     * /sui-holdings-now call) can produce more than one row for the same address+date, so we
     * still pick the most recent by cached_at among same-day rows, same reasoning as
     * getFreshCache() above.
     *
     * @return array{reportJson: string, cachedAt: int}|null
     */
    public function getCacheForDate(string $address, string $date): ?array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('report_json, cached_at')
                ->where('address = :address AND as_of_date = :as_of_date')
            ,
            ['address' => $address, 'as_of_date' => $date]
        );

        $latest = null;

        foreach ($rows as $row) {
            $cachedAt = (int) $row['cached_at'];

            if ($latest === null || $cachedAt > $latest['cachedAt']) {
                $latest = ['reportJson' => $row['report_json'], 'cachedAt' => $cachedAt];
            }
        }

        return $latest;
    }

    /**
     * Returns the earliest as_of_date cached for this address (across both live and
     * reconstructed rows), or null if nothing has ever been cached for it. Used to tell apart
     * "this date predates the wallet's on-chain history" (400 -- nothing will ever exist here,
     * since reconstruction always starts from genesis) from "this date should already be
     * covered by a prior reconstruction run but its row is unexpectedly missing" (500 -- a
     * genuine gap/bug), by checking whether the requested date falls before or after this
     * address's earliest known snapshot. See App::handleSuiHoldingsForDate() for how this is
     * actually used.
     */
    public function getEarliestCachedDate(string $address): ?string
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('as_of_date')
                ->where('address = :address')
            ,
            ['address' => $address]
        );

        $earliest = null;

        foreach ($rows as $row) {
            $asOfDate = $row['as_of_date'];

            if ($earliest === null || $asOfDate < $earliest) {
                $earliest = $asOfDate;
            }
        }

        return $earliest;
    }

    /**
     * @param string $asOfDate The calendar date (YYYY-MM-DD, UTC) this report represents --
     *                         NOT necessarily today, for backfilled/reconstructed rows. See
     *                         the "Historical reconstruction" note in the README's Migration
     *                         section for why this has to be tracked separately from cached_at.
     * @param string $source  'live' (from /sui-holdings-now) or 'reconstructed' (backfilled
     *                        from the sui-navi-report reconstruction Action).
     */
    public function store(string $address, string $reportJson, string $asOfDate, string $source): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'address, as_of_date, report_json, source, cached_at',
                    ':address, :as_of_date, :report_json, :source, :cached_at'
                )
            ,
            [
                'address' => $address,
                'as_of_date' => $asOfDate,
                'report_json' => $reportJson,
                'source' => $source,
                'cached_at' => time()
            ]
        );
    }
}
