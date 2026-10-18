<?php

/**
 * Delivery 4 acceptance check (MULTICHAIN-RECONSTRUCTION-PLAN.md, section 4): EvmHoldingsSnapshotBuilder
 * against REAL RPCs, with the section 5 reference values:
 *
 *   - the Polygon USDT0 residual 0.132953 (and the balances between its 7 transfers),
 *   - a token whose balance is non-zero only in a past window (Polygon native USDC: 18.99304 at
 *     80,406,106, 60 at 80,636,107, 61.046 at 80,745,841, gone at 80,745,873),
 *   - Compound (Ethereum), Aave (BSC, Polygon, Ethereum) and the all-zero cases, read through the builder,
 *   - native balance at each chain's native genesis block (zero one block before, non-zero at it),
 *   - negative controls with another wallet.
 *
 *   php bin/check-4-snapshot.php [--config=config.php] [--only=<id>] [--retries=6] [--rounds=4]
 *                                [--round-delay=5] [--pause=1] [--rpc-<chain>=<url>]
 *
 * Same providers and retry policy as bin/check-1-reads.php. A snapshot that cannot be built is always an
 * INFRASTRUCTURE failure (the builder never returns a partial snapshot), reported as such, never as a
 * wrong value. Exit code 0 = all as expected. Paste the whole output back for review.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . substr($class, 4) . '.php';

            if (is_file($file)) {
                require $file;
            }
        }
    });
}

use App\EvmHoldingsSnapshotBuilder;
use App\EvmReconstructionConfig;
use App\EvmRpcClient;

$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    }
}

$configFile = $options['config'] ?? $root . '/config.php';
$reconstruction = new EvmReconstructionConfig(is_file($configFile) ? require $configFile : []);

$retries = (int) ($options['retries'] ?? 6);
$rounds = max(1, (int) ($options['rounds'] ?? 4));
$roundDelay = (int) ($options['round-delay'] ?? 5);
$pauseSeconds = (int) ($options['pause'] ?? 1);

$wallet = $options['wallet'] ?? '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';
$wrongWallet = '0x000000000000000000000000000000000000dEaD';

const USDT0 = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';
const NATIVE_USDC = '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359';

/** @var array<string, list<EvmHoldingsSnapshotBuilder>> one builder per chain and provider, kept for the whole run */
$builders = [];

function buildersFor(string $chain, array $options, EvmReconstructionConfig $reconstruction, int $retries, array &$builders): array
{
    if (! isset($builders[$chain])) {
        $urls = isset($options['rpc-' . $chain]) ? [$options['rpc-' . $chain]] : array_column($reconstruction->providers($chain), 'url');
        $builders[$chain] = array_map(
            static fn (string $url): EvmHoldingsSnapshotBuilder => new EvmHoldingsSnapshotBuilder([$chain => new EvmRpcClient($url, null, 60, $retries, 2000)]),
            $urls
        );
    }

    return $builders[$chain];
}

/**
 * Builds a snapshot with the built-in retry policy: every provider in priority order, then a patient wait and
 * another round (the builder's clients already retry transient errors per call).
 *
 * @return array{0: ?array, 1: list<array>, 2: ?string} holdings, skipped tokens, infrastructure error
 */
function snapshotWithRetries(string $chain, string $address, int $block, array $tokens, array $options, EvmReconstructionConfig $reconstruction, int $retries, int $rounds, int $roundDelay, array &$builders): array
{
    $list = buildersFor($chain, $options, $reconstruction, $retries, $builders);

    if ($list === []) {
        return [null, [], "no RPC provider configured for {$chain}"];
    }

    $lastError = null;

    for ($round = 0; $round < $rounds; $round++) {
        foreach ($list as $builder) {
            $result = $builder->snapshot($address, $chain, $block, $tokens);

            if ($result->isOk()) {
                return [$result->getHoldings(), $result->getSkippedTokens(), null];
            }

            $lastError = $result->getError();
        }

        if ($round < $rounds - 1) {
            $delay = min(60, $roundDelay * (2 ** $round));
            echo "        .. snapshot failed on every provider ({$lastError}); round " . ($round + 1) . "/{$rounds}, waiting {$delay}s then retrying\n";
            sleep($delay);
        }
    }

    return [null, [], $lastError];
}

