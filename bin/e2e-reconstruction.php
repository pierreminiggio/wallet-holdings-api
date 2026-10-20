<?php

/**
 * End-to-end check of the multichain historical reconstruction (MULTICHAIN-RECONSTRUCTION-PLAN.md,
 * delivery 6), on YOUR database and YOUR providers, with a real wallet. Unlike the bin/check-N scripts it
 * writes real rows to multichain_holdings_cache and the cursor tables (it uses config.php's database).
 *
 *   php bin/e2e-reconstruction.php --date=2026-09-30 [--wallet=0x...] [--interrupt-after=600] [--interrupts=1]
 *                                  [--expect=before_genesis] [--config=config.php] [--skip-checks]
 *
 * What it does:
 *   1. runs the reconstruction service for --date from whatever state the database is in (an empty cursor for
 *      a fresh wallet: that is the test of the slow first segment). Same code path as GET /holdings?date=.
 *   2. with --interrupt-after=SECONDS, first runs the service in a child process and KILLS it after that many
 *      seconds, --interrupts times (default 1), to prove that a cut-off request resumes from the cursor with no
 *      hole and no duplicate row; the final run is not interrupted.
 *   3. checks the stored rows against the section 5 reference values (only those at or before --date),
 *      the live row of 2026-07-19 against its reconstructed neighbours (when all three exist), and the integrity
 *      of the database state: one row per day with no gap, no duplicate reconstructed row, cursors at the date.
 *
 * ALWAYS RUN IT FOR ALL FOUR CHAINS: the rows it writes are real rows of your cache, and an existing row is never
 * overwritten, so a run restricted to some chains would leave days permanently incomplete. Climb with growing
 * dates instead (a later date resumes from the cursor): see the suggested ladder in the delivery 6 reply / README.
 * --expect=before_genesis asserts the 400 case (a date before the wallet's first activity) instead of a walk.
 *
 * It can run for HOURS on a fresh wallet with keyless providers (that is the cost of the feature, not a bug);
 * progress is printed. Re-running it is always safe and resumes. Exit code 0 = everything as expected.
 * Paste the whole output back for review.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found: run `composer install` first (this script needs the database classes).\n");
    exit(2);
}

require $autoload;

use App\EvmBlockByDateRepository;
use App\EvmReconstructionChains;
use App\EvmReconstructionConfig;
use App\EvmUnresolvedBlockRepository;
use App\HoldingsNowCacheRepository;
use App\MultichainHoldingsReconstructionService;
use App\MultichainReconstructionCursorRepository;
use App\MultichainReconstructionOutcome as Outcome;
use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}

$date = $options['date'] ?? null;
$wallet = $options['wallet'] ?? '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';

if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date >= gmdate('Y-m-d')) {
    fwrite(STDERR, "Usage: php bin/e2e-reconstruction.php --date=YYYY-MM-DD (a PAST date) [--wallet=0x...] [--interrupt-after=SECONDS]\n");
    exit(2);
}

$configFile = $options['config'] ?? $root . '/config.php';

if (! is_file($configFile)) {
    fwrite(STDERR, "config file not found: {$configFile}\n");
    exit(2);
}

$rawConfig = require $configFile;
$db = $rawConfig['db'];
$fetcher = new DatabaseFetcher(new DatabaseConnection($db['host'], $db['database'], $db['username'], $db['password']));

$cache = new HoldingsNowCacheRepository($fetcher);
$cursors = new MultichainReconstructionCursorRepository($fetcher);
$unresolved = new EvmUnresolvedBlockRepository($fetcher);

$service = new MultichainHoldingsReconstructionService(
    new EvmReconstructionConfig($rawConfig),
    $cache,
    $cursors,
    new EvmBlockByDateRepository($fetcher),
    $unresolved
);

/** Runs the service once, printing the outcome. */
function runOnce(MultichainHoldingsReconstructionService $service, string $wallet, string $date): Outcome
{
    $started = time();
    $outcome = $service->reconstruct($wallet, $date);
    echo '  outcome: ' . $outcome->getStatus() . ' after ' . gmdate('H:i:s', time() - $started)
        . ($outcome->getMessage() !== null ? ' - ' . $outcome->getMessage() : '')
        . ($outcome->getWalkedThrough() !== null ? ' (walked through ' . $outcome->getWalkedThrough() . ')' : '') . "\n";

    return $outcome;
}

