<?php

namespace Tests;

use App\EvmBlockByDateRepository;
use App\EvmBlockResolution;
use App\EvmBlockResolver;
use App\EvmRpcClient;

/**
 * A synthetic chain: $timestamps[b] is block b's timestamp (non-decreasing). The handler
 * answers eth_blockNumber and eth_getBlockByNumber (null beyond the head), and counts reads.
 */
function chainHandler(array $timestamps, ?int &$reads = null): \Closure
{
    return static function (string $method, array $params) use ($timestamps, &$reads): array {
        $head = count($timestamps) - 1;

        if ($method === 'eth_blockNumber') {
            return ['result' => '0x' . dechex($head)];
        }

        if ($method === 'eth_getBlockByNumber') {
            $reads++;
            $block = (int) hexdec(substr($params[0], 2));

            if ($block > $head) {
                return ['result' => null];
            }

            return ['result' => ['number' => '0x' . dechex($block), 'timestamp' => '0x' . dechex($timestamps[$block]), 'hash' => '0xabc']];
        }

        return ['error' => ['code' => -32601, 'message' => 'method not found']];
    };
}

function resolverFor(string $chain, array $timestamps, ?EvmBlockByDateRepository $repo = null, ?int &$reads = null, array &$calls = []): EvmBlockResolver
{
    $transport = fakeTransport(chainHandler($timestamps, $reads), $calls);

    return new EvmBlockResolver($chain, [new EvmRpcClient('http://fake', $transport)], $repo);
}

/** Brute force: last block with timestamp < $endOfDay, or null when even block $start is not before it. */
function bruteForceEndBlock(array $timestamps, int $endOfDay, int $start = 0): ?int
{
    $answer = null;

    for ($b = $start; $b < count($timestamps); $b++) {
        if ($timestamps[$b] < $endOfDay) {
            $answer = $b;
        }
    }

    return $answer;
}

const DAY = 86400;
const JAN_1_2024 = 1704067200; // 2024-01-01 00:00:00 UTC

function regularChain(int $blocks, int $blockTime = 12, int $firstTimestamp = JAN_1_2024 - 3 * DAY): array
{
    $timestamps = [];

    for ($b = 0; $b < $blocks; $b++) {
        $timestamps[$b] = $firstTimestamp + $b * $blockTime;
    }

    return $timestamps;
}

test('resolver: endOfDayTimestamp is next day 00:00:00 UTC, and rejects bad dates', function () {
    assertSame(JAN_1_2024, EvmBlockResolver::endOfDayTimestamp('2023-12-31'));
    assertSame(JAN_1_2024 + DAY, EvmBlockResolver::endOfDayTimestamp('2024-01-01'));
    assertSame(1709251200, EvmBlockResolver::endOfDayTimestamp('2024-02-29'), 'leap day');

    foreach (['2024-13-01', '2024-02-30', '24-01-01', '2024-1-1', '', 'yesterday', '2024-01-01 00:00:00'] as $bad) {
        assertThrows(\InvalidArgumentException::class, fn () => EvmBlockResolver::endOfDayTimestamp($bad), $bad);
    }
});

test('resolver: exact answer on a regular chain, strict "before" at the boundary', function () {
    $timestamps = regularChain(100000);
    $resolver = resolverFor('ethereum', $timestamps);

    foreach (['2023-12-29', '2023-12-30', '2023-12-31', '2024-01-01', '2024-01-02'] as $date) {
        $endOfDay = EvmBlockResolver::endOfDayTimestamp($date);
        $expected = bruteForceEndBlock($timestamps, $endOfDay);
        $result = $resolver->endBlockForDate($date);

        assertSame(EvmBlockResolution::STATUS_RESOLVED, $result->getStatus(), $date);
        assertSame($expected, $result->getBlock(), $date);
        assertTrue($timestamps[$result->getBlock()] < $endOfDay, "{$date}: end block is before the end of the day");
        assertTrue($timestamps[$result->getBlock() + 1] >= $endOfDay, "{$date}: next block is at or after it");
    }
});

test('resolver: a block exactly AT the day end belongs to the next day', function () {
    // block 5 has timestamp == end of 2023-12-31; it must NOT be the end block of that day.
    $timestamps = [JAN_1_2024 - 50, JAN_1_2024 - 40, JAN_1_2024 - 30, JAN_1_2024 - 20, JAN_1_2024 - 10, JAN_1_2024, JAN_1_2024 + 10, JAN_1_2024 + 20];
    $result = resolverFor('ethereum', $timestamps)->endBlockForDate('2023-12-31');

    assertSame(4, $result->getBlock());
});

