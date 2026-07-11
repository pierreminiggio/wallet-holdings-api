<?php

namespace App;

/**
 * Pure ABI encoding/decoding helpers for reading Comet (Compound III) and Aave V3
 * contract data via raw eth_call, without any web3 library dependency. Shared between
 * CompoundHoldingsClient and AaveHoldingsClient.
 *
 * Uses bcmath (not GMP) for arbitrary-precision integers, consistent with the rest of
 * this codebase (see WalletDataRepository / HoldingsCalculator), since wei-level amounts
 * exceed native PHP int/float precision.
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
     * Convert a hex word (no 0x prefix) to a decimal string, using bcmath. No GMP
     * dependency -- bcmath is already required elsewhere in this project.
     */
    public static function hexToDec(string $word): string
    {
        if ($word === '') {
            return '0';
        }

        $dec = '0';

        for ($i = 0, $len = strlen($word); $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($word[$i]));
        }

        return $dec;
    }

    /** Format a decimal-string integer with `decimals` decimal places, like ethers' formatUnits. */
    public static function formatUnits(string $value, int $decimals): string
    {
        $divisor = bcpow('10', (string) $decimals);
        $whole = bcdiv($value, $divisor, 0);
        $remainder = bcmod($value, $divisor);
        $fraction = str_pad($remainder, $decimals, '0', STR_PAD_LEFT);
        $fraction = rtrim($fraction, '0');

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
