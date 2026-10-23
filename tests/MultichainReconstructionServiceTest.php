<?php

namespace Tests;

use App\EvmReconstructionConfig;
use App\EvmReconstructionLock;
use App\EvmRpcClient;
use App\MultichainHoldingsReconstructionService;
use App\MultichainReconstructionOutcome as Outcome;

const SVC_WALLET = '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';
const SVC_TOK_A = '0x00000000000000000000000000000000000000a1'; // 6 decimals: 1.0 held from block 18000 to 29999
const SVC_TOK_B = '0x00000000000000000000000000000000000000b2'; // 18 decimals: 5.0 from block 25000, contract created at 24000
const SVC_NOW = 1707566400;                                       // 2024-02-10 12:00:00 UTC

/**
 * The fake Ethereum, 12s blocks, block 0 = 2023-12-29 00:00:00 UTC, so a day is 7200 blocks:
 *   Dec 29 = 0..7199, Dec 30 = 7200..14399, Dec 31 = 14400..21599, Jan 1 = 21600..28799,
 *   Jan 2 = 28800..35999, Jan 3 = 36000..43199.
 * The wallet: native 1.5 ETH from block 16000 (0.5 from 33000); token A in at 18000 and out at 30000; token B in at 25000.
 * Expected end-of-day holdings: Dec 31 {1.5 ETH, A 1}; Jan 1 {1.5 ETH, A 1, B 5}; Jan 2 {0.5 ETH, B 5} (A sold to zero).
 */
function ethereumWorld(): array
{
    $t0 = 1703808000; // 2023-12-29 00:00:00 UTC

    return [
        'blockTime' => 12,
        't0' => $t0,
        'head' => 288000,
        'native' => static fn (int $b): int => $b < 16000 ? 0 : ($b < 33000 ? 1500000000000000000 : 500000000000000000),
        'logs' => [
            transferLog(18000, SVC_TOK_A, SCAN_OTHER, SVC_WALLET, 3, 1),
            transferLog(25000, SVC_TOK_B, SCAN_OTHER, SVC_WALLET, 3, 2),
            transferLog(30000, SVC_TOK_A, SVC_WALLET, SCAN_OTHER, 3, 3),
        ],
        'tokens' => [
            SVC_TOK_A => ['symbol' => 'TKA', 'decimals' => 6, 'from' => 0, 'balance' => static fn (int $b): int => ($b >= 18000 && $b < 30000) ? 1000000 : 0],
            SVC_TOK_B => ['symbol' => 'TKB', 'decimals' => 18, 'from' => 24000, 'balance' => static fn (int $b): string => $b >= 25000 ? '5000000000000000000' : '0'],
        ],
        'cap' => PHP_INT_MAX,
    ];
}

/** Polygon: history start 80,406,107 = 2023-12-30 12:00 UTC, 2s blocks; 41 POL from block start+100, no tokens. */
function polygonWorld(): array
{
    $start = 80406107;

    return [
        'blockTime' => 2,
        't0' => 1703937600 - $start * 2, // so that block $start is at 2023-12-30 12:00:00
        'head' => $start + 600000,
        'native' => static fn (int $b): string => $b < $start + 100 ? '0' : '41000000000000000000',
        'logs' => [],
        'tokens' => [],
        'cap' => PHP_INT_MAX,
    ];
}

