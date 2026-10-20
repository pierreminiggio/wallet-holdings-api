<?php

/**
 * End-to-end ladder for the multichain historical reconstruction (MULTICHAIN-RECONSTRUCTION-PLAN.md, delivery 6),
 * on YOUR database and YOUR providers, with the real wallet. Nothing to pass: each rung is a fixed date with a fixed
 * expectation, and the rungs go from cheap to expensive.
 *
 *   php bin/e2e-ladder.php             # runs every rung in order, stops at the first failure
 *   php bin/e2e-ladder.php --through=4 # stops after rung 4 (the later rungs take hours)
 *   php bin/e2e-ladder.php --list      # prints the rungs and what each one expects
 *
 * It uses the same code path as GET /holdings?date= (MultichainHoldingsReconstructionService) and WRITES REAL ROWS to
 * multichain_holdings_cache and the cursor tables in config.php's database. Every run covers all four chains on purpose:
 * an existing row is never overwritten, so a run restricted to some chains would leave days permanently incomplete.
 *
 * It is safe to run again at any time, and each rung resumes from the cursor of the previous one: a rung that is already
 * walked costs almost nothing, so after an interruption (Ctrl-C, a closed laptop) just run the same command.
 * Rung 2 also kills a child process after 60 seconds to prove a cut-off request resumes with no hole and no duplicate.
 *
 * Needs `composer install` (database classes), a free NodeReal URL for BNB Smart Chain AND ideally one for Ethereum
 * (config.php, "reconstruction.rpc"): the Phase 0 scan of Ethereum from block 0 takes hours with the keyless 101-block cap.
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
    // Enough for --list. The database classes are only needed to actually run (checked below).
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . substr($class, 4) . '.php';

            if (is_file($file)) {
                require $file;
            }
        }
    });
}

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

const WALLET = '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';
const USDT0 = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';
const NATIVE_USDC = '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359';

// Native genesis blocks proven by bin/check-4-snapshot.php (balance zero one block before, non-zero at it).
const GENESIS = ['ethereum' => 13024431, 'binance-smart-chain' => 10027014, 'base' => 35321858, 'polygon' => 80406107];

/** Signed native-USDC transfers of Polygon (section 5, plus the zero-net pair found by the scanner). */
const USDC_TRANSFERS = [
    [80406376, '-17.990703'], [80511190, '53.709343'], [80511229, '-54.61168'], [80512567, '160.964707'],
    [80512690, '-161.064707'], [80526467, '243.550038'], [80526491, '-243.550038'], [80636033, '235.168996'],
    [80636107, '-175.168996'], [80636216, '-58.955012'], [80745841, '60.001012'], [80745873, '-61.046'],
];

// ------------------------------------------------------------------------------------------- arguments
$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}

// ---------------------------------------------------------------------------------------------- helpers
function norm(?string $amount): ?string
{
    if ($amount === null) {
        return null;
    }

    return str_contains($amount, '.') ? (rtrim(rtrim($amount, '0'), '.') ?: '0') : $amount;
}

