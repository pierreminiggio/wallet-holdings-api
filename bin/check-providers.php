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
 *      genesis blocks proven by bin/check-4-snapshot.php), at two requests per chunk (both transfer directions);
 *   4. estimates the time to scan the logs of every day from that first activity to today (measuring the chain's blocks
 *      per day from its head), which is the part that dominates for a multi-year history.
 *   5. probes JSON-RPC BATCH support: how many archive eth_getBalance reads one HTTP request may carry, and how much faster that
 *      is than the same reads sent one by one. A day's state reads (Aave: dozens of balanceOf) are the dominant cost of a walk,
 *      and batching is the main lever on it; whether a provider accepts batches, and how many, must be measured, not assumed.
 * Log scanning is only one part of a day's cost: each walked day also makes roughly 100 to 300 other RPC calls per chain.
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

/**
 * One raw JSON-RPC batch: an array of requests in one HTTP POST. Returns [ok, seconds, message].
 *
 * @return array{0: bool, 1: float, 2: string}
 */
function sendBatch(string $url, int $size, int $head): array
{
    $payload = [];

    for ($i = 1; $i <= $size; $i++) {
        $payload[] = ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'eth_getBalance', 'params' => [WALLET, '0x' . dechex(max(1, $head - $i * 1000))]];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'wallet-holdings-api/1.0',
    ]);

    $started = microtime(true);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $seconds = microtime(true) - $started;

    if ($body === false || $body === '') {
        return [false, $seconds, "no response (HTTP {$status})"];
    }

    $decoded = json_decode($body, true);

    if (! is_array($decoded)) {
        return [false, $seconds, "HTTP {$status}, not JSON"];
    }

    // A refused batch usually comes back as ONE error object instead of an array.
    if (isset($decoded['error'])) {
        return [false, $seconds, 'HTTP ' . $status . ': ' . substr((string) ($decoded['error']['message'] ?? json_encode($decoded['error'])), 0, 90)];
    }

    $seen = [];
    $failed = 0;

    foreach ($decoded as $item) {
        if (! is_array($item) || ! isset($item['id'])) {
            return [false, $seconds, 'a batch entry has no id'];
        }

        $seen[$item['id']] = true;

        if (! isset($item['result']) || ! is_string($item['result'])) {
            $failed++;
        }
    }

    if (count($seen) !== $size) {
        return [false, $seconds, 'got ' . count($seen) . " answers for {$size} requests (dropped entries)"];
    }

    return $failed === 0 ? [true, $seconds, 'ok'] : [false, $seconds, "{$failed} of {$size} entries failed (" . substr(json_encode($decoded[0]), 0, 90) . ')'];
}

