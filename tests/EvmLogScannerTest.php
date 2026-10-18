<?php

namespace Tests;

use App\EvmLogScanner;
use App\EvmLogScanResult;
use App\EvmLogScanState;
use App\EvmRpcClient;

const SCAN_WALLET = '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';
const SCAN_OTHER = '0x1111111111111111111111111111111111111111';
const TOKEN_A = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const TOKEN_B = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const TOKEN_C = '0xcccccccccccccccccccccccccccccccccccccccc';
const NFT_N = '0xdddddddddddddddddddddddddddddddddddddddd';

function addrTopic(string $address): string
{
    return '0x' . str_repeat('0', 24) . strtolower(substr($address, 2));
}

/** A chain's log set: each entry ['block','token','from','to','topics' => 3|4]. */
function transferLog(int $block, string $token, string $from, string $to, int $topics = 3, int $index = 0): array
{
    return ['block' => $block, 'token' => $token, 'from' => $from, 'to' => $to, 'topics' => $topics, 'index' => $index];
}

/**
 * Fake eth_getLogs / eth_blockNumber. $cap: max range size before a range-cap error.
 * $failure(from, to, topics): ?array returns a handler reply to force a failure for that request.
 */
function logHandler(array $logs, int $cap = PHP_INT_MAX, ?\Closure $failure = null, int $head = 1000000000): \Closure
{
    return static function (string $method, array $params) use ($logs, $cap, $failure, $head): array {
        if ($method === 'eth_blockNumber') {
            return $failure !== null && ($forced = $failure(null, null, null)) !== null ? $forced : ['result' => '0x' . dechex($head)];
        }

        if ($method !== 'eth_getLogs') {
            return ['error' => ['code' => -32601, 'message' => 'method not found']];
        }

        $filter = $params[0];
        $from = (int) hexdec(substr($filter['fromBlock'], 2));
        $to = (int) hexdec(substr($filter['toBlock'], 2));
        $topics = $filter['topics'] ?? [];

        if ($failure !== null && ($forced = $failure($from, $to, $topics)) !== null) {
            return $forced;
        }

        if ($to - $from + 1 > $cap) {
            return ['error' => ['code' => -32602, 'message' => "eth_getLogs is limited to a {$cap} range"]];
        }

        $result = [];

        foreach ($logs as $log) {
            if ($log['block'] < $from || $log['block'] > $to) {
                continue;
            }

            $logTopics = [EvmLogScanner::TRANSFER_TOPIC, addrTopic($log['from']), addrTopic($log['to'])];

            if ($log['topics'] === 4) {
                $logTopics[] = '0x' . word(7);
            }

            $matches = true;

            foreach ($topics as $i => $wanted) {
                if ($wanted !== null && ($logTopics[$i] ?? null) !== $wanted) {
                    $matches = false;
                }
            }

            if ($matches) {
                $result[] = [
                    'blockNumber' => '0x' . dechex($log['block']),
                    'address' => $log['token'],
                    'data' => '0x' . word(1000000),
                    'topics' => $logTopics,
                    'transactionHash' => '0x' . str_pad(dechex($log['block']), 64, '0', STR_PAD_LEFT),
                    'logIndex' => '0x' . dechex($log['index']),
                ];
            }
        }

        return ['result' => $result];
    };
}

function scannerClient(\Closure $handler, array &$calls, int $retries = 0): EvmRpcClient
{
    return new EvmRpcClient('http://fake', fakeTransport($handler, $calls), 15, $retries, 1, static function (int $ms): void {});
}

/** Requested eth_getLogs [from, to] ranges, in order (each chunk issues two requests: dedupe consecutive pairs). */
function requestedRanges(array $calls): array
{
    $ranges = [];

    foreach ($calls as $call) {
        if ($call['method'] === 'eth_getLogs') {
            $ranges[] = [(int) hexdec(substr($call['params'][0]['fromBlock'], 2)), (int) hexdec(substr($call['params'][0]['toBlock'], 2))];
        }
    }

    return $ranges;
}

