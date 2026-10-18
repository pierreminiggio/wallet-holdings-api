<?php

/**
 * Delivery 3 acceptance check (MULTICHAIN-RECONSTRUCTION-PLAN.md, section 4): EvmLogScanner
 * against REAL RPCs, on small windows with known answers (section 5):
 *
 *   - Polygon blocks 93,167,662 -> 93,167,973 must yield exactly the 7 USDT0 transfers listed;
 *   - Polygon blocks 80,406,376 -> 80,745,873 (340k blocks, slow on a keyless provider: use --long)
 *     must yield exactly the 10 native-USDC transfers listed;
 *   - the same window scanned in two resumed halves gives the same logs (no hole at the boundary);
 *   - a wrong wallet does NOT produce those transfers (the check is discriminating);
 *   - Base blocks around the hardcoded skipped blocks complete without ever requesting them.
 *
 *   php bin/check-3-scan.php [--long] [--config=config.php] [--only=<id>] [--retries=6] [--rounds=4]
 *                            [--round-delay=5] [--pause=1] [--rpc-<chain>=<url>]
 *
 * Same providers and retry policy as bin/check-1-reads.php (all configured providers per chain, patient
 * backoff, an unreachable check is reported as INFRASTRUCTURE, never as a wrong value). The scanner is
 * resumable, so a provider error just resumes the same scan after a wait. Exit code 0 = all as expected.
 * Paste the whole output back for review.
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

use App\AbiCodec;
use App\EvmLogScanner;
use App\EvmLogScanResult;
use App\EvmLogScanState;
use App\EvmReconstructionConfig;
use App\EvmRpcClient;

$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? '1';
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

/** Records every eth_getLogs range requested, to prove skipped blocks are never asked for. */
final class RangeRecordingClient extends EvmRpcClient
{
    /** @var list<array{0: int, 1: int}> */
    public static array $ranges = [];

    public function request(string $method, array $params): mixed
    {
        if ($method === 'eth_getLogs') {
            self::$ranges[] = [(int) hexdec(substr($params[0]['fromBlock'], 2)), (int) hexdec(substr($params[0]['toBlock'], 2))];
        }

        return parent::request($method, $params);
    }
}

function providerClients(string $chain, array $options, EvmReconstructionConfig $reconstruction, int $retries): array
{
    $urls = isset($options['rpc-' . $chain]) ? [$options['rpc-' . $chain]] : array_column($reconstruction->providers($chain), 'url');

    return array_map(static fn (string $url): EvmRpcClient => new RangeRecordingClient($url, null, 60, $retries, 2000), $urls);
}

function norm(string $amount): string
{
    if (str_contains($amount, '.')) {
        $amount = rtrim(rtrim($amount, '0'), '.');
    }

    return $amount === '' || $amount === '-' ? '0' : $amount;
}

/**
 * Scans [$from, $to] for $address with the built-in patient policy: the scanner is resumable, so a
 * PROVIDER_ERROR is followed by a wait and a resume of the SAME state (never a restart).
 *
 * @return array{0: ?EvmLogScanState, 1: list<array>, 2: list<array>, 3: ?string} state, logs, gaps, infrastructure error
 */
function scanWindow(string $chain, array $clients, string $address, int $from, int $to, int $rounds, int $roundDelay, ?int $splitAt = null): array
{
    $logs = [];
    $gaps = [];
    $state = EvmLogScanState::startingAt($from);

    $scanner = new EvmLogScanner(
        $chain,
        $clients,
        EvmLogScanner::DEFAULT_MAX_STEP,
        20,
        null,
        static function (int $block, string $error) use (&$gaps): void {
            $gaps[] = ['block' => $block, 'error' => $error];
        },
        static function (array $chunkLogs) use (&$logs): void {
            foreach ($chunkLogs as $log) {
                $logs[] = $log;
            }
        }
    );

    $targets = $splitAt === null ? [$to] : [$splitAt, $to];

    foreach ($targets as $target) {
        for ($round = 0; ; $round++) {
            // Round-trip through the cursor representation, as the real service does between requests.
            $state = EvmLogScanState::fromArray($state->toArray());
            $result = $scanner->scan($address, $state, $target);
            $state = $result->getState();

            if ($result->isComplete()) {
                break;
            }

            if ($round >= $rounds - 1) {
                return [$state, $logs, $gaps, $result->getStatus() . ': ' . $result->getError()];
            }

            $delay = min(60, $roundDelay * (2 ** $round));
            echo "        .. {$result->getStatus()} ({$result->getError()}); resuming from block " . ($state->getLastScannedBlock() + 1) . " in {$delay}s (round " . ($round + 1) . "/{$rounds})\n";
            sleep($delay);
        }
    }

    // A log can legitimately be seen twice when a scan is resumed: dedupe by block/tx/index.
    $unique = [];

    foreach ($logs as $log) {
        $unique[($log['blockNumber'] ?? '') . '|' . ($log['transactionHash'] ?? '') . '|' . ($log['logIndex'] ?? '')] = $log;
    }

    return [$state, array_values($unique), $gaps, null];
}