// ---- child mode: just run once (the parent kills it to simulate a cut-off request) -------------------------------
if (isset($options['child'])) {
    runOnce($service, $wallet, $date);
    exit(0);
}

echo "wallet {$wallet}, target date {$date}\n";

// ---- 1. optional interruptions ----------------------------------------------------------------------------------------
$interruptAfter = (int) ($options['interrupt-after'] ?? 0);
$interrupts = max(1, (int) ($options['interrupts'] ?? 1));

if ($interruptAfter > 0) {
    for ($i = 1; $i <= $interrupts; $i++) {
        echo "\ninterrupted run {$i}/{$interrupts}: the service is killed after {$interruptAfter}s (a cut-off request)...\n";
        $command = [PHP_BINARY, __FILE__, '--child', '--date=' . $date, '--wallet=' . $wallet, '--config=' . $configFile];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes);

        if (! is_resource($process)) {
            fwrite(STDERR, "could not start the child process\n");
            exit(2);
        }

        $deadline = time() + $interruptAfter;

        while (time() < $deadline && (proc_get_status($process)['running'] ?? false)) {
            sleep(1);
        }

        if (proc_get_status($process)['running'] ?? false) {
            proc_terminate($process);
            echo "  killed after {$interruptAfter}s\n";
        } else {
            echo "  the child finished by itself before the deadline (nothing left to interrupt)\n";
        }

        proc_close($process);
        $cursor = $cursors->find($wallet, 'ethereum');
        echo '  cursor after the interruption (ethereum): date ' . ($cursor['cursorDate'] ?? 'none') . ', last scanned block ' . (($cursor['state'] ?? null)?->getLastScannedBlock() ?? 'none') . "\n";
    }
}

// ---- 2. the real run, resuming until found ---------------------------------------------------------------------------
echo "\nfinal run (resumes from the cursor)...\n";
$maxRounds = (int) ($options['rounds'] ?? 20);
$outcome = null;

for ($round = 1; $round <= $maxRounds; $round++) {
    $outcome = runOnce($service, $wallet, $date);

    if ($outcome->getStatus() === Outcome::STATUS_FOUND || $outcome->getStatus() === Outcome::STATUS_BEFORE_GENESIS) {
        break;
    }

    if ($outcome->getStatus() === Outcome::STATUS_BUSY) {
        echo '  busy: waiting ' . $outcome->getRetryAfterSeconds() . "s\n";
        sleep((int) $outcome->getRetryAfterSeconds());

        continue;
    }

    if ($outcome->getStatus() !== Outcome::STATUS_PROVIDER_ERROR) {
        break; // storage_error / inconsistent: retrying will not help
    }

    if (str_contains((string) $outcome->getMessage(), 'No archive RPC provider')) {
        break; // a configuration problem (BSC needs a NodeReal URL), retrying will not help
    }

    $delay = min(120, 10 * $round);
    echo "  provider_error: retrying (resumes from the cursor) in {$delay}s, round {$round}/{$maxRounds}\n";
    sleep($delay);
}

if (($options['expect'] ?? null) === 'before_genesis') {
    $ok = $outcome !== null && $outcome->getStatus() === Outcome::STATUS_BEFORE_GENESIS;
    echo ($ok ? "PASS" : "FAIL") . "  expected before_genesis, got " . ($outcome?->getStatus() ?? 'no outcome') . "\n";
    echo "  rows written for this wallet by this run: " . count($fetcher->query($fetcher->createQuery('multichain_holdings_cache')->select('as_of_date')->where('address = :address AND source = :source'), ['address' => strtolower($wallet), 'source' => 'reconstructed'])) . " reconstructed row(s) in total\n";
    exit($ok ? 0 : 1);
}

if ($outcome === null || $outcome->getStatus() !== Outcome::STATUS_FOUND) {
    echo "\nFAIL  the reconstruction did not complete: " . ($outcome?->getStatus() ?? 'no outcome') . "\n";
    exit(1);
}

if (isset($options['skip-checks'])) {
    echo "\nDONE (checks skipped)\n";
    exit(0);
}

