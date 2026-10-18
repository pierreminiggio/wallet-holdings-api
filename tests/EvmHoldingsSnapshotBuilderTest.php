<?php

namespace Tests;

use App\EvmHoldingsSnapshotBuilder;
use App\EvmRpcClient;

const TK_A = '0x0000000000000000000000000000000000000a01';      // 6 decimals, balance 0.132953 before block 150, then 0
const TK_B = '0x0000000000000000000000000000000000000a02';      // 18 decimals, created at block 150, balance 5 after
const TK_SPAM = '0x0000000000000000000000000000000000000a03';   // balanceOf reverts
const TK_NOFN = '0x0000000000000000000000000000000000000a04';   // has code, balanceOf returns "0x"
const TK_MKR = '0x0000000000000000000000000000000000000a05';    // symbol() is a bytes32, balance 2
const TK_BADDEC = '0x0000000000000000000000000000000000000a06'; // balance > 0 but decimals() reverts

const POLYGON_COMETS = ['0xf25212e676d1f7f89cd72ffee66158f541246445', '0xaeb318360f27748acb200ce616e389a6c9409a07'];

/**
 * A fake Polygon: wallet native balance 1.5 POL before block 150 (0 after), six token contracts with
 * different behaviours, both Compound markets not deployed, and the Aave fake of AaveHistoricalTest.
 * $overrides: fn(string $method, array $params): ?array, tried first.
 */
function snapshotHandler(?\Closure $override = null): \Closure
{
    $aave = aaveHandler();

    return static function (string $method, array $params) use ($aave, $override): array {
        if ($override !== null && ($forced = $override($method, $params)) !== null) {
            return $forced;
        }

        $blockParam = $params[1] ?? null;
        $block = $blockParam === 'latest' ? 100 : (int) hexdec(substr((string) $blockParam, 2));

        if ($method === 'eth_getBalance') {
            return ['result' => '0x' . dechex($block < 150 ? 1500000000000000000 : 0)];
        }

        if ($method === 'eth_getCode') {
            $address = strtolower($params[0]);

            if (in_array($address, POLYGON_COMETS, true)) {
                return ['result' => '0x'];
            }

            if ($address === TK_B) {
                return ['result' => $block >= 150 ? FAKE_CODE : '0x'];
            }

            if (in_array($address, [TK_A, TK_SPAM, TK_NOFN, TK_MKR, TK_BADDEC], true)) {
                return ['result' => FAKE_CODE];
            }

            // anything else (the Aave Pool and its tokens): the Aave fake decides
        }

        if ($method === 'eth_call') {
            $to = strtolower($params[0]['to']);
            $selector = substr($params[0]['data'], 0, 10);

            switch ($to) {
                case TK_A:
                    return match ($selector) {
                        '0x70a08231' => ['result' => '0x' . word($block < 150 ? 132953 : 0)],
                        '0x313ce567' => ['result' => '0x' . word(6)],
                        '0x95d89b41' => ['result' => abiString('TKA')],
                    };
                case TK_B:
                    return match ($selector) {
                        '0x70a08231' => ['result' => '0x' . wordFromDecimal('5000000000000000000')],
                        '0x313ce567' => ['result' => '0x' . word(18)],
                        '0x95d89b41' => ['result' => abiString('NEW')],
                    };
                case TK_SPAM:
                    return ['error' => ['code' => 3, 'message' => 'execution reverted']];
                case TK_NOFN:
                    return ['result' => '0x'];
                case TK_MKR:
                    return match ($selector) {
                        '0x70a08231' => ['result' => '0x' . wordFromDecimal('2000000000000000000')],
                        '0x313ce567' => ['result' => '0x' . word(18)],
                        '0x95d89b41' => ['result' => '0x' . str_pad(bin2hex('MKR'), 64, '0')],
                    };
                case TK_BADDEC:
                    return match ($selector) {
                        '0x70a08231' => ['result' => '0x' . word(1)],
                        default => ['error' => ['code' => -32000, 'message' => 'execution reverted']],
                    };
            }
        }

        return $aave($method, $params);
    };
}

function builderFor(\Closure $handler, array &$calls, int $retries = 0): EvmHoldingsSnapshotBuilder
{
    $client = new EvmRpcClient('http://fake-polygon', fakeTransport($handler, $calls), 15, $retries, 1, static function (int $ms): void {});

    return new EvmHoldingsSnapshotBuilder(['polygon' => $client]);
}