/**
 * Signed transfers of one token touching $address: "block|+amount" (received) or "block|-amount" (sent).
 *
 * @return list<string> sorted
 */
function signedTransfers(array $logs, string $token, string $address, int $decimals): array
{
    $addrTopic = '0x' . str_repeat('0', 24) . strtolower(substr($address, 2));
    $out = [];

    foreach ($logs as $log) {
        if (strtolower($log['address']) !== strtolower($token)) {
            continue;
        }

        $amount = AbiCodec::formatUnits(AbiCodec::hexToDec(AbiCodec::strip0x($log['data'])), $decimals);
        $sign = strtolower($log['topics'][2] ?? '') === $addrTopic ? '+' : '-';
        $out[] = hexdec(substr($log['blockNumber'], 2)) . '|' . $sign . norm($amount);
    }

    sort($out);

    return $out;
}

function expectedTransfers(array $pairs): array
{
    $out = array_map(static fn (array $p): string => $p[0] . '|' . ($p[1] < 0 ? '-' : '+') . norm(number_format(abs($p[1]), 6, '.', '')), $pairs);
    sort($out);

    return $out;
}

const USDT0 = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';
const NATIVE_USDC = '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359';

$usdt0Expected = expectedTransfers([
    [93167662, 75.598753], [93167710, 0.120354], [93167732, -75.679396], [93167886, 345.052550],
    [93167919, 0.801676], [93167972, -345.761329], [93167973, 0.000345],
]);

$usdcExpected = expectedTransfers([
    [80406376, -17.990703], [80511190, 53.709343], [80511229, -54.611680], [80512567, 160.964707],
    [80512690, -161.064707], [80636033, 235.168996], [80636107, -175.168996], [80636216, -58.955012],
    [80745841, 60.001012], [80745873, -61.046000],
]);

$only = $options['only'] ?? null;
$failures = 0;

$report = static function (string $id, array $problems, ?string $infra, array $gaps, string $detail) use (&$failures): void {
    if ($infra !== null) {
        $failures++;
        echo "FAIL  {$id}\n        INFRASTRUCTURE failure (the scan could not finish): this is not a wrong value: {$infra}\n";

        return;
    }

    if ($gaps !== []) {
        $problems[] = 'unresolved gaps recorded, the window is NOT fully covered: ' . json_encode($gaps);
    }

    if ($problems === []) {
        echo "PASS  {$id}: {$detail}\n";

        return;
    }

    $failures++;
    echo "FAIL  {$id}\n";

    foreach ($problems as $problem) {
        echo "        - {$problem}\n";
    }
};

$wanted = static fn (string $id): bool => $only === null || $only === $id;

