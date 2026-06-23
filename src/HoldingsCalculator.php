<?php

namespace App;

class HoldingsCalculator
{
    /**
     * Converts a wallet's normal + internal transactions into a list of signed native-coin
     * balance-change events. One row per value movement, plus one extra row per outgoing
     * normal transaction for the gas fee paid (since gas is deducted from the sender
     * regardless of whether the transaction's own value transfer succeeds).
     *
     * @param list<array<string, mixed>> $normalTxs
     * @param list<array<string, mixed>> $internalTxs
     *
     * @return list<array{txHash: string, logIndex: int, blockNumber: int, timestamp: int, signedAmount: string}>
     */
    public function buildNativeEvents(string $address, array $normalTxs, array $internalTxs): array
    {
        $normalizedAddress = strtolower($address);
        $events = [];

        foreach ($normalTxs as $tx) {
            $blockNumber = (int) $tx['blockNumber'];
            $timestamp = (int) $tx['timeStamp'];
            $hash = $tx['hash'];
            $isSender = strtolower($tx['from']) === $normalizedAddress;
            $isRecipient = strtolower($tx['to'] ?? '') === $normalizedAddress;
            // A failed transaction's intended value transfer never happened, but the
            // sender is still charged gas for the computation that was attempted.
            $succeeded = ($tx['isError'] ?? '0') === '0';

            if ($succeeded && $tx['value'] !== '0') {
                if ($isRecipient) {
                    $events[] = [
                        'txHash' => $hash,
                        'logIndex' => 0,
                        'blockNumber' => $blockNumber,
                        'timestamp' => $timestamp,
                        'signedAmount' => $tx['value']
                    ];
                }

                if ($isSender) {
                    $events[] = [
                        'txHash' => $hash,
                        'logIndex' => 1,
                        'blockNumber' => $blockNumber,
                        'timestamp' => $timestamp,
                        'signedAmount' => '-' . $tx['value']
                    ];
                }
            }

            if ($isSender) {
                $gasFee = bcmul((string) $tx['gasUsed'], (string) $tx['gasPrice']);

                if (bccomp($gasFee, '0') !== 0) {
                    $events[] = [
                        'txHash' => $hash,
                        'logIndex' => 2,
                        'blockNumber' => $blockNumber,
                        'timestamp' => $timestamp,
                        'signedAmount' => '-' . $gasFee
                    ];
                }
            }
        }

        foreach ($internalTxs as $tx) {
            // Internal transactions can also be marked as failed; a failed internal call
            // never actually moves value (and crucially: failed internal txs don't cost
            // the wallet extra gas, since gas for the whole call stack was already
            // accounted for by the top-level normal transaction above).
            $succeeded = ($tx['isError'] ?? '0') === '0';

            if (! $succeeded || $tx['value'] === '0') {
                continue;
            }

            $blockNumber = (int) $tx['blockNumber'];
            $timestamp = (int) $tx['timeStamp'];
            $hash = $tx['hash'] ?? ('internal-' . $blockNumber . '-' . ($tx['traceId'] ?? '0'));
            $isSender = strtolower($tx['from']) === $normalizedAddress;
            $isRecipient = strtolower($tx['to'] ?? '') === $normalizedAddress;
            // traceId (e.g. "0_1") distinguishes multiple internal transfers within the
            // same parent transaction. It's not itself a plain integer, so it's hashed
            // into one deterministically (crc32, kept positive and reasonably small),
            // rather than naively stripping characters, which could collide between
            // different traceId strings (e.g. "1_23" and "12_3"). Offset well above the
            // normal-tx indices (0/1/2) used for the same tx_hash, and *2/+1 to leave room
            // for both directions of a single internal transfer (a self-transfer needs
            // both a +in and a -out row).
            $traceOffset = 1000 + (crc32((string) ($tx['traceId'] ?? '0')) % 1000000);

            if ($isRecipient) {
                $events[] = [
                    'txHash' => $hash,
                    'logIndex' => $traceOffset * 2,
                    'blockNumber' => $blockNumber,
                    'timestamp' => $timestamp,
                    'signedAmount' => $tx['value']
                ];
            }

            if ($isSender) {
                $events[] = [
                    'txHash' => $hash,
                    'logIndex' => $traceOffset * 2 + 1,
                    'blockNumber' => $blockNumber,
                    'timestamp' => $timestamp,
                    'signedAmount' => '-' . $tx['value']
                ];
            }
        }

        return $events;
    }

