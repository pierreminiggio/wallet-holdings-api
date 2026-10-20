<?php

namespace Tests;

use App\HoldingsForDateResponse as Response;
use App\MultichainReconstructionOutcome as Outcome;

const RESP_ADDRESS = '0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe';

test('response: a live cached row keeps the "multichain_cache" source (existing clients do not break)', function () {
    $json = json_encode(['holdings' => ['ethereum' => ['native' => null]], 'defi_errors' => ['aave' => ['base' => 'upstream_error']]]);
    $response = Response::fromCachedRow(RESP_ADDRESS, '2026-07-19', $json, 'live');

    assertSame(200, $response['status']);
    assertSame('multichain_cache', $response['body']['source']);
    assertSame(['ethereum' => ['native' => null]], $response['body']['holdings']);
    assertSame(['aave' => ['base' => 'upstream_error']], $response['body']['defi_errors']);
    assertSame(['address', 'date', 'source', 'holdings', 'defi_errors'], array_keys($response['body']));
});

test('response: a reconstructed row is labelled "reconstructed" and carries its skipped-token report', function () {
    $json = json_encode(['holdings' => ['polygon' => ['native' => null]], 'reconstruction' => ['skipped_tokens' => ['polygon' => [['contract' => '0xabc', 'reason' => 'x']]]]]);
    $response = Response::fromCachedRow(RESP_ADDRESS, '2025-12-16', $json, 'reconstructed');

    assertSame('reconstructed', $response['body']['source']);
    assertSame(['skipped_tokens' => ['polygon' => [['contract' => '0xabc', 'reason' => 'x']]]], $response['body']['reconstruction']);
    assertFalse(isset($response['body']['defi_errors']));
});

test('response: a malformed cached payload degrades to empty holdings, not a crash', function () {
    $response = Response::fromCachedRow(RESP_ADDRESS, '2025-12-16', 'not json', 'reconstructed');

    assertSame(200, $response['status']);
    assertSame([], $response['body']['holdings']);
});

test('response: FOUND maps to 200 with the outcome\'s own source', function () {
    $json = json_encode(['holdings' => ['ethereum' => ['native' => ['symbol' => 'ETH', 'amount' => '1']]]]);

    $reconstructed = Response::fromOutcome(Outcome::found($json), RESP_ADDRESS, '2025-12-16');
    assertSame(200, $reconstructed['status']);
    assertSame('reconstructed', $reconstructed['body']['source']);

    $live = Response::fromOutcome(Outcome::found($json, 'live'), RESP_ADDRESS, '2025-12-16');
    assertSame('multichain_cache', $live['body']['source'], 'a live row that won the race is served as the live row it is');
});

test('response: BEFORE_GENESIS is 400 and names the date and the supported chains', function () {
    $response = Response::fromOutcome(Outcome::beforeGenesis('x'), RESP_ADDRESS, '2019-01-01');

    assertSame(400, $response['status']);
    assertTrue(str_contains($response['body']['message'], '2019-01-01'));
    assertTrue(str_contains($response['body']['message'], 'binance-smart-chain'));
});

test('response: BUSY is 409 with a Retry-After header (contention, not a failure)', function () {
    $response = Response::fromOutcome(Outcome::busy('details', 60), RESP_ADDRESS, '2025-12-16');

    assertSame(409, $response['status']);
    assertSame(['Retry-After' => '60'], $response['headers']);
    assertSame(60, $response['body']['retry_after_seconds']);
    assertFalse(isset($response['body']['detail']), 'no internal detail on a busy answer');
});

test('response: PROVIDER_ERROR is 502, says progress is saved, and reports how far the walk got', function () {
    $response = Response::fromOutcome(Outcome::providerError('polygon: snapshot failed', '2025-12-20'), RESP_ADDRESS, '2026-01-01');

    assertSame(502, $response['status']);
    assertSame('2025-12-20', $response['body']['walked_through']);
    assertSame('polygon: snapshot failed', $response['body']['detail']);
    assertTrue(str_contains($response['body']['message'], 'retry'));

    $none = Response::fromOutcome(Outcome::providerError('x'), RESP_ADDRESS, '2026-01-01');
    assertFalse(isset($none['body']['walked_through']));
});

test('response: INCONSISTENT and STORAGE_ERROR are 500 and never leak their detail to the client', function () {
    foreach ([Outcome::inconsistent('cursor SECRET-DETAIL'), Outcome::storageError('SQLSTATE[HY000] password for user root')] as $outcome) {
        $response = Response::fromOutcome($outcome, RESP_ADDRESS, '2025-12-16');

        assertSame(500, $response['status']);
        assertFalse(str_contains(json_encode($response['body']), 'SECRET-DETAIL'));
        assertFalse(str_contains(json_encode($response['body']), 'SQLSTATE'));
    }
});

test('response: every outcome status maps to a status code (no status can fall through unhandled)', function () {
    $expected = [
        Outcome::STATUS_FOUND => 200,
        Outcome::STATUS_BEFORE_GENESIS => 400,
        Outcome::STATUS_BUSY => 409,
        Outcome::STATUS_INCONSISTENT => 500,
        Outcome::STATUS_STORAGE_ERROR => 500,
        Outcome::STATUS_PROVIDER_ERROR => 502,
    ];
    $outcomes = [
        Outcome::STATUS_FOUND => Outcome::found('{}'),
        Outcome::STATUS_BEFORE_GENESIS => Outcome::beforeGenesis('x'),
        Outcome::STATUS_BUSY => Outcome::busy('x', 60),
        Outcome::STATUS_INCONSISTENT => Outcome::inconsistent('x'),
        Outcome::STATUS_STORAGE_ERROR => Outcome::storageError('x'),
        Outcome::STATUS_PROVIDER_ERROR => Outcome::providerError('x'),
    ];

    foreach ($expected as $status => $code) {
        assertSame($code, Response::fromOutcome($outcomes[$status], RESP_ADDRESS, '2025-12-16')['status'], $status);
    }
});