test('snapshot: same per-chain shape as /holdings-now (native, tokens, defi.compound/aave/other)', function () {
    $calls = [];
    $result = builderFor(snapshotHandler(), $calls)->snapshot(AAVE_WALLET, 'polygon', 100, [TK_A, TK_MKR]);

    assertTrue($result->isOk());
    $holdings = $result->getHoldings();

    assertSame(['native', 'tokens', 'defi'], array_keys($holdings));
    assertSame(['compound', 'aave', 'other'], array_keys($holdings['defi']));
    assertSame(['symbol' => 'POL', 'amount' => '1.5'], $holdings['native']);
    assertSame(
        [
            ['symbol' => 'TKA', 'contract' => TK_A, 'amount' => '0.132953'],
            ['symbol' => 'MKR', 'contract' => TK_MKR, 'amount' => '2'],
        ],
        $holdings['tokens']
    );
    assertSame([], $holdings['defi']['compound']);
    assertSame([], $holdings['defi']['other']);
    assertSame(['reserves', 'summary'], array_keys($holdings['defi']['aave']));
    assertSame('73.179844', $holdings['defi']['aave']['reserves'][1]['variableDebt']);
    assertSame('1.669568345605074184', $holdings['defi']['aave']['summary']['healthFactor']);
    assertFalse($result->isEmpty());
});

test('snapshot: a token held only in a past window is present then, and gone later (balanceOf, never deltas)', function () {
    $calls = [];
    $builder = builderFor(snapshotHandler(), $calls);

    $before = $builder->snapshot(AAVE_WALLET, 'polygon', 100, [TK_A]);
    $after = $builder->snapshot(AAVE_WALLET, 'polygon', 200, [TK_A]);

    assertSame('0.132953', $before->getHoldings()['tokens'][0]['amount']);
    assertSame([], $after->getHoldings()['tokens'], 'a zero balance is omitted, like the live cache');
    assertSame(null, $after->getHoldings()['native'], 'a zero native balance is null');
});

test('snapshot: every token read is a balanceOf pinned to the snapshot block', function () {
    $calls = [];
    builderFor(snapshotHandler(), $calls)->snapshot(AAVE_WALLET, 'polygon', 100, [TK_A]);

    $tokenCalls = array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_call' && strtolower($c['params'][0]['to']) === TK_A);
    $selectors = array_map(static fn (array $c): string => substr($c['params'][0]['data'], 0, 10), $tokenCalls);

    assertTrue(in_array('0x70a08231', $selectors, true));

    foreach ($tokenCalls as $call) {
        assertSame('0x64', $call['params'][1]);
    }

    foreach ($calls as $call) {
        assertTrue($call['method'] !== 'eth_getLogs', 'the builder never reads logs');
    }
});

test('snapshot: a token not created yet is a verified zero, with no balanceOf sent to it', function () {
    $calls = [];
    $result = builderFor(snapshotHandler(), $calls)->snapshot(AAVE_WALLET, 'polygon', 100, [TK_B]);

    assertSame([], $result->getHoldings()['tokens']);

    foreach ($calls as $call) {
        assertTrue($call['method'] !== 'eth_call' || strtolower($call['params'][0]['to']) !== TK_B);
    }

    $later = builderFor(snapshotHandler(), $calls)->snapshot(AAVE_WALLET, 'polygon', 200, [TK_B]);
    assertSame([['symbol' => 'NEW', 'contract' => TK_B, 'amount' => '5']], $later->getHoldings()['tokens']);
});

test('snapshot: spam/broken contracts are skipped and reported, not an outage and not retried', function () {
    $calls = [];
    $result = builderFor(snapshotHandler(), $calls, 5)->snapshot(AAVE_WALLET, 'polygon', 100, [TK_A, TK_SPAM, TK_NOFN, TK_BADDEC]);

    assertTrue($result->isOk());
    assertSame(['TKA'], array_column($result->getHoldings()['tokens'], 'symbol'));

    $skipped = array_column($result->getSkippedTokens(), 'contract');
    sort($skipped);
    assertSame([TK_SPAM, TK_NOFN, TK_BADDEC], $skipped);

    $spamCalls = array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_call' && strtolower($c['params'][0]['to']) === TK_SPAM);
    assertSame(1, count($spamCalls), 'a revert is the contract\'s answer: asked once');
});

test('snapshot: an empty balanceOf answer from a contract with NO code at that block fails the snapshot (never a silent zero)', function () {
    $handler = snapshotHandler(static function (string $method, array $params): ?array {
        // The memo says deployed (first getCode), but the confirming getCode at the snapshot block says "0x".
        static $codeCalls = 0;

        if ($method === 'eth_getCode' && strtolower($params[0]) === TK_NOFN) {
            return ['result' => ++$codeCalls === 1 ? FAKE_CODE : '0x'];
        }

        return null;
    });
    $calls = [];
    $result = builderFor($handler, $calls)->snapshot(AAVE_WALLET, 'polygon', 100, [TK_NOFN]);

    assertFalse($result->isOk());
    assertSame(null, $result->getHoldings(), 'no partial snapshot');
    assertTrue(str_contains($result->getError(), 'could not confirm'));
});