function worldHandler(array $world, ?\Closure $override = null): \Closure
{
    $logs = logHandler($world['logs'], $world['cap'], null, $world['head']);

    return static function (string $method, array $params, string $url) use ($world, $logs, $override): array {
        if ($override !== null && ($forced = $override($method, $params, $url)) !== null) {
            return $forced;
        }

        $blockOf = static fn ($param): int => $param === 'latest' ? $world['head'] : (int) hexdec(substr((string) $param, 2));

        switch ($method) {
            case 'eth_chainId':
                return isset($world['chainId']) ? ['result' => '0x' . dechex($world['chainId'])] : ['error' => ['code' => -32601, 'message' => 'method not found']];

            case 'eth_blockNumber':
            case 'eth_getLogs':
                return $logs($method, $params);

            case 'eth_getBlockByNumber':
                $b = $blockOf($params[0]);

                return $b > $world['head'] ? ['result' => null] : ['result' => ['number' => $params[0], 'timestamp' => '0x' . dechex($world['t0'] + $b * $world['blockTime']), 'hash' => '0x1']];

            case 'eth_getBalance':
                // wei amounts can exceed a native int: go through the decimal-string helper
                $hex = ltrim(wordFromDecimal((string) ($world['native'])($blockOf($params[1]))), '0');

                return ['result' => '0x' . ($hex === '' ? '0' : $hex)];

            case 'eth_getCode':
                $address = strtolower($params[0]);

                return ['result' => (isset($world['tokens'][$address]) && $blockOf($params[1]) >= $world['tokens'][$address]['from']) ? FAKE_CODE : '0x'];

            case 'eth_call':
                $to = strtolower($params[0]['to']);
                $selector = substr($params[0]['data'], 0, 10);
                $token = $world['tokens'][$to] ?? null;

                if ($token === null) {
                    return ['error' => ['code' => 3, 'message' => 'execution reverted']];
                }

                return match ($selector) {
                    '0x70a08231' => ['result' => '0x' . wordFromDecimal((string) ($token['balance'])($blockOf($params[1])))],
                    '0x313ce567' => ['result' => '0x' . word($token['decimals'])],
                    '0x95d89b41' => ['result' => abiString($token['symbol'])],
                    default => ['error' => ['code' => 3, 'message' => 'execution reverted']],
                };
        }

        return ['error' => ['code' => -32601, 'message' => 'method not found']];
    };
}

/**
 * Everything a service test needs: the service, its in-memory repositories, and the recorded RPC calls per URL.
 *
 * @param array<string, array> $worlds chain => world
 */
function serviceFixture(array $worlds, ?\Closure $override = null, ?string $lockDir = null, array $providers = []): object
{
    $fx = new \stdClass();
    $fx->calls = [];
    $fx->cache = new MemoryCacheRepository();
    $fx->cursors = new MemoryCursorRepository($fx->cache);
    $fx->blocks = new MemoryBlockRepository();
    $fx->unresolved = new MemoryUnresolvedRepository();
    $fx->lockDir = $lockDir ?? sys_get_temp_dir() . '/wha-test-locks-' . bin2hex(random_bytes(4));

    $rpc = [];
    $byUrl = [];

    foreach ($worlds as $chain => $world) {
        $rpc[$chain] = $providers[$chain] ?? [['name' => $chain === 'binance-smart-chain' ? 'nodereal' : ($chain === 'ethereum' ? 'eth-a' : 'poly-a'), 'url' => "http://{$chain}.fake"]];
        $byUrl += array_fill_keys(array_column($rpc[$chain], 'url'), $world);
    }

    $fx->config = new EvmReconstructionConfig(['reconstruction' => ['rpc' => $rpc, 'lock_directory' => $fx->lockDir]]);

    $factory = static function (string $url) use ($byUrl, $override, $fx): EvmRpcClient {
        $fx->calls[$url] ??= [];
        $transport = fakeTransport(worldHandler($byUrl[$url], $override), $fx->calls[$url]);

        return new EvmRpcClient($url, $transport, 15, 2, 1, static function (int $ms): void {});
    };

    $fx->factory = $factory;

    $fx->make = static fn (?array $chains = null) => new MultichainHoldingsReconstructionService(
        $fx->config,
        $fx->cache,
        $fx->cursors,
        $fx->blocks,
        $fx->unresolved,
        $factory,
        $chains ?? array_keys($worlds),
        static fn (): int => SVC_NOW,
        null,
        0, // no throttling: every scanner progress callback saves a cursor row, so the tests exercise those rows
    );

    return $fx;
}

function rowFor(object $fx, string $date): ?array
{
    $row = $fx->cache->getCacheForDate(SVC_WALLET, $date);

    return $row === null ? null : json_decode($row['responseJson'], true);
}

function logRanges(object $fx): array
{
    $ranges = [];

    foreach ($fx->calls as $calls) {
        foreach (requestedRanges($calls) as $range) {
            $ranges[] = $range;
        }
    }

    return $ranges;
}

