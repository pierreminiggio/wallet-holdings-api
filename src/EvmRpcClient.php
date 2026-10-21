<?php

namespace App;

use Closure;
use InvalidArgumentException;

/**
 * Minimal read-only JSON-RPC client for EVM chains. No web3 library dependency,
 * consistent with the rest of this project's from-scratch API clients (see ZerionClient).
 *
 * Two generations of callers share this class:
 *
 *  - CompoundHoldingsClient / AaveHoldingsClient on the "latest" block (what
 *    /holdings-now has always done): they use ethCall() and its legacy return contract
 *    (a hex string, or self::ERROR_UPSTREAM). That contract is unchanged.
 *  - The historical reconstruction (MULTICHAIN-RECONSTRUCTION-PLAN.md): it needs a block
 *    parameter on every read, eth_getBalance / eth_getLogs / eth_blockNumber /
 *    eth_getBlockByNumber / eth_getCode, and a failure it can classify. Those methods
 *    return the value on success or an EvmRpcError on failure (test with
 *    `instanceof EvmRpcError`).
 *
 * STRICT RESPONSE VALIDATION (MULTICHAIN-HOLDINGS.md, "SECOND CRITICAL FINDING"): a
 * response counts only if it is a well-formed JSON-RPC object carrying a "result" or an
 * "error" key. An empty body, a dropped connection, a non-JSON body or an object with
 * neither key is a FAILURE (transient), never an empty success. This exact bug shipped
 * in an earlier scan script and silently lost a real token position.
 *
 * RETRIES: only transient failures are retried, with exponential backoff, and only when
 * $maxTransientRetries > 0 (default 0, so /holdings-now behaves exactly as before). A
 * range cap or an auth failure is returned at once: waiting cannot fix either.
 */
class EvmRpcClient
{
    public const ERROR_UPSTREAM = 'upstream_error';

    private const NAMED_BLOCKS = ['latest', 'earliest', 'pending', 'safe', 'finalized'];

    private const MAX_BACKOFF_MS = 30000;

    /**
     * Diagnostics: HTTP attempts and time spent per call kind ("eth_getBalance", "eth_call:0x70a08231"...), for ALL clients of
     * the process, since the last takeStats(). Lets a long reconstruction say where its time goes (see takeStats()).
     *
     * @var array<string, array{calls: int, errors: int, seconds: float}>
     */
    private static array $stats = [];

    private ?EvmRpcError $lastError = null;

    private int $nextRequestId = 1;

    /**
     * Per lower-cased address: "deployedFrom" = lowest block where code is known to exist,
     * "missingThrough" = highest block where code is known to be absent. See hasCodeAt().
     *
     * @var array<string, array{deployedFrom: ?int, missingThrough: ?int}>
     */
    private array $codeMemo = [];

    /**
     * @param Closure|null $transport  fn(string $url, string $payload, int $timeoutSeconds):
     *                                 array{body: string|false, status: int}. Defaults to curl.
     *                                 Injectable so tests never touch the network.
     * @param Closure|null $sleeper    fn(int $milliseconds): void. Injectable for the same reason.
     */
    public function __construct(
        private string $rpcUrl,
        private ?Closure $transport = null,
        private int $timeoutSeconds = 15,
        private int $maxTransientRetries = 0,
        private int $retryBaseDelayMs = 500,
        private ?Closure $sleeper = null,
    ) {
    }

    /**
     * Returns the per-call-kind statistics accumulated since the previous call, and resets them. A call kind is the JSON-RPC
     * method, plus the 4-byte selector for eth_call (so balanceOf and getUserAccountData are told apart). Process-wide on
     * purpose (all clients, all providers): it is a diagnostic for the CLI scripts, not application state.
     *
     * @return array<string, array{calls: int, errors: int, seconds: float}>
     */
    public static function takeStats(): array
    {
        $stats = self::$stats;
        self::$stats = [];

        return $stats;
    }

