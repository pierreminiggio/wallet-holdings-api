<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

class ZerionPositionRepository
{
    private const TABLE = 'zerion_position';

    // Rate-limit window for /holdings-now: if the most recent fetch for this address
    // was within this many seconds, return cached data rather than calling Zerion again.
    // 10 minutes balances freshness against Zerion's 2,000 requests/day free-tier limit:
    // at 10 minutes per call, one address polled continuously uses 144 calls/day, leaving
    // ample headroom for multiple addresses or occasional manual refreshes.
    private const CACHE_TTL_SECONDS = 600;

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * Returns the most recent fetched_at timestamp for this address as a string
     * (e.g. "2026-07-03 16:33:09"), or null if never fetched.
     */
    public function getLastFetchedAt(string $address): ?string
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('MAX(fetched_at) AS last_fetched_at')
                ->where('address = :address')
            ,
            ['address' => strtolower($address)]
        );

        if (! $rows || $rows[0]['last_fetched_at'] === null) {
            return null;
        }

        return $rows[0]['last_fetched_at'];
    }

    /**
     * Whether the most recent cache for this address is within the TTL window,
     * meaning we can return cached data without calling Zerion again.
     */
    public function isCacheFresh(string $address): bool
    {
        $lastFetchedAt = $this->getLastFetchedAt($address);

        if ($lastFetchedAt === null) {
            return false;
        }

        $lastFetchedAtTimestamp = strtotime($lastFetchedAt);

        if ($lastFetchedAtTimestamp === false) {
            return false;
        }

        return (time() - $lastFetchedAtTimestamp) < self::CACHE_TTL_SECONDS;
    }

    /**
     * Whether the most recent cache for this address was fetched today (UTC).
     * Used to decide whether stale-but-same-day cache is acceptable as a Zerion
     * failure fallback: yesterday's holdings could be meaningfully different from
     * today's, so only same-day stale data is offered as a fallback.
     */
    public function isCacheFromToday(string $address): bool
    {
        $lastFetchedAt = $this->getLastFetchedAt($address);

        if ($lastFetchedAt === null) {
            return false;
        }

        return substr($lastFetchedAt, 0, 10) === gmdate('Y-m-d');
    }

    /**
     * Retrieves all cached positions for the most recent fetch of this address.
     *
     * @return list<ZerionPosition>
     */
    public function getLatestPositions(string $address): array
    {
        $lastFetchedAt = $this->getLastFetchedAt($address);

        if ($lastFetchedAt === null) {
            return [];
        }

        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('chain_id, symbol, contract_address, position_type, protocol_id, amount')
                ->where('address = :address AND fetched_at = :fetched_at')
            ,
            [
                'address' => strtolower($address),
                'fetched_at' => $lastFetchedAt
            ]
        );

        return $this->rowsToPositions($rows);
    }

    /**
     * Stores a batch of positions from a single Zerion API call, keyed by the
     * caller-supplied $fetchedAt timestamp (UTC, "YYYY-MM-DD HH:MM:SS").
     * ON DUPLICATE KEY UPDATE means re-fetching unchanged data is a safe no-op.
     *
     * @param list<ZerionPosition> $positions
     */
    public function storePositions(string $address, array $positions, string $fetchedAt): void
    {
        $normalizedAddress = strtolower($address);

        foreach ($positions as $position) {
            // Zerion returns updated_at as ISO 8601 (e.g. "2026-07-03T16:33:09Z");
            // MariaDB datetime columns expect "YYYY-MM-DD HH:MM:SS".
            $updatedAt = null;

            if ($position->updatedAt !== null) {
                $dt = \DateTime::createFromFormat(\DateTime::ATOM, $position->updatedAt);

                if ($dt === false) {
                    // Fallback for "Z" suffix that ATOM may not always match
                    $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $position->updatedAt);
                }

                if ($dt !== false) {
                    $dt->setTimezone(new \DateTimeZone('UTC'));
                    $updatedAt = $dt->format('Y-m-d H:i:s');
                }
            }

            $this->fetcher->exec(
                $this->fetcher
                    ->createQuery(self::TABLE)
                    ->insertInto(
                        'address, chain_id, symbol, contract_address, position_type, '
                            . 'protocol_id, amount, updated_at, fetched_at',
                        ':address, :chain_id, :symbol, :contract_address, :position_type, '
                            . ':protocol_id, :amount, :updated_at, :fetched_at'
                    )
                    ->onDuplicateKeyUpdate(
                        'amount = :amount, fetched_at = :fetched_at'
                    )
                ,
                [
                    'address' => $normalizedAddress,
                    'chain_id' => $position->chainId,
                    'symbol' => $position->symbol,
                    'contract_address' => $position->contractAddress,
                    'position_type' => $position->positionType,
                    'protocol_id' => $position->protocolId,
                    'amount' => $position->amount,
                    'updated_at' => $updatedAt,
                    'fetched_at' => $fetchedAt
                ]
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ZerionPosition>
     */
    private function rowsToPositions(array $rows): array
    {
        $positions = [];

        foreach ($rows as $row) {
            $positions[] = new ZerionPosition(
                chainId: $row['chain_id'],
                symbol: $row['symbol'],
                amount: $row['amount'],
                contractAddress: $row['contract_address'],
                isNative: $row['contract_address'] === null,
                positionType: $row['position_type'],
                protocolId: $row['protocol_id']
            );
        }

        return $positions;
    }
}