function norm(?string $amount): ?string
{
    if ($amount === null) {
        return null;
    }

    if (str_contains($amount, '.')) {
        $amount = rtrim(rtrim($amount, '0'), '.');
    }

    return $amount === '' ? '0' : $amount;
}

function tokenAmount(array $holdings, string $contract): ?string
{
    foreach ($holdings['tokens'] as $token) {
        if (strtolower($token['contract']) === strtolower($contract)) {
            return norm($token['amount']);
        }
    }

    return null;
}

function aaveReserve(array $holdings, string $symbol): ?array
{
    foreach ($holdings['defi']['aave']['reserves'] ?? [] as $reserve) {
        if ($reserve['symbol'] === $symbol) {
            return $reserve;
        }
    }

    return null;
}

/**
 * id, chain, block, tokens, assert(holdings): list<string> problems, nonZero => counts for the negative control.
 */
$checks = [];

$tokenCheck = static function (string $id, int $block, string $token, ?string $expected, string $chain = 'polygon') use (&$checks): void {
    $checks[] = [
        'id' => $id, 'chain' => $chain, 'block' => $block, 'tokens' => [$token], 'nonZero' => $expected !== null,
        'assert' => static function (array $h) use ($token, $expected): array {
            $actual = tokenAmount($h, $token);

            return $actual === $expected ? [] : ['token balance ' . ($actual ?? 'absent') . ' != ' . ($expected ?? 'absent')];
        },
    ];
};

// USDT0 residual and the balances between its 7 transfers (+75.598753 +0.120354 -75.679396 +345.052550 +0.801676 -345.761329 +0.000345)
$tokenCheck('polygon-usdt0-before-first-transfer', 93167661, USDT0, null);
$tokenCheck('polygon-usdt0-after-3-transfers-mid', 93167731, USDT0, '75.719107');
$tokenCheck('polygon-usdt0-after-3rd-transfer', 93167732, USDT0, '0.039711');
$tokenCheck('polygon-usdt0-residual', 93167973, USDT0, '0.132953');

// a token whose balance is non-zero only in a past window (native USDC: transferred in and out of the wallet)
$tokenCheck('polygon-native-usdc-before-history-start', 80406106, NATIVE_USDC, '18.99304');
$tokenCheck('polygon-native-usdc-mid-window', 80636107, NATIVE_USDC, '60');
$tokenCheck('polygon-native-usdc-just-before-exit', 80745841, NATIVE_USDC, '61.046');
$tokenCheck('polygon-native-usdc-gone-after-window', 80745873, NATIVE_USDC, null);

// A transfer pair the scanner found (+243.550038 at 80,526,467, -243.550038 at 80,526,491) that the section 5 list of 10
// transfers did not contain. It nets to zero, so balance reconciliation could never have shown it. balanceOf is the ground truth:
$tokenCheck('polygon-native-usdc-before-extra-pair', 80526466, NATIVE_USDC, null);
$tokenCheck('polygon-native-usdc-inside-extra-pair-start', 80526467, NATIVE_USDC, '243.550038');
$tokenCheck('polygon-native-usdc-inside-extra-pair-end', 80526490, NATIVE_USDC, '243.550038');
$tokenCheck('polygon-native-usdc-after-extra-pair', 80526491, NATIVE_USDC, null);

