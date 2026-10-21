<?php

/**
 * Measures the RPC providers of config.php ("reconstruction.rpc", plus the keyless defaults) and ESTIMATES how long the
 * slowest part of a reconstruction takes with each one: Phase 0, the log scan from a chain's history start to the wallet's
 * first activity. Run it before committing to a multi-hour run, and again after changing providers.
 *
 *   php bin/check-providers.php [--requests=12] [--only=ethereum]
 *
 * For every provider of every chain it
 *   1. finds the largest eth_getLogs block range it accepts (starting at 50,000, halving on a range-cap error);
 *   2. sends --requests sequential eth_getLogs at that size, with NO retry, so rate limiting and timeouts show up as
 *      they are (counted by class), and times them;
 *   3. estimates the time to scan from the chain's history start to the wallet's first activity block (the native
 *      genesis blocks proven by bin/check-4-snapshot.php), at two requests per chunk (both transfer directions).
 * The estimate is a projection from a short sample: treat it as an order of magnitude. Read-only: no database, nothing written.
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

use App\EvmLogScanner;
use App\EvmReconstructionChains;
use App\EvmReconstructionConfig;
use App\EvmRpcClient;
use App\EvmRpcError;

$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}

$configFile = $options['config'] ?? $root . '/config.php';
$config = new EvmReconstructionConfig(is_file($configFile) ? require $configFile : []);
$requests = max(3, (int) ($options['requests'] ?? 12));

const WALLET = '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';
const FIRST_ACTIVITY = ['ethereum' => 13024431, 'binance-smart-chain' => 10027014, 'base' => 35321858, 'polygon' => 80406107];
const SAMPLE_START = ['ethereum' => 1000000, 'binance-smart-chain' => 1000000, 'base' => 1000000, 'polygon' => 80406107];

function hostOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_HOST); // never the path: it often holds the API key
}

function human(float $seconds): string
{
    if ($seconds < 120) {
        return round($seconds) . ' s';
    }

    if ($seconds < 7200) {
        return round($seconds / 60, 1) . ' min';
    }

    if ($seconds < 172800) {
        return round($seconds / 3600, 1) . ' h';
    }

    return round($seconds / 86400, 1) . ' days';
}

$walletTopic = EvmLogScanner::walletTopic(WALLET);
$topics = [EvmLogScanner::TRANSFER_TOPIC, null, $walletTopic];

foreach (EvmReconstructionChains::SUPPORTED_CHAINS as $chain) {
    if (isset($options['only']) && $options['only'] !== $chain) {
        continue;
    }

    echo "\n== {$chain} ==\n";
    $providers = $config->providers($chain);

    if ($providers === []) {
        echo "   no provider configured: a reconstruction fails for this chain (add one under reconstruction.rpc in config.php)\n";

        continue;
    }

    foreach ($providers as $provider) {
        $client = new EvmRpcClient($provider['url'], null, 60, 0);
        echo "   {$provider['name']} (" . hostOf($provider['url']) . ")\n";

        $head = $client->blockNumber();

        if ($head instanceof EvmRpcError) {
            echo "      head: FAILED: {$head}\n";

            continue;
        }

        // 1. the largest accepted range
        $start = SAMPLE_START[$chain];
        $size = 50000;
        $cap = null;
        $attempts = 0;

        while ($size >= 1 && $attempts < 14) {
            $attempts++;
            $result = $client->getLogs($start, $start + $size - 1, $topics);

            if (! ($result instanceof EvmRpcError)) {
                $cap = $size;

                break;
            }

            if ($result->isRangeCap()) {
                $size = intdiv($size, 2);

                continue;
            }

            if ($result->isAuth()) {
                echo "      eth_getLogs refused: {$result}\n";

                break;
            }

            usleep(1500000); // transient: try the same size again
        }

        if ($cap === null) {
            echo "      could not establish a working range size\n";

            continue;
        }

        echo '      accepted eth_getLogs range: ' . number_format($cap) . " blocks (head " . number_format($head) . ")\n";

        // 2. sequential sample at that size, no retry
        $ok = 0;
        $errors = [];
        $started = microtime(true);

        for ($i = 0; $i < $requests; $i++) {
            $from = $start + ($i + 1) * $cap;
            $result = $client->getLogs($from, $from + $cap - 1, $topics);

            if ($result instanceof EvmRpcError) {
                $key = $result->getKind() . ': ' . substr($result->getMessage(), 0, 70);
                $errors[$key] = ($errors[$key] ?? 0) + 1;

                continue;
            }

            $ok++;
        }

        $elapsed = microtime(true) - $started;

        echo "      sample: {$ok}/{$requests} ok in " . round($elapsed, 1) . ' s';

        foreach ($errors as $message => $count) {
            echo "\n        {$count}x {$message}";
        }

        echo "\n";

        if ($ok === 0) {
            echo "      no successful request: cannot estimate\n";

            continue;
        }

        // 3. the Phase 0 projection: seconds per SUCCESSFUL request include the time lost to failures
        $secondsPerOk = $elapsed / $ok;
        $firstActivity = FIRST_ACTIVITY[$chain];
        $blocksToScan = $firstActivity - EvmReconstructionChains::historyStartBlock($chain);
        $chunks = (int) ceil($blocksToScan / $cap);
        $estimate = $chunks * 2 * $secondsPerOk;

        echo '      ' . round($secondsPerOk, 2) . ' s per successful request (' . round(1 / max($secondsPerOk, 0.001), 2) . "/s)\n";
        echo '      PHASE 0 estimate, history start to first activity (' . number_format($blocksToScan) . ' blocks, ' . number_format($chunks) . ' chunks, '
            . number_format($chunks * 2) . ' requests): ~' . human($estimate) . "\n";
    }
}

echo "\n(one more rung of cost: each walked day then makes roughly 100 to 300 RPC calls per chain; bin/e2e-ladder.php rung 3 measures it)\n";
