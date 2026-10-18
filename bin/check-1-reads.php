<?php

/**
 * Delivery 1 acceptance check (MULTICHAIN-RECONSTRUCTION-PLAN.md, section 4): reproduces the
 * reference values of section 5 with CompoundHoldingsClient / AaveHoldingsClient at a
 * historical block, against REAL RPCs, and proves the check is discriminating by re-running
 * the non-zero checks against a deliberately wrong address (they must all FAIL).
 *
 *   php bin/check-1-reads.php [--config=config.php] [--wallet=0x...] [--only=<id>]
 *                             [--rpc-<chain>=<url>]   e.g. --rpc-binance-smart-chain=https://...
 *
 * Providers come from config.php's "reconstruction.rpc" (first provider of each chain), with
 * the keyless drpc.org defaults for Ethereum/Base/Polygon. BSC needs a NodeReal URL (config
 * or --rpc-binance-smart-chain). Exit code 0 = everything as expected.
 * Run it locally and paste the whole output back for review.
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

use App\AaveHoldingsClient;
use App\CompoundHoldingsClient;
use App\EvmReconstructionConfig;
use App\EvmRpcClient;

$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    }
}

$configFile = $options['config'] ?? $root . '/config.php';
$config = is_file($configFile) ? require $configFile : [];
$reconstruction = new EvmReconstructionConfig($config);

$urls = [];

foreach (['ethereum', 'base', 'polygon', 'binance-smart-chain'] as $chain) {
    $urls[$chain] = $options['rpc-' . $chain] ?? ($reconstruction->providers($chain)[0]['url'] ?? null);
}

$wallet = $options['wallet'] ?? '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';
$wrongWallet = '0x000000000000000000000000000000000000dEaD';

/** @var list<EvmRpcClient> $clients every client created, to report the last error on failure */
$clients = [];
$factory = static function (string $url) use (&$clients): EvmRpcClient {
    $client = new EvmRpcClient($url, null, 30, 3, 1000);
    $clients[] = $client;

    return $client;
};

function same(string $a, string $b): bool
{
    $norm = static function (string $n): string {
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }

        return $n === '' ? '0' : $n;
    };

    return $norm($a) === $norm($b);
}

/** @return array<string, array> symbol => reserve */
function reservesBySymbol(?array $aave): array
{
    $out = [];

    foreach ($aave['reserves'] ?? [] as $reserve) {
        $out[$reserve['symbol']] = $reserve;
    }

    return $out;
}

/**
 * Each check: id, chain, protocol, block ('latest' or int), nonZero (counts for the
 * negative control), assert: fn(array $positionOrNull): list<string> problems.
 */
$checks = [
    [
        'id' => 'eth-compound-2025-11-01', 'chain' => 'ethereum', 'protocol' => 'compound', 'block' => 23700766, 'nonZero' => true,
        'assert' => static function (?array $p): array {
            $m = $p[0] ?? null;
            $weth = $m['collateral'][0] ?? null;

            return array_filter([
                $m === null ? 'no Compound position' : null,
                $m && ! same($m['borrowed'], '933.435002') ? "borrowed {$m['borrowed']} != 933.435002" : null,
                ! $weth || $weth['symbol'] !== 'WETH' || ! same($weth['amount'], '0.4') ? 'WETH collateral != 0.4' : null,
            ]);
        },
    ],
    [
        'id' => 'eth-compound-2025-12-01', 'chain' => 'ethereum', 'protocol' => 'compound', 'block' => 23914920, 'nonZero' => true,
        'assert' => static function (?array $p): array {
            $m = $p[0] ?? null;
            $weth = $m['collateral'][0] ?? null;

            return array_filter([
                $m === null ? 'no Compound position' : null,
                $m && ! same($m['borrowed'], '937.67524') ? "borrowed {$m['borrowed']} != 937.67524" : null,
                ! $weth || ! same($weth['amount'], '0.476818727900527526') ? 'WETH collateral != 0.476818727900527526' : null,
            ]);
        },
    ],
    [
        'id' => 'eth-compound-2026-01-01', 'chain' => 'ethereum', 'protocol' => 'compound', 'block' => 24136052, 'nonZero' => true,
        'assert' => static function (?array $p): array {
            $m = $p[0] ?? null;
            $weth = $m['collateral'][0] ?? null;

            return array_filter([
                $m === null ? 'no Compound position' : null,
                $m && ! same($m['borrowed'], '0') ? "borrowed {$m['borrowed']} != 0" : null,
                ! $weth || ! same($weth['amount'], '0.000000000057157522') ? 'WETH collateral != 0.000000000057157522' : null,
            ]);
        },
    ],
    [
        'id' => 'eth-aave-2025-12-01', 'chain' => 'ethereum', 'protocol' => 'aave', 'block' => 23914920, 'nonZero' => true,
        'assert' => static function (?array $p): array {
            $r = reservesBySymbol($p);
            $debt = array_filter($r, static fn (array $x): bool => ! same($x['variableDebt'], '0') || ! same($x['stableDebt'], '0'));

            return array_filter([
                ! isset($r['WETH']) || ! same($r['WETH']['supplied'], '0.028014789715922191') ? 'WETH supplied != 0.028014789715922191' : null,
                $debt !== [] ? 'unexpected debt on ' . implode(',', array_keys($debt)) : null,
            ]);
        },
    ],
    [
        'id' => 'bsc-aave-2025-11-01', 'chain' => 'binance-smart-chain', 'protocol' => 'aave', 'block' => 66605720, 'nonZero' => true,
        'assert' => static function (?array $p): array {
            $r = reservesBySymbol($p);
            $s = $p['summary'] ?? [];

            return array_filter([
                ! isset($r['WBNB']) || ! same($r['WBNB']['supplied'], '0.170012507196328142') ? 'WBNB supplied != 0.170012507196328142' : null,
                ! isset($r['USDC']) || ! same($r['USDC']['variableDebt'], '60.118707451980723546') ? 'USDC variableDebt != 60.118707451980723546' : null,
                ! isset($s['totalCollateralUsd']) || abs((float) $s['totalCollateralUsd'] - 185.09) >= 0.01 ? 'collateral USD != ~185.09' : null,
                ! isset($s['totalDebtUsd']) || abs((float) $s['totalDebtUsd'] - 60.11) >= 0.01 ? 'debt USD != ~60.11' : null,
                ($s['healthFactor'] ?? null) === null || ! same($s['healthFactor'], '2.309450654857429632') ? 'health factor != 2.309450654857429632' : null,
            ]);
        },
    ],
    [
        'id' => 'polygon-aave-2026-02-15', 'chain' => 'polygon', 'protocol' => 'aave', 'block' => 82996788, 'nonZero' => true,
        'assert' => static function (?array $p): array {
            $r = reservesBySymbol($p);
            $s = $p['summary'] ?? [];

            return array_filter([
                ! isset($r['WBTC']) || ! same($r['WBTC']['supplied'], '0.0035271') ? 'WBTC supplied != 0.0035271' : null,
                ! isset($r['USDT0']) || ! same($r['USDT0']['variableDebt'], '73.179844') ? 'USDT0 variableDebt != 73.179844' : null,
                ! isset($r['USDC']) || ! same($r['USDC']['variableDebt'], '41.790999') ? 'USDC variableDebt != 41.790999' : null,
                ($s['healthFactor'] ?? null) === null || ! same($s['healthFactor'], '1.669568345605074184') ? 'health factor != 1.669568345605074184' : null,
            ]);
        },
    ],
];

