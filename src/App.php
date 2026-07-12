<?php

namespace App;

use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

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
        // X is still in progress; treat an omitted date the same as today (UTC).
        $targetDate = $requestedDate ?? gmdate('Y-m-d');

        $config = $this->loadConfig();
        $fetcher = $this->createDatabaseFetcher($config);

        // This endpoint is a pure cache lookup: it returns whatever a prior /holdings-now
        // call for this address already cached, and nothing else. There is deliberately no
        // historical reconstruction here right now -- an earlier transaction-replay approach
        // (walking each network's full tx history to derive a past balance) was tried and
        // removed; see AGENTS.md if a reconstruction approach is revisited later.
        //
        // Single source: multichain_holdings_cache (the full /holdings-now response cache).
        // This is a deliberate choice, not an oversight -- an earlier version of this
        // endpoint additionally fell back to zerion_position (Zerion-only position history,
        // which genuinely accumulates a row per day, unlike this single-row-per-address
        // cache) to cover a wider date range. That fallback was removed because its data
        // never included compound/aave positions, and those are a hard requirement here, not
        // an optional nice-to-have -- a response with holdings but silently missing defi
        // positions is worse than a 404. The real trade-off this accepts: /holdings?date=X
        // now only ever has a hit for the single most recent date each address happens to
        // have been fetched on via /holdings-now, not genuine multi-day history. See
        // HoldingsNowCacheRepository's class docblock and AGENTS.md Part 3 for more.
        $multichainCache = (new HoldingsNowCacheRepository($fetcher))->getCacheForDate($address, $targetDate);

        if ($multichainCache === null) {
            http_response_code(404);
            echo json_encode(['message' => 'No cached holdings found for ' . $address . ' on '
                . $targetDate . '. This endpoint only serves the single most recent date this '
                . 'address was cached on by a prior call to /holdings-now/' . $address . ' -- it '
                . 'does not reconstruct historical data, and does not keep multiple past dates '
                . 'cached. Call /holdings-now/' . $address . ' to cache today\'s snapshot, then '
                . 'query /holdings/' . $address . ' (or with today\'s date) for it.']);

            return;
        }

        $cachedResponse = json_decode($multichainCache['responseJson'], true);

        $response = [
            'address' => $address,
            'date' => $targetDate,
            'source' => 'multichain_cache',
            'holdings' => $cachedResponse['holdings'] ?? []
        ];

        // Carry over any partial on-chain read failures from when this was originally
        // cached (see CompoundHoldingsClient/AaveHoldingsClient's per-chain error
        // handling) rather than silently dropping that information here.
        if (! empty($cachedResponse['defi_errors'])) {
            $response['defi_errors'] = $cachedResponse['defi_errors'];
        }

        http_response_code(200);
        echo json_encode($response);
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

        $cacheRepository->store($address, $reportJson, gmdate('Y-m-d'), 'live');

        http_response_code(200);
        echo $reportJson;
    }

    /**
     * Serves the cached snapshot for this address on the given UTC calendar day. Unlike
     * /sui-holdings-now, a miss here doesn't necessarily fail -- it can trigger the
     * sui-navi-report repo's historical reconstruction workflow, resuming from wherever this
     * address's reconstruction cursor last left off (or from genesis if there isn't one yet),
     * and backfilling every day walked along the way so a later request for any date in that
     * range is a pure cache hit. See SuiHoldingsReconstructionService for the actual decision
     * logic (including why some misses are a 400 and others a 500).
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

        // Reconstruction can mean walking real on-chain history spanning months, across many
        // GraphQL round trips -- same reasoning as /sui-holdings-now's set_time_limit(0), just
        // with more headroom needed.
        set_time_limit(0);

        $cursorRepository = new SuiHoldingsCursorRepository($fetcher);
        $actionClient = new SuiWalletReconstructionActionClient($githubToken);
        $service = new SuiHoldingsReconstructionService($cacheRepository, $cursorRepository, $actionClient);

        $result = $service->resolve($address, $targetDate);

        switch ($result['outcome']) {
            case SuiHoldingsReconstructionService::OUTCOME_FOUND:
                http_response_code(200);
                echo $result['reportJson'];

                return;

            case SuiHoldingsReconstructionService::OUTCOME_BEFORE_GENESIS:
                http_response_code(400);
                echo json_encode(['message' => 'No holdings can exist for ' . $address . ' on '
                    . $targetDate . ' -- this predates the wallet\'s earliest on-chain activity.']);

                return;

            case SuiHoldingsReconstructionService::OUTCOME_INCONSISTENT:
                http_response_code(500);
                echo json_encode(['message' => 'Expected a cached snapshot for ' . $address . ' on '
                    . $targetDate . ' (this date falls within an already-reconstructed range), but '
                    . 'none was found. This indicates a data inconsistency in the cache, not a problem '
                    . 'with the request.']);

                return;

            default: // OUTCOME_ACTION_ERROR
                [$statusCode, $body] = $this->buildSuiActionErrorResponse(
                    $result['actionError'],
                    'reconstruction-result.json'
                );
                http_response_code($statusCode);
                echo json_encode($body);

                return;
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function buildSuiActionErrorResponse(string $errorCode, string $artifactName = 'wallet-report.json'): array
    {
        if ($errorCode === SuiWalletReportActionClient::ERROR_NO_ARTIFACT) {
            return [502, ['message' => 'The sui-navi-report GitHub Action run completed but produced no '
                . $artifactName . ' artifact to read.']];
        }

        if ($errorCode === SuiWalletReportActionClient::ERROR_INVALID_JSON) {
            return [502, ['message' => 'The ' . $artifactName . ' artifact from the sui-navi-report GitHub '
                . 'Action was not valid.']];
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
                'description' => 'Live current holdings and DeFi positions for EVM and SUI wallets '
                    . '(/holdings-now, /sui-holdings-now), plus historical lookups for both '
                    . '(/holdings, /sui-holdings) served from whatever has already been cached by a prior '
                    . 'live call on that exact date. For EVM wallets, /holdings is currently a pure cache '
                    . 'lookup with no historical reconstruction: a date not already cached by a prior '
                    . '/holdings-now call for that address returns a 404, rather than being computed on '
                    . 'demand. (SUI historical lookups work differently -- see /sui-holdings below -- since '
                    . 'that side has its own reconstruction mechanism via a separate GitHub Action.)',
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
                        'summary' => 'Get this wallet\'s SUI + NAVI holdings as of a given UTC day, live or historical',
                        'description' => 'Returns the cached snapshot for this address on the given UTC '
                            . 'calendar day if one exists (whether it came from a prior /'
                            . self::SUI_HOLDINGS_NOW_ENDPOINT . '/{address} call, or a prior call to this '
                            . 'endpoint). On a miss, this endpoint can trigger a historical reconstruction '
                            . 'run (resuming from wherever this address\'s reconstruction previously left '
                            . 'off, or from genesis if this is the first request for it), which can take '
                            . 'from under a minute up to several minutes depending on how much history '
                            . 'needs to be walked. Every day crossed during that walk gets its own cached '
                            . 'row -- including quiet days with no on-chain activity, which carry forward '
                            . 'the most recent known state -- so a later request for any date in that range '
                            . 'is a fast, pure cache hit rather than triggering reconstruction again.',
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
                                'description' => 'UTC calendar day (YYYY-MM-DD) to get holdings as of. '
                                    . 'Defaults to today (UTC) when omitted.'
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'The snapshot for that day -- either cached already, or '
                                    . 'just reconstructed. Same shape as /' . self::SUI_HOLDINGS_NOW_ENDPOINT
                                    . '/{address}\'s response -- wallet.coins[], navi.positions[], '
                                    . 'navi.healthFactor -- plus asOfDate and source ("live" or '
                                    . '"reconstructed") fields. Reconstructed snapshots always have '
                                    . 'navi.healthFactor and navi.positions[].priceUsd reflecting current '
                                    . 'prices, not historical ones -- see the sui-navi-report repo\'s README '
                                    . 'for why.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/SuiHoldingsSnapshot']
                                    ]
                                ]
                            ],
                            '400' => [
                                'description' => 'Invalid address, date not in YYYY-MM-DD format, or the '
                                    . 'requested date is before this wallet\'s earliest on-chain activity',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '500' => [
                                'description' => 'A cache row was expected for this date (it falls within '
                                    . 'an already-reconstructed range for this address) but is unexpectedly '
                                    . 'missing -- a data inconsistency, not a problem with the request',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '502' => [
                                'description' => 'The reconstruction GitHub Action run failed, produced no '
                                    . 'artifact, or produced invalid output',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '503' => [
                                'description' => 'No GitHub token configured for triggering the '
                                    . 'reconstruction Action',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ]
                        ]
                    ]
                ],
                '/' . self::HOLDINGS_ENDPOINT . '/{address}' => [
                    'get' => [
                        'summary' => 'Get a wallet\'s cached historical holdings for a given UTC date',
                        'description' => 'Pure cache lookup, not a live computation: returns whatever a prior '
                            . '/holdings-now/{address} call for this address already cached (including its '
                            . 'compound/aave on-chain defi positions), if that call happened to be made on the '
                            . 'exact requested UTC date. Since /holdings-now\'s cache only ever holds one row '
                            . 'per address (the latest fetch, overwritten each time), this only ever has a hit '
                            . "for the single most recent date this address was fetched on -- it is not a "
                            . 'multi-day history lookup, and there is no historical reconstruction for any '
                            . 'other date.',
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
                                'description' => 'UTC date to look up cached holdings for, in YYYY-MM-DD '
                                    . 'format. Defaults to today (UTC) when omitted.'
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Cached holdings for that date, grouped by chain',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/HoldingsForDateResult']
                                    ]
                                ]
                            ],
                            '400' => [
                                'description' => 'Invalid address or date',
                                'content' => [
                                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]
                                ]
                            ],
                            '404' => [
                                'description' => 'No cached holdings exist for this address on this exact date '
                                    . '-- either no /holdings-now call was ever made for it, or it was made on '
                                    . 'a different date than requested (only the single most recent fetch per '
                                    . 'address is retained)',
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
                        'description' => 'A single cached snapshot -- either exactly as produced by the '
                            . 'pierreminiggio/sui-navi-report GitHub Action\'s live path (source: "live") '
                            . 'and stored verbatim, or reconstructed for a past date (source: '
                            . '"reconstructed") by that same repo\'s historical reconstruction workflow. '
                            . 'See that project\'s own README/AGENTS.md for the authoritative schema and '
                            . 'how reconstruction works.',
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
                                'description' => 'UTC timestamp of when this snapshot was actually computed -- '
                                    . 'for a live snapshot, when the GitHub Action ran; for a reconstructed '
                                    . 'one, when this API backfilled it (which can be long after asOfDate).'
                            ],
                            'asOfDate' => [
                                'type' => 'string',
                                'format' => 'date',
                                'example' => '2025-09-22',
                                'description' => 'The UTC calendar date this snapshot\'s holdings represent. '
                                    . 'Only present on reconstructed snapshots.'
                            ],
                            'source' => [
                                'type' => 'string',
                                'enum' => ['live', 'reconstructed'],
                                'description' => '"live" for a snapshot fetched from the chain at the time '
                                    . 'shown in generatedAt; "reconstructed" for one derived from historical '
                                    . 'on-chain data for a past date. Reconstructed snapshots\' '
                                    . 'navi.healthFactor and navi.positions[].priceUsd reflect current '
                                    . 'prices, not asOfDate\'s -- see the sui-navi-report README for why.'
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
                    'HoldingsForDateResult' => [
                        'type' => 'object',
                        'description' => 'Whatever was already cached for this address on this exact UTC date '
                            . '-- always sourced from the full /holdings-now response cache (see the "source" '
                            . 'field). This endpoint never computes anything live.',
                        'properties' => [
                            'address' => ['type' => 'string', 'example' => '0x1234...'],
                            'date' => ['type' => 'string', 'format' => 'date', 'example' => '2026-06-28'],
                            'source' => [
                                'type' => 'string',
                                'enum' => ['multichain_cache'],
                                'description' => 'Always "multichain_cache" currently -- the only source this '
                                    . 'endpoint has. Kept as a field (rather than omitted, since there\'s only '
                                    . 'one possible value right now) in case a second source is reintroduced '
                                    . 'later.'
                            ],
                            'defi_errors' => [
                                'type' => 'object',
                                'nullable' => true,
                                'description' => 'Present only if the cached response had partial on-chain read '
                                    . 'failures -- carried over as-is from the original /holdings-now response. '
                                    . 'See CurrentHoldingsResult\'s "defi_errors" for the shape.'
                            ],
                            'holdings' => [
                                'type' => 'object',
                                'description' => 'Same shape as CurrentHoldingsResult.holdings (including each '
                                    . 'chain\'s "defi" being {compound, aave, other}, not a flat list) -- this '
                                    . 'is a direct copy of a past /holdings-now response\'s "holdings" key. '
                                    . 'Keyed by Zerion chain ID (e.g. "ethereum", "base", "polygon", '
                                    . '"binance-smart-chain"). Only chains with cached data for this date appear.',
                                'additionalProperties' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'native' => [
                                            'type' => 'object',
                                            'nullable' => true,
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
                                        ],
                                        'defi' => [
                                            'type' => 'object',
                                            'description' => 'See CurrentHoldingsResult.holdings.<chain>.defi '
                                                . 'for the full {compound, aave, other} shape.'
                                        ]
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
