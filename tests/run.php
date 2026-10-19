<?php

/**
 * Offline test runner: `php tests/run.php` (also try `php -n tests/run.php`, i.e. with no
 * optional PHP extension loaded: nothing here needs bcmath, curl or the network).
 *
 * Exits 0 when every test passes, 1 otherwise. No composer / vendor needed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/support/harness.php';
require __DIR__ . '/support/fake_rpc.php';
require __DIR__ . '/support/memory_repositories.php';

$only = $argv[1] ?? null;

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$passed = 0;
$failed = 0;

foreach (Tests\Harness::$tests as $test) {
    if ($only !== null && stripos($test['name'], $only) === false) {
        continue;
    }

    try {
        ($test['fn'])();
        $passed++;
        echo "  ok    {$test['name']}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL  {$test['name']}\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        echo '        at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

echo "\n{$passed} passed, {$failed} failed, " . Tests\Harness::$assertions . " assertions\n";

exit($failed === 0 ? 0 : 1);
