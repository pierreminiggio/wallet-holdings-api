<?php

namespace App;

class RoutescanClient
{
    private const BASE_URL = 'https://api.routescan.io/v2/network/mainnet/evm';

    // Etherscan-family APIs cap these endpoints at 10,000 returned records regardless of
    // pagination; beyond that, narrowing the startblock/endblock range is required. This
    // client surfaces that situation via ERROR_TRUNCATED rather than silently returning an
    // incomplete list, since silently losing data here would corrupt a balance calculation.
    private const HARD_RECORD_CAP = 10000;

    // A smaller page size than the API's own maximum (1000): large pages on a never-before
    // synced, active wallet have been observed to intermittently time out or return a
    // generic "An error occurred" on Routescan's free tier, even though the same query
    // sometimes succeeds. Smaller pages mean more requests, but each one is cheaper for
    // their indexer to assemble and is less likely to be the one that gets dropped.
    private const PAGE_SIZE = 200;

    // Transient-looking failures (timeouts, generic errors, connection issues) are retried
    // with a short backoff before being treated as a real failure, since in practice the
    // same exact request often succeeds on a second or third attempt a moment later.
    // App::run() removes the script execution time limit for this endpoint specifically,
    // so this can afford to be more generous than a 30-second budget would allow.
    private const MAX_ATTEMPTS = 4;
    private const RETRY_DELAY_MICROSECONDS = 2_000_000;
    private const REQUEST_TIMEOUT_SECONDS = 20;

    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_UPSTREAM = 'upstream_error';
    public const ERROR_INVALID_ADDRESS = 'invalid_address';
    public const ERROR_TRUNCATED = 'truncated';

    private int $lastHttpCode = 0;
    private string $lastCurlError = '';
    private ?string $lastResponseBody = null;
    private ?string $lastAction = null;
    private ?string $lastPage = null;

    /**
     * Fetches every normal transaction involving $address on the given chain, starting
     * from $startBlock (for incremental syncs) and stopping once a row's timestamp
     * exceeds $untilTimestamp (so data beyond the requested date is never fetched).
     *
     * @return list<array<string, mixed>>|string List of transactions on success, or one of
     *                                             the self::ERROR_* constants on failure.
     */
    public function getNormalTransactions(int $chainId, string $address, int $startBlock, int $untilTimestamp): array|string
    {
        return $this->getPaginated($chainId, [
            'module' => 'account',
            'action' => 'txlist',
            'address' => $address
        ], $startBlock, $untilTimestamp);
    }

    /**
     * Fetches every internal transaction (contract-triggered native coin transfer)
     * involving $address on the given chain, with the same start/cutoff semantics as
     * getNormalTransactions().
     *
     * @return list<array<string, mixed>>|string
     */
    public function getInternalTransactions(int $chainId, string $address, int $startBlock, int $untilTimestamp): array|string
    {
        return $this->getPaginated($chainId, [
            'module' => 'account',
            'action' => 'txlistinternal',
            'address' => $address
        ], $startBlock, $untilTimestamp);
    }

    /**
     * Fetches every ERC-20 token transfer event involving $address on the given chain,
     * across every token contract (no contractaddress filter), so this single call
     * doubles as both "which tokens has this wallet ever touched" and "what moved", with
     * the same start/cutoff semantics as getNormalTransactions().
     *
     * @return list<array<string, mixed>>|string
     */
    public function getTokenTransfers(int $chainId, string $address, int $startBlock, int $untilTimestamp): array|string
    {
        return $this->getPaginated($chainId, [
            'module' => 'account',
            'action' => 'tokentx',
            'address' => $address
        ], $startBlock, $untilTimestamp);
    }