test('service: walks from the first activity to the requested day, one reconstructed row per day', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_FOUND, $outcome->getStatus(), (string) $outcome->getMessage());
    // the wallet's first activity is native funding at block 16000 (Dec 31): nothing before
    assertSame(['2023-12-31', '2024-01-01', '2024-01-02', '2024-01-03'], $fx->cache->datesOf('reconstructed'));
    assertSame([], $fx->cursors->violations);

    $dec31 = rowFor($fx, '2023-12-31');
    assertSame(SVC_WALLET === $dec31['address'] || strtolower(SVC_WALLET) === $dec31['address'], true);
    assertSame('2023-12-31', $dec31['as_of']);
    assertSame(false, $dec31['cache']['hit']);
    assertSame(['symbol' => 'ETH', 'amount' => '1.5'], $dec31['holdings']['ethereum']['native']);
    assertSame([['symbol' => 'TKA', 'contract' => SVC_TOK_A, 'amount' => '1']], $dec31['holdings']['ethereum']['tokens'], 'B is discovered in the same chunk but its contract does not exist yet: a verified zero');
    assertSame(['compound' => [], 'aave' => null, 'other' => []], $dec31['holdings']['ethereum']['defi']);

    assertSame(
        [
            ['symbol' => 'TKA', 'contract' => SVC_TOK_A, 'amount' => '1'],
            ['symbol' => 'TKB', 'contract' => SVC_TOK_B, 'amount' => '5'],
        ],
        rowFor($fx, '2024-01-01')['holdings']['ethereum']['tokens']
    );

    $jan2 = rowFor($fx, '2024-01-02')['holdings']['ethereum'];
    assertSame(['symbol' => 'ETH', 'amount' => '0.5'], $jan2['native']);
    assertSame([['symbol' => 'TKB', 'contract' => SVC_TOK_B, 'amount' => '5']], $jan2['tokens'], 'a token sold to zero disappears, like the live cache');

    assertSame(json_encode(rowFor($fx, '2024-01-03')), json_encode(json_decode($outcome->getResponseJson(), true)));
});

test('service: unsupported chains are present, empty, and flagged in every reconstructed row', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    ($fx->make)()->reconstruct(SVC_WALLET, '2023-12-31');

    $holdings = rowFor($fx, '2023-12-31')['holdings'];

    foreach (['avalanche', 'xdai'] as $key) {
        assertSame(['native' => null, 'tokens' => [], 'defi' => ['compound' => [], 'aave' => null, 'other' => []], 'reconstruction_supported' => false], $holdings[$key]);
    }

    assertFalse(isset($holdings['ethereum']['reconstruction_supported']), 'supported chains carry no extra key');
});

test('service: the cursor holds first activity, scan state, tokens and the completed date; it never runs ahead of its rows', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-02');

    $cursor = $fx->cursors->find(SVC_WALLET, 'ethereum');

    assertSame('2024-01-02', $cursor['cursorDate']);
    assertSame(16000, $cursor['firstActivityBlock'], 'min(native genesis 16000, first log 18000)');
    assertSame([SVC_TOK_A, SVC_TOK_B], $cursor['state']->tokens());
    assertTrue($cursor['state']->getLastScannedBlock() >= 35999);
    assertSame([], $fx->cursors->violations);
});

test('service: a second request for a later date resumes from the cursor (no re-scan, no Phase 0 again)', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-01');
    $scannedThrough = $fx->cursors->find(SVC_WALLET, 'ethereum')['state']->getLastScannedBlock();
    $before = count($fx->cache->rows);
    $fx->calls = [];

    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertTrue($outcome->isFound());
    assertSame($before + 2, count($fx->cache->rows), 'only the two new days get rows');
    assertSame(['2023-12-31', '2024-01-01', '2024-01-02', '2024-01-03'], $fx->cache->datesOf('reconstructed'));

    foreach (logRanges($fx) as [$from, $to]) {
        assertTrue($from > $scannedThrough, "log request {$from}-{$to} re-scans what the cursor already covers (through {$scannedThrough})");
    }
});

test('service: a date already walked is a pure read (no log scan, no snapshot call)', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-02');
    $rows = count($fx->cache->rows);
    $fx->calls = [];

    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-01');

    assertTrue($outcome->isFound());
    assertSame($rows, count($fx->cache->rows));
    assertSame([], logRanges($fx));

    foreach ($fx->calls as $calls) {
        foreach ($calls as $call) {
            assertTrue(! in_array($call['method'], ['eth_getLogs', 'eth_call', 'eth_getCode', 'eth_getBalance'], true), $call['method']);
        }
    }
});

