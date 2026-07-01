<?php

namespace App;

/**
 * Fetches *current* balances directly from Routescan, rather than reconstructing them
 * from transaction history. This sidesteps the entire signed-sum/internal-transaction
 * indexing-gap problem that affects historical reconstruction (see
 * EtherscanCompatibleClient and the README's notes on Blockscout's status:2 issue): these
 * endpoints return a balance the upstream explorer already computed and stores directly,
 * not something derived from replaying events. The trade-off is real and worth being
 * explicit about: this can only ever answer "what does this wallet hold right now", never
 * "what did it hold on a past date" -- there is no way to ask Routescan's free tier for a
 * historical version of these specific endpoints.
 */
class RoutescanCurrentBalanceClient
{
    private const BASE_URL = 'https://api.routescan.io/v2/network/mainnet/evm';

    public const ERROR_UPSTREAM = 'upstream_error';
    public const ERROR_RATE_LIMITED = 'rate_limited';

    /**
     * @return string|null Native balance in wei (as a string, since it can exceed PHP's
     *                       native integer/float precision), or null on failure.
     */
    public function getNativeBalance(int $chainId, string $address): ?string
    {
        $response = $this->request($chainId, [
            'module' => 'account',
            'action' => 'balance',
            'address' => $address,
            'tag' => 'latest'
        ]);

        if (! is_array($response) || ! isset($response['result']) || ! is_string($response['result'])) {
            return null;
        }

        return $response['result'];
    }

    /**
     * @return list<array{contract: string, symbol: string, decimals: int, balance: string}>|null
     *          Null on failure. An empty array is a legitimate "this wallet holds no
     *          tokens currently", distinct from null (couldn't determine).
     */
    public function getTokenBalances(int $chainId, string $address): ?array
    {
        $response = $this->request($chainId, [
            'module' => 'account',
            'action' => 'addresstokenbalance',
            'address' => $address,
            'page' => '1',
            'offset' => '1000'
        ]);

        if (! is_array($response)) {
            return null;
        }

        // Etherscan-style status "0" with "No tokens found" (or similar) is a legitimate
        // empty result, not a failure -- handled the same way as the other clients in
        // this project: only a non-array result is treated as an actual failure.
        $result = $response['result'] ?? null;

        if (! is_array($result)) {
            return null;
        }

        $balances = [];

        foreach ($result as $row) {
            if (
                ! isset($row['TokenAddress'], $row['TokenSymbol'], $row['TokenDivisor'], $row['TokenQuantity'])
            ) {
                continue;
            }

            $balances[] = [
                'contract' => strtolower((string) $row['TokenAddress']),
                'symbol' => (string) $row['TokenSymbol'],
                'decimals' => (int) $row['TokenDivisor'],
                'balance' => (string) $row['TokenQuantity']
            ];
        }

        return $balances;
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|string Decoded JSON body on success, or one of the
     *                                       self::ERROR_* constants on failure.
     */
    private function request(int $chainId, array $params): array|string
    {
        $url = self::BASE_URL . '/' . $chainId . '/etherscan/api?' . http_build_query($params);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($curl, CURLOPT_USERAGENT, 'wallet-holdings-api/1.0');

        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($result === false) {
            return self::ERROR_UPSTREAM;
        }

        if ($httpCode === 429) {
            return self::ERROR_RATE_LIMITED;
        }

        if ($httpCode !== 200) {
            return self::ERROR_UPSTREAM;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded)) {
            return self::ERROR_UPSTREAM;
        }

        return $decoded;
    }
}
