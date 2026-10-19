<?php

/**
 * Delivery 5 acceptance check (MULTICHAIN-RECONSTRUCTION-PLAN.md, section 4): the reconstruction service
 * against REAL RPCs, on Polygon only (history starts at the wallet's native genesis, block 80,406,107 =
 * 2025-12-16 22:18 UTC, so a short walk is enough), with IN-MEMORY repositories: no database is needed and
 * nothing is written anywhere.
 *
 *   php bin/check-5-service.php [--long] [--config=config.php] [--retries=6] [--rounds=4] [--round-delay=5]
 *                               [--rpc-polygon=<url>]
 *
 * What it proves:
 *   1. a first request for 2025-12-16 (the genesis day) writes exactly one reconstructed row for that day,
 *      with the native balance and the native USDC balance equal to values computed independently
 *      (balance read straight from the chain; known transfers of section 5);
 *   2. a later request (2025-12-17, or 2025-12-19 with --long) resumes from the cursor: the log requests it
 *      makes all start after what the first request had scanned (no re-scan), the first day's row is unchanged,
 *      and each day has exactly one row;
 *   3. re-asking an already walked date is a pure read (no log request at all);
 *   4. cursors never advanced without their rows;
 *   5. a wallet with no activity is BEFORE_GENESIS and writes nothing.
 *
 * The walk scans every Polygon block from the history start with drpc.org's ~101-block cap, so the default run
 * (two days, ~46k blocks) takes several minutes and --long (four days, which covers more of the section 5
 * native-USDC transfers) takes longer. A provider error just resumes the same walk after a patient wait.
 * Exit code 0 = all as expected. Paste the whole output back for review.
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

require $root . '/tests/support/memory_repositories.php';

use App\AbiCodec;
use App\EvmReconstructionConfig;
use App\EvmRpcClient;
use App\MultichainHoldingsReconstructionService;
use App\MultichainReconstructionOutcome as Outcome;
use Tests\MemoryBlockRepository;
use Tests\MemoryCacheRepository;
use Tests\MemoryCursorRepository;
use Tests\MemoryUnresolvedRepository;

$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}

$configFile = $options['config'] ?? $root . '/config.php';
$rawConfig = is_file($configFile) ? require $configFile : [];

if (isset($options['rpc-polygon'])) {
    $rawConfig['reconstruction']['rpc']['polygon'] = [$options['rpc-polygon']];
}

$config = new EvmReconstructionConfig($rawConfig);

$retries = (int) ($options['retries'] ?? 6);
$rounds = max(1, (int) ($options['rounds'] ?? 4));
$roundDelay = (int) ($options['round-delay'] ?? 5);

$wallet = $options['wallet'] ?? '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';
$idleWallet = '0x0000000000000000000000000000000000001234';

const NATIVE_USDC = '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359';
const USDC_BEFORE_HISTORY_START = '18.99304'; // balance at block 80,406,106, section 5

/** Signed native-USDC transfers of section 5, plus the zero-net pair found by the scanner (see check 3). */
const USDC_TRANSFERS = [
    [80406376, '-17.990703'], [80511190, '53.709343'], [80511229, '-54.61168'], [80512567, '160.964707'],
    [80512690, '-161.064707'], [80526467, '243.550038'], [80526491, '-243.550038'], [80636033, '235.168996'],
    [80636107, '-175.168996'], [80636216, '-58.955012'], [80745841, '60.001012'], [80745873, '-61.046'],
];

/** Records every eth_getLogs range requested, to prove a resume does not re-scan. */
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

/** Decimal-string arithmetic on 6-decimal amounts, in integer micro-units (no bcmath, no float). */
function micro(string $amount): int
{
    $negative = str_starts_with($amount, '-');
    $amount = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    $value = (int) $whole * 1000000 + (int) str_pad(substr($fraction, 0, 6), 6, '0');

    return $negative ? -$value : $value;
}

function fromMicro(int $value): string
{
    $negative = $value < 0;
    $value = abs($value);
    $text = intdiv($value, 1000000) . '.' . str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    $text = rtrim(rtrim($text, '0'), '.');

    return ($negative ? '-' : '') . $text;
}

function expectedUsdc(int $endBlock): string
{
    $total = micro(USDC_BEFORE_HISTORY_START);

    foreach (USDC_TRANSFERS as [$block, $amount]) {
        if ($block <= $endBlock) {
            $total += micro($amount);
        }
    }

    return fromMicro($total);
}

