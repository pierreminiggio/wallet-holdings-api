<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

/**
 * Tracks how far historical reconstruction has progressed for each SUI address -- the state
 * needed to resume reconstruct.js's sequential wallet-coin replay (its checkpoint + running
 * balances) without starting over from genesis on every call, plus the date that state has
 * been verified complete through. See the README's "Historical reconstruction" migration note
 * for why this is a dedicated table rather than something derived from sui_holdings_cache.
 *
 * Append-only, same reasoning as SuiHoldingsCacheRepository: no confirmed UPDATE/UPSERT
 * support in the query builder used here, so each reconstruction run inserts a new row and
 * getCursor() picks the most recent one per address in PHP, rather than updating one row in
 * place.
 */
class SuiHoldingsCursorRepository
{
    private const TABLE = 'sui_holdings_reconstruction_cursor';

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * @return array{checkpoint: int, walletBalances: array<string, string>, cursorDate: string}|null
     */
    public function getCursor(string $address): ?array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('checkpoint, wallet_balances_json, cursor_date, updated_at')
                ->where('address = :address')
            ,
            ['address' => $address]
        );

        $latest = null;
        $latestUpdatedAt = null;

        foreach ($rows as $row) {
            $updatedAt = (int) $row['updated_at'];

            if ($latestUpdatedAt === null || $updatedAt > $latestUpdatedAt) {
                $latestUpdatedAt = $updatedAt;
                $latest = [
                    'checkpoint' => (int) $row['checkpoint'],
                    'walletBalances' => json_decode($row['wallet_balances_json'], true) ?? [],
                    'cursorDate' => $row['cursor_date']
                ];
            }
        }

        return $latest;
    }

    /**
     * @param array<string, string> $walletBalances coinType -> raw balance (as a string, since
     *                                               these can exceed PHP int/float precision --
     *                                               same reasoning as elsewhere in this codebase)
     */
    public function storeCursor(string $address, int $checkpoint, array $walletBalances, string $cursorDate): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'address, checkpoint, wallet_balances_json, cursor_date, updated_at',
                    ':address, :checkpoint, :wallet_balances_json, :cursor_date, :updated_at'
                )
            ,
            [
                'address' => $address,
                'checkpoint' => $checkpoint,
                'wallet_balances_json' => json_encode($walletBalances),
                'cursor_date' => $cursorDate,
                'updated_at' => time()
            ]
        );
    }
}