function micro(string $amount): int
{
    $negative = str_starts_with($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
    $value = (int) $whole * 1000000 + (int) str_pad(substr($fraction, 0, 6), 6, '0');

    return $negative ? -$value : $value;
}

function fromMicro(int $value): string
{
    $text = intdiv(abs($value), 1000000) . '.' . str_pad((string) (abs($value) % 1000000), 6, '0', STR_PAD_LEFT);

    return ($value < 0 ? '-' : '') . (rtrim(rtrim($text, '0'), '.') ?: '0');
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

function addDay(string $date): string
{
    return gmdate('Y-m-d', strtotime($date . ' +1 day'));
}

function subDay(string $date): string
{
    return gmdate('Y-m-d', strtotime($date . ' -1 day'));
}

// ------------------------------------------------------------------------------------ the fixed checks
/**
 * Each check receives the context and returns a list of problems (empty = pass).
 *
 * @var array<string, callable(object): list<string>> $checks
 */
$checks = [];

// Section 5 reference values, as END-OF-DAY rows (the plan's reference blocks are the last block before D 00:00 UTC, i.e. the end of D-1).
$checks['reference 2025-10-31: Ethereum Compound, BSC Aave'] = static function (object $c): array {
    $h = $c->holdings('2025-10-31');

    if ($h === null) {
        return ['no row for 2025-10-31'];
    }

    $compound = $h['ethereum']['defi']['compound'][0] ?? null;
    $wbnb = reserve($h['binance-smart-chain'] ?? null, 'WBNB');
    $usdc = reserve($h['binance-smart-chain'] ?? null, 'USDC');

    return array_values(array_filter([
        norm($compound['borrowed'] ?? null) !== '933.435002' ? 'Ethereum Compound borrowed != 933.435002' : null,
        norm($compound['collateral'][0]['amount'] ?? null) !== '0.4' ? 'Ethereum Compound WETH collateral != 0.4' : null,
        norm($wbnb['supplied'] ?? null) !== '0.170012507196328142' ? 'BSC Aave WBNB supplied != 0.170012507196328142' : null,
        norm($usdc['variableDebt'] ?? null) !== '60.118707451980723546' ? 'BSC Aave USDC debt != 60.118707451980723546' : null,
        ($h['binance-smart-chain']['defi']['aave']['summary']['healthFactor'] ?? null) !== '2.309450654857429632' ? 'BSC Aave health factor != 2.309450654857429632' : null,
    ]));
};

$checks['reference 2025-11-30: Ethereum Compound and Aave'] = static function (object $c): array {
    $h = $c->holdings('2025-11-30');

    if ($h === null) {
        return ['no row for 2025-11-30'];
    }

    $compound = $h['ethereum']['defi']['compound'][0] ?? null;
    $weth = reserve($h['ethereum'] ?? null, 'WETH');

    return array_values(array_filter([
        norm($compound['borrowed'] ?? null) !== '937.67524' ? 'Ethereum Compound borrowed != 937.67524' : null,
        norm($compound['collateral'][0]['amount'] ?? null) !== '0.476818727900527526' ? 'Ethereum Compound WETH collateral != 0.476818727900527526' : null,
        norm($weth['supplied'] ?? null) !== '0.028014789715922191' ? 'Ethereum Aave WETH supplied != 0.028014789715922191' : null,
    ]));
};

$checks['reference 2025-12-31: Ethereum Compound dust collateral'] = static function (object $c): array {
    $h = $c->holdings('2025-12-31');

    if ($h === null) {
        return ['no row for 2025-12-31'];
    }

    $compound = $h['ethereum']['defi']['compound'][0] ?? null;

    return array_values(array_filter([
        norm($compound['borrowed'] ?? null) !== '0' ? 'Ethereum Compound borrowed != 0' : null,
        norm($compound['collateral'][0]['amount'] ?? null) !== '0.000000000057157522' ? 'Ethereum Compound WETH collateral != 0.000000000057157522' : null,
    ]));
};

$checks['reference 2026-02-14: Polygon Aave'] = static function (object $c): array {
    $h = $c->holdings('2026-02-14');

    if ($h === null) {
        return ['no row for 2026-02-14'];
    }

    $wbtc = reserve($h['polygon'] ?? null, 'WBTC');
    $usdt0 = reserve($h['polygon'] ?? null, 'USDT0');
    $usdc = reserve($h['polygon'] ?? null, 'USDC');

    return array_values(array_filter([
        norm($wbtc['supplied'] ?? null) !== '0.0035271' ? 'Polygon Aave WBTC supplied != 0.0035271' : null,
        norm($usdt0['variableDebt'] ?? null) !== '73.179844' ? 'Polygon Aave USDT0 debt != 73.179844' : null,
        norm($usdc['variableDebt'] ?? null) !== '41.790999' ? 'Polygon Aave USDC debt != 41.790999' : null,
        ($h['polygon']['defi']['aave']['summary']['healthFactor'] ?? null) !== '1.669568345605074184' ? 'Polygon Aave health factor != 1.669568345605074184' : null,
    ]));
};

// Aave all zero (the wallet DOES have Compound positions on Polygon, which is not asserted). The USDT0 residual 0.132953 was
// the balance read at the head when the plan was written: it differs only if the wallet moved it since.
$checks['reference 2026-09-30: Aave zero on BSC and Polygon, USDT0 residual'] = static function (object $c): array {
    $h = $c->holdings('2026-09-30');

    if ($h === null) {
        return ['no row for 2026-09-30'];
    }

    return array_values(array_filter([
        ($h['binance-smart-chain']['defi']['aave'] ?? null) !== null ? 'BSC Aave should be zero' : null,
        ($h['polygon']['defi']['aave'] ?? null) !== null ? 'Polygon Aave should be zero' : null,
        tokenAmount($h['polygon'] ?? null, USDT0) !== '0.132953' ? 'Polygon USDT0 residual != 0.132953 (legitimate only if the wallet moved it since)' : null,
    ]));
};

// Polygon native USDC day by day: 18.99304 held before the scan start, then the 12 known transfers; the expected balance at each
// day's end block is computed independently of the stored row.
$checks['Polygon native USDC matches the known transfers, every day from 2025-12-16 to 2026-01-05'] = static function (object $c): array {
    $problems = [];

    for ($d = '2025-12-16'; $d <= '2026-01-05'; $d = addDay($d)) {
        $endBlock = $c->blocks->find('polygon', $d);
        $holdings = $c->holdings($d);

        if ($endBlock === null || $holdings === null) {
            $problems[] = "{$d}: no end block or no row";

            continue;
        }

        $total = micro('18.99304');

        foreach (USDC_TRANSFERS as [$block, $amount]) {
            if ($block <= $endBlock) {
                $total += micro($amount);
            }
        }

        $actual = tokenAmount($holdings['polygon'] ?? null, NATIVE_USDC) ?? '0';

        if ($actual !== fromMicro($total)) {
            $problems[] = "{$d} (end block {$endBlock}): native USDC {$actual} != " . fromMicro($total);
        }
    }

    return array_slice($problems, 0, 10);
};

// The live row of 2026-07-19 must sit between the reconstructed rows around it (accrued interest only moves up).
$checks['the live row of 2026-07-19 sits between its reconstructed neighbours (Aave supplied and debt)'] = static function (object $c): array {
    $live = $c->cache->getCacheForDate(WALLET, '2026-07-19');

    if ($live === null || $live['source'] !== 'live') {
        return ['no LIVE row for 2026-07-19 in the database: nothing to compare (this check needs one)'];
    }

    $before = $c->holdings('2026-07-18');
    $after = $c->holdings('2026-07-20');

    if ($before === null || $after === null) {
        return ['the reconstructed rows around 2026-07-19 are missing'];
    }

    $problems = [];

    foreach (json_decode($live['responseJson'], true)['holdings'] ?? [] as $chain => $chainHoldings) {
        foreach ($chainHoldings['defi']['aave']['reserves'] ?? [] as $r) {
            $a = reserve($before[$chain] ?? null, $r['symbol']);
            $b = reserve($after[$chain] ?? null, $r['symbol']);

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

    return $problems;
};

// Properties every walked range must have, whatever the date.
$checks['rows: one per day without a gap, never two reconstructed rows for a day'] = static function (object $c): array {
    $perDay = $c->rowSourcesPerDay();
    $reconstructedDays = array_keys(array_filter($perDay, static fn (array $sources): bool => in_array('reconstructed', $sources, true)));

    if ($reconstructedDays === []) {
        return ['no reconstructed row at all'];
    }

    $problems = [];

    for ($d = $reconstructedDays[0]; $d <= $c->rungDate; $d = addDay($d)) {
        if (! isset($perDay[$d])) {
            $problems[] = "no row at all for {$d} (a hole in the walked range)";
        } elseif (count(array_keys($perDay[$d], 'reconstructed', true)) > 1) {
            $problems[] = "{$d} has more than one reconstructed row (a day was snapshotted twice)";
        }
    }

    return array_slice($problems, 0, 10);
};

$checks['cursors: every chain is at the rung date'] = static function (object $c): array {
    $problems = [];

    foreach (EvmReconstructionChains::SUPPORTED_CHAINS as $chain) {
        $cursor = $c->cursors->find(WALLET, $chain);

        if ($cursor === null || $cursor['cursorDate'] === null || $cursor['cursorDate'] < $c->rungDate) {
            $problems[] = "{$chain}: cursor_date " . ($cursor['cursorDate'] ?? 'none') . " is behind {$c->rungDate}";
        }
    }

    return $problems;
};

$checks['first activity is the native genesis block on Ethereum and BSC, and the first row is its day'] = static function (object $c): array {
    $problems = [];
    $firstDays = [];

    foreach (['ethereum', 'binance-smart-chain'] as $chain) {
        $cursor = $c->cursors->find(WALLET, $chain);
        $first = $cursor['firstActivityBlock'] ?? null;

        if ($first === null) {
            $problems[] = "{$chain}: no first activity block";

            continue;
        }

        if ($first > GENESIS[$chain]) {
            $problems[] = "{$chain}: first activity block {$first} is AFTER the native genesis block " . GENESIS[$chain];
        }

        echo "        {$chain}: first activity block {$first} (native genesis " . GENESIS[$chain] . ")\n";

        // The day that contains the first activity block: end_block(D-1) < block <= end_block(D).
        for ($d = $c->firstReconstructedDay(); $d !== null && $d <= $c->rungDate; $d = addDay($d)) {
            $end = $c->blocks->find($chain, $d);
            $previous = $c->blocks->find($chain, subDay($d));

            if ($end !== null && $first <= $end && ($previous === null || $previous < $first)) {
                $firstDays[$chain] = $d;

                break;
            }
        }
    }

    if (count($firstDays) === 2 && min($firstDays) !== $c->firstReconstructedDay()) {
        $problems[] = 'the earliest reconstructed row is ' . $c->firstReconstructedDay() . ' but the earliest first activity day is ' . min($firstDays);
    }

    return $problems;
};

$checks['Base first activity is its native genesis block'] = static function (object $c): array {
    $first = $c->cursors->find(WALLET, 'base')['firstActivityBlock'] ?? null;

    echo '        base: first activity block ' . ($first ?? 'none') . ' (native genesis ' . GENESIS['base'] . ")\n";

    return ($first !== null && $first <= GENESIS['base']) ? [] : ['Base first activity block is ' . ($first ?? 'none') . ', expected at most ' . GENESIS['base']];
};

$checks['Polygon first activity is its native genesis block 80,406,107'] = static function (object $c): array {
    $first = $c->cursors->find(WALLET, 'polygon')['firstActivityBlock'] ?? null;

    return $first === GENESIS['polygon'] ? [] : ['Polygon first activity block is ' . ($first ?? 'none') . ', expected ' . GENESIS['polygon']];
};

// ------------------------------------------------------------------------------------------ the rungs
/**
 * date: the date requested. expect: the status the request must end with. checks: the checks run afterwards (names of $checks).
 * interruptAfter: kill a child process after this many seconds first, to prove a cut-off request resumes.
 */
$rungs = [
    1 => [
        'date' => '2021-08-01', 'expect' => Outcome::STATUS_BEFORE_GENESIS, 'interruptAfter' => 0,
        'what' => 'a date before the wallet\'s first activity on any chain: the 400 case. Writes no row.',
        'checks' => [],
    ],
    2 => [
        'date' => '2021-08-25', 'expect' => Outcome::STATUS_FOUND, 'interruptAfter' => 60,
        'what' => 'the first real walk (about 12 days from the first activity on Ethereum and BSC), killed after 60 s and resumed',
        'checks' => [
            'first activity is the native genesis block on Ethereum and BSC, and the first row is its day',
            'rows: one per day without a gap, never two reconstructed rows for a day',
            'cursors: every chain is at the rung date',
        ],
    ],
    3 => [
        'date' => '2021-12-31', 'expect' => Outcome::STATUS_FOUND, 'interruptAfter' => 0,
        'what' => 'about 140 days: the duration printed here, divided by the number of days, is the per-day cost',
        'checks' => ['rows: one per day without a gap, never two reconstructed rows for a day', 'cursors: every chain is at the rung date'],
    ],
    4 => [
        'date' => '2023-01-31', 'expect' => Outcome::STATUS_FOUND, 'interruptAfter' => 0,
        'what' => 'crosses the Compound III (about Aug 2022) and Aave V3 (about Jan 2023) deployments on Ethereum: the "contract not deployed yet" case on real history',
        'checks' => ['rows: one per day without a gap, never two reconstructed rows for a day', 'cursors: every chain is at the rung date'],
    ],
    5 => [
        'date' => '2025-09-30', 'expect' => Outcome::STATUS_FOUND, 'interruptAfter' => 0,
        'what' => 'crosses Base\'s native genesis (about 2025-09-09): a chain that starts mid-walk, with a Phase 0 over about 35 million blocks',
        'checks' => ['Base first activity is its native genesis block', 'rows: one per day without a gap, never two reconstructed rows for a day', 'cursors: every chain is at the rung date'],
    ],
    6 => [
        'date' => '2025-10-31', 'expect' => Outcome::STATUS_FOUND, 'interruptAfter' => 0,
        'what' => 'the first reference values: Ethereum Compound and BSC Aave',
        'checks' => ['reference 2025-10-31: Ethereum Compound, BSC Aave', 'rows: one per day without a gap, never two reconstructed rows for a day', 'cursors: every chain is at the rung date'],
    ],
    7 => [
        'date' => '2025-12-31', 'expect' => Outcome::STATUS_FOUND, 'interruptAfter' => 0,
        'what' => 'Ethereum references of 2025-11-30 and 2025-12-31, Polygon\'s start (2025-12-16) and its native USDC day by day',
        'checks' => [
            'reference 2025-11-30: Ethereum Compound and Aave',
            'reference 2025-12-31: Ethereum Compound dust collateral',
            'Polygon first activity is its native genesis block 80,406,107',
            'Polygon native USDC matches the known transfers, every day from 2025-12-16 to 2026-01-05',
            'rows: one per day without a gap, never two reconstructed rows for a day',
            'cursors: every chain is at the rung date',
        ],
    ],
    8 => [
        'date' => '2026-02-14', 'expect' => Outcome::STATUS_FOUND, 'interruptAfter' => 0,
        'what' => 'the Polygon Aave reference',
        'checks' => ['reference 2026-02-14: Polygon Aave', 'Polygon native USDC matches the known transfers, every day from 2025-12-16 to 2026-01-05', 'rows: one per day without a gap, never two reconstructed rows for a day', 'cursors: every chain is at the rung date'],
    ],
    9 => [
        'date' => '2026-10-19', 'expect' => Outcome::STATUS_FOUND, 'interruptAfter' => 0,
        'what' => 'up to yesterday: Aave zero on BSC and Polygon, the USDT0 residual, and the live row of 2026-07-19 against its reconstructed neighbours',
        'checks' => [
            'reference 2026-09-30: Aave zero on BSC and Polygon, USDT0 residual',
            'the live row of 2026-07-19 sits between its reconstructed neighbours (Aave supplied and debt)',
            'rows: one per day without a gap, never two reconstructed rows for a day',
            'cursors: every chain is at the rung date',
        ],
    ],
];

if (isset($options['list'])) {
    foreach ($rungs as $n => $rung) {
        echo "rung {$n}: {$rung['date']} -> {$rung['expect']}" . ($rung['interruptAfter'] > 0 ? " (killed after {$rung['interruptAfter']}s first)" : '') . "\n    {$rung['what']}\n";
    }

    exit(0);
}

// ------------------------------------------------------------------------------------------ the service
$configFile = $options['config'] ?? $root . '/config.php';

if (! is_file($configFile)) {
    fwrite(STDERR, "config file not found: {$configFile}\n");
    exit(2);
}

if (! class_exists(DatabaseFetcher::class)) {
    fwrite(STDERR, "vendor/autoload.php not found or incomplete: run `composer install` first (this script needs the database classes).\n");
    exit(2);
}

$rawConfig = require $configFile;
$db = $rawConfig['db'];
$fetcher = new DatabaseFetcher(new DatabaseConnection($db['host'], $db['database'], $db['username'], $db['password']));

$cache = new HoldingsNowCacheRepository($fetcher);
$cursors = new MultichainReconstructionCursorRepository($fetcher);
$blocks = new EvmBlockByDateRepository($fetcher);
$unresolved = new EvmUnresolvedBlockRepository($fetcher);

$service = new MultichainHoldingsReconstructionService(new EvmReconstructionConfig($rawConfig), $cache, $cursors, $blocks, $unresolved);

function runOnce(MultichainHoldingsReconstructionService $service, string $date): Outcome
{
    $started = time();
    $outcome = $service->reconstruct(WALLET, $date);
    echo '        outcome: ' . $outcome->getStatus() . ' after ' . gmdate('H:i:s', time() - $started)
        . ($outcome->getMessage() !== null ? ' - ' . $outcome->getMessage() : '')
        . ($outcome->getWalkedThrough() !== null ? ' (walked through ' . $outcome->getWalkedThrough() . ')' : '') . "\n";

    return $outcome;
}

// Hidden child mode, used by the ladder itself to simulate a cut-off request: run once and exit.
if (isset($options['child'])) {
    runOnce($service, (string) $options['child']);
    exit(0);
}

/** The context the checks read from. */
$context = new class ($cache, $cursors, $blocks, $fetcher) {
    public string $rungDate = '';

    public function __construct(
        public HoldingsNowCacheRepository $cache,
        public MultichainReconstructionCursorRepository $cursors,
        public EvmBlockByDateRepository $blocks,
        private DatabaseFetcher $fetcher,
    ) {
    }

    /** @return array<string, mixed>|null the "holdings" of the newest row of that day */
    public function holdings(string $date): ?array
    {
        $row = $this->cache->getCacheForDate(WALLET, $date);

        return $row === null ? null : (json_decode($row['responseJson'], true)['holdings'] ?? []);
    }

    /** @return array<string, list<string>> */
    public function rowSourcesPerDay(): array
    {
        $rows = $this->fetcher->query(
            $this->fetcher->createQuery('multichain_holdings_cache')->select('as_of_date, source')->where('address = :address'),
            ['address' => strtolower(WALLET)]
        );
        $perDay = [];

        foreach ($rows as $row) {
            $perDay[$row['as_of_date']][] = $row['source'];
        }

        ksort($perDay);

        return $perDay;
    }

    public function firstReconstructedDay(): ?string
    {
        foreach ($this->rowSourcesPerDay() as $day => $sources) {
            if (in_array('reconstructed', $sources, true)) {
                return $day;
            }
        }

        return null;
    }
};

// ------------------------------------------------------------------------------------------ run the ladder
$through = (int) ($options['through'] ?? count($rungs));
$failed = false;

echo 'wallet ' . WALLET . "\n";

foreach ($rungs as $n => $rung) {
    if ($n > $through) {
        break;
    }

    $date = $rung['date'];
    echo "\n=== rung {$n}: {$date}, expecting {$rung['expect']} ===\n    {$rung['what']}\n";
    $started = time();

    if ($rung['interruptAfter'] > 0) {
        echo "        killing a child run after {$rung['interruptAfter']}s (a cut-off request)...\n";
        $process = proc_open(
            [PHP_BINARY, __FILE__, '--child=' . $date, '--config=' . $configFile],
            [0 => ['pipe', 'r'], 1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']],
            $pipes
        );

        if (is_resource($process)) {
            $deadline = time() + $rung['interruptAfter'];

            while (time() < $deadline && (proc_get_status($process)['running'] ?? false)) {
                sleep(1);
            }

            if (proc_get_status($process)['running'] ?? false) {
                proc_terminate($process);
                echo "        killed\n";
            } else {
                echo "        the child finished by itself (nothing left to interrupt)\n";
            }

            proc_close($process);
        }

        $cursor = $cursors->find(WALLET, 'ethereum');
        echo '        cursor after the interruption (ethereum): date ' . ($cursor['cursorDate'] ?? 'none') . ', last scanned block ' . (($cursor['state'] ?? null)?->getLastScannedBlock() ?? 'none') . "\n";
        echo "        resuming...\n";
    }

    // Resume until it ends: provider errors and busy are retried (the walk resumes from the cursor each time).
    $outcome = null;

    for ($round = 1; $round <= 20; $round++) {
        $outcome = runOnce($service, $date);

        if ($outcome->getStatus() === Outcome::STATUS_BUSY) {
            sleep((int) $outcome->getRetryAfterSeconds());

            continue;
        }

        if ($outcome->getStatus() !== Outcome::STATUS_PROVIDER_ERROR || str_contains((string) $outcome->getMessage(), 'No archive RPC provider')) {
            break;
        }

        $delay = min(120, 10 * $round);
        echo "        provider_error: resuming from the cursor in {$delay}s (round {$round}/20)\n";
        sleep($delay);
    }

    $problems = [];

    if ($outcome === null || $outcome->getStatus() !== $rung['expect']) {
        $problems[] = 'expected ' . $rung['expect'] . ', got ' . ($outcome?->getStatus() ?? 'no outcome') . ($outcome?->getMessage() !== null ? ': ' . $outcome->getMessage() : '');
    }

    if ($rung['expect'] === Outcome::STATUS_BEFORE_GENESIS) {
        $early = array_filter(array_keys(array_filter($context->rowSourcesPerDay(), static fn (array $s): bool => in_array('reconstructed', $s, true))), static fn (string $d): bool => $d <= $date);

        if ($early !== []) {
            $problems[] = 'reconstructed rows exist on or before ' . $date . ': ' . implode(', ', array_slice($early, 0, 5));
        }
    }

    echo '        rung duration: ' . gmdate('H:i:s', time() - $started) . "\n";

    if ($problems === []) {
        $context->rungDate = $date;

        foreach ($rung['checks'] as $name) {
            $checkProblems = $checks[$name]($context);

            if ($checkProblems === []) {
                echo "PASS  {$name}\n";
            } else {
                $problems[] = $name;
                echo "FAIL  {$name}\n";

                foreach ($checkProblems as $problem) {
                    echo "        - {$problem}\n";
                }
            }
        }
    } else {
        foreach ($problems as $problem) {
            echo "FAIL  rung {$n}: {$problem}\n";
        }
    }

    if ($problems !== []) {
        $failed = true;
        echo "\nstopping at rung {$n}: the later rungs depend on this one.\n";

        break;
    }

    echo "PASS  rung {$n}\n";
}

// What the scanner could not fetch is worth a look, whatever the rung results.
foreach (EvmReconstructionChains::SUPPORTED_CHAINS as $chain) {
    $mine = array_filter($unresolved->findByChain($chain), static fn (array $b): bool => $b['address'] === strtolower(WALLET));

    if ($mine !== []) {
        echo "\nunresolved blocks recorded for this wallet on {$chain}: " . implode(', ', array_column($mine, 'block')) . " (review on a block explorer, then promote into SKIPPED_BLOCKS if acceptable)\n";
    }
}

echo $failed ? "\nFAILED\n" : "\nALL OK (through rung " . min($through, count($rungs)) . ")\n";

exit($failed ? 1 : 0);