test('scanner: discovers tokens in both directions, lower-cased and de-duplicated', function () {
    $logs = [
        transferLog(100, TOKEN_A, SCAN_OTHER, SCAN_WALLET),
        transferLog(5000, TOKEN_B, SCAN_WALLET, SCAN_OTHER),
        transferLog(5001, TOKEN_A, SCAN_OTHER, SCAN_WALLET),
        transferLog(9000, TOKEN_C, SCAN_OTHER, SCAN_OTHER),   // does not touch the wallet
        transferLog(9500, strtoupper(TOKEN_B), SCAN_WALLET, SCAN_WALLET), // self transfer: matches both directions
    ];
    $calls = [];
    $scanner = new EvmLogScanner('ethereum', [scannerClient(logHandler($logs), $calls)]);
    $result = $scanner->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 20000);

    assertSame(EvmLogScanResult::STATUS_COMPLETE, $result->getStatus());
    assertSame([TOKEN_A, TOKEN_B], $result->getState()->tokens());
    assertSame(20000, $result->getState()->getLastScannedBlock());
    assertSame(100, $result->getState()->getFirstLogBlock());
    assertSame([], $result->getGaps());
});

test('scanner: ERC-721-shaped Transfers (4 topics) are kept apart from tokens, not dropped', function () {
    $logs = [transferLog(10, TOKEN_A, SCAN_OTHER, SCAN_WALLET), transferLog(20, NFT_N, SCAN_OTHER, SCAN_WALLET, 4)];
    $calls = [];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler($logs), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 100);

    assertSame([TOKEN_A], $result->getState()->tokens());
    assertSame([NFT_N], $result->getState()->otherTransferContracts());
});

test('scanner: sends the exact topic filters (both directions, wallet topic padded and lower-cased)', function () {
    $calls = [];
    (new EvmLogScanner('ethereum', [scannerClient(logHandler([]), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 10);

    $wallet = '0x0000000000000000000000000ed2adcc25ab3576928c1b4f47bac3e8f30afede';
    assertSame(
        [[EvmLogScanner::TRANSFER_TOPIC, null, $wallet], [EvmLogScanner::TRANSFER_TOPIC, $wallet, null]],
        [$calls[0]['params'][0]['topics'], $calls[1]['params'][0]['topics']]
    );
    assertThrows(\InvalidArgumentException::class, fn () => EvmLogScanner::walletTopic('0x123'));
});

test('scanner: a range cap shrinks IMMEDIATELY (no sleep, no retry) and coverage is contiguous', function () {
    $sleeps = 0;
    $calls = [];
    $client = new EvmRpcClient('http://fake', fakeTransport(logHandler([transferLog(777, TOKEN_A, SCAN_OTHER, SCAN_WALLET)], 101), $calls), 15, 5, 1, static function (int $ms) use (&$sleeps): void {
        $sleeps++;
    });
    $result = (new EvmLogScanner('ethereum', [$client]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 3000);

    assertSame(EvmLogScanResult::STATUS_COMPLETE, $result->getStatus());
    assertSame(0, $sleeps, 'a deterministic cap must never cost a backoff');
    assertSame([TOKEN_A], $result->getState()->tokens());

    // Every successful request [from,to] must be <= 101 blocks, and together they cover 0..3000 exactly once.
    $covered = [];

    foreach (requestedRanges($calls) as [$from, $to]) {
        if ($to - $from + 1 <= 101) {
            for ($b = $from; $b <= $to; $b++) {
                $covered[$b] = true;
            }
        }
    }

    assertSame(3001, count($covered));
});

test('scanner: once a cap is learned, growth does not keep probing it (few wasted requests)', function () {
    $calls = [];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler([], 101), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 100000);

    assertTrue($result->isComplete());

    $failed = count(array_filter(requestedRanges($calls), static fn (array $r): bool => $r[1] - $r[0] + 1 > 101));
    $total = count(requestedRanges($calls));

    // converging on the cap costs ~15 failures once, then ~3 per 200 chunks of periodic probing (about 2%)
    assertTrue($failed <= 40, "{$failed} cap errors out of {$total} requests");
});

test('scanner: transient error then success: same range retried (client backoff), never shrunk', function () {
    $attempt = 0;
    $failure = static function (?int $from, ?int $to, ?array $topics) use (&$attempt): ?array {
        if ($from === 0 && ++$attempt <= 2) {
            return ['error' => ['code' => -32000, 'message' => 'Request timeout on the free plan']];
        }

        return null;
    };
    $calls = [];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler([], PHP_INT_MAX, $failure), $calls, 5)]))
        ->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 100, null);

    assertTrue($result->isComplete());
    $firstRanges = array_slice(requestedRanges($calls), 0, 4);
    assertSame([0, 100], $firstRanges[0]);
    assertSame([0, 100], $firstRanges[1], 'retried on the SAME range');
    assertSame([0, 100], $firstRanges[2]);
    assertSame([], $result->getGaps());
});