function norm(?string $amount): ?string
{
    if ($amount === null) {
        return null;
    }

    return str_contains($amount, '.') ? (rtrim(rtrim($amount, '0'), '.') ?: '0') : $amount;
}

$cache = new MemoryCacheRepository();
$cursors = new MemoryCursorRepository($cache);
$blocks = new MemoryBlockRepository();
$unresolved = new MemoryUnresolvedRepository();

$factory = static fn (string $url): EvmRpcClient => new RangeRecordingClient($url, null, 60, $retries, 2000);

$service = new MultichainHoldingsReconstructionService($config, $cache, $cursors, $blocks, $unresolved, $factory, ['polygon']);

/** Runs reconstruct() with the built-in patience: a provider error resumes the SAME walk after a wait. */
function run(MultichainHoldingsReconstructionService $service, string $wallet, string $date, int $rounds, int $roundDelay): Outcome
{
    $started = time();

    for ($round = 0; ; $round++) {
        $outcome = $service->reconstruct($wallet, $date);

        if ($outcome->getStatus() !== Outcome::STATUS_PROVIDER_ERROR || $round >= $rounds - 1) {
            echo '        .. ' . $outcome->getStatus() . ' for ' . $date . ' after ' . (time() - $started) . "s\n";

            return $outcome;
        }

        $delay = min(60, $roundDelay * (2 ** $round));
        echo "        .. provider_error ({$outcome->getMessage()}); walked through " . ($outcome->getWalkedThrough() ?? 'nothing') . "; resuming in {$delay}s (round " . ($round + 1) . "/{$rounds})\n";
        sleep($delay);
    }
}

$failures = 0;
$check = static function (string $id, array $problems) use (&$failures): void {
    if ($problems === []) {
        echo "PASS  {$id}\n";

        return;
    }

    $failures++;
    echo "FAIL  {$id}\n";

    foreach ($problems as $problem) {
        echo "        - {$problem}\n";
    }
};

$day1 = '2025-12-16';
$day2 = isset($options['long']) ? '2025-12-19' : '2025-12-17';

echo "wallet {$wallet}, Polygon only, first request {$day1}, second request {$day2}\n\n";

// ---- 1. first request: the genesis day ------------------------------------------------------------------
echo "first request for {$day1} (Phase 0, then one day)...\n";
$first = run($service, $wallet, $day1, $rounds, $roundDelay);
$problems = [];

if ($first->getStatus() !== Outcome::STATUS_FOUND) {
    $problems[] = 'expected found, got ' . $first->getStatus() . ': ' . $first->getMessage();
} else {
    $row = json_decode($first->getResponseJson(), true);
    $polygon = $row['holdings']['polygon'] ?? null;
    $endBlock = $blocks->rows["polygon|{$day1}"] ?? null;

    if ($cache->datesOf('reconstructed') !== [$day1]) {
        $problems[] = 'expected exactly one reconstructed row for ' . $day1 . ', got ' . json_encode($cache->datesOf('reconstructed'));
    }

    if ($polygon === null || $endBlock === null) {
        $problems[] = 'no Polygon holdings in the row, or the end block was not resolved';
    } else {
        $independent = (new EvmRpcClient($config->providers('polygon')[0]['url'], null, 60, $retries, 2000))->getBalance($wallet, $endBlock);

        if ($independent instanceof App\EvmRpcError) {
            $problems[] = 'could not read the native balance independently: ' . $independent;
        } else {
            $expectedNative = AbiCodec::formatUnits(AbiCodec::hexToDec(AbiCodec::strip0x($independent)), 18);

            if (norm($polygon['native']['amount'] ?? null) !== norm($expectedNative)) {
                $problems[] = "native POL {$polygon['native']['amount']} != {$expectedNative} read directly at block {$endBlock}";
            }
        }

        $usdc = null;

        foreach ($polygon['tokens'] as $token) {
            if (strtolower($token['contract']) === NATIVE_USDC) {
                $usdc = norm($token['amount']);
            }
        }

        $expected = expectedUsdc($endBlock);

        if ($usdc !== ($expected === '0' ? null : $expected)) {
            $problems[] = 'native USDC ' . ($usdc ?? 'absent') . " != {$expected} (18.99304 plus the known transfers up to block {$endBlock})";
        }

        if (($row['holdings']['avalanche']['reconstruction_supported'] ?? null) !== false) {
            $problems[] = 'unsupported chains are not flagged in the row';
        }

        echo "        end block of {$day1}: {$endBlock}, POL {$polygon['native']['amount']}, tokens: " . count($polygon['tokens']) . ', first activity block ' . ($cursors->find($wallet, 'polygon')['firstActivityBlock'] ?? 'none') . "\n";
    }
}

