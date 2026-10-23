<?php

namespace Tests;

use App\EvmRpcClient;
use App\EvmRpcError;

/** A tiny "chain": eth_getBalance returns the block number it is asked about, eth_getCode says deployed from block 100. */
function batchWorld(): \Closure
{
    return static function (string $method, array $params): array {
        if ($method === 'eth_getBalance') {
            return ['result' => '0x' . dechex((int) hexdec(substr($params[1], 2)) * 10)];
        }

        if ($method === 'eth_getCode') {
            return ['result' => (int) hexdec(substr($params[1], 2)) >= 100 ? FAKE_CODE : '0x'];
        }

        if ($method === 'eth_call') {
            $to = strtolower($params[0]['to']);

            if ($to === '0xdead') {
                return ['error' => ['code' => 3, 'message' => 'execution reverted']];
            }

            return ['result' => '0x' . word((int) hexdec(substr($to, 2)))];
        }

        return ['error' => ['code' => -32601, 'message' => 'method not found']];
    };
}

function batchClient(int $maxBatchSize, array &$calls, array $options = [], int $retries = 0): EvmRpcClient
{
    return new EvmRpcClient('http://fake', fakeTransport(batchWorld(), $calls, $options), 15, $retries, 1, static function (int $ms): void {}, $maxBatchSize);
}

function balanceCalls(int $n): array
{
    $calls = [];

    for ($i = 1; $i <= $n; $i++) {
        $calls[] = ['eth_getBalance', ['0xabc', '0x' . dechex($i)]];
    }

    return $calls;
}

test('batch: 7 calls with a batch size of 3 are 3 HTTP requests (3, 3, 1), answers in the order asked', function () {
    HttpCounter::reset();
    $calls = [];
    $results = batchClient(3, $calls)->requestBatch(balanceCalls(7));

    assertSame(['0xa', '0x14', '0x1e', '0x28', '0x32', '0x3c', '0x46'], $results);
    assertSame(3, HttpCounter::$requests);
    assertSame(2, HttpCounter::$batches, 'the last chunk holds one call and goes out as a normal request');
});

test('batch: batching is OFF by default (a size of 1): no batch payload is ever sent', function () {
    HttpCounter::reset();
    $calls = [];
    $results = batchClient(1, $calls)->requestBatch(balanceCalls(4));

    assertSame(['0xa', '0x14', '0x1e', '0x28'], $results);
    assertSame(4, HttpCounter::$requests);
    assertSame(0, HttpCounter::$batches);
});

test('batch: answers are matched by id, not by position (a provider may reorder them)', function () {
    $calls = [];
    $results = batchClient(10, $calls, ['shuffle' => true])->requestBatch(balanceCalls(5));

    assertSame(['0xa', '0x14', '0x1e', '0x28', '0x32'], $results);
});

test('batch: a dropped entry is a failure of THAT entry, retried on its own, never an empty success', function () {
    HttpCounter::reset();
    $calls = [];
    $results = batchClient(10, $calls, ['drop' => static fn (int $position): bool => $position === 2])->requestBatch(balanceCalls(5));

    assertSame(['0xa', '0x14', '0x1e', '0x28', '0x32'], $results, 'the dropped call was re-requested individually');
    assertSame(2, HttpCounter::$requests, 'one batch, then one single request for the dropped entry');
});

test('batch: an entry that fails transiently is retried individually, the others keep their answer', function () {
    $calls = [];
    $results = batchClient(10, $calls, ['transient' => static fn (int $position): bool => $position === 0 || $position === 3], 2)->requestBatch(balanceCalls(5));

    assertSame(['0xa', '0x14', '0x1e', '0x28', '0x32'], $results);
});

test('batch: a revert inside a batch stays that entry\'s answer (not retried, not blamed on the others)', function () {
    HttpCounter::reset();
    $calls = [];
    $client = batchClient(10, $calls, [], 3);
    $results = $client->requestBatch([
        ['eth_call', [['to' => '0x01', 'data' => '0x12'], '0x10']],
        ['eth_call', [['to' => '0xdead', 'data' => '0x12'], '0x10']],
        ['eth_call', [['to' => '0x03', 'data' => '0x12'], '0x10']],
    ]);

    assertSame('0x' . word(1), $results[0]);
    assertTrue($results[1] instanceof EvmRpcError && $results[1]->isRevert());
    assertSame('0x' . word(3), $results[2]);
    assertSame(1, HttpCounter::$requests, 'a revert is final: no individual retry');
});

