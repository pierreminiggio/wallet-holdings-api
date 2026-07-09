<?php

namespace App;

/**
 * Fetches current wallet holdings and DeFi positions from Zerion's REST API, covering
 * all chains simultaneously in one call (60+ chains including Ethereum, Base, Polygon,
 * BNB, Avalanche, and more). This replaces the per-chain approach of the earlier
 * RoutescanCurrentBalanceClient + BlockscoutCurrentBalanceClient pairing with a single
 * unified source that's been confirmed to have correct, live data (e.g. correctly
 * reporting 0.004871 EURC on Base vs Blockscout's stale 210 EURC for the same wallet).
 *
 * Requires a Zerion API key (free tier: 2,000 requests/day, no credit card):
 * https://dashboard.zerion.io/
 */
class ZerionClient
{
    private const BASE_URL = 'https://api.zerion.io/v1';

    public const ERROR_UPSTREAM = 'upstream_error';
    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_UNAUTHORIZED = 'unauthorized';

    public function __construct(private string $apiKey)
    {
    }

    /**
     * Fetches all current wallet token/native-coin positions across every supported chain.
     * Uses Zerion's server-side trash filter to exclude spam airdrops automatically.
     *
     * @return list<ZerionPosition>|string List on success, or one of the ERROR_* constants.
     */
    public function getWalletPositions(string $address): array|string
    {
        return $this->fetchPositions($address, 'wallet');
    }

    /**
     * Fetches all current DeFi protocol positions (staked, deposited, LP, locked, reward,
     * investment) across every supported chain. For wallets whose Aave/Compound positions
     * appear as aToken holdings rather than protocol positions, this may return empty --
     * that's a correct, expected result, not a failure.
     *
     * @return list<ZerionPosition>|string List on success, or one of the ERROR_* constants.
     */
    public function getDefiPositions(string $address): array|string
    {
        return $this->fetchPositions($address, 'deposit,loan,locked,staked,reward,investment');
    }

    /**
     * @return list<ZerionPosition>|string
     */
    private function fetchPositions(string $address, string $positionTypes): array|string
    {
        $params = http_build_query([
            'filter[position_types]' => $positionTypes,
            'filter[trash]' => 'only_non_trash',
            'filter[positions]' => 'only_simple',
            'currency' => 'usd',
            'sort' => 'value'
        ]);

        $url = self::BASE_URL . '/wallets/' . $address . '/positions/?' . $params;

        $response = $this->request($url);

        if (is_string($response)) {
            return $response;
        }

        $items = $response['data'] ?? null;

        if (! is_array($items)) {
            return self::ERROR_UPSTREAM;
        }

        $positions = [];

        foreach ($items as $item) {
            $position = $this->parsePosition($item);

            if ($position !== null) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    private function parsePosition(array $item): ?ZerionPosition
    {
        $attrs = $item['attributes'] ?? null;

        if (! is_array($attrs)) {
            return null;
        }

        $quantity = $attrs['quantity'] ?? null;
        $fungibleInfo = $attrs['fungible_info'] ?? null;

        if (! is_array($quantity) || ! is_array($fungibleInfo)) {
            return null;
        }

        $numeric = $quantity['numeric'] ?? null;

        if ($numeric === null) {
            return null;
        }

        $chainId = $item['relationships']['chain']['data']['id'] ?? null;

        if ($chainId === null) {
            return null;
        }

        $symbol = (string) ($fungibleInfo['symbol'] ?? '???');
        $positionType = (string) ($attrs['position_type'] ?? 'wallet');
        $protocolId = $attrs['protocol'] ?? null;
        $updatedAt = isset($attrs['updated_at']) ? (string) $attrs['updated_at'] : null;
        $updatedAtBlock = isset($attrs['updated_at_block']) ? (int) $attrs['updated_at_block'] : null;

        // Find the contract address for this specific chain from the implementations list.
        // Native coins have a null address on their chain, which correctly distinguishes
        // them from ERC-20 tokens (confirmed against real data: ETH, BNB, POL all have
        // null address entries on their respective native chains).
        $contractAddress = null;
        $isNative = false;

        foreach ((array) ($fungibleInfo['implementations'] ?? []) as $impl) {
            if (($impl['chain_id'] ?? null) === $chainId) {
                if ($impl['address'] === null) {
                    $isNative = true;
                } else {
                    $contractAddress = strtolower((string) $impl['address']);
                }
                break;
            }
        }

        return new ZerionPosition(
            chainId: $chainId,
            symbol: $symbol,
            amount: (string) $numeric,
            contractAddress: $contractAddress,
            isNative: $isNative,
            positionType: $positionType,
            protocolId: is_string($protocolId) ? $protocolId : null,
            updatedAt: $updatedAt,
            updatedAtBlock: $updatedAtBlock
        );
    }

    /**
     * @return array<string, mixed>|string
     */
    private function request(string $url): array|string
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_USERAGENT, 'wallet-holdings-api/1.0');
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->apiKey . ':')
        ]);

        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($result === false) {
            return self::ERROR_UPSTREAM;
        }

        if ($httpCode === 429) {
            return self::ERROR_RATE_LIMITED;
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return self::ERROR_UNAUTHORIZED;
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