test('service: interrupted by a provider failure, then resumed: no hole, no duplicate row, cursor never ahead', function () {
    $failing = true;
    $override = static function (string $method, array $params, string $url) use (&$failing): ?array {
        // Fail every balance read at the end of Jan 2 (block 35999): the day cannot be snapshotted.
        if ($failing && $method === 'eth_getBalance' && (int) hexdec(substr($params[1], 2)) === 35999) {
            return ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']];
        }

        return null;
    };
    $fx = serviceFixture(['ethereum' => ethereumWorld()], $override);

    $first = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_PROVIDER_ERROR, $first->getStatus());
    assertSame('2024-01-01', $first->getWalkedThrough());
    assertSame(['2023-12-31', '2024-01-01'], $fx->cache->datesOf('reconstructed'), 'nothing is written for the failed day');
    assertSame('2024-01-01', $fx->cursors->find(SVC_WALLET, 'ethereum')['cursorDate']);

    $failing = false;
    $scannedThrough = $fx->cursors->find(SVC_WALLET, 'ethereum')['state']->getLastScannedBlock();
    $fx->calls = [];
    $second = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_FOUND, $second->getStatus(), (string) $second->getMessage());
    assertSame(['2023-12-31', '2024-01-01', '2024-01-02', '2024-01-03'], $fx->cache->datesOf('reconstructed'), 'each day has exactly one row');
    assertSame([], $fx->cursors->violations);

    foreach (logRanges($fx) as [$from, $to]) {
        assertTrue($from > $scannedThrough, 'the resumed walk does not re-scan covered blocks');
    }

    // and the result equals an uninterrupted walk
    $clean = serviceFixture(['ethereum' => ethereumWorld()]);
    ($clean->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    foreach (['2023-12-31', '2024-01-01', '2024-01-02', '2024-01-03'] as $day) {
        $a = rowFor($fx, $day);
        $b = rowFor($clean, $day);
        unset($a['cache'], $b['cache']);
        assertSame($b, $a, $day);
    }
});

test('service: a crash between a day\'s row and its cursor advance is harmless on resume', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    $armed = true;
    // The process dies when the cursor for 2024-01-01 is about to be saved: that day's row is already written.
    $fx->cursors->beforeSave = static function (string $chain, ?string $cursorDate) use (&$armed): void {
        if ($armed && $cursorDate === '2024-01-01') {
            throw new \RuntimeException('simulated crash');
        }
    };

    $first = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_STORAGE_ERROR, $first->getStatus());
    assertSame(['2023-12-31', '2024-01-01'], $fx->cache->datesOf('reconstructed'));
    assertSame('2023-12-31', $fx->cursors->find(SVC_WALLET, 'ethereum')['cursorDate'], 'the cursor stayed behind the row');

    $armed = false;
    $second = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_FOUND, $second->getStatus(), (string) $second->getMessage());
    assertSame(['2023-12-31', '2024-01-01', '2024-01-02', '2024-01-03'], $fx->cache->datesOf('reconstructed'), 'Jan 1 is NOT written twice');
    assertSame([SVC_TOK_A, SVC_TOK_B], $fx->cursors->find(SVC_WALLET, 'ethereum')['state']->tokens());
    assertSame([], $fx->cursors->violations);
});

test('service: a day that already has a LIVE row is never overwritten, but the cursor moves past it and scanning continues', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    $live = json_encode(['address' => SVC_WALLET, 'as_of' => '2024-01-01', 'cache' => ['hit' => false, 'fetched_at' => '2024-01-01 09:00:00'], 'holdings' => ['ethereum' => ['native' => ['symbol' => 'ETH', 'amount' => '9.99'], 'tokens' => [], 'defi' => []]]]);
    $fx->cache->store(SVC_WALLET, $live, '2024-01-01', 'live');

    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-02');

    assertTrue($outcome->isFound());
    assertSame(['2023-12-31', '2024-01-02'], $fx->cache->datesOf('reconstructed'), 'no reconstructed row for the live day');
    assertSame($live, $fx->cache->getCacheForDate(SVC_WALLET, '2024-01-01')['responseJson'], 'the live row still wins');
    assertSame('2024-01-02', $fx->cursors->find(SVC_WALLET, 'ethereum')['cursorDate']);
    // the logs of the live day were still scanned: token B (received that day) shows up the next day
    assertSame([['symbol' => 'TKB', 'contract' => SVC_TOK_B, 'amount' => '5']], rowFor($fx, '2024-01-02')['holdings']['ethereum']['tokens']);
    assertSame([], $fx->cursors->violations);

    // requesting the live day itself serves the live row as-is
    $liveOutcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-01');
    assertSame($live, $liveOutcome->getResponseJson());
});

