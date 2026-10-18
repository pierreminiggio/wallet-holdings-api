<?php

namespace Tests;

use App\AaveHoldingsClient;
use App\EvmRpcClient;

const AAVE_POOL = '0x794a61358D6845594F94dc1DB02A252b5b4814aD';          // Polygon (AaveHoldingsClient::POOLS)
const AAVE_DATA_PROVIDER = '0x243Aa95cAC2a25651eda86e80bEe66114413c43b';
const AAVE_WALLET = '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';

const MAX_UINT_HEX = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

/**
 * name => [asset, decimals, aToken, stableDebtToken, variableDebtToken, deployedFromBlock].
 * The third reserve, NEW, only exists from block 150: before that its tokens have no code.
 */
function aaveReserves(): array
{
    $zero = '0x0000000000000000000000000000000000000000';

    return [
        'WBTC' => ['0x1BFD67037B42Cf73acF2047067bd4F2C47D9BfD6', 8, '0x00000000000000000000000000000000000000a1', $zero, '0x00000000000000000000000000000000000000b1', 0],
        'USDT0' => ['0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 6, '0x00000000000000000000000000000000000000a2', '0x00000000000000000000000000000000000000c2', '0x00000000000000000000000000000000000000b2', 0],
        'NEW' => ['0x0000000000000000000000000000000000000003', 18, '0x00000000000000000000000000000000000000a3', '0x00000000000000000000000000000000000000c3', '0x00000000000000000000000000000000000000b3', 150],
    ];
}

/**
 * A fake Polygon Aave V3 deployment.
 *
 * Wallet timeline: for a block < 150 (and "latest"): 0.0035271 WBTC supplied (used as
 * collateral) and 73.179844 USDT0 variable debt, account data $185.09 / $60.11, HF
 * 1.669568345605074184. From block 150 on: only 1 NEW supplied, no debt, HF "infinite".
 * The Pool exists from block 10.
 */
function aaveHandler(array $overrides = []): \Closure
{
    $reserves = aaveReserves();

    $tokenOwner = [];

    foreach ($reserves as $name => [$asset, $decimals, $aToken, $stable, $variable, $from]) {
        $tokenOwner[strtolower($aToken)] = [$name, 'a'];
        $tokenOwner[strtolower($stable)] = [$name, 's'];
        $tokenOwner[strtolower($variable)] = [$name, 'v'];
    }

    return static function (string $method, array $params) use ($reserves, $tokenOwner, $overrides): array {
        $blockParam = $params[1] ?? null;
        $isLatest = $blockParam === 'latest';
        $block = $isLatest ? 100 : (int) hexdec(substr((string) $blockParam, 2));

        $balances = static function (string $name, string $kind) use ($block): int|string {
            if ($block < 150) {
                return match ([$name, $kind]) {
                    ['WBTC', 'a'] => 352710,
                    ['USDT0', 'v'] => 73179844,
                    default => 0,
                };
            }

            return $name === 'NEW' && $kind === 'a' ? '1000000000000000000' : 0;
        };

        if ($method === 'eth_getCode') {
            $address = strtolower($params[0]);

            if ($address === strtolower(AAVE_POOL)) {
                return ['result' => $block >= 10 ? FAKE_CODE : '0x'];
            }

            if (isset($tokenOwner[$address]) && $tokenOwner[$address][0] === 'NEW') {
                return ['result' => $block >= 150 ? FAKE_CODE : '0x'];
            }

            return ['result' => FAKE_CODE];
        }

        $to = strtolower($params[0]['to']);
        $data = $params[0]['data'];
        $selector = substr($data, 0, 10);

        if (isset($overrides[$selector])) {
            $override = $overrides[$selector]($to, $block);

            if ($override !== null) {
                return $override;
            }
        }

        if ($to === strtolower(AAVE_DATA_PROVIDER)) {
            if ($selector === '0xb316ff89') {
                return ['result' => abiReservesTokens(array_map(
                    static fn (string $name, array $r): array => [$name, $r[0]],
                    array_keys($reserves),
                    array_values($reserves)
                ))];
            }

            $assetArg = '0x' . substr($data, 10 + 24, 40);

            foreach ($reserves as $name => [$asset, $decimals, $aToken, $stable, $variable]) {
                if (strtolower($asset) !== strtolower($assetArg)) {
                    continue;
                }

                if ($selector === '0xd2493b6c') {
                    return ['result' => '0x' . word($aToken) . word($stable) . word($variable)];
                }

                if ($selector === '0x28dd2d01') {
                    // latest-mode path (DataProvider.getUserReserveData): 9 words
                    $supplied = $balances($name, 'a');
                    $variableDebt = $balances($name, 'v');
                    $usedAsCollateral = $name === 'WBTC' ? 1 : 0;

                    return ['result' => '0x'
                        . wordFromDecimal((string) $supplied) . word(0) . wordFromDecimal((string) $variableDebt)
                        . word(0) . word(0) . word(0) . word(0) . word(0) . word($usedAsCollateral)];
                }
            }

            return ['error' => ['code' => -32000, 'message' => 'unexpected data provider call ' . $selector]];
        }

        if ($to === strtolower(AAVE_POOL) && $selector === '0xbf92857c') {
            return $block < 150
                ? ['result' => '0x' . word(18509000000) . word(6011000000) . word(0) . word(7800) . word(7300) . wordFromDecimal('1669568345605074184')]
                : ['result' => '0x' . word(0) . word(0) . word(0) . word(0) . word(0) . MAX_UINT_HEX];
        }

        if (isset($tokenOwner[$to]) && $selector === '0x70a08231') {
            [$name, $kind] = $tokenOwner[$to];

            return ['result' => '0x' . wordFromDecimal((string) $balances($name, $kind))];
        }

        foreach ($reserves as [$asset, $decimals]) {
            if ($to === strtolower($asset) && $selector === '0x313ce567') {
                return ['result' => '0x' . word($decimals)];
            }
        }

        return ['error' => ['code' => -32000, 'message' => 'unexpected call to ' . $to . ' ' . $selector]];
    };
}

function aaveClient(\Closure $handler, array &$calls): AaveHoldingsClient
{
    return new AaveHoldingsClient(['polygon' => 'http://fake-polygon'], fakeFactory($handler, $calls));
}

const EXPECTED_SUMMARY_BEFORE = [
    'totalCollateralUsd' => '185.09',
    'totalDebtUsd' => '60.11',
    'ltv' => '73',
    'liquidationThreshold' => '78',
    'healthFactor' => '1.669568345605074184',
];

test('Aave latest: output unchanged (getUserReserveData path), "latest" everywhere, no getCode', function () {
    $calls = [];
    $result = aaveClient(aaveHandler(), $calls)->getHoldings(AAVE_WALLET);

    assertSame([], $result['errors']);
    assertSame(
        [
            ['symbol' => 'WBTC', 'supplied' => '0.0035271', 'usedAsCollateral' => true, 'variableDebt' => '0', 'stableDebt' => '0'],
            ['symbol' => 'USDT0', 'supplied' => '0', 'usedAsCollateral' => false, 'variableDebt' => '73.179844', 'stableDebt' => '0'],
        ],
        $result['positions']['polygon']['reserves']
    );
    assertSame(EXPECTED_SUMMARY_BEFORE, $result['positions']['polygon']['summary']);

    foreach ($calls as $call) {
        assertSame('eth_call', $call['method']);
        assertSame('latest', $call['params'][1]);
    }
});

test('Aave latest: infinite health factor is still null', function () {
    $calls = [];
    $handler = aaveHandler(['0xbf92857c' => static fn (string $to, int $block): array => [
        'result' => '0x' . word(100000000) . word(0) . word(0) . word(7800) . word(7300) . MAX_UINT_HEX,
    ]]);
    $result = aaveClient($handler, $calls)->getHoldings(AAVE_WALLET);

    assertSame(null, $result['positions']['polygon']['summary']['healthFactor']);
});

test('Aave historical: balanceOf on aTokens/debt tokens, usedAsCollateral unknown, account data at the block', function () {
    $calls = [];
    $result = aaveClient(aaveHandler(), $calls)->getHoldings(AAVE_WALLET, 100);

    assertSame([], $result['errors']);
    assertSame(
        [
            ['symbol' => 'WBTC', 'supplied' => '0.0035271', 'usedAsCollateral' => null, 'variableDebt' => '0', 'stableDebt' => '0'],
            ['symbol' => 'USDT0', 'supplied' => '0', 'usedAsCollateral' => null, 'variableDebt' => '73.179844', 'stableDebt' => '0'],
        ],
        $result['positions']['polygon']['reserves']
    );
    assertSame(EXPECTED_SUMMARY_BEFORE, $result['positions']['polygon']['summary']);
});

test('Aave historical: the DataProvider convenience read is NEVER used, and address resolution is at latest', function () {
    $calls = [];
    aaveClient(aaveHandler(), $calls)->getHoldings(AAVE_WALLET, 100);

    foreach ($calls as $call) {
        if ($call['method'] !== 'eth_call') {
            assertSame('0x64', $call['params'][1], $call['method'] . ' must be pinned to block 100');

            continue;
        }

        $selector = substr($call['params'][0]['data'], 0, 10);
        $to = strtolower($call['params'][0]['to']);

        assertTrue($selector !== '0x28dd2d01', 'getUserReserveData must not be called for a historical block');

        if ($to === strtolower(AAVE_DATA_PROVIDER)) {
            assertSame('latest', $call['params'][1], 'DataProvider is only read at latest, to resolve token addresses');
        } elseif ($selector === '0x313ce567') {
            assertSame('latest', $call['params'][1], 'decimals is resolved with the reserve list, at latest');
        } else {
            assertSame('0x64', $call['params'][1], "{$selector} on {$to} must be pinned to block 100");
        }
    }
});

test('Aave historical: tokens that do not exist yet are a verified zero (no balanceOf sent); the zero address is never called', function () {
    $calls = [];
    aaveClient(aaveHandler(), $calls)->getHoldings(AAVE_WALLET, 100);

    foreach ($calls as $call) {
        if ($call['method'] !== 'eth_call') {
            continue;
        }

        $to = strtolower($call['params'][0]['to']);

        assertTrue(! in_array($to, [
            '0x00000000000000000000000000000000000000a3',
            '0x00000000000000000000000000000000000000b3',
            '0x00000000000000000000000000000000000000c3',
            '0x0000000000000000000000000000000000000000',
        ], true), "no balanceOf to {$to}");
    }
});

test('Aave historical: a token deployed later is read once it exists (block 200)', function () {
    $calls = [];
    $result = aaveClient(aaveHandler(), $calls)->getHoldings(AAVE_WALLET, 200);

    assertSame([], $result['errors']);
    assertSame(
        [['symbol' => 'NEW', 'supplied' => '1', 'usedAsCollateral' => null, 'variableDebt' => '0', 'stableDebt' => '0']],
        $result['positions']['polygon']['reserves']
    );
    assertSame(null, $result['positions']['polygon']['summary']['healthFactor']);
});

test('Aave historical: Pool not deployed yet -> no position, no error, a single eth_getCode', function () {
    $calls = [];
    $result = aaveClient(aaveHandler(), $calls)->getHoldings(AAVE_WALLET, 5);

    assertSame([], $result['errors']);
    assertSame([], $result['positions']);
    assertSame(['eth_getCode'], array_column($calls, 'method'));
});

test('Aave historical: "0x" from a deployed aToken is an ERROR, never a zero', function () {
    $calls = [];
    $handler = aaveHandler(['0x70a08231' => static fn (string $to, int $block): ?array => $to === '0x00000000000000000000000000000000000000a1' ? ['result' => '0x'] : null]);
    $result = aaveClient($handler, $calls)->getHoldings(AAVE_WALLET, 100);

    assertSame([], $result['positions']);
    assertSame(['polygon' => EvmRpcClient::ERROR_UPSTREAM], $result['errors']);
});

test('Aave historical: a failing account-data read fails the chain (no partial position)', function () {
    $calls = [];
    $handler = aaveHandler(['0xbf92857c' => static fn (string $to, int $block): array => ['error' => ['code' => -32000, 'message' => 'Unauthorized: invalid API key']]]);
    $result = aaveClient($handler, $calls)->getHoldings(AAVE_WALLET, 100);

    assertSame([], $result['positions']);
    assertSame(['polygon' => EvmRpcClient::ERROR_UPSTREAM], $result['errors']);
});

test('Aave historical: reserve tokens are resolved once per client, not once per day', function () {
    $calls = [];
    $client = aaveClient(aaveHandler(), $calls);

    $client->getHoldings(AAVE_WALLET, 100);
    $client->getHoldings(AAVE_WALLET, 120);
    $client->getHoldings(AAVE_WALLET, 200);

    $listCalls = array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_call' && substr($c['params'][0]['data'], 0, 10) === '0xb316ff89');
    assertSame(1, count($listCalls));
});