    /**
     * Converts a wallet's ERC-20 token transfer events into signed balance-change events,
     * one row per transfer per direction the wallet was involved in.
     *
     * @param list<array<string, mixed>> $tokenTransfers
     *
     * @return list<array{txHash: string, logIndex: int, blockNumber: int, timestamp: int, tokenContract: string, tokenSymbol: string, tokenDecimals: int, signedAmount: string}>
     */
    public function buildTokenEvents(string $address, array $tokenTransfers): array
    {
        $normalizedAddress = strtolower($address);
        $events = [];

        // Tracks how many events have already been seen for each tx_hash, used to build a
        // stable per-transaction ordinal below. Etherscan-family tokentx responses do NOT
        // reliably include a logIndex field (confirmed against real data: a multicall
        // transaction with two separate incoming USDC transfers returned neither row with
        // a logIndex key at all). Relying on a missing field defaulting to 0 caused two
        // real, distinct transfers in the same transaction to collide on the same storage
        // key, silently overwriting one with the other and corrupting the balance. The fix
        // uses the order events are returned in for the same tx_hash instead, which
        // Etherscan-compatible APIs are documented and observed to return in on-chain
        // emission order.
        $eventIndexByTxHash = [];

        // Guards against the API returning the exact same transfer event twice in one
        // response (a real, confirmed category of bug on this API family -- see e.g.
        // https://routescan-bugs.nolt.io/359 for a different but related data-quality
        // issue). Without this, a duplicated row would be silently counted as two real
        // transfers instead of one.
        $seenSignatures = [];

        foreach ($tokenTransfers as $transfer) {
            $isSender = strtolower($transfer['from']) === $normalizedAddress;
            $isRecipient = strtolower($transfer['to'] ?? '') === $normalizedAddress;

            if (! $isSender && ! $isRecipient) {
                continue;
            }

            $hash = $transfer['hash'];
            $signature = $hash . '|' . strtolower($transfer['from']) . '|'
                . strtolower($transfer['to'] ?? '') . '|' . $transfer['value']
                . '|' . strtolower($transfer['contractAddress']);

            if (isset($seenSignatures[$signature])) {
                continue;
            }

            $seenSignatures[$signature] = true;

            $blockNumber = (int) $transfer['blockNumber'];
            $timestamp = (int) $transfer['timeStamp'];
            $contract = strtolower($transfer['contractAddress']);
            $symbol = $transfer['tokenSymbol'] ?? '???';
            $decimals = (int) ($transfer['tokenDecimal'] ?? 18);

            $eventIndexByTxHash[$hash] = ($eventIndexByTxHash[$hash] ?? -1) + 1;
            $eventIndex = $eventIndexByTxHash[$hash];

            if ($isRecipient) {
                $events[] = [
                    'txHash' => $hash,
                    'logIndex' => $eventIndex * 2,
                    'blockNumber' => $blockNumber,
                    'timestamp' => $timestamp,
                    'tokenContract' => $contract,
                    'tokenSymbol' => $symbol,
                    'tokenDecimals' => $decimals,
                    'signedAmount' => $transfer['value']
                ];
            }

            if ($isSender) {
                $events[] = [
                    'txHash' => $hash,
                    'logIndex' => $eventIndex * 2 + 1,
                    'blockNumber' => $blockNumber,
                    'timestamp' => $timestamp,
                    'tokenContract' => $contract,
                    'tokenSymbol' => $symbol,
                    'tokenDecimals' => $decimals,
                    'signedAmount' => '-' . $transfer['value']
                ];
            }
        }

        return $events;
    }

    /**
     * Converts a raw integer-string amount (e.g. wei, or a token's smallest unit) into a
     * human-readable decimal string given the asset's decimals, e.g. ("1500000000000000000", 18) -> "1.5".
     */
    public function toHumanAmount(string $rawAmount, int $decimals): string
    {
        $isNegative = str_starts_with($rawAmount, '-');
        $absRaw = $isNegative ? substr($rawAmount, 1) : $rawAmount;

        $divisor = bcpow('10', (string) $decimals);
        $result = bcdiv($absRaw, $divisor, $decimals);

        // Trim trailing zeros (and a trailing decimal point) for a cleaner display value,
        // without using floats anywhere in the process.
        if (str_contains($result, '.')) {
            $result = rtrim($result, '0');
            $result = rtrim($result, '.');
        }

        if ($result === '') {
            $result = '0';
        }

        return $isNegative && $result !== '0' ? '-' . $result : $result;
    }
}