test('snapshot: a provider failure on any token read fails the WHOLE snapshot (no partial result)', function () {
    $handler = snapshotHandler(static function (string $method, array $params): ?array {
        return ($method === 'eth_call' && strtolower($params[0]['to']) === TK_MKR)
            ? ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']]
            : null;
    });
    $calls = [];
    $result = builderFor($handler, $calls)->snapshot(AAVE_WALLET, 'polygon', 100, [TK_A, TK_MKR]);

    assertFalse($result->isOk());
    assertSame(null, $result->getHoldings());
    assertTrue(str_contains($result->getError(), TK_MKR));
});

test('snapshot: a failing native read, Compound read or Aave read each fail the snapshot', function () {
    $broken = [
        'native' => static fn (string $m, array $p): ?array => $m === 'eth_getBalance' ? ['raw' => '', 'status' => 502] : null,
        'compound' => static fn (string $m, array $p): ?array => ($m === 'eth_getCode' && in_array(strtolower($p[0]), POLYGON_COMETS, true)) ? ['raw' => '', 'status' => 502] : null,
        'aave' => static fn (string $m, array $p): ?array => ($m === 'eth_call' && substr($p[0]['data'], 0, 10) === '0xbf92857c') ? ['error' => ['code' => -32000, 'message' => 'Unauthorized']] : null,
    ];

    foreach ($broken as $name => $override) {
        $calls = [];
        $result = builderFor(snapshotHandler($override), $calls)->snapshot(AAVE_WALLET, 'polygon', 100, [TK_A]);

        assertFalse($result->isOk(), $name);
        assertSame(null, $result->getHoldings(), $name);
    }
});

test('snapshot: nothing anywhere is isEmpty() (the live cache omits such chains)', function () {
    $calls = [];
    // block 5: Pool not deployed, native balance 1.5 in the fake, so use a handler with zero native
    $handler = snapshotHandler(static fn (string $m, array $p): ?array => $m === 'eth_getBalance' ? ['result' => '0x0'] : null);
    $result = builderFor($handler, $calls)->snapshot(AAVE_WALLET, 'polygon', 5, [TK_B]);

    assertTrue($result->isOk());
    assertTrue($result->isEmpty());
    assertSame(['native' => null, 'tokens' => [], 'defi' => ['compound' => [], 'aave' => null, 'other' => []]], $result->getHoldings());
});

test('snapshot: token metadata is read once across the whole walk', function () {
    $calls = [];
    $builder = builderFor(snapshotHandler(), $calls);

    foreach ([60, 80, 100, 120] as $block) {
        $builder->snapshot(AAVE_WALLET, 'polygon', $block, [TK_A]);
    }

    $decimals = array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_call' && strtolower($c['params'][0]['to']) === TK_A && substr($c['params'][0]['data'], 0, 10) === '0x313ce567');
    assertSame(1, count($decimals));
});

test('snapshot: tokens are de-duplicated and ordered deterministically (case-insensitive)', function () {
    $calls = [];
    $result = builderFor(snapshotHandler(), $calls)->snapshot(AAVE_WALLET, 'polygon', 100, [TK_MKR, strtoupper(TK_A), TK_A]);

    assertSame([TK_A, TK_MKR], array_column($result->getHoldings()['tokens'], 'contract'));
});

test('snapshot: unsupported chains, missing clients and bad addresses are programming errors', function () {
    $calls = [];
    $builder = builderFor(snapshotHandler(), $calls);

    assertThrows(\InvalidArgumentException::class, fn () => $builder->snapshot(AAVE_WALLET, 'avalanche', 1, []));
    assertThrows(\InvalidArgumentException::class, fn () => $builder->snapshot(AAVE_WALLET, 'base', 1, []));
    assertThrows(\InvalidArgumentException::class, fn () => $builder->snapshot('0x123', 'polygon', 1, []));

    assertSame(
        ['native' => null, 'tokens' => [], 'defi' => ['compound' => [], 'aave' => null, 'other' => []], 'reconstruction_supported' => false],
        EvmHoldingsSnapshotBuilder::unsupportedChainShape()
    );
});

test('snapshot: native symbols per chain', function () {
    assertSame(['ethereum' => 'ETH', 'base' => 'ETH', 'polygon' => 'POL', 'binance-smart-chain' => 'BNB'], EvmHoldingsSnapshotBuilder::NATIVE_SYMBOLS);
});

test('decodeSymbol: dynamic string, bytes32, and garbage', function () {
    assertSame('USDT0', EvmHoldingsSnapshotBuilder::decodeSymbol(abiString('USDT0')));
    assertSame('MKR', EvmHoldingsSnapshotBuilder::decodeSymbol('0x' . str_pad(bin2hex('MKR'), 64, '0')));
    assertSame('', EvmHoldingsSnapshotBuilder::decodeSymbol('0x'));
    assertSame('', EvmHoldingsSnapshotBuilder::decodeSymbol('0x1234'));
    assertSame('AB', EvmHoldingsSnapshotBuilder::decodeSymbol(abiString("A\x01B")), 'control characters are stripped');
    assertSame('', EvmHoldingsSnapshotBuilder::decodeSymbol('0x' . word(32) . word(5000) . '00'));
});
