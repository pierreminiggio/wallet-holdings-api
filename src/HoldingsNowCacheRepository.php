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
 * on every fresh fetch: /holdings-now has no "give me the cached snapshot from this
 * specific past date" counterpart endpoint, so there's no use case for keeping old rows
 * around, and upserting keeps the table small regardless of how often an address is queried.
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
