<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

/**
 * The persistent "unresolved blocks" record of the multichain reconstruction
 * (MULTICHAIN-RECONSTRUCTION-PLAN.md, Table 3): a block the log scanner could not fetch even
 * alone, on every configured provider, after bounded retries, and proved block-specific (the
 * neighbouring block worked). The scan records it and moves on; a human then checks it on a
 * block explorer and, if acceptable, promotes it into EvmReconstructionChains::SKIPPED_BLOCKS.
 *
 * Append-only like the rest of this schema; the same (chain, block) may legitimately appear
 * several times (another wallet, or a later retry).
 */
class EvmUnresolvedBlockRepository
{
    private const TABLE = 'multichain_reconstruction_unresolved_block';

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    public function record(string $chain, int $block, string $address, string $lastError): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'chain, block_number, address, last_error, recorded_at',
                    ':chain, :block_number, :address, :last_error, :recorded_at'
                )
            ,
            [
                'chain' => $chain,
                'block_number' => $block,
                'address' => strtolower($address),
                'last_error' => $lastError,
                'recorded_at' => time()
            ]
        );
    }

    /**
     * Every recorded block of a chain, for a human to review.
     *
     * @return list<array{block: int, address: string, lastError: string, recordedAt: int}>
     */
    public function findByChain(string $chain): array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('block_number, address, last_error, recorded_at')
                ->where('chain = :chain')
            ,
            ['chain' => $chain]
        );

        $blocks = [];

        foreach ($rows as $row) {
            $blocks[] = [
                'block' => (int) $row['block_number'],
                'address' => $row['address'],
                'lastError' => $row['last_error'],
                'recordedAt' => (int) $row['recorded_at'],
            ];
        }

        usort($blocks, static fn (array $a, array $b): int => $a['block'] <=> $b['block']);

        return $blocks;
    }
}