test('service: before genesis (no supported chain has any activity by the date) is BEFORE_GENESIS, and nothing is written', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2023-12-30');

    assertSame(Outcome::STATUS_BEFORE_GENESIS, $outcome->getStatus());
    assertSame([], $fx->cache->rows);

    // a day with activity right after it works, from the very same cursor state
    assertTrue(($fx->make)()->reconstruct(SVC_WALLET, '2023-12-31')->isFound());
});

test('service: a wallet with no activity at all is BEFORE_GENESIS', function () {
    // (the fake chain funds every address, so the world itself must hold nothing)
    $empty = ethereumWorld();
    $empty['native'] = static fn (int $b): int => 0;
    $empty['logs'] = [];
    $fx2 = serviceFixture(['ethereum' => $empty]);
    $outcome = ($fx2->make)()->reconstruct(SVC_WALLET, '2024-01-05');

    assertSame(Outcome::STATUS_BEFORE_GENESIS, $outcome->getStatus());
});

test('service: several chains in lockstep; a chain before its history start or first activity is simply empty', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld(), 'polygon' => polygonWorld()]);

    // Polygon is funded from its history start (2023-12-30 12:00): by Dec 30 only Polygon has activity.
    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2023-12-30');

    assertSame(Outcome::STATUS_FOUND, $outcome->getStatus(), (string) $outcome->getMessage());
    $holdings = json_decode($outcome->getResponseJson(), true)['holdings'];
    assertSame(['symbol' => 'POL', 'amount' => '41'], $holdings['polygon']['native']);
    assertFalse(isset($holdings['ethereum']), 'Ethereum has nothing yet: omitted like the live cache omits empty chains');
    assertTrue(isset($holdings['avalanche']));

    // Dec 29 is before Polygon's history start and before any Ethereum activity: nothing at all
    $fx2 = serviceFixture(['ethereum' => ethereumWorld(), 'polygon' => polygonWorld()]);
    assertSame(Outcome::STATUS_BEFORE_GENESIS, ($fx2->make)()->reconstruct(SVC_WALLET, '2023-12-29')->getStatus());

    // Both chains active: a later day carries both, and the cursors of both advanced together
    $both = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-02');
    $bothHoldings = json_decode($both->getResponseJson(), true)['holdings'];
    assertTrue(isset($bothHoldings['ethereum'], $bothHoldings['polygon']));
    assertSame('2024-01-02', $fx->cursors->find(SVC_WALLET, 'ethereum')['cursorDate']);
    assertSame('2024-01-02', $fx->cursors->find(SVC_WALLET, 'polygon')['cursorDate']);
    assertSame([], $fx->cursors->violations);
});

test('service: cursors that say a date is walked but no row exists is INCONSISTENT (500)', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');
    $fx->cache->rows = array_values(array_filter($fx->cache->rows, static fn (array $r): bool => $r['date'] !== '2024-01-03'));
    $fx->cursors->violations = [];

    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_INCONSISTENT, $outcome->getStatus());
});

test('service: a second request while one is running gets BUSY, and works once the lock is free', function () {
    $fx = null;
    $nested = null;

    // While the first walk is talking to the provider, a second request for another wallet comes in.
    $override = static function (string $method) use (&$nested, &$fx): ?array {
        static $fired = false;

        if (! $fired && $method === 'eth_blockNumber') {
            $fired = true;
            $nested = ($fx->make)()->reconstruct('0x3333333333333333333333333333333333333333', '2024-01-03');
        }

        return null;
    };
    $fx = serviceFixture(['ethereum' => ethereumWorld()], $override);
    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertTrue($outcome->isFound(), (string) $outcome->getMessage());
    assertSame(Outcome::STATUS_BUSY, $nested->getStatus());
    assertSame(MultichainHoldingsReconstructionService::BUSY_RETRY_AFTER_SECONDS, $nested->getRetryAfterSeconds());

    // lock released after the first request: a new one proceeds
    $again = ($fx->make)()->reconstruct('0x3333333333333333333333333333333333333333', '2024-01-03');
    assertTrue($again->isFound(), (string) $again->getMessage());
});