test('scanner: auth error drops the provider, the next one serves the scan', function () {
    $callsA = $callsB = [];
    $auth = static fn (?int $f, ?int $t, ?array $topics): array => ['error' => ['code' => -32000, 'message' => 'Archive requests require a personal token']];
    $a = scannerClient(logHandler([], PHP_INT_MAX, $auth), $callsA);
    $b = scannerClient(logHandler([transferLog(50, TOKEN_A, SCAN_OTHER, SCAN_WALLET)]), $callsB);
    $result = (new EvmLogScanner('ethereum', [$a, $b]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 200);

    assertTrue($result->isComplete());
    assertSame([TOKEN_A], $result->getState()->tokens());
    assertSame(1, count(requestedRanges($callsA)), 'the unusable provider is asked once, then dropped (no shrinking, no retries)');
});

test('scanner: every provider unusable (auth) stops with PROVIDER_ERROR, resumable, nothing recorded', function () {
    $calls = [];
    $auth = static fn (?int $f, ?int $t, ?array $topics): array => ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler([], PHP_INT_MAX, $auth), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 200);

    assertSame(EvmLogScanResult::STATUS_PROVIDER_ERROR, $result->getStatus());
    assertSame(-1, $result->getState()->getLastScannedBlock(), 'no progress past a failed range');
    assertSame([], $result->getGaps());
});

test('scanner: empty and malformed bodies are failures, never empty successes (no silent gap)', function () {
    foreach ([['raw' => '', 'status' => 200], ['raw' => '<html>bad gateway</html>', 'status' => 200], ['raw' => '{"jsonrpc":"2.0","id":1}', 'status' => 200]] as $bad) {
        $calls = [];
        $result = (new EvmLogScanner('ethereum', [scannerClient(static fn (string $m, array $p): array => $bad, $calls)]))
            ->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 100);

        assertSame(EvmLogScanResult::STATUS_PROVIDER_ERROR, $result->getStatus(), json_encode($bad));
        assertSame(-1, $result->getState()->getLastScannedBlock());
        assertSame([], $result->getGaps(), 'a total outage must not be recorded as gaps');
    }
});

test('scanner: ONE block failing on every provider is recorded as a gap and the scan continues', function () {
    $bad = 12345;
    $failure = static function (?int $from, ?int $to, ?array $topics) use ($bad): ?array {
        return ($from !== null && $from <= $bad && $to >= $bad)
            ? ['error' => ['code' => -32000, 'message' => 'Request timeout on the free plan']]
            : null;
    };
    $logs = [transferLog(100, TOKEN_A, SCAN_OTHER, SCAN_WALLET), transferLog(30000, TOKEN_B, SCAN_OTHER, SCAN_WALLET)];
    $gapCalls = [];
    $callsA = $callsB = [];
    $a = scannerClient(logHandler($logs, PHP_INT_MAX, $failure), $callsA);
    $b = scannerClient(logHandler($logs, PHP_INT_MAX, $failure), $callsB);
    $scanner = new EvmLogScanner('ethereum', [$a, $b], 40000, 20, null, static function (int $block, string $error) use (&$gapCalls): void {
        $gapCalls[] = [$block, $error];
    });
    $result = $scanner->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 40000);

    assertSame(EvmLogScanResult::STATUS_COMPLETE, $result->getStatus());
    assertSame(40000, $result->getState()->getLastScannedBlock());
    assertSame([TOKEN_A, TOKEN_B], $result->getState()->tokens(), 'blocks before AND after the bad one are still scanned');
    assertSame([$bad], array_column($result->getGaps(), 'block'));
    assertSame([$bad], array_column($gapCalls, 0), 'the onGap callback fires so it can be persisted');
    assertTrue(str_contains($gapCalls[0][1], 'timeout'));
});