test('resolver: matches brute force on irregular chains (bursts, stalls, equal timestamps)', function () {
    mt_srand(12345);

    for ($run = 0; $run < 25; $run++) {
        $timestamps = [];
        $t = JAN_1_2024 - 20 * DAY;
        $blocks = mt_rand(50, 4000);

        for ($b = 0; $b < $blocks; $b++) {
            $t += mt_rand(0, 40) === 0 ? mt_rand(300, 20000) : mt_rand(0, 30); // stalls + same-second blocks
            $timestamps[] = $t;
        }

        $resolver = resolverFor('ethereum', $timestamps);

        for ($d = 0; $d < 8; $d++) {
            $date = gmdate('Y-m-d', JAN_1_2024 - (19 - $d * 2) * DAY);
            $endOfDay = EvmBlockResolver::endOfDayTimestamp($date);
            $result = $resolver->endBlockForDate($date);

            if ($timestamps[count($timestamps) - 1] < $endOfDay) {
                assertSame(EvmBlockResolution::STATUS_DAY_NOT_COMPLETE, $result->getStatus(), "run {$run} {$date}");
            } elseif ($timestamps[0] >= $endOfDay) {
                assertSame(EvmBlockResolution::STATUS_BEFORE_START, $result->getStatus(), "run {$run} {$date}");
            } else {
                assertSame(bruteForceEndBlock($timestamps, $endOfDay), $result->getBlock(), "run {$run} {$date}");
            }
        }
    }
});

test('resolver: a Polygon-sized range costs about a dozen reads, not thousands', function () {
    // 1,000,000 blocks of 2s (Base/Polygon-like), then a 30-minute stall: the hard case for interpolation.
    $timestamps = [];
    $t = JAN_1_2024 - 10 * DAY;

    for ($b = 0; $b < 1000000; $b++) {
        $t += ($b === 400000) ? 1800 : 2;
        $timestamps[] = $t;
    }

    $reads = 0;
    $resolver = resolverFor('ethereum', $timestamps, null, $reads);
    $date = gmdate('Y-m-d', JAN_1_2024 - 6 * DAY);
    $result = $resolver->endBlockForDate($date);

    assertSame(bruteForceEndBlock($timestamps, EvmBlockResolver::endOfDayTimestamp($date)), $result->getBlock());
    assertTrue($reads <= 45, "too many block reads: {$reads}");
});

test('resolver: never searches below the history start (Polygon starts at 80,406,107)', function () {
    $start = 80406107;
    // A sparse 80M-block chain: blocks below $start are far older than the day; the first scanned block
    // ($start) is exactly at the day's end, so nothing scanned precedes it: BEFORE_START.
    $handler = static function (string $method, array $params) use ($start): array {
        if ($method === 'eth_blockNumber') {
            return ['result' => '0x' . dechex($start + 9)];
        }

        $block = (int) hexdec(substr($params[0], 2));
        $timestamp = $block < $start ? JAN_1_2024 - 100 * DAY : JAN_1_2024 + ($block - $start);

        return ['result' => ['number' => $params[0], 'timestamp' => '0x' . dechex($timestamp), 'hash' => '0x1']];
    };

    $calls = [];
    $resolver = new EvmBlockResolver('polygon', [new EvmRpcClient('http://fake', fakeTransport($handler, $calls))]);
    $result = $resolver->endBlockForDate('2023-12-31');

    foreach ($calls as $call) {
        if ($call['method'] === 'eth_getBlockByNumber') {
            assertTrue((int) hexdec(substr($call['params'][0], 2)) >= $start, 'no read below the history start');
        }
    }

    assertSame(EvmBlockResolution::STATUS_BEFORE_START, $result->getStatus());
});

test('resolver: today / a future date is DAY_NOT_COMPLETE and is never stored', function () {
    $timestamps = regularChain(1000);
    $repo = new MemoryBlockRepository();
    $resolver = resolverFor('ethereum', $timestamps, $repo);

    $headDay = gmdate('Y-m-d', end($timestamps));
    $result = $resolver->endBlockForDate($headDay);

    assertSame(EvmBlockResolution::STATUS_DAY_NOT_COMPLETE, $result->getStatus());
    assertSame(EvmBlockResolution::STATUS_DAY_NOT_COMPLETE, $resolver->endBlockForDate('2999-01-01')->getStatus());
    assertSame([], $repo->rows);
});