// ---- Polygon USDT0, 7 transfers -------------------------------------------------------------------
if ($wanted('polygon-usdt0-7-transfers')) {
    sleep($pauseSeconds);
    [$state, $logs, $gaps, $infra] = scanWindow('polygon', providerClients('polygon', $options, $reconstruction, $retries), $wallet, 93167662, 93167973, $rounds, $roundDelay);
    $actual = signedTransfers($logs, USDT0, $wallet, 6);
    $problems = $infra === null && $actual !== $usdt0Expected
        ? ['USDT0 transfers differ', '  expected: ' . implode(' ', $usdt0Expected), '  actual:   ' . implode(' ', $actual)]
        : [];

    if ($infra === null && ! in_array(USDT0, $state->tokens(), true)) {
        $problems[] = 'USDT0 was not discovered as a token';
    }

    $report('polygon-usdt0-7-transfers', $problems, $infra, $gaps, count($actual) . ' USDT0 transfers, tokens found: ' . count($state->tokens()));

    // Resume in the middle (block 93,167,800): same logs, no hole.
    if ($infra === null && $wanted('polygon-usdt0-resume')) {
        sleep($pauseSeconds);
        [$stateR, $logsR, $gapsR, $infraR] = scanWindow('polygon', providerClients('polygon', $options, $reconstruction, $retries), $wallet, 93167662, 93167973, $rounds, $roundDelay, 93167800);
        $resumed = signedTransfers($logsR, USDT0, $wallet, 6);
        $report(
            'polygon-usdt0-resume',
            $infraR === null && $resumed !== $usdt0Expected ? ['resumed scan differs: ' . implode(' ', $resumed)] : [],
            $infraR,
            $gapsR,
            'two halves with the state round-tripped in between give the same 7 transfers'
        );
    }

    // Negative control: another wallet must NOT produce these transfers.
    sleep($pauseSeconds);
    [$stateW, $logsW, $gapsW, $infraW] = scanWindow('polygon', providerClients('polygon', $options, $reconstruction, $retries), $wrongWallet, 93167662, 93167973, $rounds, $roundDelay);
    $wrong = signedTransfers($logsW, USDT0, $wrongWallet, 6);

    if ($infraW !== null) {
        echo "FAIL  negative-control-wrong-wallet\n        INFRASTRUCTURE failure: {$infraW}\n";
        $failures++;
    } elseif ($wrong === $usdt0Expected) {
        echo "FAIL  negative-control-wrong-wallet: the wrong wallet also 'matches', so the check is not discriminating\n";
        $failures++;
    } else {
        echo 'PASS  negative-control-wrong-wallet: ' . count($wrong) . " USDT0 transfers for another address (must differ from the 7)\n";
    }
}

// ---- Base skipped blocks -----------------------------------------------------------------------------
if ($wanted('base-skipped-blocks-never-requested')) {
    sleep($pauseSeconds);
    RangeRecordingClient::$ranges = [];
    [$state, $logs, $gaps, $infra] = scanWindow('base', providerClients('base', $options, $reconstruction, $retries), $wallet, 44661790, 44661850, $rounds, $roundDelay);
    $problems = [];

    foreach (RangeRecordingClient::$ranges as [$from, $to]) {
        if ($from <= 44661820 && $to >= 44661819) {
            $problems[] = "a request {$from}-{$to} included a skipped block";
        }
    }

    if ($infra === null && $state->getLastScannedBlock() !== 44661850) {
        $problems[] = 'the scan did not reach the end of the window';
    }

    $report('base-skipped-blocks-never-requested', array_slice($problems, 0, 5), $infra, $gaps, count(RangeRecordingClient::$ranges) . ' requests, none touching blocks 44661819-44661820');
}

// ---- Polygon native USDC, 10 transfers (long) ---------------------------------------------------------
if (isset($options['long']) || $only === 'polygon-native-usdc-10-transfers') {
    sleep($pauseSeconds);
    echo "        (long check: 340k Polygon blocks, expect many minutes on a keyless provider)\n";
    [$state, $logs, $gaps, $infra] = scanWindow('polygon', providerClients('polygon', $options, $reconstruction, $retries), $wallet, 80406376, 80745873, $rounds, $roundDelay);
    $actual = signedTransfers($logs, NATIVE_USDC, $wallet, 6);
    $problems = $infra === null && $actual !== $usdcExpected
        ? ['native USDC transfers differ', '  expected: ' . implode(' ', $usdcExpected), '  actual:   ' . implode(' ', $actual)]
        : [];

    $report('polygon-native-usdc-10-transfers', $problems, $infra, $gaps, count($actual) . ' native USDC transfers, tokens found: ' . count($state->tokens()));
} elseif ($only === null) {
    echo "SKIP  polygon-native-usdc-10-transfers (340k blocks, slow): run with --long\n";
}

echo $failures === 0 ? "\nALL OK\n" : "\n{$failures} check(s) failed\n";

exit($failures === 0 ? 0 : 1);
