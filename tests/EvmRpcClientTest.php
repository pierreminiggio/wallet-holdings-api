<?php

namespace Tests;

use App\EvmRpcClient;
use App\EvmRpcError;

/** Builds a client wired to a scripted queue of raw replies; every request is recorded. */
function scripted(array $replies, array &$calls, array $options = []): EvmRpcClient
{
    $queue = $replies;
    $transport = static function (string $url, string $payload, int $timeout) use (&$queue, &$calls): array {
        $request = json_decode($payload, true);
        $calls[] = ['method' => $request['method'], 'params' => $request['params']];
        $reply = array_shift($queue);

        if ($reply === null) {
            throw new \RuntimeException('transport called more often than scripted');
        }

        return $reply;
    };

    $sleeps = &$options['sleeps'];

    return new EvmRpcClient(
        'http://fake',
        $transport,
        15,
        $options['retries'] ?? 0,
        100,
        static function (int $ms) use (&$sleeps): void {
            if (is_array($sleeps)) {
                $sleeps[] = $ms;
            }
        }
    );
}

function ok(mixed $result): array
{
    return ['body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]), 'status' => 200];
}

function rpcError(string $message, int $code = -32000, int $status = 200): array
{
    return ['body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => $code, 'message' => $message]]), 'status' => $status];
}

// ---------------------------------------------------------------- legacy contract

test('ethCall on latest: unchanged contract (hex on success, "0x" passed through)', function () {
    $calls = [];
    $client = scripted([ok('0x00ff'), ok('0x')], $calls);

    assertSame('0x00ff', $client->ethCall('0xabc', '0x1234'));
    assertSame('0x', $client->ethCall('0xabc', '0x1234'), 'latest keeps legacy behaviour for "0x"');
    assertSame('latest', $calls[0]['params'][1]);
    assertSame(['to' => '0xabc', 'data' => '0x1234'], $calls[0]['params'][0]);
});

test('ethCall: every kind of failure is ERROR_UPSTREAM (legacy contract)', function () {
    $calls = [];
    $client = scripted([
        ['body' => false, 'status' => 0],
        ['body' => '', 'status' => 200],
        ['body' => 'not json', 'status' => 200],
        ['body' => '{"jsonrpc":"2.0","id":1}', 'status' => 200],
        ['body' => '{"jsonrpc":"2.0","id":1,"result":null}', 'status' => 200],
        ok(123),
        rpcError('execution reverted'),
        ['body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x01']), 'status' => 502],
    ], $calls);

    for ($i = 0; $i < 8; $i++) {
        assertSame(EvmRpcClient::ERROR_UPSTREAM, $client->ethCall('0xabc', '0x12'), "failure #{$i}");
    }
});

// ------------------------------------------------------ historical eth_call rules

test('ethCall at a historical block sends the block as hex', function () {
    $calls = [];
    $client = scripted([ok('0x01')], $calls);

    assertSame('0x01', $client->ethCall('0xabc', '0x12', 23700766));
    assertSame('0x' . dechex(23700766), $calls[0]['params'][1]);
});

test('ethCall at a historical block: "0x" is an error, never a zero', function () {
    $calls = [];
    $client = scripted([ok('0x')], $calls);

    assertSame(EvmRpcClient::ERROR_UPSTREAM, $client->ethCall('0xabc', '0x12', 100));
    assertTrue($client->getLastError()?->isTransient() === true);
});

test('ethCall at a historical block: non-hex result is an error', function () {
    $calls = [];
    $client = scripted([ok('hello')], $calls);

    assertSame(EvmRpcClient::ERROR_UPSTREAM, $client->ethCall('0xabc', '0x12', 100));
});