test('resolver: BEFORE_START and ERROR are never stored either', function () {
    $repo = new MemoryBlockRepository();
    $before = resolverFor('ethereum', regularChain(1000), $repo)->endBlockForDate('2000-01-01');
    assertSame(EvmBlockResolution::STATUS_BEFORE_START, $before->getStatus());

    $calls = [];
    $transport = fakeTransport(static fn (string $m, array $p): array => ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']], $calls);
    $broken = new EvmBlockResolver('ethereum', [new EvmRpcClient('http://fake', $transport)], $repo);
    $error = $broken->endBlockForDate('2023-12-31');

    assertSame(EvmBlockResolution::STATUS_ERROR, $error->getStatus());
    assertTrue($error->getError()->isAuth());
    assertSame([], $repo->rows);
});

test('resolver: a resolved day is stored once, and a cache hit makes no RPC call at all', function () {
    $timestamps = regularChain(100000);
    $repo = new MemoryBlockRepository();
    $calls = [];
    $resolver = resolverFor('ethereum', $timestamps, $repo, $reads, $calls);

    $first = $resolver->endBlockForDate('2023-12-31');
    assertSame(['ethereum|2023-12-31' => $first->getBlock()], $repo->rows);

    $callsAfterFirst = count($calls);
    $again = $resolver->endBlockForDate('2023-12-31');

    assertSame($first->getBlock(), $again->getBlock());
    assertSame($callsAfterFirst, count($calls), 'cache hit must not touch the RPC');

    // another chain does not share the row
    assertSame(null, $repo->find('base', '2023-12-31'));
});

test('resolver: a lower-bound hint saves reads, a wrong hint is ignored', function () {
    $timestamps = regularChain(300000);
    $date = '2023-12-31';
    $expected = bruteForceEndBlock($timestamps, EvmBlockResolver::endOfDayTimestamp($date));

    $readsPlain = 0;
    resolverFor('ethereum', $timestamps, null, $readsPlain)->endBlockForDate($date);

    $readsHinted = 0;
    $hinted = resolverFor('ethereum', $timestamps, null, $readsHinted)->endBlockForDate($date, $expected - 3000);
    assertSame($expected, $hinted->getBlock());
    assertTrue($readsHinted <= $readsPlain, "hinted {$readsHinted} vs plain {$readsPlain}");

    // a hint AFTER the answer (timestamp not before the day end) must be ignored, not trusted
    $readsBad = 0;
    $bad = resolverFor('ethereum', $timestamps, null, $readsBad)->endBlockForDate($date, $expected + 5000);
    assertSame($expected, $bad->getBlock());

    // out-of-range hints too
    assertSame($expected, resolverFor('ethereum', $timestamps)->endBlockForDate($date, 99999999)->getBlock());
    assertSame($expected, resolverFor('ethereum', $timestamps)->endBlockForDate($date, -5)->getBlock());
});

test('resolver: provider failover, per read (auth on the first provider, lagging second, healthy third)', function () {
    $timestamps = regularChain(100000);
    $expected = bruteForceEndBlock($timestamps, EvmBlockResolver::endOfDayTimestamp('2023-12-31'));

    $callsA = $callsB = $callsC = [];
    $auth = fakeTransport(static fn (string $m, array $p): array => ['error' => ['code' => -32000, 'message' => 'Archive requests require a personal token']], $callsA);
    // B only knows the first 100 blocks: every deeper read answers "block not found"
    $lagging = fakeTransport(chainHandler(array_slice($timestamps, 0, 100)), $callsB);
    $healthy = fakeTransport(chainHandler($timestamps), $callsC);

    $resolver = new EvmBlockResolver('ethereum', [
        new EvmRpcClient('http://a', $auth),
        new EvmRpcClient('http://b', $lagging),
        new EvmRpcClient('http://c', $healthy),
    ]);

    assertSame($expected, $resolver->endBlockForDate('2023-12-31')->getBlock());
    assertTrue(count($callsC) > 0 && count($callsA) > 0);
});

test('resolver: transient errors are retried by the client, then the result is exact', function () {
    $timestamps = regularChain(100000);
    $expected = bruteForceEndBlock($timestamps, EvmBlockResolver::endOfDayTimestamp('2023-12-31'));
    $inner = chainHandler($timestamps);
    $n = 0;

    // every 3rd request is rate limited
    $handler = static function (string $m, array $p) use ($inner, &$n): array {
        return (++$n % 3 === 0) ? ['error' => ['code' => -32005, 'message' => 'Too many requests']] : $inner($m, $p);
    };

    $calls = [];
    $client = new EvmRpcClient('http://fake', fakeTransport($handler, $calls), 15, 3, 1, static function (int $ms): void {});
    $result = (new EvmBlockResolver('ethereum', [$client]))->endBlockForDate('2023-12-31');

    assertSame($expected, $result->getBlock());
});

test('resolver: every provider failing is an ERROR, not a guess', function () {
    $calls = [];
    $transport = fakeTransport(static fn (string $m, array $p): array => ['raw' => '', 'status' => 502], $calls);
    $client = new EvmRpcClient('http://fake', $transport, 15, 2, 1, static function (int $ms): void {});
    $result = (new EvmBlockResolver('ethereum', [$client]))->endBlockForDate('2023-12-31');

    assertSame(EvmBlockResolution::STATUS_ERROR, $result->getStatus());
    assertSame(null, $result->getBlock());
    assertTrue($result->getError()->isTransient());
});

test('resolver: constructor rejects no clients and unsupported chains', function () {
    assertThrows(\InvalidArgumentException::class, fn () => new EvmBlockResolver('ethereum', []));
    assertThrows(\InvalidArgumentException::class, fn () => new EvmBlockResolver('avalanche', [new EvmRpcClient('http://x')]));
    assertThrows(\InvalidArgumentException::class, fn () => resolverFor('ethereum', regularChain(10))->endBlockForDate('not-a-date'));
});