test('service: BUSY when the lock is held by someone else, and every lock is released afterwards, also on failure', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld(), 'polygon' => polygonWorld()]);
    $other = new EvmReconstructionLock($fx->lockDir);
    assertTrue($other->acquire('polygon', 'poly-a'));

    $busy = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');
    assertSame(Outcome::STATUS_BUSY, $busy->getStatus());
    assertSame([], $fx->cache->rows);

    // the ethereum lock this attempt took before failing on polygon must have been released
    $probe = new EvmReconstructionLock($fx->lockDir);
    assertTrue($probe->acquire('ethereum', 'eth-a'), 'a BUSY attempt must not leak the locks it took');
    $probe->releaseAll();

    $other->releaseAll();
    assertTrue(($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03')->isFound());

    // released after a failed walk too
    $broken = serviceFixture(['ethereum' => ethereumWorld()], static fn (string $m): ?array => $m === 'eth_blockNumber' ? ['raw' => '', 'status' => 502] : null);
    assertSame(Outcome::STATUS_PROVIDER_ERROR, ($broken->make)()->reconstruct(SVC_WALLET, '2024-01-03')->getStatus());
    $probe2 = new EvmReconstructionLock($broken->lockDir);
    assertTrue($probe2->acquire('ethereum', 'eth-a'));
    $probe2->releaseAll();
});

test('service: a provider that cannot resolve the end block fails the walk with PROVIDER_ERROR, nothing written', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()], static fn (string $m): ?array => $m === 'eth_getBlockByNumber' ? ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']] : null);
    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_PROVIDER_ERROR, $outcome->getStatus());
    assertSame([], $fx->cache->rows);
    assertSame([], $fx->cursors->rows);
});

test('service: a chain with no configured provider fails the whole walk loudly (never a silently skipped chain)', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    $service = new MultichainHoldingsReconstructionService(
        $fx->config, $fx->cache, $fx->cursors, $fx->blocks, $fx->unresolved, null, ['ethereum', 'binance-smart-chain'], static fn (): int => SVC_NOW
    );
    $outcome = $service->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_PROVIDER_ERROR, $outcome->getStatus());
    assertTrue(str_contains($outcome->getMessage(), 'binance-smart-chain'));
});

test('service: provider failover — a dead first provider does not stop the walk', function () {
    $override = static fn (string $m, array $p, string $url): ?array => $url === 'http://dead.fake' ? ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']] : null;
    $fx = serviceFixture(['ethereum' => ethereumWorld()], $override);

    // Two providers for Ethereum: the first one is dead (auth), the second one works.
    $config = new EvmReconstructionConfig(['reconstruction' => ['rpc' => ['ethereum' => [['name' => 'dead', 'url' => 'http://dead.fake'], ['name' => 'eth-b', 'url' => 'http://eth-b.fake']]], 'lock_directory' => $fx->lockDir]]);
    $world = ethereumWorld();
    $factory = static function (string $url) use ($world, $override, $fx): EvmRpcClient {
        $fx->calls[$url] ??= [];

        return new EvmRpcClient($url, fakeTransport(worldHandler($world, $override), $fx->calls[$url]), 15, 1, 1, static function (int $ms): void {});
    };
    $service = new MultichainHoldingsReconstructionService($config, $fx->cache, $fx->cursors, $fx->blocks, $fx->unresolved, $factory, ['ethereum'], static fn (): int => SVC_NOW);
    $outcome = $service->reconstruct(SVC_WALLET, '2024-01-02');

    assertSame(Outcome::STATUS_FOUND, $outcome->getStatus(), (string) $outcome->getMessage());
    assertSame(['2023-12-31', '2024-01-01', '2024-01-02'], $fx->cache->datesOf('reconstructed'));
});

test('service: Phase 0 never saves a progress row past the first log while the first activity is unknown', function () {
    $world = ethereumWorld();
    $world['cap'] = 300; // many small chunks, so progress callbacks fire during Phase 0
    $world['native'] = static fn (int $b): int => 0; // activity comes from the log at 18000 only
    $fx = serviceFixture(['ethereum' => $world]);
    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-01');

    assertTrue($outcome->isFound(), (string) $outcome->getMessage());

    foreach ($fx->cursors->rows as $row) {
        if ($row['firstActivityBlock'] === null) {
            assertTrue($row['state']['last_scanned_block'] < 18000, 'a progress row past the first log with no first activity would lose it on a crash');
        }
    }

    assertSame(18000, $fx->cursors->find(SVC_WALLET, 'ethereum')['firstActivityBlock']);
});

