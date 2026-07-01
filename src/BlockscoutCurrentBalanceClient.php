<?php

namespace App;

/**
 * Fetches *current* balances directly from Blockscout's v2 API for Base, rather than
 * reconstructing them from transaction history. See RoutescanCurrentBalanceClient's
 * docblock for the full rationale; the short version is that this sidesteps the
 * internal-transaction indexing gap entirely, at the cost of only ever answering "what
 * does this wallet hold right now", never a historical date.
 */
class BlockscoutCurrentBalanceClient
{
    private const BASE_URL = 'https://base.blockscout.com/api/v2';

    public const ERROR_UPSTREAM = 'upstream_error';
    public const ERROR_RATE_LIMITED = 'rate_limited';

    /**
     * @return string|null Native balance in wei (as a string), or null on failure.
     */
    public function getNativeBalance(string $address): ?string
    {
        $response = $this->request('/addresses/' . $address);

        if (! is_array($response) || ! isset($response['coin_balance']) || ! is_string($response['coin_balance'])) {
            return null;
        }

        return $response['coin_balance'];
    }

    /**
     * @return list<array{contract: string, symbol: string, decimals: int, balance: string}>|null
     *          Null on failure. An empty array is a legitimate "this wallet holds no
     *          tokens currently", distinct from null (couldn't determine).
     */
    public function getTokenBalances(string $address): ?array
    {
        $response = $this->request('/addresses/' . $address . '/token-balances');

        // This endpoint's documented shape is a plain top-level array of items, not
        // wrapped in an "items"/"result" envelope the way the paginated v2 list endpoints
        // are (e.g. /addresses/{address}/tokens, which does have "items"). Both shapes
        // are accepted defensively here in case that assumption turns out wrong in
        // practice, rather than only handling the one that was found in documentation --
        // documentation alone has been wrong more than once in this project already.
        if (is_array($response) && isset($response['items']) && is_array($response['items'])) {
            $rows = $response['items'];
        } elseif (is_array($response) && ! isset($response['items'])) {
            $rows = $response;
        } else {
            return null;
        }

        $balances = [];

        foreach ($rows as $row) {
            if (! isset($row['value'], $row['token']['symbol'], $row['token']['decimals'], $row['token']['address_hash'])) {
                continue;
            }

            $balances[] = [
                'contract' => strtolower((string) $row['token']['address_hash']),
                'symbol' => (string) $row['token']['symbol'],
                'decimals' => (int) $row['token']['decimals'],
                'balance' => (string) $row['value']
            ];
        }

        return $balances;
    }

    /**
     * @return array<string, mixed>|string Decoded JSON body on success, or one of the
     *                                       self::ERROR_* constants on failure.
     */
    private function request(string $path): array|string
    {
        $url = self::BASE_URL . $path;

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