$checks[] = [
    'id' => 'eth-compound-2025-10-31-end', 'chain' => 'ethereum', 'block' => 23700766, 'tokens' => [], 'nonZero' => true,
    'assert' => static function (array $h): array {
        $m = $h['defi']['compound'][0] ?? null;
        $weth = $m['collateral'][0] ?? null;

        return array_filter([
            $m === null ? 'no Compound position' : null,
            $m && norm($m['borrowed']) !== '933.435002' ? "borrowed {$m['borrowed']} != 933.435002" : null,
            ! $weth || $weth['symbol'] !== 'WETH' || norm($weth['amount']) !== '0.4' ? 'WETH collateral != 0.4' : null,
            $h['defi']['other'] !== [] ? '"other" must be empty in a reconstruction' : null,
        ]);
    },
];

$checks[] = [
    'id' => 'bsc-aave-2025-10-31-end', 'chain' => 'binance-smart-chain', 'block' => 66605720, 'tokens' => [], 'nonZero' => true,
    'assert' => static function (array $h): array {
        $wbnb = aaveReserve($h, 'WBNB');
        $usdc = aaveReserve($h, 'USDC');
        $s = $h['defi']['aave']['summary'] ?? [];

        return array_filter([
            ! $wbnb || norm($wbnb['supplied']) !== '0.170012507196328142' ? 'WBNB supplied != 0.170012507196328142' : null,
            ! $usdc || norm($usdc['variableDebt']) !== '60.118707451980723546' ? 'USDC variableDebt != 60.118707451980723546' : null,
            ! isset($s['totalCollateralUsd']) || abs((float) $s['totalCollateralUsd'] - 185.09) >= 0.01 ? 'collateral USD != ~185.09' : null,
            ! isset($s['totalDebtUsd']) || abs((float) $s['totalDebtUsd'] - 60.11) >= 0.01 ? 'debt USD != ~60.11' : null,
            ($s['healthFactor'] ?? null) !== '2.309450654857429632' ? 'health factor != 2.309450654857429632' : null,
        ]);
    },
];

$checks[] = [
    'id' => 'polygon-aave-2026-02-14-end', 'chain' => 'polygon', 'block' => 82996788, 'tokens' => [], 'nonZero' => true,
    'assert' => static function (array $h): array {
        $wbtc = aaveReserve($h, 'WBTC');
        $usdt0 = aaveReserve($h, 'USDT0');
        $usdc = aaveReserve($h, 'USDC');

        return array_filter([
            ! $wbtc || norm($wbtc['supplied']) !== '0.0035271' ? 'WBTC supplied != 0.0035271' : null,
            ! $usdt0 || norm($usdt0['variableDebt']) !== '73.179844' ? 'USDT0 variableDebt != 73.179844' : null,
            ! $usdc || norm($usdc['variableDebt']) !== '41.790999' ? 'USDC variableDebt != 41.790999' : null,
            ($h['defi']['aave']['summary']['healthFactor'] ?? null) !== '1.669568345605074184' ? 'health factor != 1.669568345605074184' : null,
        ]);
    },
];

$checks[] = [
    'id' => 'eth-aave-2025-11-30-end', 'chain' => 'ethereum', 'block' => 23914920, 'tokens' => [], 'nonZero' => true,
    'assert' => static function (array $h): array {
        $weth = aaveReserve($h, 'WETH');
        $debt = array_filter($h['defi']['aave']['reserves'] ?? [], static fn (array $r): bool => norm($r['variableDebt']) !== '0' || norm($r['stableDebt']) !== '0');

        return array_filter([
            ! $weth || norm($weth['supplied']) !== '0.028014789715922191' ? 'WETH supplied != 0.028014789715922191' : null,
            $debt !== [] ? 'unexpected Aave debt' : null,
        ]);
    },
];

// The reference "all zero" values are about Aave only (the wallet DOES have Compound positions on Polygon), so
// only Aave is asserted here, and the Compound result is printed for information.
foreach ([['bsc-aave-all-zero', 'binance-smart-chain', 125000754], ['polygon-aave-all-zero', 'polygon', 94738439]] as [$id, $chain, $block]) {
    $checks[] = [
        'id' => $id, 'chain' => $chain, 'block' => $block, 'tokens' => [], 'nonZero' => false,
        'info' => static fn (array $h): string => $h['defi']['compound'] === [] ? '' : '  [Compound, not asserted: ' . count($h['defi']['compound']) . ' market(s) with a position]',
        'assert' => static fn (array $h): array => $h['defi']['aave'] === null ? [] : ['expected no Aave position, got ' . json_encode($h['defi']['aave'])],
    ];
}