$check('first-request-genesis-day', $problems);

$scannedThroughFirst = $cursors->find($wallet, 'polygon')['state']->getLastScannedBlock() ?? -1;
$firstRow = $cache->getCacheForDate($wallet, $day1)['responseJson'] ?? null;

// ---- 2. second request resumes from the cursor -----------------------------------------------------------------
echo "\nsecond request for {$day2} (resume)...\n";
RangeRecordingClient::$ranges = [];
$second = run($service, $wallet, $day2, $rounds, $roundDelay);
$problems = [];

if ($second->getStatus() !== Outcome::STATUS_FOUND) {
    $problems[] = 'expected found, got ' . $second->getStatus() . ': ' . $second->getMessage();
} else {
    $expectedDays = [];

    for ($d = $day1; $d <= $day2; $d = gmdate('Y-m-d', strtotime($d . ' +1 day'))) {
        $expectedDays[] = $d;
    }

    if ($cache->datesOf('reconstructed') !== $expectedDays) {
        $problems[] = 'expected exactly one row per day ' . json_encode($expectedDays) . ', got ' . json_encode($cache->datesOf('reconstructed'));
    }

    foreach (RangeRecordingClient::$ranges as [$from, $to]) {
        if ($from <= $scannedThroughFirst) {
            $problems[] = "log request {$from}-{$to} re-scans blocks the first request already covered (through {$scannedThroughFirst})";

            break;
        }
    }

    if (($cache->getCacheForDate($wallet, $day1)['responseJson'] ?? null) !== $firstRow) {
        $problems[] = "the row for {$day1} changed";
    }

    $row2 = json_decode($second->getResponseJson(), true);
    $end2 = $blocks->rows["polygon|{$day2}"] ?? null;
    $usdc2 = null;

    foreach ($row2['holdings']['polygon']['tokens'] ?? [] as $token) {
        if (strtolower($token['contract']) === NATIVE_USDC) {
            $usdc2 = norm($token['amount']);
        }
    }

    $expected2 = $end2 === null ? null : expectedUsdc($end2);

    if ($end2 === null || $usdc2 !== ($expected2 === '0' ? null : $expected2)) {
        $problems[] = 'native USDC on ' . $day2 . ' = ' . ($usdc2 ?? 'absent') . ", expected {$expected2} (end block {$end2})";
    }

    echo "        end block of {$day2}: {$end2}, native USDC " . ($usdc2 ?? 'absent') . ', log requests in this resume: ' . count(RangeRecordingClient::$ranges) . "\n";
}

$check('second-request-resumes-from-cursor', $problems);

// ---- 3. an already walked date is a pure read ---------------------------------------------------------------------
RangeRecordingClient::$ranges = [];
$rowsBefore = count($cache->rows);
$again = run($service, $wallet, $day1, $rounds, $roundDelay);
$check('already-walked-date-is-a-pure-read', array_filter([
    $again->getStatus() !== Outcome::STATUS_FOUND ? 'expected found, got ' . $again->getStatus() : null,
    RangeRecordingClient::$ranges !== [] ? count(RangeRecordingClient::$ranges) . ' log requests were made' : null,
    count($cache->rows) !== $rowsBefore ? 'a row was written' : null,
]));

// ---- 4. invariants ----------------------------------------------------------------------------------------------
$check('cursor-never-ahead-of-rows', $cursors->violations);
$check('no-unresolved-blocks-on-this-window', array_map(static fn (array $u): string => json_encode($u), $unresolved->recorded));

// ---- 5. a wallet with no activity ---------------------------------------------------------------------------------
echo "\nrequest for a wallet with no activity...\n";
$rowsBefore = count($cache->rows);
$idle = run($service, $idleWallet, $day1, $rounds, $roundDelay);
$check('idle-wallet-is-before-genesis', array_filter([
    $idle->getStatus() !== Outcome::STATUS_BEFORE_GENESIS ? 'expected before_genesis, got ' . $idle->getStatus() . ': ' . $idle->getMessage() : null,
    count($cache->rows) !== $rowsBefore ? 'rows were written for a wallet with no activity' : null,
]));

echo $failures === 0 ? "\nALL OK\n" : "\n{$failures} check(s) failed\n";

exit($failures === 0 ? 0 : 1);
