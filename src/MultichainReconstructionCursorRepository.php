<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

/**
 * The resumable state of the multichain historical reconstruction, one row per advancement per
 * (address, chain), the newest winning (MULTICHAIN-RECONSTRUCTION-PLAN.md, Table 2).
 *
 * Why a cursor and not the cache rows: a cache row only lists tokens with a current balance, so a
 * token that was held and then sold to zero disappears from later rows. The discovered token set and
 * how far logs have been scanned cannot be recovered from the rows; they live here.
 *
 * Append-only like the rest of this schema (the query builder has no confirmed UPDATE/UPSERT), and the
 * newest row is picked in PHP by highest `id`, NOT by `updated_at`: several rows can share a second.
 */
class MultichainReconstructionCursorRepository
{
    private const TABLE = 'multichain_reconstruction_cursor';

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * @return array{state: EvmLogScanState, cursorDate: ?string, firstActivityBlock: ?int}|null null: no cursor yet
     */
    public function find(string $address, string $chain): ?array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('id, last_scanned_block, step_size, tokens_json, first_activity_block, cursor_date')
                ->where('address = :address AND chain = :chain')
            ,
            ['address' => strtolower($address), 'chain' => $chain]
        );

        $latest = null;

        foreach ($rows as $row) {
            if ($latest === null || (int) $row['id'] > (int) $latest['id']) {
                $latest = $row;
            }
        }

        if ($latest === null) {
            return null;
        }

        $tokens = json_decode((string) $latest['tokens_json'], true);

        return [
            'state' => new EvmLogScanState(
                (int) $latest['last_scanned_block'],
                max(1, (int) $latest['step_size']),
                is_array($tokens) ? array_values($tokens) : [],
            ),
            'cursorDate' => $latest['cursor_date'] !== null && $latest['cursor_date'] !== '' ? (string) $latest['cursor_date'] : null,
            'firstActivityBlock' => $latest['first_activity_block'] !== null ? (int) $latest['first_activity_block'] : null,
        ];
    }

    /**
     * @param string|null $cursorDate The date the walk has FULLY completed through (all its rows written),
     *                                or null until the first day is done. A mid-scan progress row passes the
     *                                unchanged value, only the scan state moves.
     */
    public function save(string $address, string $chain, EvmLogScanState $state, ?string $cursorDate, ?int $firstActivityBlock): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'address, chain, last_scanned_block, step_size, tokens_json, first_activity_block, cursor_date, updated_at',
                    ':address, :chain, :last_scanned_block, :step_size, :tokens_json, :first_activity_block, :cursor_date, :updated_at'
                )
            ,
            [
                'address' => strtolower($address),
                'chain' => $chain,
                'last_scanned_block' => $state->getLastScannedBlock(),
                'step_size' => $state->getStepSize(),
                'tokens_json' => json_encode($state->tokens()),
                'first_activity_block' => $firstActivityBlock,
                'cursor_date' => $cursorDate,
                'updated_at' => time()
            ]
        );
    }
}