function probeBatch(string $url, EvmRpcClient $client, int $head): void
{
    // Sequential reference: the same kind of read, one request each.
    $started = microtime(true);
    $okSequential = 0;

    for ($i = 1; $i <= 5; $i++) {
        $balance = $client->getBalance(WALLET, max(1, $head - $i * 1000));
        $okSequential += $balance instanceof EvmRpcError ? 0 : 1;
    }

    $perCall = $okSequential > 0 ? (microtime(true) - $started) / $okSequential : null;

    $best = 0;
    $bestSeconds = 0.0;
    $lastFailure = null;

    foreach ([2, 10, 25, 50, 100] as $size) {
        [$ok, $seconds, $message] = sendBatch($url, $size, $head);

        if (! $ok) {
            $lastFailure = "batch of {$size}: {$message}";

            break;
        }

        $best = $size;
        $bestSeconds = $seconds;
    }

    if ($best === 0) {
        echo "      JSON-RPC batch: NOT usable ({$lastFailure})\n";

        return;
    }

    $speed = $perCall !== null && $bestSeconds > 0 ? ' = ' . round($best * $perCall / $bestSeconds, 1) . 'x faster than ' . $best . ' sequential reads (~' . round($best * $perCall, 1) . ' s)' : '';
    echo "      JSON-RPC batch: accepted up to {$best} per request in " . round($bestSeconds, 2) . " s{$speed}"
        . ($lastFailure !== null ? "; refused beyond: {$lastFailure}" : ' (larger sizes not tried)') . "\n";
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

        // A URL pasted under the wrong chain answers like a healthy provider: check what chain it really serves.
        $chainId = $client->chainId();
        $expectedChainId = EvmReconstructionChains::CHAIN_IDS[$chain];

        if ($chainId instanceof EvmRpcError) {
            echo "      chain id: could not be read ({$chainId})\n";
        } elseif ($chainId !== $expectedChainId) {
            echo "      *** WRONG CHAIN: this URL serves chain id {$chainId}, but {$chain} is chain id {$expectedChainId}. Fix config.php. ***\n";

            continue;
        } else {
            echo "      chain id {$chainId}: ok\n";
        }

        // 1. the largest accepted range
        $start = SAMPLE_START[$chain];
        $size = 50000;
        $cap = null;
        $attempts = 0;
        $lastCapError = null;

        while ($size >= 1 && $attempts < 14) {
            $attempts++;
            $result = $client->getLogs($start, $start + $size - 1, $topics);

            if (! ($result instanceof EvmRpcError)) {
                $cap = $size;

                break;
            }

            $lastCapError = $result;

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
            echo '      could not establish a working range size' . ($lastCapError !== null ? " (last error: {$lastCapError})" : '') . "\n";

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

        // 5. JSON-RPC batch probe (archive eth_getBalance at distinct old blocks, like a day's state reads)
        probeBatch($provider['url'], $client, $head);

        // 3. the Phase 0 projection: seconds per SUCCESSFUL request include the time lost to failures
        $secondsPerOk = $elapsed / $ok;
        $firstActivity = FIRST_ACTIVITY[$chain];
        $blocksToScan = $firstActivity - EvmReconstructionChains::historyStartBlock($chain);
        $chunks = (int) ceil($blocksToScan / $cap);
        $estimate = $chunks * 2 * $secondsPerOk;

        echo '      ' . round($secondsPerOk, 2) . ' s per successful request (' . round(1 / max($secondsPerOk, 0.001), 2) . "/s)\n";
        echo '      PHASE 0 estimate, history start to first activity (' . number_format($blocksToScan) . ' blocks, ' . number_format($chunks) . ' chunks, '
            . number_format($chunks * 2) . ' requests): ~' . human($estimate) . "\n";

        // 4. the daily log scan from the first activity to today
        $latest = $client->getBlockByNumber($head);
        $earlier = $client->getBlockByNumber(max(0, $head - 100000));
        $first = $client->getBlockByNumber($firstActivity);

        if ($latest instanceof EvmRpcError || $earlier instanceof EvmRpcError || $first instanceof EvmRpcError || $latest['timestamp'] <= $earlier['timestamp']) {
            echo "      daily scan estimate: could not read block timestamps (archive access needed)\n";

            continue;
        }

        $blocksPerDay = 86400 * 100000 / ($latest['timestamp'] - $earlier['timestamp']);
        $days = max(1, ($latest['timestamp'] - $first['timestamp']) / 86400);
        $requestsPerDay = (int) ceil($blocksPerDay / $cap) * 2;
        $dailyTotal = $days * $requestsPerDay * $secondsPerOk;

        echo '      DAILY log scan, first activity to today: ~' . number_format($blocksPerDay) . ' blocks/day, ' . number_format($requestsPerDay) . ' requests/day over '
            . round($days) . ' days = ' . number_format($days * $requestsPerDay) . ' requests: ~' . human($dailyTotal) . "\n";
        echo '      TOTAL log scanning for a full reconstruction: ~' . human($estimate + $dailyTotal) . "\n";
    }
}

echo "\n(one more rung of cost: each walked day then makes roughly 100 to 300 RPC calls per chain; bin/e2e-ladder.php rung 3 measures it)\n";
