<?php

namespace App;

class EtherscanApiClient extends EtherscanCompatibleClient
{
    private const BASE_URL = 'https://api.etherscan.io/v2/api';

    public function __construct(private string $apiKey)
    {
    }

    protected function buildUrl(int $chainId, array $params): string
    {
        $params['chainid'] = (string) $chainId;
        $params['apikey'] = $this->apiKey;

        return self::BASE_URL . '?' . http_build_query($params);
    }
}
