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
        $zerionApiKey = $config['zerion']['api_key'] ?? '';

        if ($zerionApiKey === '') {
            http_response_code(503);
            echo json_encode(['message' => 'This endpoint requires a Zerion API key. '
                . 'Register for free at https://dashboard.zerion.io/ and add it to config.php '
                . 'under zerion.api_key.']);

            return;
        }

        $client = new ZerionClient($zerionApiKey);

        $walletPositions = $client->getWalletPositions($address);

        if (is_string($walletPositions)) {
            [$statusCode, $body] = $this->buildZerionErrorResponse($walletPositions);
            http_response_code($statusCode);
            echo json_encode($body);

            return;
        }

        $defiPositions = $client->getDefiPositions($address);

        if (is_string($defiPositions)) {
            [$statusCode, $body] = $this->buildZerionErrorResponse($defiPositions);
            http_response_code($statusCode);
            echo json_encode($body);

            return;
        }

        // Merge wallet and DeFi positions, then group by chain.
        $allPositions = [...$walletPositions, ...$defiPositions];
        $byChain = [];

        foreach ($allPositions as $position) {
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

        http_response_code(200);
        echo json_encode([
            'address' => $address,
            'as_of' => gmdate('Y-m-d'),
            'holdings' => $byChain
        ]);
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
                                    . '"polygon", "binance-smart-chain"). Only chains where this wallet '
                                    . 'has a non-zero position appear.',
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
                                            'type' => 'array',
                                            'description' => 'DeFi protocol positions (staked, locked, LP, etc.). '
                                                . 'Aave/Compound deposits typically appear as aTokens in '
                                                . 'the tokens array rather than here.',
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
