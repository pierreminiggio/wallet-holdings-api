<?php

return [
    'db' => [
        'host' => '',
        'database' => 'wallet_holdings_api',
        'username' => '',
        'password' => ''
    ],
    'etherscan' => [
        // Optional. Free, no credit card required: https://etherscan.io/myapikey
        // Used as a fallback when Routescan persistently fails for a given wallet+call
        // (confirmed to happen for specific wallets on specific endpoints in practice,
        // even though Routescan works fine for most wallets). Leave blank to disable the
        // fallback entirely -- a persistent Routescan failure will then surface as-is.
        'api_key' => ''
    ],
    'zerion' => [
        // Required for /holdings-now. Free tier: 2,000 requests/day, no credit card.
        // Register at: https://dashboard.zerion.io/
        'api_key' => ''
    ],
    'github' => [
        // Required for /sui-holdings-now. A GitHub personal access token with permission
        // to trigger workflow runs and read Actions artifacts on
        // pierreminiggio/sui-navi-report. Without this, /sui-holdings-now returns a 503
        // (unless a fresh-enough cached result already exists for the requested address).
        'token' => ''
    ]
];
