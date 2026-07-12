<?php

namespace App;

/**
 * Minimal read-only JSON-RPC client for EVM chains -- just eth_call, which is all
 * CompoundHoldingsClient and AaveHoldingsClient need. No web3 library dependency,
 * consistent with the rest of this project's from-scratch API clients (see ZerionClient).
 */
class EvmRpcClient
{
    public const ERROR_UPSTREAM = 'upstream_error';

    public function __construct(private string $rpcUrl)
    {
    }

    /**
     * @return string Hex result (e.g. "0x000...123") on success, or an ERROR_* constant.
     */
    public function ethCall(string $to, string $data): string
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'eth_call',
            'params' => [['to' => $to, 'data' => $data], 'latest'],
        ]);

        $curl = curl_init($this->rpcUrl);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);
        curl_setopt($curl, CURLOPT_USERAGENT, 'wallet-holdings-api/1.0');

        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($result === false || $httpCode !== 200) {
            return self::ERROR_UPSTREAM;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded) || ! isset($decoded['result']) || ! is_string($decoded['result'])) {
            return self::ERROR_UPSTREAM;
        }

        return $decoded['result'];
    }
}