test('scanner: a failing zone with no healthy block nearby is a provider problem, not a gap', function () {
    $failure = static function (?int $from, ?int $to, ?array $topics): ?array {
        return ($from !== null && $to >= 500 && $from <= 700) ? ['error' => ['code' => -32000, 'message' => 'Request timeout on the free plan']] : null;
    };
    $calls = [];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler([], PHP_INT_MAX, $failure), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 2000);

    assertSame(EvmLogScanResult::STATUS_PROVIDER_ERROR, $result->getStatus());
    assertSame([], $result->getGaps());
    assertSame(499, $result->getState()->getLastScannedBlock(), 'stopped right before the failing block, resumable');
});

test('scanner: ADJACENT bad blocks (the Base pairs) are both recorded as gaps and the scan completes', function () {
    $failure = static function (?int $from, ?int $to, ?array $topics): ?array {
        return ($from !== null && $to >= 500 && $from <= 501) ? ['error' => ['code' => -32000, 'message' => 'Request timeout on the free plan']] : null;
    };
    $calls = [];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler([transferLog(900, TOKEN_A, SCAN_OTHER, SCAN_WALLET)], PHP_INT_MAX, $failure), $calls)]))
        ->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 2000);

    assertSame(EvmLogScanResult::STATUS_COMPLETE, $result->getStatus());
    assertSame([500, 501], array_column($result->getGaps(), 'block'));
    assertSame([TOKEN_A], $result->getState()->tokens());
});

test('scanner: too many consecutive unresolvable blocks stops the scan', function () {
    $failure = static function (?int $from, ?int $to, ?array $topics): ?array {
        return ($from !== null && $to >= 100 && $from <= 107) ? ['error' => ['code' => -32000, 'message' => 'Request timeout on the free plan']] : null;
    };
    $calls = [];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler([], PHP_INT_MAX, $failure), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 1000);

    assertSame(EvmLogScanResult::STATUS_PROVIDER_ERROR, $result->getStatus());
    assertSame([100, 101, 102, 103, 104], array_column($result->getGaps(), 'block'));
    assertSame(104, $result->getState()->getLastScannedBlock());
});

