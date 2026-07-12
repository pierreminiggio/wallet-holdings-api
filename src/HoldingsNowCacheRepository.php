<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

/**
 * Caches the full /holdings-now JSON response per address, so a request within the TTL
 * window returns instantly without re-calling Zerion or re-running the on-chain
 * Compound/Aave reads. Mirrors SuiHoldingsCacheRepository's pattern (same 2-hour TTL
 * constant name/value) used by /sui-holdings-now, for consistency.
 *
 * Unlike sui_holdings_cache (deliberately append-only, since /sui-holdings reads
 * historical snapshots by date), this is a single row per address that gets overwritten
 * on every fresh fetch -- there's no per-address history here, only ever "whenever this
 * address was last fetched." /holdings?date=X does read this table (getCacheForDate()),
 * but only ever gets a hit for the single most recent date each address happens to have
 * been fetched on; for any other date it falls back to zerion_position's genuine
 * multi-day history instead (see the /holdings block in App::run() and AGENTS.md Part 3).
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
     * Returns the cached response for this address if one exists and is younger than
     * MAX_CACHE_AGE_SECONDS, or null otherwise (no cache yet, or it's too old).
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

        if (! $rows) {
            return null;
        }

        $cachedAt = (int) $rows[0]['cached_at'];

        if ((time() - $cachedAt) > self::MAX_CACHE_AGE_SECONDS) {
            return null;
        }

        return ['responseJson' => $rows[0]['response_json'], 'cachedAt' => $cachedAt];
    }

    /**
     * Returns the cached response for this address if its cached_at falls on the given UTC
     * date, or null otherwise (no cache at all, or the cached row is from a different day).
     * Unlike getFreshCache(), this ignores MAX_CACHE_AGE_SECONDS entirely -- a row can be
     * "for" any date, old or new, since it's just whenever this address was last fetched.
     * Used by /holdings?date=X, which -- unlike /holdings-now -- wants an exact-day match,
     * not a freshness window.
     *
     * @return array{responseJson: string, cachedAt: int}|null
     */
    public function getCacheForDate(string $address, string $date): ?array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('response_json, cached_at')
                ->where('address = :address')
            ,
            ['address' => strtolower($address)]
        );

        if (! $rows) {
            return null;
        }

        $cachedAt = (int) $rows[0]['cached_at'];

        if (gmdate('Y-m-d', $cachedAt) !== $date) {
            return null;
        }

        return ['responseJson' => $rows[0]['response_json'], 'cachedAt' => $cachedAt];
    }

    public function store(string $address, string $responseJson): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'address, response_json, cached_at',
                    ':address, :response_json, :cached_at'
                )
                ->onDuplicateKeyUpdate(
                    'response_json = :response_json, cached_at = :cached_at'
                )
            ,
            [
                'address' => strtolower($address),
                'response_json' => $responseJson,
                'cached_at' => time()
            ]
        );
    }
}