    /**
     * One line summarising takeStats()'s result, biggest time first: "97 calls in 33.2s: eth_call:0x70a08231 40x/15.1s ...".
     *
     * @param array<string, array{calls: int, errors: int, seconds: float}> $stats
     */
    public static function describeStats(array $stats, int $top = 6): string
    {
        if ($stats === []) {
            return 'no RPC call';
        }

        uasort($stats, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        $calls = array_sum(array_column($stats, 'calls'));
        $seconds = array_sum(array_column($stats, 'seconds'));
        $parts = [];

        foreach (array_slice($stats, 0, $top, true) as $kind => $stat) {
            $parts[] = $kind . ' ' . $stat['calls'] . 'x/' . round($stat['seconds'], 1) . 's' . ($stat['errors'] > 0 ? '(' . $stat['errors'] . ' err)' : '');
        }

        return $calls . ' RPC attempts in ' . round($seconds, 1) . 's: ' . implode(', ', $parts);
    }

    public function getRpcUrl(): string
    {
        return $this->rpcUrl;
    }

    /**
     * The failure behind the most recent call that returned ERROR_UPSTREAM or an
     * EvmRpcError (null after a success). Diagnostic only.
     */
    public function getLastError(): ?EvmRpcError
    {
        return $this->lastError;
    }

    // ------------------------------------------------------------------ eth_call

    /**
     * @param int|string $block "latest" (default, legacy behaviour) or a block number.
     *
     * @return string Hex result (e.g. "0x000...123") on success, or ERROR_UPSTREAM.
     *                For a historical block (anything but "latest") a "0x" result is an
     *                ERROR, never a zero: it is what a call to a not-yet-deployed
     *                contract, or to a pruned state, silently returns. Callers that
     *                legitimately probe a contract that may not exist yet check
     *                hasCodeAt() first.
     */
    public function ethCall(string $to, string $data, int|string $block = 'latest'): string
    {
        $blockParam = self::blockParam($block);
        $result = $this->request('eth_call', [['to' => $to, 'data' => $data], $blockParam]);

        if ($result instanceof EvmRpcError) {
            return self::ERROR_UPSTREAM;
        }

        if (! is_string($result)) {
            $this->lastError = new EvmRpcError(EvmRpcError::KIND_TRANSIENT, 'eth_call result is not a string');

            return self::ERROR_UPSTREAM;
        }

        if ($blockParam !== 'latest') {
            if ($result === '0x') {
                $this->lastError = new EvmRpcError(
                    EvmRpcError::KIND_TRANSIENT,
                    'empty "0x" result for a historical eth_call (contract absent at that block, or state pruned)'
                );

                return self::ERROR_UPSTREAM;
            }

            if (preg_match('/^0x[0-9a-fA-F]*$/', $result) !== 1) {
                $this->lastError = new EvmRpcError(EvmRpcError::KIND_TRANSIENT, 'eth_call result is not hex');

                return self::ERROR_UPSTREAM;
            }
        }

        return $result;
    }

    // --------------------------------------------------------- historical helpers

    /**
     * @return int|EvmRpcError The chain head height.
     */
    public function blockNumber(): int|EvmRpcError
    {
        $result = $this->request('eth_blockNumber', []);

        if ($result instanceof EvmRpcError) {
            return $result;
        }

        $value = self::parseQuantity($result);

        return $value ?? $this->fail('eth_blockNumber result is not a hex quantity');
    }

    /**
     * @return string|EvmRpcError Native balance in wei as a hex quantity ("0x..."), never empty.
     */
    public function getBalance(string $address, int|string $block = 'latest'): string|EvmRpcError
    {
        $result = $this->request('eth_getBalance', [$address, self::blockParam($block)]);

        if ($result instanceof EvmRpcError) {
            return $result;
        }

        // Not parseQuantity(): a balance in wei routinely exceeds a native int.
        if (! self::isHexQuantity($result)) {
            return $this->fail('eth_getBalance result is not a hex quantity');
        }

        return $result;
    }

    /**
     * @return array{number: int, timestamp: int, hash: string}|EvmRpcError
     */
    public function getBlockByNumber(int|string $block): array|EvmRpcError
    {
        $result = $this->request('eth_getBlockByNumber', [self::blockParam($block), false]);

        if ($result instanceof EvmRpcError) {
            return $result;
        }

        if ($result === null) {
            // A null block is "not found": a provider lagging behind, or not holding that
            // block. Not a range cap, not an auth failure; bounded retries are the answer.
            return $this->fail('block not found');
        }

        if (! is_array($result)) {
            return $this->fail('eth_getBlockByNumber result is not an object');
        }

        $number = self::parseQuantity($result['number'] ?? null);
        $timestamp = self::parseQuantity($result['timestamp'] ?? null);

        if ($number === null || $timestamp === null) {
            return $this->fail('eth_getBlockByNumber result has no usable number/timestamp');
        }

        return [
            'number' => $number,
            'timestamp' => $timestamp,
            'hash' => is_string($result['hash'] ?? null) ? $result['hash'] : '',
        ];
    }

    /**
     * @return string|EvmRpcError The code as hex ("0x" when the address has none).
     */
    public function getCode(string $address, int|string $block = 'latest'): string|EvmRpcError
    {
        $result = $this->request('eth_getCode', [$address, self::blockParam($block)]);

        if ($result instanceof EvmRpcError) {
            return $result;
        }

        if (! is_string($result) || preg_match('/^0x[0-9a-fA-F]*$/', $result) !== 1) {
            return $this->fail('eth_getCode result is not hex');
        }

        return $result;
    }

    /**
     * Whether a contract exists at $address as of $block.
     *
     * This is how a historical read distinguishes "the contract was not deployed yet"
     * (the position is genuinely zero) from "the provider returned nothing" (a failure,
     * never a zero). A contract that exists at block B exists at every later block (the
     * contracts read here are proxies, never self-destructed), and one that is absent at
     * B is absent at every earlier block, so answers are remembered per instance and a
     * day-by-day walk pays for one eth_getCode per address, not one per day.
     */
    public function hasCodeAt(string $address, int $block): bool|EvmRpcError
    {
        $key = strtolower($address);
        $memo = $this->codeMemo[$key] ?? ['deployedFrom' => null, 'missingThrough' => null];

        if ($memo['deployedFrom'] !== null && $block >= $memo['deployedFrom']) {
            return true;
        }

        if ($memo['missingThrough'] !== null && $block <= $memo['missingThrough']) {
            return false;
        }

        $code = $this->getCode($address, $block);

        if ($code instanceof EvmRpcError) {
            return $code;
        }

        if ($code === '0x') {
            $memo['missingThrough'] = max($memo['missingThrough'] ?? $block, $block);
            $this->codeMemo[$key] = $memo;

            return false;
        }

        $memo['deployedFrom'] = min($memo['deployedFrom'] ?? $block, $block);
        $this->codeMemo[$key] = $memo;

        return true;
    }

    /**
     * eth_getLogs over an inclusive block range. An empty array is a genuine "no logs"
     * answer; a missing/null/non-list result is a failure.
     *
     * @param list<string|list<string>|null> $topics
     * @param string|list<string>|null $address
     *
     * @return list<array<string, mixed>>|EvmRpcError
     */
    public function getLogs(int $fromBlock, int $toBlock, array $topics = [], string|array|null $address = null): array|EvmRpcError
    {
        if ($fromBlock < 0 || $toBlock < $fromBlock) {
            throw new InvalidArgumentException("Invalid block range {$fromBlock}..{$toBlock}");
        }

        $filter = [
            'fromBlock' => self::blockParam($fromBlock),
            'toBlock' => self::blockParam($toBlock),
        ];

        if ($topics !== []) {
            $filter['topics'] = $topics;
        }

        if ($address !== null) {
            $filter['address'] = $address;
        }

        $result = $this->request('eth_getLogs', [$filter]);

        if ($result instanceof EvmRpcError) {
            return $result;
        }

        if (! is_array($result) || ($result !== [] && array_keys($result) !== range(0, count($result) - 1))) {
            return $this->fail('eth_getLogs result is not a list');
        }

        foreach ($result as $log) {
            if (
                ! is_array($log)
                || ! isset($log['blockNumber'], $log['address'], $log['data'])
                || ! is_array($log['topics'] ?? null)
            ) {
                return $this->fail('eth_getLogs returned a malformed log entry');
            }
        }

        return $result;
    }

    // ---------------------------------------------------------------- core request

    /**
     * One JSON-RPC call with strict validation and bounded transient retries.
     *
     * @param list<mixed> $params
     *
     * @return mixed The "result" member (may legitimately be null for some methods; the
     *               typed helpers above decide), or an EvmRpcError.
     */
    public function request(string $method, array $params): mixed
    {
        $attempt = 0;

        while (true) {
            $outcome = $this->requestOnce($method, $params);

            if (! ($outcome instanceof EvmRpcError)) {
                $this->lastError = null;

                return $outcome;
            }

            $this->lastError = $outcome;
            self::countError($method, $params);

            if (! $outcome->isTransient() || $attempt >= $this->maxTransientRetries) {
                return $outcome;
            }

            $delay = min(self::MAX_BACKOFF_MS, $this->retryBaseDelayMs * (2 ** $attempt));
            $attempt++;
            $this->sleepMs($delay);
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function requestOnce(string $method, array $params): mixed
    {
        $id = $this->nextRequestId++;
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);

        $transport = $this->transport ?? Closure::fromCallable([$this, 'curlTransport']);
        $startedAt = microtime(true);
        $response = $transport($this->rpcUrl, $payload, $this->timeoutSeconds);

        $kind = $method === 'eth_call' ? 'eth_call:' . substr((string) ($params[0]['data'] ?? ''), 0, 10) : $method;
        $stat = self::$stats[$kind] ?? ['calls' => 0, 'errors' => 0, 'seconds' => 0.0];
        $stat['calls']++;
        $stat['seconds'] += microtime(true) - $startedAt;
        self::$stats[$kind] = $stat;

        $body = $response['body'] ?? false;
        $status = (int) ($response['status'] ?? 0);

        if ($body === false || $body === '') {
            return EvmRpcError::fromTransportFailure($status, 'empty response body or network failure (HTTP ' . $status . ')');
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return EvmRpcError::fromTransportFailure($status, 'response is not a JSON object (HTTP ' . $status . ')');
        }

        if (array_key_exists('error', $decoded) && $decoded['error'] !== null) {
            return EvmRpcError::fromJsonRpcError($decoded['error'], $status);
        }

        if ($status !== 200) {
            return EvmRpcError::fromTransportFailure($status, 'unexpected HTTP status ' . $status);
        }

        if (! array_key_exists('result', $decoded)) {
            return EvmRpcError::fromTransportFailure(
                $status,
                'JSON-RPC response has neither "result" nor "error"'
            );
        }

        return $decoded['result'];
    }

    /**
     * @return array{body: string|false, status: int}
     */
    private function curlTransport(string $url, string $payload, int $timeoutSeconds): array
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($curl, CURLOPT_USERAGENT, 'wallet-holdings-api/1.0');

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return ['body' => $body, 'status' => $status];
    }

    private static function countError(string $method, array $params): void
    {
        $kind = $method === 'eth_call' ? 'eth_call:' . substr((string) ($params[0]['data'] ?? ''), 0, 10) : $method;

        if (isset(self::$stats[$kind])) {
            self::$stats[$kind]['errors']++;
        }
    }

    private function sleepMs(int $milliseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }

    private function fail(string $message): EvmRpcError
    {
        $this->lastError = new EvmRpcError(EvmRpcError::KIND_TRANSIENT, $message);

        return $this->lastError;
    }

    // ------------------------------------------------------------------- encoding

    /**
     * @param int|string $block A non-negative block number, a hex quantity, or a named tag.
     */
    public static function blockParam(int|string $block): string
    {
        if (is_int($block)) {
            if ($block < 0) {
                throw new InvalidArgumentException("Negative block number {$block}");
            }

            return '0x' . dechex($block);
        }

        if (in_array($block, self::NAMED_BLOCKS, true)) {
            return $block;
        }

        if (preg_match('/^0x[0-9a-fA-F]+$/', $block) === 1) {
            return $block;
        }

        throw new InvalidArgumentException("Invalid block parameter \"{$block}\"");
    }

    /**
     * @return int|null The integer value of a "0x..." quantity, or null when it is not one
     *                  (or does not fit a native int).
     */
    private static function parseQuantity(mixed $value): ?int
    {
        if (! self::isHexQuantity($value)) {
            return null;
        }

        $hex = ltrim(substr($value, 2), '0');

        if ($hex === '') {
            return 0;
        }

        if (strlen($hex) > 15) {
            return null;
        }

        return (int) hexdec($hex);
    }

    private static function isHexQuantity(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0x[0-9a-fA-F]+$/', $value) === 1;
    }
}
