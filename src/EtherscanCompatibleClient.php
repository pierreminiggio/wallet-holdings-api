<?php

namespace App;

abstract class EtherscanCompatibleClient
{
    // Etherscan-family APIs cap these endpoints at 10,000 returned records regardless of
    // pagination; beyond that, narrowing the startblock/endblock range is required. This
    // client surfaces that situation via ERROR_TRUNCATED rather than silently returning an
    // incomplete list, since silently losing data here would corrupt a balance calculation.
    private const HARD_RECORD_CAP = 10000;

    // A smaller page size than the API's own maximum (1000): large pages on a never-before
    // synced, active wallet have been observed to intermittently time out or return a
    // generic error on some of these free tiers, even though the same query sometimes
    // succeeds. Smaller pages mean more requests, but each one is cheaper for the upstream
    // indexer to assemble and is less likely to be the one that gets dropped.
    protected const PAGE_SIZE = 200;

    // Transient-looking failures (timeouts, generic errors, connection issues) are retried
    // with a short backoff before being treated as a real failure, since in practice the
    // same exact request often succeeds on a second or third attempt a moment later.
    // App::run() removes the script execution time limit for the holdings endpoint, so this
    // can afford to be more generous than a 30-second budget would allow.
    protected const MAX_ATTEMPTS = 4;
    protected const RETRY_DELAY_MICROSECONDS = 2_000_000;
    protected const REQUEST_TIMEOUT_SECONDS = 20;

    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_UPSTREAM = 'upstream_error';
    public const ERROR_INVALID_ADDRESS = 'invalid_address';
    public const ERROR_TRUNCATED = 'truncated';
    public const ERROR_NOT_YET_INDEXED = 'not_yet_indexed';

    private int $lastHttpCode = 0;
    private string $lastCurlError = '';
    private ?string $lastResponseBody = null;
    private ?string $lastAction = null;
    private ?string $lastPage = null;

    /**
     * Builds the full request URL for the given chain and Etherscan-style params. Each
     * concrete client knows its own base URL shape and any extra params it needs to add
     * (e.g. an API key).
     *
     * @param array<string, string> $params
     */
    abstract protected function buildUrl(int $chainId, array $params): string;

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
        $pageSize = static::PAGE_SIZE;
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
        for ($attempt = 1; $attempt <= static::MAX_ATTEMPTS; $attempt++) {
            $result = $this->requestOnce($chainId, $params);

            if (! is_string($result)) {
                return $result;
            }

            // Rate-limit and invalid-address responses are not transient: retrying
            // immediately won't help the former (it would only make it worse) and can't
            // help the latter at all, so both are returned immediately without retrying.
            // Not-yet-indexed (Blockscout status "2") is excluded too: confirmed via two
            // independent tests (this wallet, and an unrelated heavily-used contract) that
            // it reproduces consistently for the same block range rather than clearing up
            // moments later, so retrying within this request would only waste time. (Also
            // worth noting: without this exclusion, the loop would retry it anyway and
            // then fall through to returning the generic ERROR_UPSTREAM once attempts ran
            // out, silently discarding this more specific and more useful error code.)
            if (
                $result === self::ERROR_RATE_LIMITED
                || $result === self::ERROR_INVALID_ADDRESS
                || $result === self::ERROR_NOT_YET_INDEXED
            ) {
                return $result;
            }

            // Anything else (timeout, connection error, or a generic upstream error) has
            // been observed to be transient on these free tiers: the exact same request
            // often succeeds moments later. Retry with a short delay rather than failing
            // the whole sync on a single bad call.
            if ($attempt < static::MAX_ATTEMPTS) {
                usleep(static::RETRY_DELAY_MICROSECONDS);
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

        $url = $this->buildUrl($chainId, $params);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, static::REQUEST_TIMEOUT_SECONDS);
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

        // Blockscout-specific: status "2" means the requested block range hasn't finished
        // being indexed yet for internal transactions, sometimes with a genuinely empty
        // result, sometimes with real partial data alongside the warning -- confirmed via
        // direct testing on a real, unrelated wallet (Base's own bridge contract), so this
        // isn't specific to one address or a momentary blip: it's a known, possibly
        // long-lived characteristic of Blockscout's legacy API (Blockscout's own
        // per-transaction status message is "Awaiting internal transactions for status",
        // reported as far back as 2021 and still relevant in current Blockscout migration
        // discussions elsewhere).
        //
        // This API's stated goal is a *correct* balance for a given date, which rules out
        // using the partial data even though it's real: internal-transaction rows feed a
        // signed sum (+ for incoming, - for outgoing), and a partial result could be
        // missing rows from either side. Missing incoming rows understates the balance;
        // missing outgoing rows overstates it. Either way, "use what we got" doesn't move
        // the result closer to correct, it just produces a *different* wrong number --
        // and an overstated balance is arguably worse than the obviously-impossible
        // negative one this bug originally produced, since it wouldn't visibly signal
        // that anything was wrong. So this fails clearly instead, without touching
        // $allRows or trying to salvage the partial rows: there's no point processing
        // data that's about to be discarded regardless.
        if (isset($decoded['status']) && $decoded['status'] === '2') {
            return self::ERROR_NOT_YET_INDEXED;
        }

        // Etherscan-style status "0" can mean several different things, and they need to
        // be told apart here -- not downstream in getPaginated() -- because only errors
        // returned as a string from this method actually go through request()'s retry loop.
        // Only specifically recognizing "invalid address" and letting every other status
        // "0" message fall through as if it were a normal, successful, just-empty response
        // would mean a transient error is never retried at all: it would look like a
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

            if (str_contains($message, 'missing/invalid api key')) {
                // A bad or missing key is a configuration problem, not a transient one --
                // retrying the exact same request won't fix it. It's still reported as
                // ERROR_UPSTREAM (there's no dedicated constant for it) since the caller's
                // handling for "something is wrong with this client" is the same either way,
                // but it's called out explicitly here rather than silently falling into the
                // generic branch below, since this one is worth a clearer log message if it
                // ever shows up (it would mean config.php's etherscan api_key is missing or
                // wrong, not that the upstream service is having problems).
                error_log('Etherscan-compatible client: missing or invalid API key');

                return self::ERROR_UPSTREAM;
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