test('scanner: skipped blocks are NEVER requested, and count as covered', function () {
    $calls = [];
    // Base skips 44661819-44661820 and 44692818-44692819
    $result = (new EvmLogScanner('base', [scannerClient(logHandler([transferLog(44661818, TOKEN_A, SCAN_OTHER, SCAN_WALLET), transferLog(44661821, TOKEN_B, SCAN_OTHER, SCAN_WALLET)]), $calls)]))
        ->scan(SCAN_WALLET, EvmLogScanState::startingAt(44661800, 40000), 44661900);

    assertTrue($result->isComplete());
    assertSame([TOKEN_A, TOKEN_B], $result->getState()->tokens(), 'blocks right around the skipped ones are scanned');

    foreach (requestedRanges($calls) as [$from, $to]) {
        assertTrue($to < 44661819 || $from > 44661820, "range {$from}-{$to} must not include a skipped block");
    }

    // the scan starting INSIDE a skipped zone, and ending inside one
    $calls = [];
    $result = (new EvmLogScanner('base', [scannerClient(logHandler([]), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(44661819), 44661820);
    assertTrue($result->isComplete());
    assertSame([], $calls, 'nothing to request at all');
    assertSame(44661820, $result->getState()->getLastScannedBlock());
});

test('scanner: a skipped-block list is per chain (Ethereum has none, so the same blocks are requested)', function () {
    $calls = [];
    (new EvmLogScanner('ethereum', [scannerClient(logHandler([]), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(44661800), 44661900);

    $included = array_filter(requestedRanges($calls), static fn (array $r): bool => $r[0] <= 44661819 && $r[1] >= 44661819);
    assertTrue(count($included) > 0);
});

test('scanner: resume at an interruption point finds the same tokens as one uninterrupted scan, with no hole', function () {
    mt_srand(99);
    $logs = [];

    for ($i = 0; $i < 40; $i++) {
        $logs[] = transferLog(mt_rand(0, 60000), [TOKEN_A, TOKEN_B, TOKEN_C][mt_rand(0, 2)], SCAN_OTHER, SCAN_WALLET, 3, $i);
    }

    $calls = [];
    $whole = (new EvmLogScanner('ethereum', [scannerClient(logHandler($logs, 500), $calls)]))->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 60000);

    // Interrupted every ~4 chunks via a deadline that fires after 4 clock reads, state serialised in between.
    $state = EvmLogScanState::startingAt(0);
    $rounds = 0;
    $callsB = [];
    $ticks = 0;
    $clock = static function () use (&$ticks): float {
        return (float) ($ticks++);
    };

    do {
        $ticks = 0;
        $state = EvmLogScanState::fromArray($state->toArray()); // what the cursor table round-trips
        $scanner = new EvmLogScanner('ethereum', [scannerClient(logHandler($logs, 500), $callsB)], 40000, 20, null, null, null, $clock);
        $result = $scanner->scan(SCAN_WALLET, $state, 60000, 4.0);
        $state = $result->getState();
        $rounds++;
    } while (! $result->isComplete() && $rounds < 500);

    assertTrue($rounds > 3, 'the scan really was interrupted several times');
    assertSame($whole->getState()->tokens(), $state->tokens());
    assertSame(60000, $state->getLastScannedBlock());

    // contiguity: every block 0..60000 was inside a successful request, nothing skipped
    $covered = [];

    foreach (requestedRanges($callsB) as [$from, $to]) {
        if ($to - $from + 1 <= 500) {
            for ($b = $from; $b <= $to; $b++) {
                $covered[$b] = true;
            }
        }
    }

    assertSame(60001, count($covered), 'no hole at any resume boundary');
});

test('scanner: time budget returns TIME_BUDGET with a valid resume point', function () {
    $ticks = 0;
    $clock = static function () use (&$ticks): float {
        return (float) $ticks++;
    };
    $calls = [];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler([]), $calls)], 1000, 20, null, null, null, $clock))
        ->scan(SCAN_WALLET, EvmLogScanState::startingAt(0, 1000), 1000000, 3.0);

    assertSame(EvmLogScanResult::STATUS_TIME_BUDGET, $result->getStatus());
    assertTrue($result->getState()->getLastScannedBlock() >= 0 && $result->getState()->getLastScannedBlock() < 1000000);
});

test('scanner: stopAtFirstLog stops after the chunk with the first activity', function () {
    $logs = [transferLog(4321, TOKEN_A, SCAN_OTHER, SCAN_WALLET)];
    $calls = [];
    $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler($logs), $calls)], 1000))
        ->scan(SCAN_WALLET, EvmLogScanState::startingAt(0, 1000), 1000000, null, true);

    assertSame(EvmLogScanResult::STATUS_FIRST_ACTIVITY, $result->getStatus());
    assertSame(4321, $result->getState()->getFirstLogBlock());
    assertTrue($result->getState()->getLastScannedBlock() >= 4321 && $result->getState()->getLastScannedBlock() < 6000);

    // and a wallet with no activity is scanned to the end
    $calls = [];
    $none = (new EvmLogScanner('ethereum', [scannerClient(logHandler($logs), $calls)], 1000))
        ->scan('0x2222222222222222222222222222222222222222', EvmLogScanState::startingAt(0, 1000), 20000, null, true);
    assertSame(EvmLogScanResult::STATUS_COMPLETE, $none->getStatus());
});

