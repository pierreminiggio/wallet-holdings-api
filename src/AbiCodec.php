<?php

namespace App;

/**
 * Pure ABI encoding/decoding helpers for reading Comet (Compound III) and Aave V3
 * contract data via raw eth_call, without any web3 library dependency. Shared between
 * CompoundHoldingsClient and AaveHoldingsClient.
 *
 * Deliberately uses only native PHP string/int arithmetic for its big-integer math (see
 * bigMulAdd/hexToDec/formatUnits below) -- no bcmath or GMP extension required -- since
 * wei-level amounts exceed native PHP int/float precision but this project's production
 * environment doesn't have bcmath enabled.
 */
class AbiCodec
{
    /**
     * Strip a literal "0x" prefix. NOTE: ltrim($hex, '0x') is WRONG in PHP -- its second
     * argument is a character mask, not a literal string, so it would strip leading 0s
     * and x's and corrupt any zero-padded hex value. Always use this instead.
     */
    public static function strip0x(string $hex): string
    {
        return str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
    }

    public static function encodeAddress(string $addr): string
    {
        return str_pad(strtolower(self::strip0x($addr)), 64, '0', STR_PAD_LEFT);
    }

    public static function encodeUint(int $n): string
    {
        return str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
    }

    /**
     * Split a 0x-prefixed (or bare) hex string into 32-byte (64 hex char) ABI words.
     *
     * @return list<string>
     */
    public static function words(string $hex): array
    {
        return str_split(self::strip0x($hex), 64);
    }

    /** Last 20 bytes of a 32-byte word = an address. */
    public static function wordToAddress(string $word): string
    {
        return '0x' . substr($word, 24);
    }

    /**
     * Multiplies a decimal-string integer by a small int (0-16 in practice here) and adds
     * a small int (0-15), returning the result as a decimal string -- i.e. decimal*mul+add.
     * Pure native PHP: manual long multiplication with carry, digit by digit. Since mul/add
     * are always tiny constants (a hex digit's value and base 16), each per-digit operation
     * stays well within native PHP int range regardless of how long $decimal is, so this
     * works correctly for arbitrarily large numbers (e.g. full 256-bit values) without
     * bcmath/GMP.
     */
    private static function bigMulAdd(string $decimal, int $mul, int $add): string
    {
        $carry = $add;
        $result = '';

        for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
            $product = ((int) $decimal[$i]) * $mul + $carry;
            $result .= (string) ($product % 10);
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result .= (string) ($carry % 10);
            $carry = intdiv($carry, 10);
        }

        $result = ltrim(strrev($result), '0');

        return $result === '' ? '0' : $result;
    }

    /**
     * Convert a hex word (no 0x prefix) to a decimal string, using only native PHP integer
     * arithmetic (no bcmath/GMP extension required) -- see bigMulAdd() above. Builds the
     * result the same way you'd do long multiplication by hand: for each hex digit,
     * dec = dec*16 + digit.
     */
    public static function hexToDec(string $word): string
    {
        if ($word === '') {
            return '0';
        }

        $dec = '0';

        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            $dec = self::bigMulAdd($dec, 16, hexdec($word[$i]));
        }

        return $dec;
    }

    /**
     * True if a canonical (no leading zeros) decimal-string integer represents zero.
     * Replaces bccomp($x, '0') === 0 without needing bcmath.
     */
    public static function isZero(string $decimal): bool
    {
        return ltrim($decimal, '0') === '';
    }

    /**
     * Format a decimal-string integer with `decimals` decimal places, like ethers'
     * formatUnits -- using only string slicing, no bcmath/GMP required. This works because
     * the divisor here is always a power of 10 (10^decimals): dividing a base-10 string by
     * 10^n is just "move the decimal point n digits from the right", which is exactly what
     * slicing the string does -- no general big-integer division algorithm needed.
     */
    public static function formatUnits(string $value, int $decimals): string
    {
        if ($decimals === 0) {
            return $value;
        }

        // Left-pad so there are always at least `decimals + 1` digits, e.g. formatUnits('5', 6)
        // needs to become "0.000005", so the string must be long enough to slice a whole part
        // off the front at all.
        $padded = str_pad($value, $decimals + 1, '0', STR_PAD_LEFT);

        $whole = ltrim(substr($padded, 0, -$decimals), '0');
        $whole = $whole === '' ? '0' : $whole;

        $fraction = rtrim(substr($padded, -$decimals), '0');

        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }

    /** Decode a dynamic `string` return value (offset word + length word + utf8 bytes). */
    public static function decodeString(string $hex): string
    {
        $hex = self::strip0x($hex);
        $lenWord = substr($hex, 64, 64); // second word = byte length (offset word is first)
        $len = hexdec($lenWord);
        $dataHex = substr($hex, 128, $len * 2);

        return hex2bin($dataHex);
    }

    /**
     * Decode the return value of Aave's getAllReservesTokens(): a dynamic array of dynamic
     * tuples, tuple(string symbol, address tokenAddress)[]. This needs real head/tail ABI
     * decoding, unlike everything else here (which is static/fixed-size words).
     *
     * @return list<array{symbol: string, address: string}>
     */
    public static function decodeReservesTokens(string $hex): array
    {
        $w = self::words($hex);

        $arrayOffsetWords = intdiv(hexdec($w[0]), 32);
        $lenIndex = $arrayOffsetWords;
        $length = hexdec($w[$lenIndex]);
        $arrayDataStart = $lenIndex + 1;

        $results = [];

        for ($i = 0; $i < $length; $i++) {
            $tupleOffsetWords = intdiv(hexdec($w[$arrayDataStart + $i]), 32);
            $tupleStart = $arrayDataStart + $tupleOffsetWords;

            $stringOffsetWords = intdiv(hexdec($w[$tupleStart]), 32);
            $stringStart = $tupleStart + $stringOffsetWords;
            $strLen = hexdec($w[$stringStart]);
            $numWords = (int) ceil($strLen / 32);
            $strHex = '';

            for ($j = 0; $j < $numWords; $j++) {
                $strHex .= $w[$stringStart + 1 + $j];
            }

            $symbol = hex2bin(substr($strHex, 0, $strLen * 2));
            $tokenAddress = self::wordToAddress($w[$tupleStart + 1]);

            $results[] = ['symbol' => $symbol, 'address' => $tokenAddress];
        }

        return $results;
    }
}
