<?php

namespace App;

use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;
use PierreMiniggio\DatabaseFetcher\Exception\DatabaseFetcherException;

class App
{
    private const HOLDINGS_ENDPOINT = 'holdings';
    private const HOLDINGS_NOW_ENDPOINT = 'holdings-now';
    private const SUI_HOLDINGS_NOW_ENDPOINT = 'sui-holdings-now';
    private const SUI_HOLDINGS_ENDPOINT = 'sui-holdings';
    private const OPENAPI_ENDPOINT = 'openapi';

    public function run(string $path, ?string $queryParameters): void
    {
        header('Content-Type: application/json');

        $trimmedPath = trim($path, '/');

        if ($trimmedPath === self::OPENAPI_ENDPOINT) {
            $this->renderOpenApiDoc();

            return;
        }

        $segments = explode('/', $trimmedPath);

        if (count($segments) === 2 && $segments[0] === self::HOLDINGS_NOW_ENDPOINT) {
            $this->handleHoldingsNow($segments[1]);

            return;
        }

        if (count($segments) === 2 && $segments[0] === self::SUI_HOLDINGS_NOW_ENDPOINT) {
            $this->handleSuiHoldingsNow($segments[1]);

            return;
        }

        if (count($segments) === 2 && $segments[0] === self::SUI_HOLDINGS_ENDPOINT) {
            $this->handleSuiHoldingsForDate($segments[1], $queryParameters);

            return;
        }

        // A first-ever sync for a wallet can involve many sequential upstream calls across
        // 4 networks (each with its own short per-call timeout and a possible retry), which
        // can add up to longer than PHP's default execution time limit. Since this work is
        // legitimate (not a runaway loop) and the person querying has explicitly accepted
        // that a first sync may take a while, the time limit is removed for this endpoint
        // specifically rather than raised globally for every PHP script on the server.
        set_time_limit(0);

        if (count($segments) !== 2 || $segments[0] !== self::HOLDINGS_ENDPOINT) {
            http_response_code(404);

            return;
        }

        $address = $segments[1];

        if (! $this->isValidAddress($address)) {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid wallet address']);

            return;
        }

        $requestedDate = $this->getRequestedDate($queryParameters);

        if ($requestedDate === false) {
            http_response_code(400);
            echo json_encode(['message' => 'date must be in YYYY-MM-DD format']);

            return;
        }

        // "Today" isn't a meaningful cutoff for "what did the wallet hold on date X" if
        // X is still in progress; treat an omitted date the same as today (UTC), and use
        // the end of that UTC day as the cutoff so today's activity so far is included.
        $targetDate = $requestedDate ?? gmdate('Y-m-d');
        $untilTimestamp = $this->endOfDayTimestamp($targetDate);

        $config = $this->loadConfig();
        $fetcher = $this->createDatabaseFetcher($config);

        // Zerion cache bypass: if we have positions stored from /holdings-now calls made
        // on the requested date, use those directly rather than running the full
        // transaction-replay reconstruction. This is both faster and more reliable (no
        // risk of the internal-transaction indexing gaps that affect reconstruction on
        // Base), and builds historical coverage naturally as /holdings-now is called
        // frequently over time. Only bypasses networks that have Zerion cache -- any
        // network not covered falls through to the reconstruction path below.
        $zerionRepository = new ZerionPositionRepository($fetcher);
        $zerionPositions = $zerionRepository->getPositionsForDate($address, $targetDate);

        if (! empty($zerionPositions)) {
            // Group Zerion positions by chain -- Zerion uses its own chain ID strings
            // (e.g. "ethereum", "base", "binance-smart-chain"), so we return those
            // directly rather than mapping to Network::* constants.
            $zerionByChain = $this->groupPositionsByChain($zerionPositions);

            http_response_code(200);
            echo json_encode([
                'address' => $address,
                'date' => $targetDate,
                'source' => 'zerion_cache',
                'holdings' => $zerionByChain
            ]);

            return;
        }

        $repository = new WalletDataRepository($fetcher);
        $calculator = new HoldingsCalculator();

        $holdingsByNetwork = [];

        try {
            foreach (Network::ALL as $network) {
                [$primaryClient, $fallbackClient] = $this->createClientsForNetwork($network, $config);
                $syncService = new WalletSyncService($primaryClient, $fallbackClient, $repository, $calculator);

                $syncResult = $syncService->syncUpTo($address, $network, $untilTimestamp);

                if (is_string($syncResult)) {
                    // Whichever client actually produced the final error (the fallback,
                    // if one was configured and the primary's error was the kind that
                    // triggers it; otherwise the primary itself) is the one whose debug
                    // info is relevant to log.
                    $erroringClient = $fallbackClient !== null && $syncResult === EtherscanCompatibleClient::ERROR_UPSTREAM
                        ? $fallbackClient
                        : $primaryClient;

                    [$statusCode, $body] = $this->buildSyncErrorResponse($syncResult, $network, $erroringClient);
                    http_response_code($statusCode);
                    echo json_encode($body);

                    return;
                }

                $holdingsByNetwork[$network] = $this->buildNetworkHoldings(
                    $repository,
                    $calculator,
                    $address,
                    $network,
                    $targetDate,
                    $untilTimestamp
                );
            }
        } catch (DatabaseFetcherException $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Database error']);

            return;
        }

        http_response_code(200);
        echo json_encode([
            'address' => $address,
            'date' => $targetDate,
            'holdings' => $holdingsByNetwork
        ]);
    }

