<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

class WalletDataRepository
{
    private const SYNC_TABLE = 'wallet_sync';
    private const NATIVE_EVENT_TABLE = 'wallet_native_event';
    private const TOKEN_EVENT_TABLE = 'wallet_token_event';

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * Returns [lastBlock, lastTimestamp] already synced for this wallet+network, or null
     * if it has never been synced before (a full sync from block 0 is needed). lastBlock
     * is where the next sync should resume from; lastTimestamp is compared against a newly
     * requested date to decide whether a sync is needed at all.
     *
     * @return array{lastBlock: int, lastTimestamp: int}|null
     */
    public function getSyncState(string $address, string $network): ?array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::SYNC_TABLE)
                ->select('last_block, last_timestamp')
                ->where('address = :address AND network = :network')
            ,
            [
                'address' => $address,
                'network' => $network
            ]
        );

        if (! $rows) {
            return null;
        }

        return [
            'lastBlock' => (int) $rows[0]['last_block'],
            'lastTimestamp' => (int) $rows[0]['last_timestamp']
        ];
    }

    public function setSyncState(string $address, string $network, int $block, int $timestamp): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::SYNC_TABLE)
                ->insertInto(
                    'address, network, last_block, last_timestamp',
                    ':address, :network, :last_block, :last_timestamp'
                )
                ->onDuplicateKeyUpdate('last_block = :last_block, last_timestamp = :last_timestamp')
            ,
            [
                'address' => $address,
                'network' => $network,
                'last_block' => $block,
                'last_timestamp' => $timestamp
            ]
        );
    }

    /**
     * Stores a batch of native-coin balance-affecting events (normal tx value/gas and
     * internal tx value, already normalized to one signed amount per row by the caller).
     *
     * @param list<array{txHash: string, logIndex: int, blockNumber: int, timestamp: int, signedAmount: string}> $events
     */
    public function storeNativeEvents(string $address, string $network, array $events): void
    {
        foreach ($events as $event) {
            $this->fetcher->exec(
                $this->fetcher
                    ->createQuery(self::NATIVE_EVENT_TABLE)
                    ->insertInto(
                        'address, network, tx_hash, log_index, block_number, block_timestamp, signed_amount',
                        ':address, :network, :tx_hash, :log_index, :block_number, :block_timestamp, :signed_amount'
                    )
                    ->onDuplicateKeyUpdate('signed_amount = :signed_amount')
                ,
                [
                    'address' => $address,
                    'network' => $network,
                    'tx_hash' => $event['txHash'],
                    'log_index' => $event['logIndex'],
                    'block_number' => $event['blockNumber'],
                    'block_timestamp' => $event['timestamp'],
                    'signed_amount' => $event['signedAmount']
                ]
            );
        }
    }

    /**
     * Stores a batch of ERC-20 token transfer events.
     *
     * @param list<array{txHash: string, logIndex: int, blockNumber: int, timestamp: int, tokenContract: string, tokenSymbol: string, tokenDecimals: int, signedAmount: string}> $events
     */
    public function storeTokenEvents(string $address, string $network, array $events): void
    {
        foreach ($events as $event) {
            $this->fetcher->exec(
                $this->fetcher
                    ->createQuery(self::TOKEN_EVENT_TABLE)
                    ->insertInto(
                        'address, network, tx_hash, log_index, block_number, block_timestamp, '
                            . 'token_contract, token_symbol, token_decimals, signed_amount',
                        ':address, :network, :tx_hash, :log_index, :block_number, :block_timestamp, '
                            . ':token_contract, :token_symbol, :token_decimals, :signed_amount'
                    )
                    ->onDuplicateKeyUpdate('signed_amount = :signed_amount')
                ,
                [
                    'address' => $address,
                    'network' => $network,
                    'tx_hash' => $event['txHash'],
                    'log_index' => $event['logIndex'],
                    'block_number' => $event['blockNumber'],
                    'block_timestamp' => $event['timestamp'],
                    'token_contract' => $event['tokenContract'],
                    'token_symbol' => $event['tokenSymbol'],
                    'token_decimals' => $event['tokenDecimals'],
                    'signed_amount' => $event['signedAmount']
                ]
            );
        }
    }

    /**
     * Sum of native-coin signed amounts for this wallet+network, up to and including
     * the given timestamp. Summed in PHP using bcmath (rather than relying on unconfirmed
     * SQL aggregate/group-by support in the query builder) since wei-level amounts can
     * exceed PHP's native integer/float precision.
     */
    public function sumNativeBalance(string $address, string $network, int $upToTimestamp): string
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::NATIVE_EVENT_TABLE)
                ->select('signed_amount')
                ->where(
                    'address = :address AND network = :network AND block_timestamp <= :up_to_timestamp'
                )
            ,
            [
                'address' => $address,
                'network' => $network,
                'up_to_timestamp' => $upToTimestamp
            ]
        );

        $total = '0';

        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row['signed_amount']);
        }

        return $total;
    }

    /**
     * Sum of signed amounts per token contract for this wallet+network, up to and
     * including the given timestamp. Only returns tokens with a non-zero balance.
     * Aggregated in PHP for the same reason as sumNativeBalance() above.
     *
     * @return list<array{tokenContract: string, tokenSymbol: string, tokenDecimals: int, balance: string}>
     */
    public function sumTokenBalances(string $address, string $network, int $upToTimestamp): array
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TOKEN_EVENT_TABLE)
                ->select('token_contract, token_symbol, token_decimals, signed_amount')
                ->where(
                    'address = :address AND network = :network AND block_timestamp <= :up_to_timestamp'
                )
            ,
            [
                'address' => $address,
                'network' => $network,
                'up_to_timestamp' => $upToTimestamp
            ]
        );

        // Keyed by contract address: sums the running balance and remembers the most
        // recently seen symbol/decimals for that contract (a token's symbol/decimals
        // never actually change, but transfer rows always carry them along anyway).
        $totals = [];

        foreach ($rows as $row) {
            $contract = $row['token_contract'];

            if (! isset($totals[$contract])) {
                $totals[$contract] = [
                    'tokenContract' => $contract,
                    'tokenSymbol' => $row['token_symbol'],
                    'tokenDecimals' => (int) $row['token_decimals'],
                    'balance' => '0'
                ];
            }

            $totals[$contract]['balance'] = bcadd($totals[$contract]['balance'], (string) $row['signed_amount']);
        }

        $balances = [];

        foreach ($totals as $total) {
            if (bccomp($total['balance'], '0') !== 0) {
                $balances[] = $total;
            }
        }

        return $balances;
    }
}
