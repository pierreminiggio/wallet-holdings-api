<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

/**
 * Date-to-block cache, per (chain, day), NOT per wallet: the end block of a day is the same
 * for every wallet, so every wallet shares one resolved block (MULTICHAIN-RECONSTRUCTION-PLAN.md,
 * Table 1). Only COMPLETED days are ever stored (EvmBlockResolver guarantees it): today's end
 * block does not exist yet.
 *
 * Append-only like the rest of this schema (no confirmed UPDATE/UPSERT in the query builder
 * used here). Two racing requests may insert the same (chain, day) twice with the same value,
 * which is harmless, so find() just takes any row.
 */
class EvmBlockByDateRepository
{
    private const TABLE = 'evm_block_by_date';

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * @param string $date YYYY-MM-DD (UTC)
     *
     * @return int|null The stored end block, or null when this (chain, day) was never resolved.
     */
    public function find(string $chain, string $date): ?int
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('end_block')
                ->where('chain = :chain AND day_date = :day_date')
            ,
            ['chain' => $chain, 'day_date' => $date]
        );

        foreach ($rows as $row) {
            return (int) $row['end_block'];
        }

        return null;
    }

    /**
     * @param string $date YYYY-MM-DD (UTC), a completed day
     */
    public function store(string $chain, string $date, int $endBlock): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'chain, day_date, end_block, resolved_at',
                    ':chain, :day_date, :end_block, :resolved_at'
                )
            ,
            [
                'chain' => $chain,
                'day_date' => $date,
                'end_block' => $endBlock,
                'resolved_at' => time()
            ]
        );
    }
}
