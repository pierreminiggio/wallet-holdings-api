<?php

namespace App;

class RoutescanApiClient extends EtherscanCompatibleClient
{
    private const BASE_URL = 'https://api.routescan.io/v2/network/mainnet/evm';

    protected function buildUrl(int $chainId, array $params): string
    {
        return self::BASE_URL . '/' . $chainId . '/etherscan/api?' . http_build_query($params);
    }
}