test('service: skipped blocks (Base) are never requested during a walk, and unresolved blocks are recorded', function () {
    $world = ethereumWorld();
    $world['t0'] = 1703808000; // unused for Base here: the world is addressed as a chain by name below
    // A Base world whose blocks 44661819-44661820 would fail if ever requested: they must simply not be asked for.
    $start = 44661700;
    $base = [
        'blockTime' => 2, 't0' => 1703808000 - $start * 2 + 3600, 'head' => $start + 200000,
        'native' => static fn (int $b): int => $b < $start + 10 ? 0 : 1000000000000000000,
        'logs' => [], 'tokens' => [], 'cap' => PHP_INT_MAX,
    ];
    $fx = serviceFixture(['base' => $base], static function (string $method, array $params): ?array {
        if ($method === 'eth_getLogs') {
            $from = (int) hexdec(substr($params[0]['fromBlock'], 2));
            $to = (int) hexdec(substr($params[0]['toBlock'], 2));

            if ($to >= 44661819 && $from <= 44661820) {
                return ['error' => ['code' => -32000, 'message' => 'Request timeout on the free plan']];
            }
        }

        return null;
    }, null, ['base' => [['name' => 'base-a', 'url' => 'http://base.fake']]]);

    // Base's history starts at block 0 in the constants, so Phase 0 scans from 0: the fake has no logs there.
    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2023-12-30');

    assertTrue($outcome->isFound() || $outcome->getStatus() === Outcome::STATUS_BEFORE_GENESIS, $outcome->getStatus() . ' ' . $outcome->getMessage());
    assertSame([], $fx->unresolved->recorded, 'skipped blocks are never requested, so none is discovered as unresolved');

    foreach (logRanges($fx) as [$from, $to]) {
        assertTrue($to < 44661819 || $from > 44661820, "range {$from}-{$to} touches a skipped block");
    }
});

test('service: invalid input is rejected before anything runs', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    $service = ($fx->make)();

    assertThrows(\InvalidArgumentException::class, fn () => $service->reconstruct('0x123', '2024-01-03'));
    assertThrows(\InvalidArgumentException::class, fn () => $service->reconstruct(SVC_WALLET, '2024-1-3'));
    assertThrows(\InvalidArgumentException::class, fn () => $service->reconstruct(SVC_WALLET, '2024-02-10'), 'today goes through /holdings-now');
    assertThrows(\InvalidArgumentException::class, fn () => $service->reconstruct(SVC_WALLET, '2024-03-01'), 'a future date');
    assertSame([], $fx->calls);
});

test('service: native genesis search finds the exact block (coarse samples, then bisection), including a funding at the very first sample', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    ($fx->make)()->reconstruct(SVC_WALLET, '2023-12-31');
    assertSame(16000, $fx->cursors->find(SVC_WALLET, 'ethereum')['firstActivityBlock']);

    // funded from the first sample (block 0) on a chain whose history starts at 0
    $world = ethereumWorld();
    $world['native'] = static fn (int $b): int => 1000000000000000000;
    $world['logs'] = [];
    $fx2 = serviceFixture(['ethereum' => $world]);
    ($fx2->make)()->reconstruct(SVC_WALLET, '2023-12-30');
    assertSame(0, $fx2->cursors->find(SVC_WALLET, 'ethereum')['firstActivityBlock']);
    assertSame(['2023-12-29', '2023-12-30'], $fx2->cache->datesOf('reconstructed'));
});

