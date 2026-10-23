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
     * @param int $maxBatchSize        Most JSON-RPC calls sent in ONE HTTP request by requestBatch() (1 = no batching, the safe
     *                                 default). Provider specific and measured, never assumed: NodeReal accepted 100, drpc's free
     *                                 plan 3 (bin/check-providers.php). A provider that refuses a batch turns batching off for this
     *                                 client and the calls are sent one by one, so a too-large setting costs speed, not correctness.
     */
    public function __construct(
        private string $rpcUrl,
        private ?Closure $transport = null,
        private int $timeoutSeconds = 15,
        private int $maxTransientRetries = 0,
        private int $retryBaseDelayMs = 500,
        private ?Closure $sleeper = null,
        private int $maxBatchSize = 1,
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

        return $this->finishEthCall($result, $blockParam);
    }

    /**
     * The eth_call result rules, shared by ethCall() and ethCallMany().
     */
    private function finishEthCall(mixed $result, string $blockParam): string
    {
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

    /**
     * Many eth_calls at one block, batched when the provider allows it. Same per-call contract as ethCall() (a hex string, or
     * ERROR_UPSTREAM for that call), same order as $calls. getLastError() describes the first failure.
     *
     * @param list<array{0: string, 1: string}> $calls [to, data] pairs
     *
     * @return list<string>
     */
    public function ethCallMany(array $calls, int|string $block): array
    {
        $blockParam = self::blockParam($block);
        $batch = [];

        foreach ($calls as [$to, $data]) {
            $batch[] = ['eth_call', [['to' => $to, 'data' => $data], $blockParam]];
        }

        $results = [];
        $firstError = null;

        foreach ($this->requestBatch($batch) as $result) {
            $value = $this->finishEthCall($result, $blockParam);

            if ($value === self::ERROR_UPSTREAM && $firstError === null) {
                $firstError = $this->lastError;
            }

            $results[] = $value;
        }

        if ($firstError !== null) {
            $this->lastError = $firstError;
        }

        return $results;
    }

    public function getMaxBatchSize(): int
    {
        return $this->maxBatchSize;
    }

    /**
     * The chain id the provider serves (eth_chainId). A configured URL that belongs to ANOTHER chain (a BNB Smart Chain URL
     * pasted under "base") would otherwise answer every read with the wrong chain's data and look perfectly healthy.
     */
    public function chainId(): int|EvmRpcError
    {
        $result = $this->request('eth_chainId', []);

        if ($result instanceof EvmRpcError) {
            return $result;
        }

        $value = self::parseQuantity($result);

        return $value ?? $this->fail('eth_chainId result is not a hex quantity');
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
        $known = $this->knownCode($key, $block);

        if ($known !== null) {
            return $known;
        }

        $code = $this->getCode($address, $block);

        if ($code instanceof EvmRpcError) {
            return $code;
        }

        $has = $code !== '0x';
        $this->rememberCode($key, $block, $has);

        return $has;
    }

    /**
     * hasCodeAt() for many addresses at one block: the memo answers what it can, and ONE batched eth_getCode round trip (when
     * the provider allows batching) answers the rest.
     *
     * @param list<string> $addresses
     *
     * @return array<string, bool>|EvmRpcError lower-cased address => has code; the first failure otherwise
     */
    public function hasCodesAt(array $addresses, int $block): array|EvmRpcError
    {
        $answers = [];
        $unknown = [];

        foreach ($addresses as $address) {
            $key = strtolower($address);
            $known = $this->knownCode($key, $block);

            if ($known !== null) {
                $answers[$key] = $known;
            } else {
                $unknown[$key] = $address;
            }
        }

        if ($unknown === []) {
            return $answers;
        }

        $keys = array_keys($unknown);
        $batch = [];

        foreach ($keys as $key) {
            $batch[] = ['eth_getCode', [$unknown[$key], self::blockParam($block)]];
        }

        foreach ($this->requestBatch($batch) as $i => $result) {
            if ($result instanceof EvmRpcError) {
                return $result;
            }

            if (! is_string($result) || preg_match('/^0x[0-9a-fA-F]*$/', $result) !== 1) {
                return $this->fail('eth_getCode result is not hex');
            }

            $has = $result !== '0x';
            $this->rememberCode($keys[$i], $block, $has);
            $answers[$keys[$i]] = $has;
        }

        return $answers;
    }

    /** @return bool|null null when the memo cannot answer */
    private function knownCode(string $key, int $block): ?bool
    {
        $memo = $this->codeMemo[$key] ?? ['deployedFrom' => null, 'missingThrough' => null];

        if ($memo['deployedFrom'] !== null && $block >= $memo['deployedFrom']) {
            return true;
        }

        if ($memo['missingThrough'] !== null && $block <= $memo['missingThrough']) {
            return false;
        }

        return null;
    }

    private function rememberCode(string $key, int $block, bool $has): void
    {
        $memo = $this->codeMemo[$key] ?? ['deployedFrom' => null, 'missingThrough' => null];

        if ($has) {
            $memo['deployedFrom'] = min($memo['deployedFrom'] ?? $block, $block);
        } else {
            $memo['missingThrough'] = max($memo['missingThrough'] ?? $block, $block);
        }

        $this->codeMemo[$key] = $memo;
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
     * Several JSON-RPC calls, in as few HTTP requests as the provider allows (maxBatchSize). Same order in and out; each
     * element is the call's result or an EvmRpcError, exactly as request() would have returned it.
     *
     * RULES, because a batch is where providers get creative:
     *  - answers are matched by id, never by position (a provider may reorder them);
     *  - an entry the provider silently dropped is a FAILURE of that entry (transient), never an empty success;
     *  - a transient failure of the whole batch is retried with the same bounded backoff as a single call;
     *  - a batch REFUSED outright (too large, not supported: a deterministic answer) turns batching off for this client and the
     *    calls are sent one by one, so a wrong batch size costs speed and never correctness;
     *  - after a batch, entries that failed transiently are re-requested individually (with request()'s own bounded retries),
     *    while a revert, a range cap or an auth failure of an entry is kept as that entry's answer.
     *
     * @param list<array{0: string, 1: list<mixed>}> $calls [method, params] pairs
     *
     * @return list<mixed|EvmRpcError>
     */
    public function requestBatch(array $calls): array
    {
        if ($this->maxBatchSize <= 1 || count($calls) <= 1) {
            return array_map(fn (array $call): mixed => $this->request($call[0], $call[1]), $calls);
        }

        $results = [];

        foreach (array_chunk($calls, $this->maxBatchSize, true) as $chunk) {
            if ($this->maxBatchSize <= 1) {
                // batching was switched off by an earlier chunk of this very call
                foreach ($chunk as $index => $call) {
                    $results[$index] = $this->request($call[0], $call[1]);
                }

                continue;
            }

            if (count($chunk) === 1) {
                foreach ($chunk as $index => $call) {
                    $results[$index] = $this->request($call[0], $call[1]);
                }

                continue;
            }

            $answers = $this->batchWithRetries($chunk);

            if ($answers instanceof EvmRpcError) {
                if (! $answers->isTransient()) {
                    // refused outright: deterministic, stop batching for this client
                    $this->lastError = $answers;
                    $this->maxBatchSize = 1;
                }

                foreach ($chunk as $index => $call) {
                    $results[$index] = $this->request($call[0], $call[1]);
                }

                continue;
            }

            foreach ($chunk as $index => $call) {
                $answer = $answers[$index];

                if ($answer instanceof EvmRpcError && $answer->isTransient()) {
                    $answer = $this->request($call[0], $call[1]);
                }

                $results[$index] = $answer;
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @param array<int, array{0: string, 1: list<mixed>}> $chunk
     *
     * @return array<int, mixed|EvmRpcError>|EvmRpcError per entry (same keys as $chunk), or the failure of the whole batch
     */
    private function batchWithRetries(array $chunk): array|EvmRpcError
    {
        $attempt = 0;

        while (true) {
            $outcome = $this->batchOnce($chunk);

            if (! ($outcome instanceof EvmRpcError) || ! $outcome->isTransient() || $attempt >= $this->maxTransientRetries) {
                return $outcome;
            }

            $this->lastError = $outcome;
            $delay = min(self::MAX_BACKOFF_MS, $this->retryBaseDelayMs * (2 ** $attempt));
            $attempt++;
            $this->sleepMs($delay);
        }
    }

    /**
     * @param array<int, array{0: string, 1: list<mixed>}> $chunk
     *
     * @return array<int, mixed|EvmRpcError>|EvmRpcError
     */
    private function batchOnce(array $chunk): array|EvmRpcError
    {
        $payload = [];
        $idToIndex = [];

        foreach ($chunk as $index => [$method, $params]) {
            $id = $this->nextRequestId++;
            $idToIndex[$id] = $index;
            $payload[] = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
        }

        $transport = $this->transport ?? Closure::fromCallable([$this, 'curlTransport']);
        $startedAt = microtime(true);
        $response = $transport($this->rpcUrl, json_encode($payload), $this->timeoutSeconds);

        $stat = self::$stats['batch'] ?? ['calls' => 0, 'errors' => 0, 'seconds' => 0.0];
        $stat['calls']++;
        $stat['seconds'] += microtime(true) - $startedAt;
        self::$stats['batch'] = $stat;

        $body = $response['body'] ?? false;
        $status = (int) ($response['status'] ?? 0);

        if ($body === false || $body === '') {
            return EvmRpcError::fromTransportFailure($status, 'empty response body or network failure (HTTP ' . $status . ')');
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return EvmRpcError::fromTransportFailure($status, 'batch response is not JSON (HTTP ' . $status . ')');
        }

        // A refused batch usually comes back as ONE error object instead of an array of answers.
        if (array_key_exists('error', $decoded) && $decoded['error'] !== null) {
            return EvmRpcError::fromJsonRpcError($decoded['error'], $status);
        }

        if ($status !== 200) {
            return EvmRpcError::fromTransportFailure($status, 'unexpected HTTP status ' . $status);
        }

        if ($decoded === [] || array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return EvmRpcError::fromTransportFailure($status, 'batch response is not a list of answers');
        }

        $answers = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ! array_key_exists('id', $entry) || ! isset($idToIndex[$entry['id']])) {
                continue; // an answer we cannot attribute: ignored, the entry it should have been stays missing below
            }

            $index = $idToIndex[$entry['id']];

            if (array_key_exists('error', $entry) && $entry['error'] !== null) {
                $answers[$index] = EvmRpcError::fromJsonRpcError($entry['error'], $status);
            } elseif (array_key_exists('result', $entry)) {
                $answers[$index] = $entry['result'];
            }
        }

        foreach (array_keys($chunk) as $index) {
            // A dropped entry is a failure of that entry, never an empty success.
            $answers[$index] ??= new EvmRpcError(EvmRpcError::KIND_TRANSIENT, 'the batch answer has no usable entry for this call');
        }

        return $answers;
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
