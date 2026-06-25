<?php

namespace App;

/**
 * Base's own Blockscout instance (base.blockscout.com), used as Base's primary provider.
 * Confirmed keyless and working directly against live data: both Routescan ("chain not
 * supported") and Etherscan's free tier (requires a paid plan for Base specifically) were
 * ruled out for this network first. Blockscout's legacy Etherscan-compatible API
 * (module=account&action=...) returns the same flat field shape as the other two clients,
 * confirmed against a real wallet with ~200 transactions spanning over a year with no
 * timeout, so it slots into the shared EtherscanCompatibleClient base with no new parsing.
 */
class BaseBlockscoutApiClient extends EtherscanCompatibleClient
{
    private const BASE_URL = 'https://base.blockscout.com/api';

    protected function buildUrl(int $chainId, array $params): string
    {
        // Blockscout's legacy per-instance API doesn't take a chain ID in the URL at all
        // (the instance itself is already chain-specific), unlike Routescan and Etherscan's
        // unified APIs. $chainId is accepted for interface compatibility with the abstract
        // base class but intentionally unused here.
        return self::BASE_URL . '?' . http_build_query($params);
    }
}