test('blockParam: numbers, tags, hex; rejects garbage and negatives', function () {
    assertSame('0x0', EvmRpcClient::blockParam(0));
    assertSame('0xff', EvmRpcClient::blockParam(255));
    assertSame('latest', EvmRpcClient::blockParam('latest'));
    assertSame('0x10', EvmRpcClient::blockParam('0x10'));
    assertThrows(\InvalidArgumentException::class, fn () => EvmRpcClient::blockParam(-1));
    assertThrows(\InvalidArgumentException::class, fn () => EvmRpcClient::blockParam('yesterday'));
    assertThrows(\InvalidArgumentException::class, fn () => EvmRpcClient::blockParam('0x'));
});

// ------------------------------------------------------------ strict validation

test('strict validation: empty, malformed and key-less bodies are transient failures, never empty successes', function () {
    $calls = [];
    $client = scripted([
        ['body' => '', 'status' => 200],
        ['body' => false, 'status' => 0],
        ['body' => '<html>gateway</html>', 'status' => 200],
        ['body' => '{}', 'status' => 200],
        ['body' => '[]', 'status' => 200],
        ['body' => '"x"', 'status' => 200],
    ], $calls);

    for ($i = 0; $i < 6; $i++) {
        $result = $client->getLogs(1, 2);
        assertTrue($result instanceof EvmRpcError, "reply #{$i} must be an error");
        assertTrue($result->isTransient(), "reply #{$i} must be transient");
    }
});

test('strict validation: a genuinely empty log list IS a valid empty success', function () {
    $calls = [];
    $client = scripted([ok([])], $calls);

    assertSame([], $client->getLogs(1, 2));
});

test('strict validation: null / non-list / malformed log entries are failures', function () {
    $calls = [];
    $client = scripted([
        ok(null),
        ok('0x'),
        ok(['a' => 1]),
        ok([['blockNumber' => '0x1']]),
        ok([['blockNumber' => '0x1', 'address' => '0xa', 'data' => '0x', 'topics' => 'x']]),
    ], $calls);

    for ($i = 0; $i < 5; $i++) {
        assertTrue($client->getLogs(1, 2) instanceof EvmRpcError, "reply #{$i}");
    }
});

test('getLogs: request shape and valid result', function () {
    $calls = [];
    $log = ['blockNumber' => '0x10', 'address' => '0xtoken', 'data' => '0x01', 'topics' => ['0xsig']];
    $client = scripted([ok([$log])], $calls);

    $result = $client->getLogs(16, 32, ['0xsig', null, '0xwallet'], '0xtoken');

    assertSame([$log], $result);
    assertSame('eth_getLogs', $calls[0]['method']);
    assertSame(
        ['fromBlock' => '0x10', 'toBlock' => '0x20', 'topics' => ['0xsig', null, '0xwallet'], 'address' => '0xtoken'],
        $calls[0]['params'][0]
    );
    assertThrows(\InvalidArgumentException::class, fn () => $client->getLogs(5, 4));
});

// ------------------------------------------------------------- error classification

test('error classes: transient / range cap / auth', function () {
    $cases = [
        // transient
        ['Too many requests', EvmRpcError::KIND_TRANSIENT],
        ['rate limit exceeded', EvmRpcError::KIND_TRANSIENT],
        ['Request timeout on the free plan', EvmRpcError::KIND_TRANSIENT],
        ['request timed out', EvmRpcError::KIND_TRANSIENT],
        ['something nobody has ever seen', EvmRpcError::KIND_TRANSIENT],
        // deterministic range / result cap
        ['eth_getLogs is limited to a 2,000 range', EvmRpcError::KIND_RANGE_CAP],
        ['ranges over 10000 blocks are not supported', EvmRpcError::KIND_RANGE_CAP],
        ['query returned more than 10000 results', EvmRpcError::KIND_RANGE_CAP],
        ['Log response size exceeded', EvmRpcError::KIND_RANGE_CAP],
        // auth: neither waiting nor shrinking helps
        ['Unauthorized: invalid API key', EvmRpcError::KIND_AUTH],
        ['Archive requests require a personal token', EvmRpcError::KIND_AUTH],
        ['invalid params', EvmRpcError::KIND_AUTH],
        ['missing trie node abc (path )', EvmRpcError::KIND_AUTH],
    ];

    foreach ($cases as [$message, $expected]) {
        assertSame($expected, EvmRpcError::fromJsonRpcError(['code' => -32000, 'message' => $message])->getKind(), $message);
    }
});