test('scanner: onProgress fires periodically, onLogs receives each chunk\'s logs once', function () {
    $logs = [transferLog(10, TOKEN_A, SCAN_WALLET, SCAN_WALLET)]; // self transfer: both queries return it
    $progress = 0;
    $seen = [];
    $calls = [];
    $scanner = new EvmLogScanner(
        'ethereum',
        [scannerClient(logHandler($logs, 50), $calls)],
        40000,
        3,
        static function (EvmLogScanState $s) use (&$progress): void {
            $progress++;
        },
        null,
        static function (array $chunkLogs) use (&$seen): void {
            foreach ($chunkLogs as $log) {
                $seen[] = $log['blockNumber'];
            }
        }
    );
    $scanner->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 2000);

    assertTrue($progress >= 3, "progress callbacks: {$progress}");
    assertSame(['0xa'], $seen, 'the self transfer is reported once, not once per direction');
});

test('scanner: state serialisation round-trips, and the step size survives a resume', function () {
    $state = new EvmLogScanState(4999, 77, [strtoupper(TOKEN_A), TOKEN_B], [NFT_N], 123);
    $copy = EvmLogScanState::fromArray(json_decode(json_encode($state->toArray()), true));

    assertSame($state->toArray(), $copy->toArray());
    assertSame(77, $copy->getStepSize());
    assertSame([TOKEN_A, TOKEN_B], $copy->tokens());
    assertThrows(\InvalidArgumentException::class, fn () => $copy->advanceTo(4999), 'the cursor can only move forward');
    assertThrows(\InvalidArgumentException::class, fn () => new EvmLogScanState(0, 0));
    assertSame(-1, EvmLogScanState::startingAt(0)->getLastScannedBlock());
});

test('scanner: constructor rejects no clients and unsupported chains', function () {
    assertThrows(\InvalidArgumentException::class, fn () => new EvmLogScanner('ethereum', []));
    assertThrows(\InvalidArgumentException::class, fn () => new EvmLogScanner('avalanche', [new EvmRpcClient('http://x')]));
});

test('scanner: randomized stress (flaky transients + a size cap + isolated bad blocks) matches ground truth with exact gaps', function () {
    mt_srand(2024);

    for ($run = 0; $run < 6; $run++) {
        $badBlocks = [];

        while (count($badBlocks) < 3) {
            $badBlocks[mt_rand(100, 29000)] = true;
        }

        $badBlocks = array_keys($badBlocks);
        sort($badBlocks);
        $logs = [];
        $truth = [];

        for ($i = 0; $i < 60; $i++) {
            $block = mt_rand(0, 30000);

            if (in_array($block, $badBlocks, true)) {
                continue;
            }

            $token = '0x' . str_pad(dechex(mt_rand(1, 12)), 40, '0', STR_PAD_LEFT);
            $logs[] = transferLog($block, $token, SCAN_OTHER, SCAN_WALLET, 3, $i);
            $truth[$token] = true;
        }

        $truth = array_keys($truth);
        sort($truth);
        $cap = mt_rand(60, 400);
        $flaky = 0;
        $failure = static function (?int $from, ?int $to, ?array $topics) use ($badBlocks, &$flaky): ?array {
            if ($from === null) {
                return null;
            }

            foreach ($badBlocks as $bad) {
                if ($from <= $bad && $to >= $bad) {
                    return ['error' => ['code' => -32000, 'message' => 'Request timeout on the free plan']];
                }
            }

            return (++$flaky % 7 === 0) ? ['error' => ['code' => -32005, 'message' => 'Too many requests']] : null;
        };

        $calls = [];
        $result = (new EvmLogScanner('ethereum', [scannerClient(logHandler($logs, $cap, $failure), $calls, 4)]))
            ->scan(SCAN_WALLET, EvmLogScanState::startingAt(0), 30000);

        assertSame(EvmLogScanResult::STATUS_COMPLETE, $result->getStatus(), "run {$run}");
        assertSame($truth, $result->getState()->tokens(), "run {$run}: tokens");
        assertSame($badBlocks, array_column($result->getGaps(), 'block'), "run {$run}: gaps are exactly the bad blocks");
    }
});
