<?php

/**
 * A deliberately tiny test harness: no PHPUnit, no composer dependency, so the tests run
 * anywhere PHP runs (including with `php -n`, i.e. with no optional extension loaded --
 * the way this project's production server is configured, see README "Why bcmath isn't
 * needed anywhere in this project").
 */

namespace Tests;

use Closure;

final class Harness
{
    /** @var list<array{name: string, fn: Closure}> */
    public static array $tests = [];

    public static int $assertions = 0;
}

function test(string $name, Closure $fn): void
{
    Harness::$tests[] = ['name' => $name, 'fn' => $fn];
}

function fail(string $message): void
{
    throw new \RuntimeException($message);
}

function assertTrue(mixed $actual, string $message = ''): void
{
    Harness::$assertions++;

    if ($actual !== true) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected true, got ' . json_encode($actual));
    }
}

function assertFalse(mixed $actual, string $message = ''): void
{
    Harness::$assertions++;

    if ($actual !== false) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected false, got ' . json_encode($actual));
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    Harness::$assertions++;

    if ($expected !== $actual) {
        fail(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . json_encode($expected, JSON_PRETTY_PRINT)
            . ', got ' . json_encode($actual, JSON_PRETTY_PRINT)
        );
    }
}

function assertThrows(string $class, Closure $fn, string $message = ''): void
{
    Harness::$assertions++;

    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return;
        }

        fail(($message !== '' ? $message . ': ' : '') . "expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
    }

    fail(($message !== '' ? $message . ': ' : '') . "expected {$class} to be thrown");
}
