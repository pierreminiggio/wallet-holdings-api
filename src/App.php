<?php

namespace App;

use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;
use PierreMiniggio\DatabaseFetcher\Exception\DatabaseFetcherException;

class App
{
    private const HOLDINGS_ENDPOINT = 'holdings';
    private const OPENAPI_ENDPOINT = 'openapi';

    public function run(string $path, ?string $queryParameters): void
    {
        header('Content-Type: application/json');

        $trimmedPath = trim($path, '/');

        if ($trimmedPath === self::OPENAPI_ENDPOINT) {
            $this->renderOpenApiDoc();

            return;
        }

        // A first-ever sync for a wallet can involve many sequential upstream calls across
        // 4 networks (each with its own short per-call timeout and a possible retry), which
        // can add up to longer than PHP's default execution time limit. Since this work is
        // legitimate (not a runaway loop) and the person querying has explicitly accepted
        // that a first sync may take a while, the time limit is removed for this endpoint
        // specifically rather than raised globally for every PHP script on the server.
        set_time_limit(0);

        $segments = explode('/', $trimmedPath);

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

        $fetcher = $this->createDatabaseFetcher();
        $repository = new WalletDataRepository($fetcher);
        $client = new RoutescanClient();
        $calculator = new HoldingsCalculator();
        $syncService = new WalletSyncService($client, $repository, $calculator);

        $holdingsByNetwork = [];

        try {
            foreach (Network::ALL as $network) {
                $syncResult = $syncService->syncUpTo($address, $network, $untilTimestamp);

                if (is_string($syncResult)) {
                    [$statusCode, $body] = $this->buildSyncErrorResponse($syncResult, $network, $client);
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

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function buildSyncErrorResponse(string $errorCode, string $network, RoutescanClient $client): array
    {
        if ($errorCode === RoutescanClient::ERROR_INVALID_ADDRESS) {
            return [400, ['message' => 'Invalid wallet address']];
        }

        if ($errorCode === RoutescanClient::ERROR_RATE_LIMITED) {
            return [503, ['message' => 'Rate limited by upstream source, please retry shortly']];
        }

        if ($errorCode === RoutescanClient::ERROR_TRUNCATED) {
            return [
                502,
                [
                    'message' => 'This wallet has too much activity on ' . $network
                        . ' to fully reconstruct in one request (upstream 10,000 record cap). '
                        . 'Try a more recent date, or contact support to sync this wallet in smaller steps.'
                ]
            ];
        }

        $debugInfo = $client->getLastRequestDebugInfo();
        error_log(sprintf(
            'Routescan upstream error on %s (action=%s, page=%s): httpCode=%d curlError=%s responseBody=%s',
            $network,
            $debugInfo['lastAction'] ?? 'unknown',
            $debugInfo['lastPage'] ?? 'unknown',
            $debugInfo['httpCode'],
            $debugInfo['curlError'] !== '' ? $debugInfo['curlError'] : '(none)',
            $debugInfo['responseBody'] !== null ? substr($debugInfo['responseBody'], 0, 500) : '(none)'
        ));

        return [502, ['message' => 'Could not retrieve wallet data for ' . $network]];
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

    private function createDatabaseFetcher(): DatabaseFetcher
    {
        $config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
        $dbConfig = $config['db'];

        return new DatabaseFetcher(new DatabaseConnection(
            $dbConfig['host'],
            $dbConfig['database'],
            $dbConfig['username'],
            $dbConfig['password']
        ));
    }

    private function renderOpenApiDoc(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Wallet Holdings API',
                'description' => 'Reconstructs what a wallet held, on a given date, by replaying its full '
                    . 'transaction history rather than relying on a (paid-only) historical balance snapshot. '
                    . 'Data is fetched from Routescan (Etherscan-compatible, keyless tier) and cached, so '
                    . "repeat queries for already-synced ranges never re-hit the upstream source.\n\n"
                    . 'Currently only Ethereum is active. The architecture supports Base, Polygon, and BNB '
                    . 'Smart Chain too, but each returned "chain not supported" or was otherwise unverified '
                    . 'against the live Routescan API and so is disabled for now (see Network::ALL) until '
                    . 'each is individually confirmed working, the same way Ethereum was.',
                'version' => '1.0.0'
            ],
            'paths' => [
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
                ]
            ],
            'components' => [
                'schemas' => [
                    'HoldingsResult' => [
                        'type' => 'object',
                        'properties' => [
                            'address' => ['type' => 'string', 'example' => '0x1234...'],
                            'date' => ['type' => 'string', 'format' => 'date', 'example' => '2024-01-15'],
                            'holdings' => [
                                'type' => 'object',
                                'description' => 'Keyed by network: currently ethereum only. polygon, bnb, and '
                                    . 'base are temporarily disabled pending re-verification against the live '
                                    . 'upstream API (polygon and base were both found to return "chain not '
                                    . 'supported" despite earlier docs/pages suggesting otherwise; bnb was '
                                    . 'never independently confirmed). They will be re-added one at a time '
                                    . 'as each is actually confirmed working.',
                                'properties' => [
                                    'ethereum' => ['$ref' => '#/components/schemas/NetworkHoldings']
                                    // 'polygon' => ['$ref' => '#/components/schemas/NetworkHoldings'],
                                    // 'bnb' => ['$ref' => '#/components/schemas/NetworkHoldings'],
                                    // 'base' => ['$ref' => '#/components/schemas/NetworkHoldings'],
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
