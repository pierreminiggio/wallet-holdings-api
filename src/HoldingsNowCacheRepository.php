<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

/**
 * Stores successive full /holdings-now response snapshots per address (see
 * App::handleHoldingsNow() / App::respondWithHoldingsNow()). Deliberately append-only --
 * every fetch inserts a new row rather than overwriting the previous one -- so a history
 * of snapshots builds up per address over time instead of only ever keeping the latest
 * one. Mirrors SuiHoldingsCacheRepository's structure and reasoning exactly (same
 * MAX_CACHE_AGE_SECONDS value, same as_of_date/source columns, same "aggregate most
 * recent in PHP rather than SQL ORDER BY/LIMIT" pattern, since that support isn't
 * confirmed to exist in the query builder used here), for consistency between the two.
 *
 * /holdings/{address}?date=X (see App.php's /holdings block) reads this table via
 * getCacheForDate() and this table alone -- there is no separate Zerion-only cache
 * anymore (ZerionPositionRepository and the zerion_position table it used were removed;
 * see AGENTS.md Part 3 for why an earlier two-source design was simplified down to this
 * single one).
 */
class HoldingsNowCacheRepository
{
    private const TABLE = 'multichain_holdings_cache';

    // A cached response younger than this is served as-is instead of triggering a fresh
    // fetch (Zerion calls + on-chain Compound/Aave reads).
    public const MAX_CACHE_AGE_SECONDS = 2 * 60 * 60;

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * Returns the most recently cached response for this address if one exists and is
     * younger than MAX_CACHE_AGE_SECONDS, or null otherwise (no cache at all yet, or the
     * newest one is too old). "Most recent" is picked in PHP rather than via a SQL ORDER
     * BY/LIMIT, since that support isn't confirmed to exist in the query builder used here
     * (same reasoning as SuiHoldingsCacheRepository::getFreshCache()).
     *
     * @return array{responseJson: string, cachedAt: int}|null
     */
    public function getFreshCache(string $address): ?array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('response_json, cached_at')
                ->where('address = :address')
            ,
            ['address' => strtolower($address)]
        );

        $latest = null;

        foreach ($rows as $row) {
            $cachedAt = (int) $row['cached_at'];

            if ($latest === null || $cachedAt > $latest['cachedAt']) {
                $latest = ['responseJson' => $row['response_json'], 'cachedAt' => $cachedAt];
            }
        }

        if ($latest === null || (time() - $latest['cachedAt']) > self::MAX_CACHE_AGE_SECONDS) {
            return null;
        }

        return $latest;
    }

    /**
     * Returns the cached response for this address whose as_of_date exactly matches the
     * given date, or null if none exists. Ignores MAX_CACHE_AGE_SECONDS entirely, unlike
     * getFreshCache() -- a row "for" a given date is valid regardless of how old it is now.
     * At most one write path (a live /holdings-now call) can currently produce a row for a
     * given address+date, but the most recent by cached_at is still picked among same-day
     * rows for robustness, same reasoning as SuiHoldingsCacheRepository::getCacheForDate().
     *
     * @return array{responseJson: string, cachedAt: int}|null
     */
    public function getCacheForDate(string $address, string $date): ?array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('response_json, cached_at')
                ->where('address = :address AND as_of_date = :as_of_date')
            ,
            ['address' => strtolower($address), 'as_of_date' => $date]
        );

        $latest = null;

        foreach ($rows as $row) {
            $cachedAt = (int) $row['cached_at'];

            if ($latest === null || $cachedAt > $latest['cachedAt']) {
                $latest = ['responseJson' => $row['response_json'], 'cachedAt' => $cachedAt];
            }
        }

        return $latest;
    }

    /**
     * @param string $asOfDate The calendar date (YYYY-MM-DD, UTC) this response represents.
     *                         Currently always today, since /holdings-now only ever produces
     *                         live snapshots -- but tracked as its own column (not derived
     *                         from cached_at) for the same reason as sui_holdings_cache, in
     *                         case a backfilled source is ever added later.
     * @param string $source  'live' currently -- the only source that writes to this table.
     *                        Kept as an explicit parameter (rather than hardcoded) to mirror
     *                        SuiHoldingsCacheRepository::store()'s signature, in case a second
     *                        source is introduced later the way SUI's 'reconstructed' was.
     */
    public function store(string $address, string $responseJson, string $asOfDate, string $source): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'address, as_of_date, response_json, source, cached_at',
                    ':address, :as_of_date, :response_json, :source, :cached_at'
                )
            ,
            [
                'address' => strtolower($address),
                'as_of_date' => $asOfDate,
                'response_json' => $responseJson,
                'source' => $source,
                'cached_at' => time()
            ]
        );
    }
}