$zero = static fn (?array $p): array => $p === null || $p === [] ? [] : ['expected no position, got one'];

foreach ([
    ['bsc-aave-2026-10-01-zero', 'binance-smart-chain', 'aave', 125000754],
    ['bsc-aave-latest-zero', 'binance-smart-chain', 'aave', 'latest'],
    ['polygon-aave-2026-10-01-zero', 'polygon', 'aave', 94738439],
    ['polygon-aave-latest-zero', 'polygon', 'aave', 'latest'],
    // Contracts that do not exist yet at that block: must be a clean zero, NOT an error.
    ['eth-before-comet-and-aave-v3-deployment', 'ethereum', 'compound', 14000000],
    ['eth-before-aave-v3-deployment', 'ethereum', 'aave', 14000000],
    ['base-before-usds-market-and-late-reserves', 'base', 'compound', 10000000],
    ['base-aave-with-late-reserves', 'base', 'aave', 10000000],
] as [$id, $chain, $protocol, $block]) {
    $checks[] = ['id' => $id, 'chain' => $chain, 'protocol' => $protocol, 'block' => $block, 'nonZero' => false, 'assert' => $zero];
}

/** @return array{0: ?array, 1: ?string} [position or null, error or null] */
function read(array $check, string $address, array $urls, Closure $factory): array
{
    $chain = $check['chain'];

    if (empty($urls[$chain])) {
        return [null, "no RPC provider configured for {$chain}"];
    }

    $client = $check['protocol'] === 'compound'
        ? new CompoundHoldingsClient([$chain => $urls[$chain]], $factory)
        : new AaveHoldingsClient([$chain => $urls[$chain]], $factory);

    $result = $client->getHoldings($address, $check['block']);

    if ($result['errors'] !== []) {
        return [null, 'RPC errors: ' . json_encode($result['errors'])];
    }

    return [$result['positions'][$chain] ?? null, null];
}

$only = $options['only'] ?? null;
$failures = 0;
$controlWrong = 0;
$controlRun = 0;

echo "wallet {$wallet}\n\n";

foreach ($checks as $check) {
    if ($only !== null && $check['id'] !== $only) {
        continue;
    }

    $clients = [];
    [$position, $error] = read($check, $wallet, $urls, $factory);
    $problems = $error !== null ? [$error] : array_values(($check['assert'])($position));
    $lastErrors = array_filter(array_map(static fn (EvmRpcClient $c): ?string => $c->getLastError() ? (string) $c->getLastError() : null, $clients));

    if ($problems === []) {
        echo "PASS  {$check['id']} ({$check['chain']} {$check['protocol']} @ {$check['block']})\n";
    } else {
        $failures++;
        echo "FAIL  {$check['id']} ({$check['chain']} {$check['protocol']} @ {$check['block']})\n";

        foreach ($problems as $problem) {
            echo "        - {$problem}\n";
        }

        foreach (array_unique($lastErrors) as $e) {
            echo "        last RPC error: {$e}\n";
        }

        echo '        actual: ' . json_encode($position) . "\n";
    }

    if ($check['nonZero'] && $error === null) {
        $controlRun++;
        [$wrongPosition, $wrongError] = read($check, $wrongWallet, $urls, $factory);
        $wrongProblems = $wrongError !== null ? [$wrongError] : ($check['assert'])($wrongPosition);

        if ($wrongProblems === [] ) {
            $controlWrong++;
            echo "        NEGATIVE CONTROL BROKEN: the wrong address also satisfies {$check['id']}\n";
        }
    }
}

echo "\nnegative control: {$controlRun} non-zero checks re-run on a wrong address, "
    . ($controlWrong === 0 ? 'all of them failed, as they must' : "{$controlWrong} wrongly PASSED") . "\n";
echo $failures === 0 && $controlWrong === 0 ? "ALL OK\n" : "{$failures} check(s) failed\n";

exit($failures === 0 && $controlWrong === 0 ? 0 : 1);
