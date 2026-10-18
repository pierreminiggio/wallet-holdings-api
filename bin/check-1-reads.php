<?php

/**
 * Delivery 1 acceptance check (MULTICHAIN-RECONSTRUCTION-PLAN.md, section 4): reproduces the
 * reference values of section 5 with CompoundHoldingsClient / AaveHoldingsClient at a
 * historical block, against REAL RPCs, and proves the check is discriminating by re-running
 * the non-zero checks against a deliberately wrong address (they must all FAIL).
 *
 *   php bin/check-1-reads.php [--config=config.php] [--wallet=0x...] [--only=<id>]
 *                             [--retries=6] [--rounds=4] [--round-delay=5] [--pause=1] [--rpc-<chain>=<url>]   e.g. --rpc-binance-smart-chain=https://...
 *
 * Providers come from config.php's "reconstruction.rpc" (ALL providers of each chain, in
 * priority order), with the keyless drpc.org defaults for Ethereum/Base/Polygon. BSC needs a
 * NodeReal URL (config or --rpc-binance-smart-chain). Exit code 0 = everything as expected.
 *
 * RETRIES (built in, following the policy of MULTICHAIN-HOLDINGS.md section 3): infrastructure
 * failures are never reported as data results. Transient errors (rate limit, timeout, empty
 * body...) are retried on the SAME input with exponential backoff, first inside each RPC call
 * (--retries), then by re-running the whole check in rounds across every configured provider
 * (--rounds, --round-delay). An auth error (bad key, archive refused) makes a provider unusable
 * for that check, so the next provider is tried at once without waiting. A DATA mismatch is
 * never retried: it is a real failure. A check that stays unreachable is reported as
 * INFRASTRUCTURE, distinct from a wrong value.
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
use App\EvmRpcError;

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
    $urls[$chain] = isset($options['rpc-' . $chain])
        ? [$options['rpc-' . $chain]]
        : array_column($reconstruction->providers($chain), 'url');
}

// Keyless public endpoints (drpc.org) rate-limit hard, so be patient. Per RPC call: exponential
// backoff from 2s (capped at 30s). Per check: $rounds passes over every provider, waiting
// $roundDelay * 2^round seconds (capped at 60s) between passes.
$retries = (int) ($options['retries'] ?? 6);
$rounds = max(1, (int) ($options['rounds'] ?? 4));
$roundDelay = (int) ($options['round-delay'] ?? 5);
$pauseSeconds = (int) ($options['pause'] ?? 1);

$wallet = $options['wallet'] ?? '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';
$wrongWallet = '0x000000000000000000000000000000000000dEaD';

/**
 * Records the final outcome class of every failed RPC call (after the client's own retries),
 * so a check can tell "provider unusable" (auth) from "try again later" (transient).
 */
final class RecordingRpcClient extends EvmRpcClient
{
    /** @var list<string> */
    public static array $failures = [];

    public function request(string $method, array $params): mixed
    {
        $result = parent::request($method, $params);

        if ($result instanceof EvmRpcError) {
            self::$failures[] = $result->getKind() . ': ' . $result->getMessage();
        }

        return $result;
    }
}

$factory = static function (string $url) use ($retries): EvmRpcClient {
    return new RecordingRpcClient($url, null, 30, $retries, 2000);
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

function hostOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_HOST); // never print the path: it often holds the API key
}

/**
 * One attempt of a check against one provider.
 *
 * @return array{0: ?array, 1: ?string, 2: bool} [position, error, onlyAuthFailures]
 */
function readOnce(array $check, string $address, string $url, Closure $factory): array
{
    $chain = $check['chain'];

    $client = $check['protocol'] === 'compound'
        ? new CompoundHoldingsClient([$chain => $url], $factory)
        : new AaveHoldingsClient([$chain => $url], $factory);

    RecordingRpcClient::$failures = [];
    $result = $client->getHoldings($address, $check['block']);

    if ($result['errors'] === []) {
        return [$result['positions'][$chain] ?? null, null, false];
    }

    $failures = array_values(array_unique(RecordingRpcClient::$failures));
    $onlyAuth = $failures !== [] && count(array_filter($failures, static fn (string $f): bool => str_starts_with($f, 'auth:'))) === count($failures);

    return [null, 'RPC errors: ' . json_encode($result['errors']) . ' | ' . implode(' | ', $failures), $onlyAuth];
}

/**
 * Runs a check with the built-in retry policy (see the header). Returns the position and an
 * error string; the error is only ever set when no provider could answer, never for a value.
 *
 * @return array{0: ?array, 1: ?string}
 */
function read(array $check, string $address, array $urls, Closure $factory, int $rounds, int $roundDelay): array
{
    $providers = $urls[$check['chain']] ?? [];

    if ($providers === []) {
        return [null, "no RPC provider configured for {$check['chain']}"];
    }

    $usable = $providers;
    $lastError = null;

    for ($round = 0; $round < $rounds; $round++) {
        $transient = false;

        foreach ($usable as $i => $url) {
            [$position, $error, $onlyAuth] = readOnce($check, $address, $url, $factory);

            if ($error === null) {
                return [$position, null];
            }

            $lastError = hostOf($url) . ': ' . $error;

            if ($onlyAuth) {
                // Neither waiting nor retrying helps: this provider is unusable for this check.
                unset($usable[$i]);
                echo "        .. " . hostOf($url) . " unusable for this check (auth), dropped for this check\n";

                continue;
            }

            $transient = true;
        }

        if ($usable === [] || ! $transient || $round === $rounds - 1) {
            break;
        }

        $delay = min(60, $roundDelay * (2 ** $round));
        echo "        .. transient failure on every provider, round " . ($round + 1) . "/{$rounds}, waiting {$delay}s then retrying the same check\n";
        sleep($delay);
    }

    return [null, $lastError];
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

    if ($pauseSeconds > 0) {
        sleep($pauseSeconds);
    }

    [$position, $error] = read($check, $wallet, $urls, $factory, $rounds, $roundDelay);
    $problems = $error !== null ? [$error] : array_values(($check['assert'])($position));

    if ($problems === []) {
        echo "PASS  {$check['id']} ({$check['chain']} {$check['protocol']} @ {$check['block']})\n";
    } else {
        $failures++;
        echo "FAIL  {$check['id']} ({$check['chain']} {$check['protocol']} @ {$check['block']})\n";

        foreach ($problems as $problem) {
            echo "        - {$problem}\n";
        }

        if ($error !== null) {
            echo "        INFRASTRUCTURE failure (no provider could answer): this is not a wrong value.\n";
        } else {
            echo '        actual: ' . json_encode($position) . "\n";
        }
    }

    if ($check['nonZero'] && $error === null) {
        $controlRun++;
        [$wrongPosition, $wrongError] = read($check, $wrongWallet, $urls, $factory, $rounds, $roundDelay);
        $wrongProblems = $wrongError !== null ? [$wrongError] : ($check['assert'])($wrongPosition);

        if ($wrongProblems === [] ) {
            $controlWrong++;
            echo "        NEGATIVE CONTROL BROKEN: the wrong address also satisfies {$check['id']}\n";
        }
    }
}

echo "\nnegative control: " . ($controlRun === 0
    ? 'not run (no non-zero check could be read)'
    : "{$controlRun} non-zero checks re-run on a wrong address, "
        . ($controlWrong === 0 ? 'all of them failed, as they must' : "{$controlWrong} wrongly PASSED")) . "\n";
echo $failures === 0 && $controlWrong === 0 ? "ALL OK\n" : "{$failures} check(s) failed\n";

exit($failures === 0 && $controlWrong === 0 ? 0 : 1);
