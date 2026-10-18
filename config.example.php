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
    ],
    'rpc' => [
        // Used by CompoundHoldingsClient / AaveHoldingsClient (the "compound" and "aave"
        // keys inside each chain's "defi" object under /holdings-now) to read on-chain
        // contract data directly via eth_call -- no API key needed for this part. These
        // same values are also baked in as defaults directly in App.php
        // (App::DEFAULT_RPC_URLS), so this whole section can be omitted entirely from
        // config.php and things still work -- only add a chain here if you want to
        // override it with your own provider (Alchemy/Infura/QuickNode), e.g. because the
        // public default is rate-limiting you.
        'ethereum' => 'https://ethereum-rpc.publicnode.com',
        'base' => 'https://base-rpc.publicnode.com',
        'polygon' => 'https://polygon-bor-rpc.publicnode.com',
        'arbitrum' => 'https://arbitrum-one-rpc.publicnode.com',
        'optimism' => 'https://optimism-rpc.publicnode.com'
    ],
    'reconstruction' => [
        // Used only by the multichain historical reconstruction (work in progress, see
        // MULTICHAIN-RECONSTRUCTION-PLAN.md). Kept apart from 'rpc' above on purpose: that
        // section only needs "latest" reads, while reconstruction needs archive access and
        // eth_getLogs, which is chain-specific (see MULTICHAIN-HOLDINGS.md, "RPC endpoints
        // used"). Per chain: a list of providers in priority order (first = primary, the
        // others are fallbacks); each is {name, url}, or just a URL string (the name is then
        // derived from the host). Provider names must match [a-z0-9][a-z0-9_-]* because
        // they end up in lock file names. Configuring a chain REPLACES its defaults.
        //
        // This whole section can be omitted: App-level defaults (EvmReconstructionConfig::
        // DEFAULT_PROVIDERS) cover Ethereum, Base and Polygon with keyless drpc.org
        // endpoints. BSC has NO keyless archive provider: add a free NodeReal key below, or
        // BSC cannot be reconstructed.
        'rpc' => [
            'binance-smart-chain' => [
                ['name' => 'nodereal', 'url' => 'https://bsc-mainnet.nodereal.io/v1/YOUR_KEY']
            ],
            // Optional, e.g. a second Ethereum provider (NodeReal's real eth_getLogs cap is
            // ~49,999 blocks, against ~101 on drpc.org, so it is much faster for the scan):
            // 'ethereum' => [
            //     ['name' => 'nodereal', 'url' => 'https://eth-mainnet.nodereal.io/v1/YOUR_KEY'],
            //     ['name' => 'drpc', 'url' => 'https://eth.drpc.org']
            // ],
        ],
        // Directory for the per-(chain, provider) lock files. Must be writable by the web
        // server user and NOT inside the web root. Optional: defaults to a
        // 'wallet-holdings-api-locks' directory under the system temp dir.
        'lock_directory' => ''
    ]
];
