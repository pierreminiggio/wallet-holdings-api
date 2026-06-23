<?php

namespace App;

class Network
{
    public const ETHEREUM = 'ethereum';
    public const BASE = 'base';
    public const POLYGON = 'polygon';
    public const BNB = 'bnb';

    private const CHAIN_IDS = [
        self::ETHEREUM => 1,
        self::BASE => 8453,
        self::POLYGON => 137,
        self::BNB => 56
    ];

    private const NATIVE_SYMBOLS = [
        self::ETHEREUM => 'ETH',
        self::BASE => 'ETH',
        self::POLYGON => 'POL',
        self::BNB => 'BNB'
    ];

    // Polygon's native token was MATIC before this date and was migrated 1:1 to POL on
    // this date; the underlying balance is continuous, only the display symbol changes
    // depending on which date is being queried.
    private const POLYGON_POL_MIGRATION_DATE = '2024-09-04';

    // Polygon and BNB are temporarily disabled alongside Base: Polygon was just confirmed to
    // return the same "chain not supported" error directly from Routescan's free tier (so my
    // earlier claim that it was "confirmed working" was wrong -- that was based on finding a
    // Routescan *web explorer* page for Polygon, which is not the same as the keyless *API*
    // tier actually serving it). BNB was never independently verified against the live API
    // either, only assumed from the existence of a 56.routescan.io subdomain, which has the
    // same problem. Only Ethereum has actually returned real data through this client so far,
    // so only Ethereum is left active until each of the others is re-verified one at a time
    // directly against the live API, the same way Ethereum was.
    public const ALL = [self::ETHEREUM /*, self::POLYGON, self::BNB, self::BASE */];

    public static function isValid(string $network): bool
    {
        return isset(self::CHAIN_IDS[$network]);
    }

    public static function chainId(string $network): int
    {
        return self::CHAIN_IDS[$network];
    }

    /**
     * @param string $date The date being queried, in YYYY-MM-DD format, used to pick MATIC
     *                      vs POL on Polygon depending on whether it's before or after the
     *                      1:1 rebrand migration. Irrelevant for every other network.
     */
    public static function nativeSymbol(string $network, string $date): string
    {
        if ($network === self::POLYGON && $date < self::POLYGON_POL_MIGRATION_DATE) {
            return 'MATIC';
        }

        return self::NATIVE_SYMBOLS[$network];
    }
}