// Native balance at each chain's native genesis: zero one block earlier, non-zero at it.
foreach ([['ethereum', 13024431, 'ETH'], ['binance-smart-chain', 10027014, 'BNB'], ['polygon', 80406107, 'POL'], ['base', 35321858, 'ETH']] as [$chain, $genesis, $symbol]) {
    $checks[] = [
        // The pair (zero one block before, non-zero at genesis) is its own control: the same assertion fails on the
        // other side of the boundary. A burn address would NOT make a valid wrong-wallet control: it holds funds.
        'id' => "{$chain}-native-genesis-minus-1", 'chain' => $chain, 'block' => $genesis - 1, 'tokens' => [], 'nonZero' => false,
        'assert' => static fn (array $h): array => $h['native'] === null ? [] : ["native balance before genesis should be zero, got {$h['native']['amount']}"],
    ];
    $checks[] = [
        'id' => "{$chain}-native-genesis", 'chain' => $chain, 'block' => $genesis, 'tokens' => [], 'nonZero' => false,
        'assert' => static fn (array $h): array => ($h['native'] !== null && $h['native']['symbol'] === $symbol && norm($h['native']['amount']) !== '0') ? [] : ['expected a non-zero ' . $symbol . ' balance at the native genesis block, got ' . json_encode($h['native'])],
    ];
}

$only = $options['only'] ?? null;
$failures = 0;
$controlRun = 0;
$controlBroken = 0;

echo "wallet {$wallet}\n\n";

foreach ($checks as $check) {
    if ($only !== null && $only !== $check['id']) {
        continue;
    }

    sleep($pauseSeconds);
    [$holdings, $skipped, $infra] = snapshotWithRetries($check['chain'], $wallet, $check['block'], $check['tokens'], $options, $reconstruction, $retries, $rounds, $roundDelay, $builders);
    $label = "{$check['id']} ({$check['chain']} @ {$check['block']})";

    if ($infra !== null) {
        $failures++;
        echo "FAIL  {$label}\n        INFRASTRUCTURE failure (no snapshot could be built): this is not a wrong value: {$infra}\n";

        continue;
    }

    $problems = array_values(($check['assert'])($holdings));

    if ($problems === []) {
        echo "PASS  {$label}" . ($skipped !== [] ? '  [skipped tokens: ' . count($skipped) . ']' : '') . (isset($check['info']) ? ($check['info'])($holdings) : '') . "\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";

        foreach ($problems as $problem) {
            echo "        - {$problem}\n";
        }
    }

    // Negative control: the same non-zero expectation must NOT hold for another wallet.
    if ($check['nonZero']) {
        $controlRun++;
        [$wrongHoldings, , $wrongInfra] = snapshotWithRetries($check['chain'], $wrongWallet, $check['block'], $check['tokens'], $options, $reconstruction, $retries, $rounds, $roundDelay, $builders);

        if ($wrongInfra === null && ($check['assert'])($wrongHoldings) === []) {
            $controlBroken++;
            echo "        NEGATIVE CONTROL BROKEN: the wrong address also satisfies {$check['id']}\n";
        }
    }
}

echo "\nnegative control: " . ($controlRun === 0 ? 'not run' : "{$controlRun} non-zero checks re-run on another wallet, " . ($controlBroken === 0 ? 'none of them satisfied, as it must be' : "{$controlBroken} wrongly PASSED")) . "\n";
echo $failures === 0 && $controlBroken === 0 ? "ALL OK\n" : "{$failures} check(s) failed\n";

exit($failures === 0 && $controlBroken === 0 ? 0 : 1);
