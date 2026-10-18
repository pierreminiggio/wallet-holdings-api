<?php

/**
 * Delivery 2 acceptance check (MULTICHAIN-RECONSTRUCTION-PLAN.md, section 4): EvmBlockResolver
 * against REAL RPCs. It resolves the reference blocks of section 5 and verifies, independently of
 * those references, the defining property of every resolved block:
 *
 *     timestamp(end_block) < end of day <= timestamp(end_block + 1)
 *
 *   php bin/check-2-blocks.php [--config=config.php] [--only=<id>] [--retries=6] [--rounds=4]
 *                              [--round-delay=5] [--pause=1] [--rpc-<chain>=<url>]
 *
 * MAPPING NOTE (plan section 5): the reference blocks were resolved as "the last block before
 * D 00:00 UTC", which is the end block of D-1, so "block for 2025-11-01" is checked here as the end
 * block of 2025-10-31.
 *
 * Providers and retry policy are the same as bin/check-1-reads.php: every configured provider of a
 * chain is used in priority order; transient errors are retried with exponential backoff (per call,
 * then per check in rounds); an unreachable check is reported as INFRASTRUCTURE, never as a wrong
 * value. Exit code 0 = everything as expected. Paste the whole output back for review.
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

use App\EvmBlockResolution;
use App\EvmBlockResolver;
use App\EvmReconstructionConfig;
use App\EvmRpcClient;
use App\EvmRpcError;

$options = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    }
}

$config = is_file($options['config'] ?? $root . '/config.php') ? require ($options['config'] ?? $root . '/config.php') : [];
$reconstruction = new EvmReconstructionConfig($config);

$retries = (int) ($options['retries'] ?? 6);
$rounds = max(1, (int) ($options['rounds'] ?? 4));
$roundDelay = (int) ($options['round-delay'] ?? 5);
$pauseSeconds = (int) ($options['pause'] ?? 1);

/** Counts block reads, so the output shows how cheap a resolution is. */
final class CountingRpcClient extends EvmRpcClient
{
    public static int $blockReads = 0;

    public function request(string $method, array $params): mixed
    {
        if ($method === 'eth_getBlockByNumber') {
            self::$blockReads++;
        }

        return parent::request($method, $params);
    }
}

function clientsFor(string $chain, array $options, EvmReconstructionConfig $reconstruction, int $retries): array
{
    $urls = isset($options['rpc-' . $chain])
        ? [$options['rpc-' . $chain]]
        : array_column($reconstruction->providers($chain), 'url');

    return array_map(static fn (string $url): EvmRpcClient => new CountingRpcClient($url, null, 30, $retries, 2000), $urls);
}

/** Runs one resolution with the built-in retry rounds; only an ERROR status is retried. */
function resolveWithRetries(EvmBlockResolver $resolver, string $date, ?int $hint, int $rounds, int $roundDelay): EvmBlockResolution
{
    for ($round = 0; ; $round++) {
        $result = $resolver->endBlockForDate($date, $hint);

        if (! $result->isError() || $round >= $rounds - 1 || $result->getError()->isAuth()) {
            return $result;
        }

        $delay = min(60, $roundDelay * (2 ** $round));
        echo "        .. {$result->getError()}; round " . ($round + 1) . "/{$rounds}, waiting {$delay}s then retrying\n";
        sleep($delay);
    }
}

$references = [
    // id, chain, day whose END block is expected (= reference date minus one day), expected block
    ['eth-ref-2025-11-01', 'ethereum', '2025-10-31', 23700766],
    ['eth-ref-2025-12-01', 'ethereum', '2025-11-30', 23914920],
    ['eth-ref-2026-01-01', 'ethereum', '2025-12-31', 24136052],
    ['bsc-ref-2025-11-01', 'binance-smart-chain', '2025-10-31', 66605720],
    ['bsc-ref-2026-10-01', 'binance-smart-chain', '2026-09-30', 125000754],
    ['polygon-ref-2026-02-15', 'polygon', '2026-02-14', 82996788],
    ['polygon-ref-2026-10-01', 'polygon', '2026-09-30', 94738439],
];

$only = $options['only'] ?? null;
$failures = 0;

