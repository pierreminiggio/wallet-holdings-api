<?php

namespace Tests;

use App\AbiCodec;
use App\EvmRpcClient;
use Closure;

/**
 * Builds an EvmRpcClient transport that never touches the network.
 *
 * $handler receives (string $method, array $params, string $url) and returns one of:
 *   ['result' => mixed]                       a normal JSON-RPC success (HTTP 200)
 *   ['error' => ['code' => int, 'message' => string]]   a JSON-RPC error (HTTP 200)
 *   ['raw' => string|false, 'status' => int]  a raw HTTP reply, to test malformed bodies
 *
 * Every request is appended to $calls (by reference), so tests can assert exactly what was
 * sent, in particular the block parameter of every historical read.
 */
function fakeTransport(Closure $handler, array &$calls, array $batch = []): Closure
{
    return static function (string $url, string $payload, int $timeout) use ($handler, &$calls, $batch): array {
        HttpCounter::$requests++;
        $request = json_decode($payload, true);

        // A JSON-RPC BATCH: a list of requests. $batch options: 'maxSize' (refuse larger ones with ONE error object),
        // 'shuffle' (answer in another order), 'drop' (fn(int $position): bool, silently omit that answer),
        // 'transient' (fn(int $position): bool, answer that entry with a rate-limit error).
        if (is_array($request) && $request !== [] && array_keys($request) === range(0, count($request) - 1) && isset($request[0]["method"])) {
            HttpCounter::$batches++;

            if (isset($batch['maxSize']) && count($request) > $batch['maxSize']) {
                return ['body' => json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Batch of more than ' . $batch['maxSize'] . ' requests are not allowed']]), 'status' => 200];
            }

            $answers = [];

            foreach ($request as $position => $entry) {
                $calls[] = ['method' => $entry['method'], 'params' => $entry['params'], 'url' => $url, 'batch' => count($request)];

                if (isset($batch['drop']) && $batch['drop']($position)) {
                    continue;
                }

                if (isset($batch['transient']) && $batch['transient']($position)) {
                    $answers[] = ['jsonrpc' => '2.0', 'id' => $entry['id'], 'error' => ['code' => -32005, 'message' => 'Too many requests']];

                    continue;
                }

                $reply = $handler($entry['method'], $entry['params'], $url);
                $answers[] = array_key_exists('raw', $reply)
                    ? ['jsonrpc' => '2.0', 'id' => $entry['id'], 'error' => ['code' => -32000, 'message' => 'raw reply in a batch']]
                    : ['jsonrpc' => '2.0', 'id' => $entry['id']] + $reply;
            }

            if (! empty($batch['shuffle'])) {
                $answers = array_reverse($answers);
            }

            return ['body' => json_encode($answers), 'status' => 200];
        }

        $calls[] = ['method' => $request['method'], 'params' => $request['params'], 'url' => $url];

        $reply = $handler($request['method'], $request['params'], $url);

        if (array_key_exists('raw', $reply)) {
            return ['body' => $reply['raw'], 'status' => $reply['status'] ?? 200];
        }

        $body = ['jsonrpc' => '2.0', 'id' => $request['id']] + $reply;

        return ['body' => json_encode($body), 'status' => 200];
    };
}

/** Counts the HTTP requests the fake transport received (a batch is ONE request). */
final class HttpCounter
{
    public static int $requests = 0;

    public static int $batches = 0;

    public static function reset(): void
    {
        self::$requests = 0;
        self::$batches = 0;
    }
}

/**
 * A client factory (fn(string $url): EvmRpcClient) wired to a fake transport, for the
 * Compound / Aave clients' $rpcFactory parameter.
 */
function fakeFactory(Closure $handler, array &$calls, int $maxBatchSize = 1, array $batchOptions = []): Closure
{
    $transport = fakeTransport($handler, $calls, $batchOptions);

    return static fn (string $url): EvmRpcClient => new EvmRpcClient($url, $transport, 15, 0, 500, null, $maxBatchSize);
}

function word(int|string $value): string
{
    if (is_int($value)) {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    return str_pad(strtolower(AbiCodec::strip0x($value)), 64, '0', STR_PAD_LEFT);
}

/** A decimal string of any size -> one 32-byte hex word (test-side, via repeated division). */
function wordFromDecimal(string $decimal): string
{
    $hex = '';

    while ($decimal !== '0') {
        $remainder = 0;
        $quotient = '';

        foreach (str_split($decimal) as $digit) {
            $current = $remainder * 10 + (int) $digit;
            $quotient .= intdiv($current, 16);
            $remainder = $current % 16;
        }

        $hex = dechex($remainder) . $hex;
        $decimal = ltrim($quotient, '0');
        $decimal = $decimal === '' ? '0' : $decimal;
    }

    return str_pad($hex, 64, '0', STR_PAD_LEFT);
}

/** ABI-encode a single dynamic `string` return value. */
function abiString(string $value): string
{
    $hex = bin2hex($value);
    $padded = str_pad($hex, (int) (ceil(strlen($hex) / 64) * 64), '0');

    return '0x' . word(32) . word(strlen($value)) . $padded;
}

/**
 * ABI-encode Aave's getAllReservesTokens() return value: tuple(string symbol, address
 * tokenAddress)[] (a dynamic array of dynamic tuples).
 *
 * @param list<array{0: string, 1: string}> $reserves [symbol, address] pairs
 */
function abiReservesTokens(array $reserves): string
{
    $tuples = [];

    foreach ($reserves as [$symbol, $address]) {
        $hex = bin2hex($symbol);
        $padded = str_pad($hex, (int) (ceil(strlen($hex) / 64) * 64), '0');
        $tuples[] = word(64) . word($address) . word(strlen($symbol)) . $padded;
    }

    $head = '';
    $offset = count($tuples) * 32;

    foreach ($tuples as $tuple) {
        $head .= word($offset);
        $offset += strlen($tuple) / 2;
    }

    return '0x' . word(32) . word(count($reserves)) . $head . implode('', $tuples);
}

const FAKE_CODE = '0x6080604052';
