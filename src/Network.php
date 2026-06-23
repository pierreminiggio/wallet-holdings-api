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

    // Base is temporarily disabled: Routescan's free tier doesn't index it ("chain not
    // supported" returned directly by their API), unlike Ethereum/Polygon/BNB which are all
    // confirmed working. Re-enabling Base requires a different upstream client (e.g.
    // Etherscan's official V2 API, which does support Base, but needs its own free API key)
    // rather than just adding it back here. See RoutescanClient and WalletSyncService for
    // the other places Base-specific wiring would need to be added back.
    public const ALL = [self::ETHEREUM, self::POLYGON, self::BNB /*, self::BASE */];

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
