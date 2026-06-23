# wallet-holdings-api

Reconstructs what an EVM wallet held — native coin and ERC-20/BEP-20 tokens — on a given date,
across Ethereum, Base, Polygon and BNB Smart Chain.

## Why "reconstruct" instead of "look up"

Free, keyless blockchain explorer APIs only expose an address's **current** balance directly.
Getting a **historical** balance for an arbitrary past date is normally a paid-tier feature
(both Etherscan and CoinGecko gate this behind paid plans). This API works around that by
fetching a wallet's *entire transaction history* (free, even on the keyless tier) and replaying
it to derive what the balance must have been on any given date — the same approach a block
explorer's own indexer uses internally, just done client-side.

This means the first time a given wallet+network is queried, the API may need to fetch and store
a potentially large amount of historical data; every subsequent query (even for a different date)
reuses what's already cached and only fetches the gap, if any.

## Endpoints

* `GET /holdings/{address}` - Holdings across all 4 networks, as of today (UTC).
* `GET /holdings/{address}?date=YYYY-MM-DD` - Holdings as of the end of the given UTC day.
* `GET /openapi` - Interactive API documentation (Swagger UI).

`{address}` must be a `0x`-prefixed, 40-hex-character EVM address (the same address format works
identically across all 4 networks, since they're all EVM-compatible).

### Example

```
GET /holdings/0x1234567890123456789012345678901234567890?date=2024-06-15
{
  "address": "0x1234567890123456789012345678901234567890",
  "date": "2024-06-15",
  "holdings": {
    "ethereum": {
      "native": { "symbol": "ETH", "amount": "1.5" },
      "tokens": [
        { "symbol": "USDC", "contract": "0xa0b8...", "amount": "500" }
      ]
    },
    "base": { "native": { "symbol": "ETH", "amount": "0" }, "tokens": [] },
    "polygon": { "native": { "symbol": "MATIC", "amount": "120.4" }, "tokens": [] },
    "bnb": { "native": { "symbol": "BNB", "amount": "0.2" }, "tokens": [] }
  }
}
```

Note `polygon`'s native symbol is `MATIC` for dates before 2024-09-04 and `POL` after, reflecting
Polygon's 1:1 token rebrand on that date — the underlying balance is continuous, only the label
changes depending on which date is queried.

## How balances are calculated

For each network, three data sources from [Routescan](https://routescan.io/) (an Etherscan-compatible
explorer API, used keyless/free here) are fetched and cached as raw events:

* **Normal transactions** (`txlist`) — direct sends/receives initiated by the wallet itself, plus the
  gas fee paid on every transaction the wallet sent (gas is deducted even if the transaction failed).
* **Internal transactions** (`txlistinternal`) — native-coin transfers triggered by a contract during
  execution (e.g. receiving funds from a DEX swap), which don't appear in the normal transaction list.
* **Token transfers** (`tokentx`) — every ERC-20/BEP-20 transfer event involving the wallet, across
  every token contract; this single source covers both "which tokens has this wallet ever touched"
  and "how much moved", so there's no need for a separate token-discovery step.

Each event is stored as one signed amount (positive = received, negative = sent, gas fees always
negative). A balance "as of date X" is just the sum of every cached event up to and including that
date — append-only, immutable data, so once a date range is synced it never needs to be re-fetched.

### Known precision limitation

Internal transactions are deduplicated using a hash derived from their `traceId` field (which
distinguishes multiple internal transfers within one parent transaction) combined into a bounded
integer via `crc32(...) % 1000000`. This has a theoretical, extremely unlikely collision risk
between two different internal transfers *within the exact same transaction* — in practice this
would require a transaction with an enormous number of internal calls to ever matter. Avoiding
this entirely would require widening the `log_index` column to a string, which wasn't done here
to keep the schema aligned with the other event table.

## Setup

1. `composer install`
2. `cp config.example.php config.php` and fill in your DB credentials
3. Ensure the `bcmath` PHP extension is enabled (used throughout for precise arbitrary-size
   integer arithmetic on wei-level amounts, which exceed native PHP int/float precision)
4. Run the migration below on your database
5. Point your webserver's document root to `public/`, or use the provided `.htaccess` with Apache

## Migration

```sql
CREATE TABLE `wallet_sync` (
  `id` int(11) NOT NULL,
  `address` varchar(42) NOT NULL,
  `network` varchar(16) NOT NULL,
  `last_block` bigint(20) NOT NULL,
  `last_timestamp` bigint(20) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `wallet_sync`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `address_network` (`address`, `network`);

ALTER TABLE `wallet_sync`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

CREATE TABLE `wallet_native_event` (
  `id` bigint(20) NOT NULL,
  `address` varchar(42) NOT NULL,
  `network` varchar(16) NOT NULL,
  `tx_hash` varchar(80) NOT NULL,
  `log_index` int(11) NOT NULL,
  `block_number` bigint(20) NOT NULL,
  `block_timestamp` bigint(20) NOT NULL,
  `signed_amount` decimal(40,0) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `wallet_native_event`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `address_network_tx_log` (`address`, `network`, `tx_hash`, `log_index`),
  ADD KEY `address_network_timestamp` (`address`, `network`, `block_timestamp`);

ALTER TABLE `wallet_native_event`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

CREATE TABLE `wallet_token_event` (
  `id` bigint(20) NOT NULL,
  `address` varchar(42) NOT NULL,
  `network` varchar(16) NOT NULL,
  `tx_hash` varchar(80) NOT NULL,
  `log_index` int(11) NOT NULL,
  `block_number` bigint(20) NOT NULL,
  `block_timestamp` bigint(20) NOT NULL,
  `token_contract` varchar(42) NOT NULL,
  `token_symbol` varchar(32) NOT NULL,
  `token_decimals` tinyint(4) NOT NULL,
  `signed_amount` decimal(50,0) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `wallet_token_event`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `address_network_tx_log` (`address`, `network`, `tx_hash`, `log_index`),
  ADD KEY `address_network_timestamp` (`address`, `network`, `block_timestamp`);

ALTER TABLE `wallet_token_event`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;
```

`signed_amount` is stored as `decimal(40,0)` / `decimal(50,0)` (no decimal places: these are raw
on-chain integer amounts, e.g. wei) rather than a PHP int/float, since wei-level amounts routinely
exceed both PHP's native integer range and float precision. All arithmetic on these columns is
done in PHP via `bcmath`, never in SQL, since `GROUP BY`/`SUM` support in the underlying query
builder package wasn't confirmed to exist.

Events are inserted one row per query (no bulk multi-row insert), since the underlying query
builder's confirmed API only demonstrated single-row `insertInto`. For a wallet with thousands of
historical events, a first-ever sync can mean thousands of individual `INSERT` statements; this is
a one-time cost per wallet+network range, not a recurring one, but is worth knowing if a very
active wallet's first sync feels slow.

## A note on the Routescan keyless tier

This API calls Routescan's keyless public tier (2 requests/second, 10,000 calls/day), chosen as a
single Etherscan-compatible provider covering all 4 networks for free (Etherscan itself has, at
various points, restricted free access to some of these chains). Each of the 4 networks needs up
to 3 calls (normal tx, internal tx, token transfers) per sync, so a single never-before-seen wallet
query can use up to 12 calls; cached/already-synced wallets use 0.

Any single data source (normal tx, internal tx, or token transfers) is capped by the upstream API
at 10,000 records *per request*, regardless of pagination. If a wallet has more activity than that
within the range being synced, the API returns a `502` rather than silently returning an incomplete
(and therefore wrong) balance. There's currently no automatic narrower-range retry for this case.

### A note on timeouts and PHP's execution time limit

Routescan's free tier has been observed to occasionally fail transiently on a never-before-synced,
active wallet — a generic `{"status":"0","message":"An error occurred"}`, or an outright connection
timeout, even when the exact same request succeeds moments later on retry. `RoutescanClient` retries
once (2 attempts total) with a short delay before giving up, which resolves most of these.

This retry logic adds real time on top of an already request-heavy first sync (up to 12 upstream
calls across 4 networks), and PHP's default `max_execution_time` is commonly 30 seconds on a typical
Apache/PHP-FPM setup (PHP's built-in CLI dev server, `php -S`, has no such limit by default, which is
why this may behave differently in local testing vs. a real deployment). In the worst case — every
call needing its one retry — a single request could exceed that 30-second budget and be killed by
the web server before finishing.

If this becomes a practical problem, the options are: raise `max_execution_time` for this endpoint
specifically (e.g. via `set_time_limit()` at the top of `App::run()`, or a server-level override),
or split the "ensure synced" step into its own endpoint/job that runs independently of the request
that reads the (by-then-cached) result — the latter is the more robust fix, but a larger change than
was in scope here.