    private function handleHoldingsNow(string $address): void
    {
        if (! $this->isValidAddress($address)) {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid wallet address']);

            return;
        }

        $config = $this->loadConfig();
        $fetcher = $this->createDatabaseFetcher($config);

        // Whole-response cache: if a fresh-enough (< 2h) response was already computed
        // for this address -- including the on-chain Compound/Aave reads below, which
        // are by far the most expensive part -- return it as-is and skip everything else.
        $holdingsNowCache = new HoldingsNowCacheRepository($fetcher);
        $freshCache = $holdingsNowCache->getFreshCache($address);

        if ($freshCache !== null) {
            http_response_code(200);
            echo $freshCache['responseJson'];

            return;
        }

        // Building a fresh response below can involve many sequential upstream calls
        // (Zerion, plus up to 5 chains x 30+ Aave reserves x 2 eth_calls each), which can
        // add up to longer than PHP's default execution time limit -- same reasoning as
        // App::run()'s set_time_limit(0) call for /holdings.
        set_time_limit(0);

        $zerionApiKey = $config['zerion']['api_key'] ?? '';

        if ($zerionApiKey === '') {
            http_response_code(503);
            echo json_encode(['message' => 'This endpoint requires a Zerion API key. '
                . 'Register for free at https://dashboard.zerion.io/ and add it to config.php '
                . 'under zerion.api_key.']);

            return;
        }

        $repository = new ZerionPositionRepository($fetcher);

        // Rate-limit cache: if the last fetch for this address was within the TTL window,
        // reuse cached Zerion data directly without hitting Zerion at all.
        if ($repository->isCacheFresh($address)) {
            $cachedPositions = $repository->getLatestPositions($address);
            $lastFetchedAt = $repository->getLastFetchedAt($address);

            $this->respondWithHoldingsNow(
                $address,
                $cachedPositions,
                ['hit' => true, 'fetched_at' => $lastFetchedAt],
                $config,
                $holdingsNowCache
            );

            return;
        }

        // Cache is stale (or absent) -- call Zerion for fresh data.
        $client = new ZerionClient($zerionApiKey);
        $walletPositions = $client->getWalletPositions($address);

        if (is_string($walletPositions)) {
            // Zerion failed. Fall back to same-day cache if available -- stale-but-today
            // data is likely still close to correct. Yesterday's or older data is not
            // offered as a fallback, since holdings can change materially overnight.
            if ($repository->isCacheFromToday($address)) {
                $cachedPositions = $repository->getLatestPositions($address);
                $lastFetchedAt = $repository->getLastFetchedAt($address);

                $this->respondWithHoldingsNow(
                    $address,
                    $cachedPositions,
                    [
                        'hit' => true,
                        'fetched_at' => $lastFetchedAt,
                        'warning' => 'Zerion call failed; returning stale same-day cache. '
                            . 'Data may not reflect the most recent activity.'
                    ],
                    $config,
                    $holdingsNowCache
                );

                return;
            }

            [$statusCode, $body] = $this->buildZerionErrorResponse($walletPositions);
            http_response_code($statusCode);
            echo json_encode($body);

            return;
        }

        $defiPositions = $client->getDefiPositions($address);

        if (is_string($defiPositions)) {
            // Same fallback logic as above for the DeFi call specifically.
            if ($repository->isCacheFromToday($address)) {
                $cachedPositions = $repository->getLatestPositions($address);
                $lastFetchedAt = $repository->getLastFetchedAt($address);

                $this->respondWithHoldingsNow(
                    $address,
                    $cachedPositions,
                    [
                        'hit' => true,
                        'fetched_at' => $lastFetchedAt,
                        'warning' => 'Zerion DeFi call failed; returning stale same-day cache. '
                            . 'DeFi positions may not reflect the most recent activity.'
                    ],
                    $config,
                    $holdingsNowCache
                );

                return;
            }

            [$statusCode, $body] = $this->buildZerionErrorResponse($defiPositions);
            http_response_code($statusCode);
            echo json_encode($body);

            return;
        }

        // Both calls succeeded -- store the fresh Zerion data and return it.
        $allPositions = [...$walletPositions, ...$defiPositions];
        $fetchedAt = gmdate('Y-m-d H:i:s');

        try {
            $repository->storePositions($address, $allPositions, $fetchedAt);
        } catch (\Throwable $e) {
            // A storage failure is non-fatal here: we have fresh data from Zerion and
            // can return it even if we couldn't cache it. Log it but don't fail the request.
            error_log('ZerionPositionRepository::storePositions failed: ' . $e->getMessage());
        }

        $this->respondWithHoldingsNow(
            $address,
            $allPositions,
            ['hit' => false, 'fetched_at' => $fetchedAt],
            $config,
            $holdingsNowCache
        );
    }

    // Default RPC endpoints for CompoundHoldingsClient / AaveHoldingsClient, used for any
    // chain not overridden in config.php's "rpc" section (or if that section is absent
    // entirely -- e.g. an existing config.php created before this section existed). These
    // are free public endpoints (publicnode.com); config.php values always take priority.
    private const DEFAULT_RPC_URLS = [
        'ethereum' => 'https://ethereum-rpc.publicnode.com',
        'base' => 'https://base-rpc.publicnode.com',
        'polygon' => 'https://polygon-bor-rpc.publicnode.com',
        'arbitrum' => 'https://arbitrum-one-rpc.publicnode.com',
        'optimism' => 'https://optimism-rpc.publicnode.com'
    ];