foreach ($references as [$id, $chain, $date, $expected]) {
    if ($only !== null && $only !== $id) {
        continue;
    }

    sleep($pauseSeconds);
    $clients = clientsFor($chain, $options, $reconstruction, $retries);
    $label = "{$id} ({$chain} end block of {$date}, expected {$expected})";

    if ($clients === []) {
        $failures++;
        echo "FAIL  {$label}\n        - no RPC provider configured for {$chain}\n";

        continue;
    }

    CountingRpcClient::$blockReads = 0;
    $resolver = new EvmBlockResolver($chain, $clients);
    $result = resolveWithRetries($resolver, $date, null, $rounds, $roundDelay);
    $reads = CountingRpcClient::$blockReads;

    if ($result->isError()) {
        $failures++;
        echo "FAIL  {$label}\n        INFRASTRUCTURE failure (no provider could answer): {$result->getError()}\n";

        continue;
    }

    $problems = [];

    if (! $result->isResolved() || $result->getBlock() !== $expected) {
        $problems[] = 'resolved ' . ($result->getBlock() ?? $result->getStatus()) . " != {$expected}";
    }

    if ($result->isResolved()) {
        // Independent verification of the defining property, whatever the reference says.
        $endOfDay = EvmBlockResolver::endOfDayTimestamp($date);
        $at = $clients[0]->getBlockByNumber($result->getBlock());
        $next = $clients[0]->getBlockByNumber($result->getBlock() + 1);

        if ($at instanceof EvmRpcError || $next instanceof EvmRpcError) {
            $problems[] = 'could not verify the boundary property: ' . ($at instanceof EvmRpcError ? $at : $next);
        } else {
            if ($at['timestamp'] >= $endOfDay) {
                $problems[] = "block {$result->getBlock()} is NOT before the end of the day";
            }

            if ($next['timestamp'] < $endOfDay) {
                $problems[] = 'block ' . ($result->getBlock() + 1) . ' is still before the end of the day';
            }
        }
    }

    if ($problems === []) {
        echo "PASS  {$label}: {$result->getBlock()} in {$reads} block reads\n";
    } else {
        $failures++;
        echo "FAIL  {$label}\n";

        foreach ($problems as $problem) {
            echo "        - {$problem}\n";
        }
    }
}

// ---- behaviours that are not a reference value ----------------------------------------------

$extra = [
    ['today-not-complete', 'ethereum', gmdate('Y-m-d'), EvmBlockResolution::STATUS_DAY_NOT_COMPLETE],
    ['future-not-complete', 'base', gmdate('Y-m-d', time() + 5 * 86400), EvmBlockResolution::STATUS_DAY_NOT_COMPLETE],
    // Polygon's scan starts at 80,406,107 (mid-2025): any earlier day has no scanned block before it.
    ['polygon-before-history-start', 'polygon', '2024-01-01', EvmBlockResolution::STATUS_BEFORE_START],
    ['base-yesterday-resolves', 'base', gmdate('Y-m-d', time() - 86400), EvmBlockResolution::STATUS_RESOLVED],
];

foreach ($extra as [$id, $chain, $date, $expectedStatus]) {
    if ($only !== null && $only !== $id) {
        continue;
    }

    sleep($pauseSeconds);
    $clients = clientsFor($chain, $options, $reconstruction, $retries);

    if ($clients === []) {
        $failures++;
        echo "FAIL  {$id}: no RPC provider configured for {$chain}\n";

        continue;
    }

    $result = resolveWithRetries(new EvmBlockResolver($chain, $clients), $date, null, $rounds, $roundDelay);

    if ($result->getStatus() === $expectedStatus) {
        echo "PASS  {$id} ({$chain} {$date}): {$result->getStatus()}" . ($result->isResolved() ? " {$result->getBlock()}" : '') . "\n";
    } else {
        $failures++;
        echo "FAIL  {$id} ({$chain} {$date}): expected {$expectedStatus}, got {$result->getStatus()}"
            . ($result->isError() ? " ({$result->getError()}), INFRASTRUCTURE failure, not a wrong value" : '') . "\n";
    }
}

// Consecutive days, with and without the previous day's end block as a hint: same answer, ordered.
if ($only === null || $only === 'ethereum-consecutive-days-hint') {
    sleep($pauseSeconds);
    $clients = clientsFor('ethereum', $options, $reconstruction, $retries);

    if ($clients !== []) {
        $resolver = new EvmBlockResolver('ethereum', $clients);
        $a = resolveWithRetries($resolver, '2025-10-30', null, $rounds, $roundDelay);
        $b = resolveWithRetries($resolver, '2025-10-31', $a->getBlock(), $rounds, $roundDelay);
        $plain = resolveWithRetries(new EvmBlockResolver('ethereum', $clients), '2025-10-31', null, $rounds, $roundDelay);

        if ($a->isResolved() && $b->isResolved() && $plain->isResolved() && $a->getBlock() < $b->getBlock() && $b->getBlock() === $plain->getBlock()) {
            echo "PASS  ethereum-consecutive-days-hint: {$a->getBlock()} < {$b->getBlock()}, hinted == unhinted\n";
        } else {
            $failures++;
            echo "FAIL  ethereum-consecutive-days-hint: {$a->getStatus()} {$a->getBlock()}, {$b->getStatus()} {$b->getBlock()}, {$plain->getStatus()} {$plain->getBlock()}\n";
        }
    }
}

echo $failures === 0 ? "\nALL OK\n" : "\n{$failures} check(s) failed\n";

exit($failures === 0 ? 0 : 1);
