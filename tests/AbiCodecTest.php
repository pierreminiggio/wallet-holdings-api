<?php

namespace Tests;

use App\AbiCodec;

// A regression net for the helpers the historical reads lean on. These are the vectors that
// caught the ltrim() trap and the bcmath dependency originally (see AGENTS.md, Part 2).

test('AbiCodec: strip0x removes a literal prefix only (never a character mask)', function () {
    assertSame('00ff', AbiCodec::strip0x('0x00ff'));
    assertSame('x00ff', AbiCodec::strip0x('x00ff'));
    assertSame('0000', AbiCodec::strip0x('0x0000'));
});

test('AbiCodec: hexToDec / formatUnits, including the 256-bit boundary and dust', function () {
    assertSame('0', AbiCodec::hexToDec(''));
    assertSame('255', AbiCodec::hexToDec('ff'));
    assertSame(
        '115792089237316195423570985008687907853269984665640564039457584007913129639935',
        AbiCodec::hexToDec(str_repeat('f', 64))
    );
    assertSame('933.435002', AbiCodec::formatUnits('933435002', 6));
    assertSame('0.000000000057157522', AbiCodec::formatUnits('57157522', 18));
    assertSame('0.4', AbiCodec::formatUnits('400000000000000000', 18));
    assertSame('1', AbiCodec::formatUnits('1000000000000000000', 18));
});

test('test helper: wordFromDecimal round-trips through AbiCodec::hexToDec', function () {
    foreach (['0', '1', '255', '1669568345605074184', '115792089237316195423570985008687907853269984665640564039457584007913129639935'] as $decimal) {
        assertSame($decimal, AbiCodec::hexToDec(wordFromDecimal($decimal)));
    }
});

test('test helper: abiReservesTokens round-trips through AbiCodec::decodeReservesTokens', function () {
    $pairs = [['WBTC', '0x1BFD67037B42Cf73acF2047067bd4F2C47D9BfD6'], ['A_VERY_LONG_RESERVE_SYMBOL_OVER_32_BYTES_X', '0x0000000000000000000000000000000000000003']];
    $decoded = AbiCodec::decodeReservesTokens(abiReservesTokens($pairs));

    assertSame('WBTC', $decoded[0]['symbol']);
    assertSame(strtolower('0x1BFD67037B42Cf73acF2047067bd4F2C47D9BfD6'), $decoded[0]['address']);
    assertSame('A_VERY_LONG_RESERVE_SYMBOL_OVER_32_BYTES_X', $decoded[1]['symbol']);
});