    /**
     * Assembles and outputs the final /holdings-now response: Zerion-derived
     * token/native/defi holdings, grouped by chain as before, except each chain's
     * existing "defi" key is now an object with "compound" and "aave" sub-keys holding
     * directly-verified on-chain Compound III / Aave V3 positions for that chain
     * (CompoundHoldingsClient / AaveHoldingsClient -- no third-party API, straight
     * eth_call reads), plus an "other" sub-key preserving whatever Zerion-sourced
     * misc-protocol positions (staking, LP, etc.) were there before. Also stores the
     * fully-assembled response in the whole-response cache so the next call within the
     * TTL window -- including this potentially-expensive on-chain enrichment -- is
     * skipped entirely.
     *
     * Only called from the "successful" branches of handleHoldingsNow(); error
     * responses (missing API key, Zerion failure with no usable fallback) are
     * deliberately never cached, matching the existing precedent in this codebase
     * (SuiHoldingsCacheRepository / handleSuiHoldingsNow only ever stores successful
     * reports too).
     *
     * @param list<ZerionPosition> $positions
     * @param array{hit: bool, fetched_at: ?string, warning?: string} $cacheInfo Zerion-level
     *        cache info (distinct from, and nested inside, the outer whole-response cache).
     * @param array<string, mixed> $config
     */
    private function respondWithHoldingsNow(
        string $address,
        array $positions,
        array $cacheInfo,
        array $config,
        HoldingsNowCacheRepository $holdingsNowCache
    ): void {
        $configRpcUrls = array_filter(
            (array) ($config['rpc'] ?? []),
            fn ($url) => is_string($url) && $url !== ''
        );
        // Config values override defaults per-chain; a chain missing from config.php
        // still gets a working default rather than silently being skipped.
        $rpcUrls = array_merge(self::DEFAULT_RPC_URLS, $configRpcUrls);

        $compound = (new CompoundHoldingsClient($rpcUrls))->getHoldings($address);
        $aave = (new AaveHoldingsClient($rpcUrls))->getHoldings($address);

        $byChain = $this->groupPositionsByChain($positions);

        // Merge compound/aave into each chain's existing "defi" key. Also covers chains
        // that have a Compound/Aave position but no Zerion-tracked native/token/defi
        // activity at all (e.g. a wallet that only ever interacted with a lending
        // protocol on some chain) -- such a chain wouldn't exist in $byChain yet, so it's
        // added here rather than that position being silently dropped.
        $allChains = array_unique(array_merge(
            array_keys($byChain),
            array_keys($compound['positions']),
            array_keys($aave['positions'])
        ));

        foreach ($allChains as $chain) {
            if (! isset($byChain[$chain])) {
                $byChain[$chain] = ['native' => null, 'tokens' => [], 'defi' => []];
            }

            $zerionDefi = $byChain[$chain]['defi']; // previous flat Zerion-sourced list

            $byChain[$chain]['defi'] = [
                'compound' => $compound['positions'][$chain] ?? [],
                'aave' => $aave['positions'][$chain] ?? null,
                'other' => $zerionDefi
            ];
        }

        // Per-chain RPC failures are non-fatal (see CompoundHoldingsClient::getHoldings /
        // AaveHoldingsClient::getHoldings docblocks) -- surface them for transparency
        // rather than silently dropping that chain's data, but only if something
        // actually failed, and separately from "holdings" so it doesn't disturb that
        // per-chain shape.
        $defiErrors = array_filter([
            'compound' => $compound['errors'],
            'aave' => $aave['errors']
        ], fn ($errors) => ! empty($errors));

        $response = [
            'address' => $address,
            'as_of' => gmdate('Y-m-d'),
            'cache' => $cacheInfo,
            'holdings' => $byChain
        ];

        if (! empty($defiErrors)) {
            $response['defi_errors'] = $defiErrors;
        }

        $responseJson = json_encode($response);

        try {
            $holdingsNowCache->store($address, $responseJson);
        } catch (\Throwable $e) {
            // Same reasoning as the ZerionPositionRepository::storePositions catch
            // above: a caching failure shouldn't fail a request that otherwise succeeded.
            error_log('HoldingsNowCacheRepository::store failed: ' . $e->getMessage());
        }

        http_response_code(200);
        echo $responseJson;
    }

    /**
     * Groups a flat list of ZerionPositions into the by-chain array shape used in API
     * responses: ['ethereum' => ['native' => ..., 'tokens' => [...], 'defi' => [...]]].
     * Note: the "defi" list produced here is a flat Zerion-sourced list; callers building
     * the /holdings-now response restructure it further (see respondWithHoldingsNow) into
     * {compound, aave, other} before it reaches the client.
     *
     * @param list<ZerionPosition> $positions
     *
     * @return array<string, array{native: array|null, tokens: list<array>, defi: list<array>}>
     */
    private function groupPositionsByChain(array $positions): array
    {
        $byChain = [];

        foreach ($positions as $position) {
            $chain = $position->chainId;

            if (! isset($byChain[$chain])) {
                $byChain[$chain] = ['native' => null, 'tokens' => [], 'defi' => []];
            }

            if ($position->isNative) {
                $byChain[$chain]['native'] = [
                    'symbol' => $position->symbol,
                    'amount' => $position->amount
                ];
            } elseif ($position->positionType === 'wallet') {
                $byChain[$chain]['tokens'][] = [
                    'symbol' => $position->symbol,
                    'contract' => $position->contractAddress,
                    'amount' => $position->amount
                ];
            } else {
                $byChain[$chain]['defi'][] = [
                    'symbol' => $position->symbol,
                    'contract' => $position->contractAddress,
                    'amount' => $position->amount,
                    'type' => $position->positionType,
                    'protocol' => $position->protocolId
                ];
            }
        }

        return $byChain;
    }

    private function handleSuiHoldingsNow(string $address): void
    {
        if (! $this->isValidSuiAddress($address)) {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid SUI wallet address -- expected a 0x-prefixed, '
                . '64-hex-character address.']);