test('service: mid-scan progress rows are throttled by time, not written every few chunks', function () {
    $world = ethereumWorld();
    $world['cap'] = 101; // the keyless-provider case: hundreds of tiny chunks

    $unthrottled = serviceFixture(['ethereum' => $world]);
    ($unthrottled->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    $fx = serviceFixture(['ethereum' => $world]);
    $service = new MultichainHoldingsReconstructionService(
        $fx->config, $fx->cache, $fx->cursors, $fx->blocks, $fx->unresolved, $fx->factory, ['ethereum'], static fn (): int => SVC_NOW, null, 3600
    );
    $outcome = $service->reconstruct(SVC_WALLET, '2024-01-03');

    assertTrue($outcome->isFound(), (string) $outcome->getMessage());
    assertTrue(count($unthrottled->cursors->rows) > 15, 'sanity: without throttling there are many progress rows (' . count($unthrottled->cursors->rows) . ')');
    // at most one progress row per phase, plus first activity, plus one per day (4 days)
    assertTrue(count($fx->cursors->rows) <= 8, 'with a one-hour interval only a handful of rows remain, got ' . count($fx->cursors->rows));

    // and the result is identical: throttling only affects how often progress is saved
    foreach (['2023-12-31', '2024-01-01', '2024-01-02', '2024-01-03'] as $day) {
        $a = rowFor($fx, $day);
        $b = rowFor($unthrottled, $day);
        unset($a['cache'], $b['cache']);
        assertSame($b, $a, $day);
    }
});

test('service: the logger is told what a long walk is doing', function () {
    $fx = serviceFixture(['ethereum' => ethereumWorld()]);
    $messages = [];
    $service = new MultichainHoldingsReconstructionService(
        $fx->config, $fx->cache, $fx->cursors, $fx->blocks, $fx->unresolved, $fx->factory, ['ethereum'], static fn (): int => SVC_NOW, null, 0,
        static function (string $m) use (&$messages): void {
            $messages[] = $m;
        }
    );
    $service->reconstruct(SVC_WALLET, '2023-12-31');

    assertTrue(count(array_filter($messages, static fn (string $m): bool => str_contains($m, 'first activity at block 16000'))) === 1, implode(' | ', $messages));
    assertTrue(count(array_filter($messages, static fn (string $m): bool => str_contains($m, '2023-12-31: row written'))) === 1);
});

test('service: a provider that serves ANOTHER chain is refused before anything is read or cached (a BSC URL under "base")', function () {
    $wrong = ethereumWorld();
    $wrong['chainId'] = 56; // a BNB Smart Chain endpoint configured for Ethereum

    $fx = serviceFixture(['ethereum' => $wrong]);
    $outcome = ($fx->make)()->reconstruct(SVC_WALLET, '2024-01-03');

    assertSame(Outcome::STATUS_PROVIDER_ERROR, $outcome->getStatus());
    assertTrue(str_contains($outcome->getMessage(), 'chain id 56') && str_contains($outcome->getMessage(), 'chain id 1'), $outcome->getMessage());
    assertSame([], $fx->blocks->rows, 'no end block was resolved, so none was cached for the wrong chain');
    assertSame([], $fx->cursors->rows);
    assertSame([], $fx->cache->rows);

    // the right chain id passes
    $right = ethereumWorld();
    $right['chainId'] = 1;
    $ok = serviceFixture(['ethereum' => $right]);
    assertTrue(($ok->make)()->reconstruct(SVC_WALLET, '2024-01-01')->isFound());
});

test('service: only ONE wrong provider among several is enough to refuse the walk', function () {
    $good = ethereumWorld();
    $good['chainId'] = 1;
    $fx = serviceFixture(['ethereum' => $good]);

    $config = new EvmReconstructionConfig(['reconstruction' => ['rpc' => ['ethereum' => [['name' => 'eth-a', 'url' => 'http://ok.fake'], ['name' => 'bsc-oops', 'url' => 'http://wrong.fake']]], 'lock_directory' => $fx->lockDir]]);
    $wrongWorld = ethereumWorld();
    $wrongWorld['chainId'] = 56;
    $factory = static function (string $url) use ($good, $wrongWorld, $fx): EvmRpcClient {
        $fx->calls[$url] ??= [];

        return new EvmRpcClient($url, fakeTransport(worldHandler($url === 'http://wrong.fake' ? $wrongWorld : $good), $fx->calls[$url]), 15, 0, 1, static function (int $ms): void {});
    };
    $service = new MultichainHoldingsReconstructionService($config, $fx->cache, $fx->cursors, $fx->blocks, $fx->unresolved, $factory, ['ethereum'], static fn (): int => SVC_NOW);
    $outcome = $service->reconstruct(SVC_WALLET, '2024-01-02');

    assertSame(Outcome::STATUS_PROVIDER_ERROR, $outcome->getStatus());
    assertTrue(str_contains($outcome->getMessage(), 'bsc-oops'), $outcome->getMessage());
    assertSame([], $fx->cache->rows);
});