test('error classes: HTTP statuses without a usable body', function () {
    assertSame(EvmRpcError::KIND_TRANSIENT, EvmRpcError::fromTransportFailure(429, 'x')->getKind());
    assertSame(EvmRpcError::KIND_TRANSIENT, EvmRpcError::fromTransportFailure(503, 'x')->getKind());
    assertSame(EvmRpcError::KIND_TRANSIENT, EvmRpcError::fromTransportFailure(0, 'x')->getKind());
    assertSame(EvmRpcError::KIND_AUTH, EvmRpcError::fromTransportFailure(401, 'x')->getKind());
    assertSame(EvmRpcError::KIND_AUTH, EvmRpcError::fromTransportFailure(403, 'x')->getKind());
    assertSame(EvmRpcError::KIND_RANGE_CAP, EvmRpcError::fromTransportFailure(413, 'x')->getKind());
});

test('a range cap answered with HTTP 400 is classified from the body, not the status', function () {
    $calls = [];
    $client = scripted([rpcError('eth_getLogs is limited to a 2,000 range', -32602, 400)], $calls);
    $result = $client->getLogs(1, 5000);

    assertTrue($result instanceof EvmRpcError);
    assertSame(EvmRpcError::KIND_RANGE_CAP, $result->getKind());
    assertSame(400, $result->getHttpStatus());
});

// ------------------------------------------------------------------------ retries

test('transient error then success: retried with exponential backoff', function () {
    $calls = [];
    $sleeps = [];
    $client = scripted([rpcError('Too many requests'), ['body' => '', 'status' => 200], ok('0x0a')], $calls, ['retries' => 3, 'sleeps' => &$sleeps]);

    assertSame(10, $client->blockNumber());
    assertSame(3, count($calls), 'same input sent three times');
    assertSame($calls[0], $calls[1]);
    assertSame($calls[1], $calls[2]);
    assertSame(null, $client->getLastError());
});

test('transient retries are bounded, and the last error is returned', function () {
    $calls = [];
    $client = scripted([rpcError('rate limit'), rpcError('rate limit'), rpcError('rate limit')], $calls, ['retries' => 2]);
    $result = $client->blockNumber();

    assertTrue($result instanceof EvmRpcError);
    assertTrue($result->isTransient());
    assertSame(3, count($calls), '1 attempt + 2 retries');
});

test('backoff delays double and are capped', function () {
    $calls = [];
    $sleeps = [];
    $replies = array_fill(0, 12, rpcError('rate limit'));
    $client = scripted($replies, $calls, ['retries' => 11, 'sleeps' => &$sleeps]);
    $client->blockNumber();

    assertSame(12, count($calls));
    assertSame([100, 200, 400, 800, 1600, 3200, 6400, 12800, 25600, 30000, 30000], $sleeps);
});

test('range cap is never retried (no backoff, caller shrinks)', function () {
    $calls = [];
    $sleeps = [];
    $client = scripted([rpcError('eth_getLogs is limited to a 2,000 range')], $calls, ['retries' => 5, 'sleeps' => &$sleeps]);
    $result = $client->getLogs(1, 9999);

    assertTrue($result instanceof EvmRpcError && $result->isRangeCap());
    assertSame(1, count($calls));
    assertSame([], $sleeps);
});

test('auth failure is never retried', function () {
    $calls = [];
    $sleeps = [];
    $client = scripted([rpcError('Unauthorized: invalid API key', -32000, 401)], $calls, ['retries' => 5, 'sleeps' => &$sleeps]);
    $result = $client->getBalance('0xabc', 10);

    assertTrue($result instanceof EvmRpcError && $result->isAuth());
    assertSame(1, count($calls));
    assertSame([], $sleeps);
});