            return;
        }

        $config = $this->loadConfig();
        $fetcher = $this->createDatabaseFetcher($config);
        $cacheRepository = new SuiHoldingsCacheRepository($fetcher);

        $cached = $cacheRepository->getFreshCache($address);

        if ($cached !== null) {
            http_response_code(200);
            echo $cached['reportJson'];

            return;
        }

        $githubToken = $config['github']['token'] ?? '';

        if ($githubToken === '') {
            http_response_code(503);
            echo json_encode(['message' => 'This endpoint requires a GitHub token with permission to '
                . 'trigger and read Actions runs/artifacts on pierreminiggio/sui-navi-report. Set it as '
                . 'github.token in config.php.']);

            return;
        }

        // Triggering and waiting on a live GitHub Actions run (polled every 30s until it
        // finishes) can legitimately take longer than PHP's default execution time limit,
        // so it's removed for this endpoint specifically -- the same reasoning as
        // App::run()'s set_time_limit(0) call for /holdings.
        set_time_limit(0);

        $actionClient = new SuiWalletReportActionClient($githubToken);
        $report = $actionClient->fetchReport($address);

        if (is_string($report)) {
            [$statusCode, $body] = $this->buildSuiActionErrorResponse($report);
            http_response_code($statusCode);
            echo json_encode($body);

            return;
        }

        $reportJson = json_encode($report, JSON_UNESCAPED_SLASHES);

        $cacheRepository->store($address, $reportJson);

        http_response_code(200);
        echo $reportJson;
    }

    /**
     * Serves the most recent cached /sui-holdings-now snapshot for this address that
     * falls within the given UTC calendar day. Unlike /sui-holdings-now, this endpoint
     * never triggers a fresh GitHub Action run itself -- it only ever reads what's
     * already been cached (by earlier /sui-holdings-now calls on that day), and fails
     * loudly with a 500 if nothing was cached that day rather than silently falling
     * back to a different day's snapshot.
     */
    private function handleSuiHoldingsForDate(string $address, ?string $queryParameters): void
    {
        if (! $this->isValidSuiAddress($address)) {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid SUI wallet address -- expected a 0x-prefixed, '
                . '64-hex-character address.']);

            return;
        }

        $requestedDate = $this->getRequestedDate($queryParameters);

        if ($requestedDate === false) {
            http_response_code(400);
            echo json_encode(['message' => 'date must be in YYYY-MM-DD format']);

            return;
        }

        // No date given -> today (UTC), consistent with /holdings' own default.
        $targetDate = $requestedDate ?? gmdate('Y-m-d');

        $config = $this->loadConfig();
        $fetcher = $this->createDatabaseFetcher($config);
        $cacheRepository = new SuiHoldingsCacheRepository($fetcher);

        $cached = $cacheRepository->getCacheForDate($address, $targetDate);

        if ($cached === null) {
            http_response_code(500);
            echo json_encode(['message' => 'No cached SUI holdings snapshot exists for ' . $address
                . ' on ' . $targetDate . '. This endpoint only serves snapshots already cached by '
                . 'a prior /' . self::SUI_HOLDINGS_NOW_ENDPOINT . '/{address} call on that day -- it '
                . 'never triggers a fresh fetch itself.']);

            return;
        }

        http_response_code(200);
        echo $cached['reportJson'];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function buildSuiActionErrorResponse(string $errorCode): array
    {
        if ($errorCode === SuiWalletReportActionClient::ERROR_NO_ARTIFACT) {
            return [502, ['message' => 'The sui-navi-report GitHub Action run completed but produced no '
                . 'wallet-report.json artifact to read.']];
        }

        if ($errorCode === SuiWalletReportActionClient::ERROR_INVALID_JSON) {
            return [502, ['message' => 'The wallet-report.json artifact from the sui-navi-report GitHub '
                . 'Action was not valid JSON.']];
        }

        return [502, ['message' => 'Could not run the sui-navi-report GitHub Action for this address. '
            . 'See the server log for details.']];
    }

    private function isValidSuiAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{64}$/', $address);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function buildZerionErrorResponse(string $errorCode): array
    {
        if ($errorCode === ZerionClient::ERROR_RATE_LIMITED) {
            return [503, ['message' => 'Rate limited by Zerion. '
                . 'The free tier allows 2,000 requests/day -- retry after a moment.']];
        }

        if ($errorCode === ZerionClient::ERROR_UNAUTHORIZED) {
            return [503, ['message' => 'Zerion API key is invalid or expired. '
                . 'Check the zerion.api_key value in config.php.']];
        }

        return [502, ['message' => 'Could not retrieve portfolio data from Zerion.']];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function buildSyncErrorResponse(string $errorCode, string $network, EtherscanCompatibleClient $client): array
    {
        if ($errorCode === EtherscanCompatibleClient::ERROR_INVALID_ADDRESS) {
            return [400, ['message' => 'Invalid wallet address']];
        }

        if ($errorCode === EtherscanCompatibleClient::ERROR_RATE_LIMITED) {
            return [503, ['message' => 'Rate limited by upstream source, please retry shortly']];
        }

        if ($errorCode === EtherscanCompatibleClient::ERROR_TRUNCATED) {
            return [
                502,
                [
                    'message' => 'This wallet has too much activity on ' . $network
                        . ' to fully reconstruct in one request (upstream 10,000 record cap). '
                        . 'Try a more recent date, or contact support to sync this wallet in smaller steps.'
                ]
            ];
        }

        if ($errorCode === EtherscanCompatibleClient::ERROR_NOT_YET_INDEXED) {
            return [
                503,
                [
                    'message' => 'The upstream explorer for ' . $network . ' reports that internal transactions '
                        . 'for part of the needed date range are not fully indexed yet. Since incoming and '
                        . 'outgoing transfers can both be missing from a partial result, using it could produce '
                        . 'either an understated or an overstated balance -- not a closer approximation, just a '
                        . 'different wrong number -- so no holdings are returned rather than risk an incorrect '
                        . 'one. This has been observed to persist for the same block range rather than resolve '
                        . 'quickly, so there is no reliable wait time to suggest.'
                ]
            ];
        }

        $debugInfo = $client->getLastRequestDebugInfo();
        $action = $debugInfo['lastAction'] ?? 'unknown';
        $provider = match (get_class($client)) {
            EtherscanApiClient::class => 'Etherscan (fallback)',
            BaseBlockscoutApiClient::class => 'Blockscout (Base)',
            default => 'Routescan'
        };

        error_log(sprintf(
            '%s upstream error on %s (action=%s, page=%s): httpCode=%d curlError=%s responseBody=%s',
            $provider,
            $network,
            $action,
            $debugInfo['lastPage'] ?? 'unknown',
            $debugInfo['httpCode'],
            $debugInfo['curlError'] !== '' ? $debugInfo['curlError'] : '(none)',
            $debugInfo['responseBody'] !== null ? substr($debugInfo['responseBody'], 0, 500) : '(none)'
        ));

        return [
            502,
            [
                'message' => 'Could not retrieve ' . $action . ' data for ' . $network . ' after retrying'
                    . ($provider === 'Etherscan (fallback)' ? ' (including a fallback provider)' : '') . '. '
                    . 'This can be ordinary transient upstream load, but a failure that persists across '
                    . 'every available provider for one specific wallet (while the same call works for '
                    . 'other wallets) can also indicate an upstream indexing issue specific to this address '
                    . '-- in that case retrying later may still help eventually, but there is no further '
                    . 'fallback available from this codebase. See the server log for the exact upstream '
                    . 'response.'
            ]
        ];
    }

    /**
     * Builds the (primary, fallback) client pair for a given network. Each network can have
     * a different primary provider, since not every provider covers every chain (Routescan
     * doesn't index Base at all, confirmed directly against its API, so Base needs its own
     * dedicated primary -- Blockscout's Base instance -- rather than sharing Ethereum's
     * Routescan+Etherscan pair).
     *
     * @return array{0: EtherscanCompatibleClient, 1: EtherscanCompatibleClient|null}
     */
    private function createClientsForNetwork(string $network, array $config): array
    {
        if ($network === Network::BASE) {
            // No fallback configured yet for Base: Etherscan's free tier doesn't cover it
            // either (confirmed directly: "Free API access is not supported for this
            // chain"), so there's currently no second option if Blockscout itself has a
            // persistent issue for a specific wallet, the same way Routescan did for one
            // wallet on Ethereum.
            return [new BaseBlockscoutApiClient(), null];
        }

        $etherscanApiKey = $config['etherscan']['api_key'] ?? '';
        $fallbackClient = $etherscanApiKey !== '' ? new EtherscanApiClient($etherscanApiKey) : null;

        return [new RoutescanApiClient(), $fallbackClient];
    }

    /**
     * @return array{native: array{symbol: string, amount: string}, tokens: list<array{symbol: string, contract: string, amount: string}>}
     */
    private function buildNetworkHoldings(
        WalletDataRepository $repository,
        HoldingsCalculator $calculator,
        string $address,
        string $network,
        string $targetDate,
        int $untilTimestamp
    ): array {
        $nativeBalance = $repository->sumNativeBalance($address, $network, $untilTimestamp);
        $tokenBalances = $repository->sumTokenBalances($address, $network, $untilTimestamp);

        $tokens = [];

        foreach ($tokenBalances as $token) {
            $tokens[] = [
                'symbol' => $token['tokenSymbol'],
                'contract' => $token['tokenContract'],
                'amount' => $calculator->toHumanAmount($token['balance'], $token['tokenDecimals'])
            ];
        }

        return [
            'native' => [
                'symbol' => Network::nativeSymbol($network, $targetDate),
                'amount' => $calculator->toHumanAmount($nativeBalance, 18)
            ],
            'tokens' => $tokens
        ];
    }

    private function isValidAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address);
    }

    /**
     * @return string|null|false The requested date, null if none was given (use today),
     *                            or false if the provided date is malformed.
     */
    private function getRequestedDate(?string $queryParameters): string|null|false
    {
        if ($queryParameters === null) {
            return null;
        }

        parse_str(ltrim($queryParameters, '?'), $parsedQuery);

        if (empty($parsedQuery['date'])) {
            return null;
        }

        $date = $parsedQuery['date'];

        if (! is_string($date) || ! $this->isValidDate($date)) {
            return false;
        }

        return $date;
    }

    private function isValidDate(string $date): bool
    {
        $dateTime = \DateTime::createFromFormat('Y-m-d', $date);

        return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
    }

    /**
     * Unix timestamp for 23:59:59 UTC on the given date, used as an inclusive cutoff so
     * "holdings on 2024-01-15" includes everything that happened that day.
     */
    private function endOfDayTimestamp(string $date): int
    {
        return \DateTime::createFromFormat('Y-m-d H:i:s', $date . ' 23:59:59', new \DateTimeZone('UTC'))
            ->getTimestamp();
    }

    private function createDatabaseFetcher(array $config): DatabaseFetcher
    {
        $dbConfig = $config['db'];

        return new DatabaseFetcher(new DatabaseConnection(
            $dbConfig['host'],
            $dbConfig['database'],
            $dbConfig['username'],
            $dbConfig['password']
        ));
    }

    private function loadConfig(): array
    {
        return require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
    }

    private function renderOpenApiDoc(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Wallet Holdings API',
                'description' => 'Reconstructs what a wallet held, on a given date, by replaying its full '
                    . 'transaction history rather than relying on a (paid-only) historical balance snapshot. '
                    . 'Ethereum uses Routescan (Etherscan-compatible, keyless tier) as its primary source, with '
                    . 'an optional Etherscan API fallback for calls that persistently fail on Routescan for a '
                    . "specific wallet. Base uses its own Blockscout instance instead (Routescan doesn't index\n"
                    . 'Base, and Etherscan\'s free tier excludes it too). Results are cached, so repeat queries '
                    . 'for already-synced ranges never re-hit any upstream source.\n\n'
                    . 'Currently Ethereum and Base are active. Polygon and BNB Smart Chain are not yet: each '
                    . 'returned "chain not supported" from Routescan or was otherwise unverified against a live '
                    . 'API and so is disabled for now (see Network::ALL) until each is individually confirmed '
                    . 'working with some provider, the same way Ethereum and Base were.',
                'version' => '1.0.0'
            ],
            'paths' => [
                '/' . self::SUI_HOLDINGS_NOW_ENDPOINT . '/{address}' => [
                    'get' => [
                        'summary' => 'Get a SUI wallet\'s current coin holdings and NAVI Protocol positions',
                        'description' => 'Serves a cached snapshot if one younger than 2 hours exists; '
                            . 'otherwise triggers a live pierreminiggio/sui-navi-report GitHub Action run '
                            . '(which can take up to a minute or so) and caches the result.',
                        'parameters' => [
                            [
                                'name' => 'address',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                                'description' => 'A 0x-prefixed, 64-hex-character SUI wallet address.'
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Wallet coin holdings and NAVI positions/health factor '
                                    . '(cached or freshly fetched) -- see the sui-navi-report project\'s '
                                    . 'own README for the exact schema.',
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]]
                            ],
                            '400' => [
                                'description' => 'Invalid address',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '502' => [
                                'description' => 'The GitHub Action run failed, or produced no/invalid artifact',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '503' => [
                                'description' => 'GitHub token missing',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ]
                        ]
                    ]
                ],
                '/' . self::SUI_HOLDINGS_ENDPOINT . '/{address}' => [
                    'get' => [
                        'summary' => 'Get the most recently cached SUI wallet snapshot for a given UTC day',
                        'description' => 'Reads-only: returns the most recent snapshot already cached by a '
                            . 'prior /' . self::SUI_HOLDINGS_NOW_ENDPOINT . '/{address} call that falls within '
                            . 'the given UTC calendar day. Never triggers a fresh GitHub Action run itself -- '
                            . 'if no snapshot was cached for that address on that day, this fails with a 500 '
                            . 'rather than falling back to a different day\'s data or fetching a new one.',
                        'parameters' => [
                            [
                                'name' => 'address',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                                'description' => 'A 0x-prefixed, 64-hex-character SUI wallet address.'
                            ],
                            [
                                'name' => 'date',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string', 'format' => 'date'],
                                'description' => 'UTC calendar day (YYYY-MM-DD) to look up the most recent '
                                    . 'cached snapshot for. Defaults to today (UTC) when omitted.'
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'The most recent cached snapshot from that day. Same shape '
                                    . 'as /' . self::SUI_HOLDINGS_NOW_ENDPOINT . '/{address}\'s response -- '
                                    . 'wallet.coins[], navi.positions[], navi.healthFactor -- with the '
                                    . 'address and generatedAt fields reflecting when that snapshot was '
                                    . 'originally fetched, not the time of this request.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/SuiHoldingsSnapshot']
                                    ]
                                ]
                            ],
                            '400' => [
                                'description' => 'Invalid address, or date not in YYYY-MM-DD format',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '500' => [
                                'description' => 'No cached snapshot exists for this address on that '
                                    . 'UTC day',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ]
                        ]
                    ]
                ],
                '/' . self::HOLDINGS_ENDPOINT . '/{address}' => [
                    'get' => [
                        'summary' => 'Get a wallet\'s holdings across all supported networks on a given date',
                        'parameters' => [
                            [
                                'name' => 'address',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                                'description' => 'A 0x-prefixed 40-hex-character EVM wallet address.'
                            ],
                            [
                                'name' => 'date',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string', 'format' => 'date'],
                                'description' => 'Date to reconstruct holdings for, in YYYY-MM-DD format. '
                                    . 'Defaults to today (UTC) when omitted.'
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Holdings per network',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/HoldingsResult']
                                    ]
                                ]
                            ],
                            '400' => [
                                'description' => 'Invalid address or date',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '502' => [
                                'description' => 'Could not retrieve data from the upstream source, '
                                    . 'or the wallet has too much activity to fully reconstruct in one request',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '503' => [
                                'description' => 'Rate limited by the upstream source; retry shortly',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ]
                        ]
                    ]
                ],
                '/' . self::HOLDINGS_NOW_ENDPOINT . '/{address}' => [
                    'get' => [
                        'summary' => 'Get a wallet\'s current holdings and DeFi positions across all chains, right now',
                        'description' => 'Returns live, directly-queried current balances from Zerion\'s '
                            . 'portfolio API, covering 60+ chains simultaneously in two calls (wallet tokens '
                            . 'and DeFi positions). Includes native coins, ERC-20 tokens, and protocol '
                            . 'positions (Aave deposits show as aTokens in wallet holdings; LP shares, '
                            . 'staked positions, and locked assets appear in the defi section). Requires a '
                            . 'Zerion API key in config.php (free tier: 2,000 requests/day). '
                            . 'Trade-off: current state only -- no historical date support.',
                        'parameters' => [
                            [
                                'name' => 'address',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                                'description' => 'A 0x-prefixed 40-hex-character EVM wallet address.'
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Current holdings grouped by chain',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/CurrentHoldingsResult']
                                    ]
                                ]
                            ],
                            '400' => [
                                'description' => 'Invalid address',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '502' => [
                                'description' => 'Could not retrieve portfolio data from Zerion',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '503' => [
                                'description' => 'Zerion API key missing, invalid, or rate-limited',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'components' => [
                'schemas' => [
                    'SuiHoldingsSnapshot' => [
                        'type' => 'object',
                        'description' => 'A single cached wallet-report.json snapshot, exactly as produced '
                            . 'by the pierreminiggio/sui-navi-report GitHub Action and stored verbatim -- '
                            . 'see that project\'s own README for the authoritative schema.',
                        'properties' => [
                            'address' => [
                                'type' => 'string',
                                'example' => '0x77ffeb08306a95f2386467002c71b33e8022bb2ae98dd57ebcdf00d316fccbea',
                                'description' => 'The SUI address this snapshot was generated for.'
                            ],
                            'generatedAt' => [
                                'type' => 'string',
                                'format' => 'date-time',
                                'example' => '2026-07-08T21:45:56.730Z',
                                'description' => 'UTC timestamp of when this snapshot was originally fetched '
                                    . '(i.e. when the underlying GitHub Action ran), not when it was read.'
                            ],
                            'wallet' => [
                                'type' => 'object',
                                'properties' => [
                                    'coins' => [
                                        'type' => 'array',
                                        'description' => 'Every coin/NFT type held directly in the wallet '
                                            . '(as opposed to supplied/borrowed on NAVI).',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'coinType' => [
                                                    'type' => 'string',
                                                    'description' => 'Fully-qualified on-chain coin type.',
                                                    'example' => '0x0000000000000000000000000000000000000000000000000000000000000002::sui::SUI'
                                                ],
                                                'symbol' => ['type' => 'string', 'example' => 'SUI'],
                                                'name' => ['type' => 'string', 'example' => 'Sui'],
                                                'decimals' => ['type' => 'integer', 'example' => 9],
                                                'rawBalance' => [
                                                    'type' => 'string',
                                                    'description' => 'Raw on-chain integer balance, as a '
                                                        . 'string (can exceed native integer precision).',
                                                    'example' => '5117254324'
                                                ],
                                                'amount' => [
                                                    'type' => 'number',
                                                    'description' => 'Human-readable amount (rawBalance '
                                                        . 'divided by 10^decimals).',
                                                    'example' => 5.117254324
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            'navi' => [
                                'type' => 'object',
                                'description' => 'NAVI Protocol lending/borrowing positions.',
                                'properties' => [
                                    'positions' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'market' => [
                                                    'type' => 'string',
                                                    'description' => 'Which NAVI market this position is in.',
                                                    'example' => 'main'
                                                ],
                                                'assetId' => ['type' => 'integer', 'example' => 32],
                                                'symbol' => ['type' => 'string', 'example' => 'WBTC'],
                                                'coinType' => ['type' => 'string'],
                                                'supplyBalance' => [
                                                    'type' => 'string',
                                                    'description' => 'Raw supplied balance, as a string.',
                                                    'example' => '7279723'
                                                ],
                                                'borrowBalance' => [
                                                    'type' => 'string',
                                                    'description' => 'Raw borrowed balance, as a string.',
                                                    'example' => '0'
                                                ],
                                                'supplyAmount' => ['type' => 'number', 'example' => 0.007279723],
                                                'borrowAmount' => ['type' => 'number', 'example' => 0],
                                                'priceUsd' => ['type' => 'number', 'example' => 62113.226]
                                            ]
                                        ]
                                    ],
                                    'healthFactor' => [
                                        'type' => 'number',
                                        'description' => 'NAVI account health factor across all positions; '
                                            . 'below 1 is eligible for liquidation.',
                                        'example' => 1.7883891539706314
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'CurrentHoldingsResult' => [
                        'type' => 'object',
                        'properties' => [
                            'address' => ['type' => 'string', 'example' => '0x1234...'],
                            'as_of' => [
                                'type' => 'string',
                                'format' => 'date',
                                'example' => '2026-06-28',
                                'description' => 'UTC date the query was made.'
                            ],
                            'holdings' => [
                                'type' => 'object',
                                'description' => 'Keyed by Zerion chain ID (e.g. "ethereum", "base", '
                                    . '"polygon", "binance-smart-chain"). Includes every chain where this '
                                    . 'wallet has a non-zero position of any kind (native, token, or defi), '
                                    . 'plus any chain with a Compound/Aave position even if Zerion tracked '
                                    . 'nothing else there.',
                                'additionalProperties' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'native' => [
                                            'type' => 'object',
                                            'nullable' => true,
                                            'properties' => [
                                                'symbol' => ['type' => 'string', 'example' => 'ETH'],
                                                'amount' => ['type' => 'string', 'example' => '0.023489']
                                            ]
                                        ],
                                        'tokens' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'symbol' => ['type' => 'string', 'example' => 'USDC'],
                                                    'contract' => ['type' => 'string', 'example' => '0xa0b8...'],
                                                    'amount' => ['type' => 'string', 'example' => '500.0']
                                                ]
                                            ]
                                        ],
                                        'defi' => [
                                            'type' => 'object',
                                            'description' => 'DeFi positions on this chain, split by source. '
                                                . '"compound"/"aave" are directly-verified on-chain reads '
                                                . '(raw eth_call against each protocol\'s own contracts -- no '
                                                . 'third-party API); "other" is whatever misc protocol positions '
                                                . '(staking, LP, etc.) Zerion reported for this chain, unrelated '
                                                . 'to Compound/Aave.',
                                            'properties' => [
                                                'compound' => [
                                                    'type' => 'array',
                                                    'description' => 'Empty if this wallet has no Compound '
                                                        . 'position on this chain. Currently tracks each '
                                                        . 'chain\'s USDC market only.',
                                                    'items' => [
                                                        'type' => 'object',
                                                        'properties' => [
                                                            'base' => ['type' => 'string', 'example' => 'USDC'],
                                                            'market' => [
                                                                'type' => 'string',
                                                                'description' => 'Comet proxy contract address.',
                                                                'example' => '0xb125E6687d4313864e53df431d5425969c15Eb2F'
                                                            ],
                                                            'supplied' => ['type' => 'string', 'example' => '0'],
                                                            'borrowed' => ['type' => 'string', 'example' => '7498.864669'],
                                                            'collateral' => [
                                                                'type' => 'array',
                                                                'items' => [
                                                                    'type' => 'object',
                                                                    'properties' => [
                                                                        'symbol' => ['type' => 'string', 'example' => 'WETH'],
                                                                        'amount' => ['type' => 'string', 'example' => '0.134']
                                                                    ]
                                                                ]
                                                            ]
                                                        ]
                                                    ]
                                                ],
                                                'aave' => [
                                                    'type' => 'object',
                                                    'nullable' => true,
                                                    'description' => 'null if this wallet has no Aave position '
                                                        . 'on this chain. Reserve list is discovered live from '
                                                        . 'this chain\'s Aave Pool, not hardcoded.',
                                                    'properties' => [
                                                        'reserves' => [
                                                            'type' => 'array',
                                                            'items' => [
                                                                'type' => 'object',
                                                                'properties' => [
                                                                    'symbol' => ['type' => 'string', 'example' => 'WETH'],
                                                                    'supplied' => ['type' => 'string', 'example' => '0.134'],
                                                                    'usedAsCollateral' => ['type' => 'boolean'],
                                                                    'variableDebt' => ['type' => 'string', 'example' => '0'],
                                                                    'stableDebt' => ['type' => 'string', 'example' => '0']
                                                                ]
                                                            ]
                                                        ],
                                                        'summary' => [
                                                            'type' => 'object',
                                                            'description' => 'Aggregated across every reserve on '
                                                                . 'this chain, from Aave\'s own getUserAccountData.',
                                                            'properties' => [
                                                                'totalCollateralUsd' => ['type' => 'string', 'example' => '4901.31014678'],
                                                                'totalDebtUsd' => ['type' => 'string', 'example' => '1805.27149713'],
                                                                'ltv' => [
                                                                    'type' => 'string',
                                                                    'description' => 'Percent, e.g. "80" means 80%.',
                                                                    'example' => '80'
                                                                ],
                                                                'liquidationThreshold' => ['type' => 'string', 'example' => '83'],
                                                                'healthFactor' => [
                                                                    'type' => 'string',
                                                                    'nullable' => true,
                                                                    'description' => 'Below 1 is eligible for '
                                                                        . 'liquidation. null means no debt (Aave '
                                                                        . 'returns "infinite").',
                                                                    'example' => '2.2534490952163145'
                                                                ]
                                                            ]
                                                        ]
                                                    ]
                                                ],
                                                'other' => [
                                                    'type' => 'array',
                                                    'description' => 'Misc Zerion-sourced defi positions on this '
                                                        . 'chain (staked, locked, LP, etc.), unrelated to '
                                                        . 'Compound/Aave.',
                                                    'items' => [
                                                        'type' => 'object',
                                                        'properties' => [
                                                            'symbol' => ['type' => 'string', 'example' => 'ETH'],
                                                            'contract' => ['type' => 'string', 'nullable' => true],
                                                            'amount' => ['type' => 'string', 'example' => '1.5'],
                                                            'type' => ['type' => 'string', 'example' => 'staked'],
                                                            'protocol' => ['type' => 'string', 'nullable' => true, 'example' => 'lido']
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            'defi_errors' => [
                                'type' => 'object',
                                'nullable' => true,
                                'description' => 'Present only if at least one chain\'s on-chain Compound/Aave '
                                    . 'read failed (e.g. RPC timeout). A failed chain is simply omitted from '
                                    . 'that chain\'s holdings.<chain>.defi.compound/aave rather than failing '
                                    . 'the whole request -- this is where that failure is surfaced instead. '
                                    . 'The whole /holdings-now response (including on-chain reads) is cached '
                                    . 'for 2 hours per address -- see the "cache" field for the *inner* '
                                    . 'Zerion-level cache status; there is currently no separate field '
                                    . 'exposing the outer 2-hour cache\'s own age.',
                                'properties' => [
                                    'compound' => [
                                        'type' => 'object',
                                        'description' => 'Chain => error code (e.g. "upstream_error").'
                                    ],
                                    'aave' => [
                                        'type' => 'object',
                                        'description' => 'Chain => error code (e.g. "upstream_error").'
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'HoldingsResult' => [
                        'type' => 'object',
                        'properties' => [
                            'address' => ['type' => 'string', 'example' => '0x1234...'],
                            'date' => ['type' => 'string', 'format' => 'date', 'example' => '2024-01-15'],
                            'holdings' => [
                                'type' => 'object',
                                'description' => 'Keyed by network: currently ethereum and base. polygon and bnb '
                                    . 'are temporarily disabled pending verification against a live upstream API '
                                    . '(polygon was found to return "chain not supported" on Routescan despite '
                                    . 'an earlier docs/page suggesting otherwise; bnb was never independently '
                                    . 'confirmed at all). They will be re-added one at a time as each is '
                                    . 'actually confirmed working with some provider.',
                                'properties' => [
                                    'ethereum' => ['$ref' => '#/components/schemas/NetworkHoldings'],
                                    'base' => ['$ref' => '#/components/schemas/NetworkHoldings']
                                    // 'polygon' => ['$ref' => '#/components/schemas/NetworkHoldings'],
                                    // 'bnb' => ['$ref' => '#/components/schemas/NetworkHoldings'],
                                ]
                            ]
                        ]
                    ],
                    'NetworkHoldings' => [
                        'type' => 'object',
                        'properties' => [
                            'native' => [
                                'type' => 'object',
                                'properties' => [
                                    'symbol' => ['type' => 'string', 'example' => 'ETH'],
                                    'amount' => ['type' => 'string', 'example' => '1.2345']
                                ]
                            ],
                            'tokens' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'symbol' => ['type' => 'string', 'example' => 'USDC'],
                                        'contract' => ['type' => 'string', 'example' => '0xa0b8...'],
                                        'amount' => ['type' => 'string', 'example' => '500.0']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string']
                        ]
                    ]
                ]
            ]
        ];

        $encodedSpec = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Wallet Holdings API documentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
    <script>
        window.onload = function () {
            SwaggerUIBundle({
                spec: {$encodedSpec},
                dom_id: '#swagger-ui'
            });
        };
    </script>
</body>
</html>
HTML;
    }
}