    /**
     * Handles pagination for any of the account-list endpoints above: keeps requesting
     * subsequent pages (in ascending block order) until either a short page signals the
     * end, or a row's timestamp exceeds $untilTimestamp, in which case that row and
     * everything after it (all later, since results are ascending) is discarded and
     * fetching stops there.
     *
     * @param array<string, string> $baseParams
     *
     * @return list<array<string, mixed>>|string
     */
    private function getPaginated(int $chainId, array $baseParams, int $startBlock, int $untilTimestamp): array|string
    {
        $pageSize = self::PAGE_SIZE;
        $page = 1;
        $allRows = [];

        while (true) {
            $params = $baseParams + [
                'startblock' => (string) $startBlock,
                'endblock' => '999999999',
                'page' => (string) $page,
                'offset' => (string) $pageSize,
                'sort' => 'asc'
            ];

            $response = $this->request($chainId, $params);

            if (is_string($response)) {
                return $response;
            }

            // Etherscan-style APIs return status "0" with message "No transactions found"
            // for an address with no (further) activity; that's a normal empty result,
            // not an error, so it's treated the same as an empty result array below.
            $result = $response['result'] ?? null;

            if (! is_array($result)) {
                if (($response['message'] ?? '') === 'No transactions found') {
                    return $allRows;
                }

                return self::ERROR_UPSTREAM;
            }

            $reachedCutoff = false;

            foreach ($result as $row) {
                if ((int) $row['timeStamp'] > $untilTimestamp) {
                    $reachedCutoff = true;

                    break;
                }

                $allRows[] = $row;
            }

            if ($reachedCutoff) {
                break;
            }

            if (count($allRows) >= self::HARD_RECORD_CAP) {
                // Hit (or exceeded) the API's hard 10,000-record cap while still inside
                // the requested date range: the data is genuinely incomplete, not just
                // "no more pages". Surfacing this distinctly matters because a silently
                // truncated transaction list means a silently wrong balance.
                return self::ERROR_TRUNCATED;
            }

            if (count($result) < $pageSize) {
                break;
            }

            $page++;
        }

        return $allRows;
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|string Decoded JSON body on success, or one of the
     *                                       self::ERROR_* constants on failure.
     */
    private function request(int $chainId, array $params): array|string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->requestOnce($chainId, $params);

            if (! is_string($result)) {
                return $result;
            }

            // Rate-limit and invalid-address responses are not transient: retrying
            // immediately won't help the former (it would only make it worse) and can't
            // help the latter at all, so both are returned immediately without retrying.
            if ($result === self::ERROR_RATE_LIMITED || $result === self::ERROR_INVALID_ADDRESS) {
                return $result;
            }

            // Anything else (timeout, connection error, or a generic upstream error like
            // Routescan's own "An error occurred") has been observed to be transient on
            // this free tier: the exact same request often succeeds moments later. Retry
            // with a short delay rather than failing the whole sync on a single bad call.
            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(self::RETRY_DELAY_MICROSECONDS);
            }
        }

        return self::ERROR_UPSTREAM;
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|string Decoded JSON body on success, or one of the
     *                                       self::ERROR_* constants on failure.
     */
    private function requestOnce(int $chainId, array $params): array|string
    {
        $this->lastAction = $params['action'] ?? null;
        $this->lastPage = $params['page'] ?? null;

        $url = self::BASE_URL . '/' . $chainId . '/etherscan/api?' . http_build_query($params);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($curl, CURLOPT_USERAGENT, 'wallet-holdings-api/1.0');

        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $this->lastHttpCode = $httpCode;
        $this->lastCurlError = $curlError;
        $this->lastResponseBody = is_string($result) ? $result : null;

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

        // Etherscan-style status "0" can mean several different things, and they need to
        // be told apart here -- not downstream in getPaginated() -- because only errors
        // returned as a string from this method actually go through request()'s retry loop.
        // A previous version of this check only specifically recognized "invalid address"
        // and let every other status "0" message (including transient ones) fall through
        // as if it were a normal, successful, just-empty response. That meant a transient
        // "An error occurred" response was never retried at all: it looked like a
        // legitimate empty result by the time getPaginated() saw it, which doesn't retry.
        if (isset($decoded['status']) && $decoded['status'] === '0') {
            $message = strtolower((string) ($decoded['message'] ?? ''));

            // A genuinely empty result for this address/range: not an error at all, and
            // must be returned as a real (empty) array so getPaginated() can tell it apart
            // from a transient failure and stop paginating cleanly.
            if (str_contains($message, 'no transactions found')) {
                return $decoded;
            }

            if (str_contains($message, 'invalid address')) {
                return self::ERROR_INVALID_ADDRESS;
            }

            // Anything else with status "0" -- "An error occurred", "Invalid querystring
            // request", "NOTOK", or any other message -- is treated as a transient
            // upstream error and retried, rather than assumed to be a permanent failure.
            return self::ERROR_UPSTREAM;
        }

        return $decoded;
    }

    /**
     * @return array{httpCode: int, curlError: string, responseBody: string|null, lastAction: string|null, lastPage: string|null}
     */
    public function getLastRequestDebugInfo(): array
    {
        return [
            'httpCode' => $this->lastHttpCode,
            'curlError' => $this->lastCurlError,
            'responseBody' => $this->lastResponseBody,
            'lastAction' => $this->lastAction,
            'lastPage' => $this->lastPage
        ];
    }
}
