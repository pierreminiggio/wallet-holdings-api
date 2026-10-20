<?php

namespace App;

/**
 * Builds the HTTP response of GET /holdings/{address}?date= for a past date, as plain data
 * (status, headers, body), so App only has to emit it and the mapping is unit-testable without a
 * web server or a database.
 *
 * STATUS MAPPING of the multichain historical reconstruction (same family as /sui-holdings):
 *   200 found           the row for that date, from the cache or just reconstructed;
 *   400 before_genesis  no supported chain has any activity for the wallet on or before that date;
 *   409 busy            another reconstruction holds a (chain, provider) lock this one needs
 *                       (contention, not a job status), with a Retry-After header;
 *   500 inconsistent    the cursors say the date is walked but no row exists for it;
 *   500 storage_error   the database or the lock directory failed;
 *   502 provider_error  an RPC provider failed after bounded retries; progress is saved and the
 *                       same request resumes where it stopped.
 */
final class HoldingsForDateResponse
{
    /**
     * The "source" field: 'reconstructed' for a reconstructed row, 'multichain_cache' for a live one
     * (the value /holdings has always returned, kept so existing clients do not break).
     */
    public static function sourceLabel(string $rowSource): string
    {
        return $rowSource === 'reconstructed' ? 'reconstructed' : 'multichain_cache';
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>}
     */
    public static function fromCachedRow(string $address, string $date, string $responseJson, string $rowSource): array
    {
        $cached = json_decode($responseJson, true);
        $cached = is_array($cached) ? $cached : [];

        $body = [
            'address' => $address,
            'date' => $date,
            'source' => self::sourceLabel($rowSource),
            'holdings' => $cached['holdings'] ?? [],
        ];

        // Carry over any partial on-chain read failures recorded when the row was cached, rather
        // than silently dropping that information here.
        if (! empty($cached['defi_errors'])) {
            $body['defi_errors'] = $cached['defi_errors'];
        }

        // Reconstructed rows may list token contracts that could not be read as ERC-20s at that block.
        if (! empty($cached['reconstruction'])) {
            $body['reconstruction'] = $cached['reconstruction'];
        }

        return ['status' => 200, 'headers' => [], 'body' => $body];
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>}
     */
    public static function fromOutcome(MultichainReconstructionOutcome $outcome, string $address, string $date): array
    {
        switch ($outcome->getStatus()) {
            case MultichainReconstructionOutcome::STATUS_FOUND:
                return self::fromCachedRow($address, $date, (string) $outcome->getResponseJson(), (string) ($outcome->getSource() ?? 'reconstructed'));

            case MultichainReconstructionOutcome::STATUS_BEFORE_GENESIS:
                return [
                    'status' => 400,
                    'headers' => [],
                    'body' => ['message' => "No activity was found for {$address} on any supported chain ("
                        . implode(', ', EvmReconstructionChains::SUPPORTED_CHAINS) . ") on or before {$date}."],
                ];

            case MultichainReconstructionOutcome::STATUS_BUSY:
                $retryAfter = $outcome->getRetryAfterSeconds() ?? MultichainHoldingsReconstructionService::BUSY_RETRY_AFTER_SECONDS;

                return [
                    'status' => 409,
                    'headers' => ['Retry-After' => (string) $retryAfter],
                    'body' => [
                        'message' => 'Another historical reconstruction is currently using the same RPC providers. '
                            . 'Retry shortly: this is contention, not a failure.',
                        'retry_after_seconds' => $retryAfter,
                    ],
                ];

            case MultichainReconstructionOutcome::STATUS_PROVIDER_ERROR:
                $body = [
                    'message' => 'An RPC provider failed while reconstructing this wallet\'s history. Progress is saved: '
                        . 'retry the same request and it resumes where it stopped.',
                    'detail' => $outcome->getMessage(),
                ];

                if ($outcome->getWalkedThrough() !== null) {
                    $body['walked_through'] = $outcome->getWalkedThrough();
                }

                return ['status' => 502, 'headers' => [], 'body' => $body];

            case MultichainReconstructionOutcome::STATUS_INCONSISTENT:
                // The detail goes to the error log, not to the client.
                error_log('Multichain reconstruction inconsistent for ' . $address . ' on ' . $date . ': ' . $outcome->getMessage());

                return ['status' => 500, 'headers' => [], 'body' => ['message' => 'Internal error: the reconstruction state for this wallet is inconsistent.']];

            default: // STATUS_STORAGE_ERROR, and anything unforeseen
                return ['status' => 500, 'headers' => [], 'body' => ['message' => 'Internal error while reconstructing this wallet\'s history.']];
        }
    }
}
