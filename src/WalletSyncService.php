<?php

namespace App;

class WalletSyncService
{
    public function __construct(
        private EtherscanCompatibleClient $primaryClient,
        private ?EtherscanCompatibleClient $fallbackClient,
        private WalletDataRepository $repository,
        private HoldingsCalculator $calculator
    ) {
    }

    /**
     * Ensures this wallet+network's cached data covers everything up to $untilTimestamp,
     * fetching only the gap since the last sync (or everything, on a first-ever sync).
     * Never fetches anything beyond $untilTimestamp, per the "only up to the requested
     * date" requirement: a later request for an even later date will simply sync further
     * next time it's needed, rather than this call eagerly fetching "up to now".
     *
     * @return string|null Null on success, or one of EtherscanCompatibleClient::ERROR_*
     *                       on failure. (Not a true|string union, since standalone "true"
     *                       as a type requires PHP 8.2+ and this project targets PHP 8.0.)
     */
    public function syncUpTo(string $address, string $network, int $untilTimestamp): ?string
    {
        $chainId = Network::chainId($network);
        $syncState = $this->repository->getSyncState($address, $network);

        if ($syncState !== null && $syncState['lastTimestamp'] >= $untilTimestamp) {
            // Already synced at least as far as the requested date; the cached events
            // are sufficient and no network call is needed.
            return null;
        }

        $startBlock = $syncState['lastBlock'] ?? 0;

        $normalTxs = $this->fetchWithFallback(
            fn (EtherscanCompatibleClient $client) => $client->getNormalTransactions($chainId, $address, $startBlock, $untilTimestamp)
        );

        if (is_string($normalTxs)) {
            return $normalTxs;
        }

        $internalTxs = $this->fetchWithFallback(
            fn (EtherscanCompatibleClient $client) => $client->getInternalTransactions($chainId, $address, $startBlock, $untilTimestamp)
        );

        if (is_string($internalTxs)) {
            return $internalTxs;
        }

        $tokenTransfers = $this->fetchWithFallback(
            fn (EtherscanCompatibleClient $client) => $client->getTokenTransfers($chainId, $address, $startBlock, $untilTimestamp)
        );

        if (is_string($tokenTransfers)) {
            return $tokenTransfers;
        }

        $nativeEvents = $this->calculator->buildNativeEvents($address, $normalTxs, $internalTxs);
        $tokenEvents = $this->calculator->buildTokenEvents($address, $tokenTransfers);

        $this->repository->storeNativeEvents($address, $network, $nativeEvents);
        $this->repository->storeTokenEvents($address, $network, $tokenEvents);

        // The new high-water mark is the latest block actually seen across all three
        // sources (some networks/wallets may have activity in one source but not others
        // in a given range), or $untilTimestamp's corresponding block if nothing at all
        // was found, so that an inactive wallet doesn't get re-synced from block 0 every
        // single time it's queried.
        $latestBlockSeen = $this->findLatestBlock($normalTxs, $internalTxs, $tokenTransfers);
        $latestTimestampSeen = $this->findLatestTimestamp($normalTxs, $internalTxs, $tokenTransfers);

        $newBlock = max($startBlock, $latestBlockSeen ?? $startBlock);
        $newTimestamp = max($syncState['lastTimestamp'] ?? 0, $latestTimestampSeen ?? 0, $untilTimestamp);

        // We know for certain there is nothing left to fetch before $untilTimestamp (every
        // page was consumed without hitting the cutoff), so it's safe to mark the sync as
        // caught up to $untilTimestamp even if no events happened to land exactly on it.
        $this->repository->setSyncState($address, $network, $newBlock, $newTimestamp);

        return null;
    }

    /**
     * Tries the primary client first; if it fails with a generic, persistent upstream
     * error (i.e. it already exhausted its own retries), and a fallback client is
     * configured, tries that instead. This is specifically for the case where one
     * provider's indexer has a genuine data problem with a particular wallet+endpoint
     * combination -- confirmed to happen in practice: a real wallet's internal-transaction
     * data was found to fail consistently on Routescan while working fine on Etherscan,
     * for that wallet specifically, while every other wallet and endpoint worked fine on
     * Routescan. Rate-limit, invalid-address, and truncated-result errors are NOT retried
     * against the fallback, since switching providers wouldn't fix any of those: a rate
     * limit and a too-large result set are about volume, not data correctness, and an
     * invalid address is invalid on both.
     *
     * @param callable(EtherscanCompatibleClient): (list<array<string, mixed>>|string) $fetch
     *
     * @return list<array<string, mixed>>|string
     */
    private function fetchWithFallback(callable $fetch): array|string
    {
        $result = $fetch($this->primaryClient);

        if (! is_string($result) || $result !== EtherscanCompatibleClient::ERROR_UPSTREAM) {
            return $result;
        }

        if ($this->fallbackClient === null) {
            return $result;
        }

        return $fetch($this->fallbackClient);
    }

    /**
     * @param list<array<string, mixed>> ...$sources
     */
    private function findLatestBlock(array ...$sources): ?int
    {
        $latest = null;

        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                $block = (int) $row['blockNumber'];

                if ($latest === null || $block > $latest) {
                    $latest = $block;
                }
            }
        }

        return $latest;
    }

    /**
     * @param list<array<string, mixed>> ...$sources
     */
    private function findLatestTimestamp(array ...$sources): ?int
    {
        $latest = null;

        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                $timestamp = (int) $row['timeStamp'];

                if ($latest === null || $timestamp > $latest) {
                    $latest = $timestamp;
                }
            }
        }

        return $latest;
    }
}