// ---- 3. checks ---------------------------------------------------------------------------------------------------------
$failures = 0;
$report = static function (string $id, array $problems) use (&$failures): void {
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

function norm(?string $amount): ?string
{
    if ($amount === null) {
        return null;
    }

    return str_contains($amount, '.') ? (rtrim(rtrim($amount, '0'), '.') ?: '0') : $amount;
}

function holdingsOf(HoldingsNowCacheRepository $cache, string $wallet, string $date): ?array
{
    $row = $cache->getCacheForDate($wallet, $date);

    return $row === null ? null : (json_decode($row['responseJson'], true)['holdings'] ?? []);
}

function reserve(?array $chain, string $symbol): ?array
{
    foreach ($chain['defi']['aave']['reserves'] ?? [] as $reserve) {
        if ($reserve['symbol'] === $symbol) {
            return $reserve;
        }
    }

    return null;
}

function tokenAmount(?array $chain, string $contract): ?string
{
    foreach ($chain['tokens'] ?? [] as $token) {
        if (strtolower($token['contract']) === strtolower($contract)) {
            return norm($token['amount']);
        }
    }

    return null;
}

echo "\nchecks\n";

// Section 5 reference values, as END-OF-DAY rows (the plan's reference blocks are the last block before D 00:00 UTC,
// i.e. the end of D-1).
$references = [
    '2025-10-31' => static function (array $h): array {
        $compound = $h['ethereum']['defi']['compound'][0] ?? null;
        $wbnb = reserve($h['binance-smart-chain'] ?? null, 'WBNB');
        $usdc = reserve($h['binance-smart-chain'] ?? null, 'USDC');

        return array_filter([
            norm($compound['borrowed'] ?? null) !== '933.435002' ? 'Ethereum Compound borrowed != 933.435002' : null,
            norm($compound['collateral'][0]['amount'] ?? null) !== '0.4' ? 'Ethereum Compound WETH collateral != 0.4' : null,
            norm($wbnb['supplied'] ?? null) !== '0.170012507196328142' ? 'BSC Aave WBNB supplied != 0.170012507196328142' : null,
            norm($usdc['variableDebt'] ?? null) !== '60.118707451980723546' ? 'BSC Aave USDC debt != 60.118707451980723546' : null,
            ($h['binance-smart-chain']['defi']['aave']['summary']['healthFactor'] ?? null) !== '2.309450654857429632' ? 'BSC Aave health factor != 2.309450654857429632' : null,
        ]);
    },
    '2025-11-30' => static function (array $h): array {
        $compound = $h['ethereum']['defi']['compound'][0] ?? null;
        $weth = reserve($h['ethereum'] ?? null, 'WETH');

        return array_filter([
            norm($compound['borrowed'] ?? null) !== '937.67524' ? 'Ethereum Compound borrowed != 937.67524' : null,
            norm($compound['collateral'][0]['amount'] ?? null) !== '0.476818727900527526' ? 'Ethereum Compound WETH collateral != 0.476818727900527526' : null,
            norm($weth['supplied'] ?? null) !== '0.028014789715922191' ? 'Ethereum Aave WETH supplied != 0.028014789715922191' : null,
        ]);
    },
    '2025-12-31' => static function (array $h): array {
        $compound = $h['ethereum']['defi']['compound'][0] ?? null;

        return array_filter([
            norm($compound['borrowed'] ?? null) !== '0' ? 'Ethereum Compound borrowed != 0' : null,
            norm($compound['collateral'][0]['amount'] ?? null) !== '0.000000000057157522' ? 'Ethereum Compound WETH collateral != 0.000000000057157522' : null,
        ]);
    },
    '2026-02-14' => static function (array $h): array {
        $wbtc = reserve($h['polygon'] ?? null, 'WBTC');
        $usdt0 = reserve($h['polygon'] ?? null, 'USDT0');
        $usdc = reserve($h['polygon'] ?? null, 'USDC');

        return array_filter([
            norm($wbtc['supplied'] ?? null) !== '0.0035271' ? 'Polygon Aave WBTC supplied != 0.0035271' : null,
            norm($usdt0['variableDebt'] ?? null) !== '73.179844' ? 'Polygon Aave USDT0 debt != 73.179844' : null,
            norm($usdc['variableDebt'] ?? null) !== '41.790999' ? 'Polygon Aave USDC debt != 41.790999' : null,
            ($h['polygon']['defi']['aave']['summary']['healthFactor'] ?? null) !== '1.669568345605074184' ? 'Polygon Aave health factor != 1.669568345605074184' : null,
        ]);
    },
    // Aave all zero (the wallet DOES have Compound positions on Polygon, which is not asserted). The USDT0 residual
    // 0.132953 is the balance read at the head when the plan was written: it differs only if the wallet moved it since.
    '2026-09-30' => static function (array $h): array {
        return array_filter([
            ($h['binance-smart-chain']['defi']['aave'] ?? null) !== null ? 'BSC Aave should be zero' : null,
            ($h['polygon']['defi']['aave'] ?? null) !== null ? 'Polygon Aave should be zero' : null,
            tokenAmount($h['polygon'] ?? null, '0xc2132d05d31c914a87c6611c10748aeb04b58e8f') !== '0.132953' ? 'Polygon USDT0 residual != 0.132953 (legitimate only if the wallet moved it since)' : null,
        ]);
    },
];

foreach ($references as $referenceDate => $assert) {
    if ($referenceDate > $date) {
        echo "SKIP  reference {$referenceDate} (after the requested date {$date})\n";

        continue;
    }

    $holdings = holdingsOf($cache, $wallet, $referenceDate);
    $report("reference-{$referenceDate}", $holdings === null ? ["no row for {$referenceDate}: the walk should have written one"] : array_values($assert($holdings)));
}

// Polygon native USDC, day by day: 18.99304 held before the scan start, then the 12 known transfers (section 5 + the zero-net
// pair the scanner found). The expected balance at each day's end block is computed independently of the row.
$usdcTransfers = [
    [80406376, '-17.990703'], [80511190, '53.709343'], [80511229, '-54.61168'], [80512567, '160.964707'],
    [80512690, '-161.064707'], [80526467, '243.550038'], [80526491, '-243.550038'], [80636033, '235.168996'],
    [80636107, '-175.168996'], [80636216, '-58.955012'], [80745841, '60.001012'], [80745873, '-61.046'],
];
$micro = static function (string $amount): int {
    $negative = str_starts_with($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
    $value = (int) $whole * 1000000 + (int) str_pad(substr($fraction, 0, 6), 6, '0');

    return $negative ? -$value : $value;
};
$fromMicro = static function (int $value): string {
    $text = intdiv(abs($value), 1000000) . '.' . str_pad((string) (abs($value) % 1000000), 6, '0', STR_PAD_LEFT);

    return ($value < 0 ? '-' : '') . (rtrim(rtrim($text, '0'), '.') ?: '0');
};

if ($date < '2025-12-16') {
    echo "SKIP  polygon-native-usdc-day-by-day (the requested date is before Polygon's scan start, 2025-12-16)\n";
} else {
    $blockRepository = new EvmBlockByDateRepository($fetcher);
    $problems = [];

    for ($d = '2025-12-16'; $d <= min($date, '2026-01-05'); $d = gmdate('Y-m-d', strtotime($d . ' +1 day'))) {
        $endBlock = $blockRepository->find('polygon', $d);
        $holdings = holdingsOf($cache, $wallet, $d);

        if ($endBlock === null || $holdings === null) {
            $problems[] = "{$d}: no end block or no row";

            continue;
        }

        $total = $micro('18.99304');

        foreach ($usdcTransfers as [$block, $amount]) {
            if ($block <= $endBlock) {
                $total += $micro($amount);
            }
        }

        $expected = $fromMicro($total);
        $actual = tokenAmount($holdings['polygon'] ?? null, '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359') ?? '0';

        if ($actual !== $expected) {
            $problems[] = "{$d} (end block {$endBlock}): native USDC {$actual} != {$expected}";
        }
    }

    $report('polygon-native-usdc-day-by-day', array_slice($problems, 0, 10));
}

// The live row of 2026-07-19 must sit between the reconstructed rows around it (accrued interest only moves up).
$live = $cache->getCacheForDate($wallet, '2026-07-19');
$before = $cache->getCacheForDate($wallet, '2026-07-18');
$after = $cache->getCacheForDate($wallet, '2026-07-20');

if ($live === null || $before === null || $after === null || $live['source'] !== 'live') {
    echo "SKIP  live-row-2026-07-19 bracket (needs a LIVE row on 2026-07-19 and rows on the days around it: request a date >= 2026-07-20)\n";
} else {
    $problems = [];
    $liveHoldings = json_decode($live['responseJson'], true)['holdings'] ?? [];
    $beforeHoldings = json_decode($before['responseJson'], true)['holdings'] ?? [];
    $afterHoldings = json_decode($after['responseJson'], true)['holdings'] ?? [];

    foreach ($liveHoldings as $chain => $chainHoldings) {
        foreach ($chainHoldings['defi']['aave']['reserves'] ?? [] as $r) {
            $a = reserve($beforeHoldings[$chain] ?? null, $r['symbol']);
            $b = reserve($afterHoldings[$chain] ?? null, $r['symbol']);

            foreach (['supplied', 'variableDebt'] as $field) {
                if ($a === null || $b === null) {
                    $problems[] = "{$chain} {$r['symbol']} {$field}: missing in a neighbouring reconstructed row";

                    continue;
                }

                $low = min((float) $a[$field], (float) $b[$field]);
                $high = max((float) $a[$field], (float) $b[$field]);
                $value = (float) $r[$field];

                if ($value < $low * (1 - 1e-9) - 1e-12 || $value > $high * (1 + 1e-9) + 1e-12) {
                    $problems[] = "{$chain} {$r['symbol']} {$field}: live {$r[$field]} is outside [{$a[$field]}, {$b[$field]}]";
                }
            }
        }
    }

    $report('live-row-2026-07-19-within-reconstructed-neighbours', $problems);
}

// Database integrity: one row per day without a gap, never two reconstructed rows for a day, cursors at the date.
$rows = $fetcher->query(
    $fetcher->createQuery('multichain_holdings_cache')->select('as_of_date, source')->where('address = :address'),
    ['address' => strtolower($wallet)]
);
$perDay = [];

foreach ($rows as $row) {
    $perDay[$row['as_of_date']][] = $row['source'];
}

ksort($perDay);
$reconstructedDays = array_keys(array_filter($perDay, static fn (array $sources): bool => in_array('reconstructed', $sources, true)));
$problems = [];

if ($reconstructedDays === []) {
    $problems[] = 'no reconstructed row at all';
} else {
    for ($d = $reconstructedDays[0]; $d <= $date; $d = gmdate('Y-m-d', strtotime($d . ' +1 day'))) {
        if (! isset($perDay[$d])) {
            $problems[] = "no row at all for {$d} (a hole in the walked range)";
        } elseif (count(array_keys($perDay[$d], 'reconstructed', true)) > 1) {
            $problems[] = "{$d} has more than one reconstructed row (a day was snapshotted twice)";
        }
    }
}

$report('rows-contiguous-and-unique', array_slice($problems, 0, 10));

$problems = [];

foreach (EvmReconstructionChains::SUPPORTED_CHAINS as $chain) {
    $cursor = $cursors->find($wallet, $chain);

    if ($cursor === null || $cursor['cursorDate'] === null || $cursor['cursorDate'] < $date) {
        $problems[] = "{$chain}: cursor_date " . ($cursor['cursorDate'] ?? 'none') . " is behind {$date}";
    }
}

$report('cursors-at-the-requested-date', $problems);

echo "\nsummary: " . count($reconstructedDays) . ' reconstructed day(s) from ' . ($reconstructedDays[0] ?? 'none') . ' to ' . ($reconstructedDays === [] ? 'none' : end($reconstructedDays)) . ', '
    . count($perDay) . " day(s) with a row in total\n";

foreach (EvmReconstructionChains::SUPPORTED_CHAINS as $chain) {
    $cursor = $cursors->find($wallet, $chain);
    $blocks = array_filter($unresolved->findByChain($chain), static fn (array $b): bool => $b['address'] === strtolower($wallet));
    echo "  {$chain}: first activity block " . ($cursor['firstActivityBlock'] ?? 'none') . ', tokens discovered ' . count(($cursor['state'] ?? null)?->tokens() ?? [])
        . ', unresolved blocks recorded for this wallet: ' . count($blocks) . ($blocks !== [] ? ' (' . implode(', ', array_column($blocks, 'block')) . ') -> review on a block explorer' : '') . "\n";
}

echo $failures === 0 ? "\nALL OK\n" : "\n{$failures} check(s) failed\n";

exit($failures === 0 ? 0 : 1);