test('default client does not retry at all (legacy behaviour)', function () {
    $calls = [];
    $client = scripted([rpcError('rate limit')], $calls);

    assertSame(EvmRpcClient::ERROR_UPSTREAM, $client->ethCall('0xabc', '0x12'));
    assertSame(1, count($calls));
});

// ----------------------------------------------------------------- typed helpers

test('blockNumber / getBalance / getBlockByNumber / getCode', function () {
    $calls = [];
    $client = scripted([
        ok('0x1388'),
        ok('0xde0b6b3a7640000'),
        ok(['number' => '0x10', 'timestamp' => '0x65f0a1c0', 'hash' => '0xabc', 'transactions' => []]),
        ok(null),
        ok('0x6080'),
        ok('0x'),
        ok('nope'),
        ok('0x'),
        ok('zzz'),
    ], $calls);

    assertSame(5000, $client->blockNumber());
    assertSame('0xde0b6b3a7640000', $client->getBalance('0xwallet', 123));
    assertSame('0x7b', $calls[1]['params'][1]);

    $block = $client->getBlockByNumber(16);
    assertSame(['number' => 16, 'timestamp' => 0x65f0a1c0, 'hash' => '0xabc'], $block);
    assertSame(['0x10', false], $calls[2]['params']);

    $missing = $client->getBlockByNumber(17);
    assertTrue($missing instanceof EvmRpcError && $missing->isTransient(), 'null block is a failure, not a block');

    assertSame('0x6080', $client->getCode('0xabc', 5));
    assertSame('0x', $client->getCode('0xabc', 5));
    assertTrue($client->getCode('0xabc', 5) instanceof EvmRpcError);
    // eth_getBalance: "0x" (empty) and garbage are failures
    assertTrue($client->getBalance('0xw', 1) instanceof EvmRpcError);
    assertTrue($client->getBalance('0xw', 1) instanceof EvmRpcError);
});

test('getBalance accepts balances beyond a native int', function () {
    $calls = [];
    $client = scripted([ok('0x' . str_repeat('f', 64))], $calls);

    assertSame('0x' . str_repeat('f', 64), $client->getBalance('0xw', 1));
});

test('hasCodeAt: answers are remembered (deployed stays deployed, absent stays absent)', function () {
    $calls = [];
    $client = scripted([ok('0x'), ok('0x6080'), ok('0x6080')], $calls);

    assertFalse($client->hasCodeAt('0xAbC', 100));
    assertSame(1, count($calls));
    // earlier than a known-absent block: absent, no call
    assertFalse($client->hasCodeAt('0xabc', 50));
    assertFalse($client->hasCodeAt('0xabc', 100));
    assertSame(1, count($calls));
    // later block: must ask
    assertTrue($client->hasCodeAt('0xabc', 200));
    assertSame(2, count($calls));
    // later than a known-deployed block: deployed, no call
    assertTrue($client->hasCodeAt('0xabc', 300));
    assertTrue($client->hasCodeAt('0xabc', 200));
    assertSame(2, count($calls));
    // between the two (the gap): must ask
    assertTrue($client->hasCodeAt('0xabc', 150));
    assertSame(3, count($calls));
});

test('hasCodeAt: memo is per address', function () {
    $calls = [];
    $client = scripted([ok('0x'), ok('0x6080')], $calls);

    assertFalse($client->hasCodeAt('0xaaa', 100));
    assertTrue($client->hasCodeAt('0xbbb', 100), 'a different address must not reuse the first one\'s answer');
    assertSame(2, count($calls));
});

test('hasCodeAt: an RPC failure is an error, not "no code"', function () {
    $calls = [];
    $client = scripted([rpcError('Unauthorized: invalid API key')], $calls);
    $result = $client->hasCodeAt('0xabc', 100);

    assertTrue($result instanceof EvmRpcError);
});
