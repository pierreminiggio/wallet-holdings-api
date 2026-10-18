<?php

namespace Tests;

use App\CompoundHoldingsClient;
use App\EvmRpcClient;

const COMET = '0xc3d688B66703497DAA19211EEdff47f25384cdc3'; // Ethereum USDC market (CompoundHoldingsClient::MARKETS)
const WETH = '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2';
const WALLET = '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';

/**
 * A fake Ethereum Compound III USDC market.
 *
 * Timeline: the Comet contract exists from block $deployBlock. For a block < 150 the wallet
 * has borrowed 933.435002 USDC against 0.4 WETH; from block 150 on it has no borrow and only
 * dust collateral (0.000000000057157522 WETH). "latest" behaves like block 100.
 */
function cometHandler(int $deployBlock, array $overrides = []): \Closure
{
    return static function (string $method, array $params) use ($deployBlock, $overrides): array {
        $blockParam = $params[1] ?? null;
        $block = $blockParam === 'latest' ? 100 : (int) hexdec(substr((string) $blockParam, 2));

        if ($method === 'eth_getCode') {
            $isComet = strtolower($params[0]) === strtolower(COMET);

            return ['result' => (! $isComet || $block >= $deployBlock) ? FAKE_CODE : '0x'];
        }

        $to = strtolower($params[0]['to']);
        $data = $params[0]['data'];
        $selector = substr($data, 0, 10);

        if (isset($overrides[$selector])) {
            return $overrides[$selector]($block);
        }

        if ($to === strtolower(COMET)) {
            return match ($selector) {
                '0x313ce567' => ['result' => '0x' . word(6)],
                '0x70a08231' => ['result' => '0x' . word(0)],
                '0x374c49b4' => ['result' => '0x' . word($block < 150 ? 933435002 : 0)],
                '0xa46fe83b' => ['result' => '0x' . word(1)],
                '0xc8c7fe6b' => ['result' => '0x' . word(0) . word(WETH) . str_repeat(word(0), 6)],
                '0x2b92a07d' => ['result' => '0x' . word($block < 150 ? 400000000000000000 : 57157522) . word(0)],
                default => ['error' => ['code' => -32000, 'message' => 'unexpected selector ' . $selector]],
            };
        }

        if ($to === strtolower(WETH)) {
            return match ($selector) {
                '0x313ce567' => ['result' => '0x' . word(18)],
                '0x95d89b41' => ['result' => abiString('WETH')],
                default => ['error' => ['code' => -32000, 'message' => 'unexpected selector ' . $selector]],
            };
        }

        return ['error' => ['code' => -32000, 'message' => 'unexpected call to ' . $to]];
    };
}

function compoundClient(\Closure $handler, array &$calls): CompoundHoldingsClient
{
    return new CompoundHoldingsClient(['ethereum' => 'http://fake-eth'], fakeFactory($handler, $calls));
}

test('Compound latest: output unchanged, "latest" on every call, no extra eth_getCode', function () {
    $calls = [];
    $result = compoundClient(cometHandler(10), $calls)->getHoldings(WALLET);

    assertSame([], $result['errors']);
    assertSame(
        [[
            'base' => 'USDC',
            'market' => COMET,
            'supplied' => '0',
            'borrowed' => '933.435002',
            'collateral' => [['symbol' => 'WETH', 'amount' => '0.4']],
        ]],
        $result['positions']['ethereum']
    );

    foreach ($calls as $call) {
        assertSame('eth_call', $call['method'], 'the legacy path must not make any other kind of call');
        assertSame('latest', $call['params'][1]);
    }
});

test('Compound historical: same position, every read pinned to the requested block', function () {
    $calls = [];
    $result = compoundClient(cometHandler(10), $calls)->getHoldings(WALLET, 100);

    assertSame([], $result['errors']);
    assertSame('933.435002', $result['positions']['ethereum'][0]['borrowed']);
    assertSame([['symbol' => 'WETH', 'amount' => '0.4']], $result['positions']['ethereum'][0]['collateral']);

    assertSame('eth_getCode', $calls[0]['method']);

    foreach ($calls as $call) {
        assertSame('0x64', $call['params'][1], $call['method'] . ' must be pinned to block 100');
    }
});

test('Compound historical: a later block reads the later state (dust collateral, no borrow)', function () {
    $calls = [];
    $result = compoundClient(cometHandler(10), $calls)->getHoldings(WALLET, 200);

    assertSame([], $result['errors']);
    $position = $result['positions']['ethereum'][0];
    assertSame('0', $position['borrowed']);
    assertSame([['symbol' => 'WETH', 'amount' => '0.000000000057157522']], $position['collateral']);
});

test('Compound historical: a market not deployed yet is a verified zero, with no error and no eth_call', function () {
    $calls = [];
    $result = compoundClient(cometHandler(60), $calls)->getHoldings(WALLET, 50);

    assertSame([], $result['errors']);
    assertSame([], $result['positions']);
    assertSame(['eth_getCode'], array_column($calls, 'method'));
});

test('Compound historical: "0x" from a deployed market is an ERROR, never a zero', function () {
    $calls = [];
    $handler = cometHandler(10, ['0x70a08231' => static fn (int $block): array => ['result' => '0x']]);
    $result = compoundClient($handler, $calls)->getHoldings(WALLET, 100);

    assertSame([], $result['positions']);
    assertSame(['ethereum/USDC' => EvmRpcClient::ERROR_UPSTREAM], $result['errors']);
});

test('Compound historical: an RPC failure is an error entry, not a missing position', function () {
    $calls = [];
    $handler = cometHandler(10, ['0xa46fe83b' => static fn (int $block): array => ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']]]);
    $result = compoundClient($handler, $calls)->getHoldings(WALLET, 100);

    assertSame([], $result['positions']);
    assertSame(['ethereum/USDC' => EvmRpcClient::ERROR_UPSTREAM], $result['errors']);
});

test('Compound historical: a failing eth_getCode is an error entry', function () {
    $calls = [];
    $handler = static fn (string $method, array $params): array => ['raw' => '', 'status' => 502];
    $result = compoundClient($handler, $calls)->getHoldings(WALLET, 100);

    assertSame([], $result['positions']);
    assertSame(['ethereum/USDC' => EvmRpcClient::ERROR_UPSTREAM], $result['errors']);
});

test('Compound historical: one eth_getCode serves a whole day-by-day walk', function () {
    $calls = [];
    $client = compoundClient(cometHandler(60), $calls);

    $client->getHoldings(WALLET, 50);
    $client->getHoldings(WALLET, 100);
    $client->getHoldings(WALLET, 200);

    // block 50: absent; block 100: asked again (later than a known-absent block); block 200: memoized.
    $codeCalls = array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_getCode');
    assertSame(2, count($codeCalls));
});