test('batch: a batch REFUSED outright turns batching off for this client and the calls still all get answered', function () {
    HttpCounter::reset();
    $calls = [];
    $client = batchClient(50, $calls, ['maxSize' => 3]);
    $results = $client->requestBatch(balanceCalls(8));

    assertSame(['0xa', '0x14', '0x1e', '0x28', '0x32', '0x3c', '0x46', '0x50'], $results);
    assertSame(1, $client->getMaxBatchSize(), 'a deterministic refusal switches batching off');

    HttpCounter::reset();
    $client->requestBatch(balanceCalls(4));
    assertSame(0, HttpCounter::$batches, 'no further batch is attempted');
});

test('batch: a malformed or empty batch answer is a transient failure, handled like a single call (bounded retries, then individual)', function () {
    $calls = [];
    $client = new EvmRpcClient('http://fake', static function (string $url, string $payload, int $timeout): array {
        $request = json_decode($payload, true);

        if (isset($request[0])) {
            return ['body' => '', 'status' => 200]; // every batch comes back empty
        }

        return ['body' => json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => '0x07']), 'status' => 200];
    }, 15, 1, 1, static function (int $ms): void {}, 10);

    assertSame(['0x07', '0x07', '0x07'], $client->requestBatch(balanceCalls(3)));
    assertSame(10, $client->getMaxBatchSize(), 'a TRANSIENT batch failure does not switch batching off');
});

test('ethCallMany: same per-call contract as ethCall (historical "0x" is an error), same order, one request', function () {
    HttpCounter::reset();
    $calls = [];
    $handler = static function (string $method, array $params): array {
        $to = strtolower($params[0]['to']);

        return match ($to) {
            '0x01' => ['result' => '0x' . word(1)],
            '0x02' => ['result' => '0x'],
            '0x03' => ['error' => ['code' => 3, 'message' => 'execution reverted']],
            default => ['result' => '0xzz'],
        };
    };
    $client = new EvmRpcClient('http://fake', fakeTransport($handler, $calls), 15, 0, 1, null, 10);
    $results = $client->ethCallMany([['0x01', '0x12'], ['0x02', '0x12'], ['0x03', '0x12'], ['0x04', '0x12']], 100);

    assertSame(['0x' . word(1), EvmRpcClient::ERROR_UPSTREAM, EvmRpcClient::ERROR_UPSTREAM, EvmRpcClient::ERROR_UPSTREAM], $results);
    assertSame(1, HttpCounter::$requests);
    assertTrue($client->getLastError() !== null);

    // at "latest" an empty "0x" stays as legacy ethCall returns it
    $latest = $client->ethCallMany([['0x02', '0x12']], 'latest');
    assertSame(['0x'], $latest);
});

test('hasCodesAt: the memo answers what it can, one batched eth_getCode answers the rest, and answers are remembered', function () {
    HttpCounter::reset();
    $calls = [];
    $client = batchClient(10, $calls);

    $first = $client->hasCodesAt(['0xAA', '0xbb', '0xcc'], 150);
    assertSame(['0xaa' => true, '0xbb' => true, '0xcc' => true], $first);
    assertSame(1, HttpCounter::$requests);

    // later block: every address is already known deployed, no request at all
    $second = $client->hasCodesAt(['0xaa', '0xbb'], 200);
    assertSame(['0xaa' => true, '0xbb' => true], $second);
    assertSame(1, HttpCounter::$requests);

    // an earlier block than a known deployment must be asked again
    $third = $client->hasCodesAt(['0xaa'], 50);
    assertSame(['0xaa' => false], $third);
    assertSame(2, HttpCounter::$requests);
});

test('hasCodesAt: a failing provider is an error, not "no code"', function () {
    $calls = [];
    $client = new EvmRpcClient('http://fake', fakeTransport(static fn (string $m, array $p): array => ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']], $calls), 15, 0, 1, null, 10);

    assertTrue($client->hasCodesAt(['0xaa', '0xbb'], 100) instanceof EvmRpcError);
});

test('chainId: parsed from eth_chainId, an error is an error', function () {
    $calls = [];
    $client = new EvmRpcClient('http://fake', fakeTransport(static fn (string $m, array $p): array => $m === 'eth_chainId' ? ['result' => '0x2105'] : ['error' => ['code' => -32601, 'message' => 'nope']], $calls));

    assertSame(8453, $client->chainId());

    $broken = new EvmRpcClient('http://fake', fakeTransport(static fn (string $m, array $p): array => ['result' => 'base'], $calls));
    assertTrue($broken->chainId() instanceof EvmRpcError);
});
