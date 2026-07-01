# wallet-holdings-api

Reconstructs what an EVM wallet held — native coin and ERC-20/BEP-20 tokens — on a given date.

**Currently Ethereum and Base are active.** The architecture supports any EVM network and is
fully network-agnostic (driven entirely by the `Network::ALL` array), and each network can use a
completely different upstream provider — Ethereum uses Routescan with an Etherscan fallback, Base
uses its own Blockscout instance, since neither Routescan nor Etherscan's free tier serves Base.
Polygon and BNB Smart Chain aren't active yet — see "Why Polygon and BNB aren't active yet" below
for what was checked. Re-adding a network that's confirmed working against a real provider is a
small, contained change (see `App::createClientsForNetwork()`).

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

* `GET /holdings/{address}` - Historical holdings across active networks, as of today (UTC).
* `GET /holdings/{address}?date=YYYY-MM-DD` - Historical holdings as of the end of the given UTC day.
* `GET /holdings-now/{address}` - **Current** holdings right now, queried directly from upstream
  rather than reconstructed from transaction history. Covers Ethereum and Base. No caching — each
  call hits the upstream providers live. Use this when you need the wallet's live state; use
  `/holdings/{address}` when you need a historical date.
* `GET /openapi` - Interactive API documentation (Swagger UI).

`{address}` must be a `0x`-prefixed, 40-hex-character EVM address.

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
    "base": {
      "native": { "symbol": "ETH", "amount": "0.02" },
      "tokens": []
    }
  }
}
```

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

## How Base gets its data: a per-network provider, not a fallback

Base needed a genuinely different solution, not just "Routescan plus a fallback" like Ethereum:
neither of Ethereum's two providers serves Base at all.

* **Routescan**: confirmed *not* indexed by Routescan's free/keyless tier — the API itself
  directly returns `{"status":"0","message":"chain not supported"}` for chain ID 8453. This
  contradicts some third-party reporting that Routescan added free Base support; the live API's
  own answer is the one that matters here.
* **Etherscan**: also confirmed *not* free for Base specifically — the live API directly returns
  `{"status":"0","message":"NOTOK","result":"Free API access is not supported for this chain. Please upgrade your api plan..."}`, despite Etherscan's own current docs listing Base as a
  supported chain under the unified V2 key. The docs describe what the API *can* serve on a paid
  plan; the live response is what it actually does on the free one, and those turned out to
  disagree.
* **Blockscout**: Base runs its own official Blockscout instance at `base.blockscout.com`, which
  does work keyless, confirmed directly against a real wallet with ~196 transactions spanning
  over a year (no timeout, no error). Blockscout's *legacy* API
  (`base.blockscout.com/api?module=account&action=...`) returns the exact same flat field shape
  (`hash`, `from`, `to`, `value`, `gasUsed`, etc.) as Routescan and Etherscan, so
  `BaseBlockscoutApiClient` is just a thin subclass of the same `EtherscanCompatibleClient` base
  used by the other two — no new parsing logic needed.

Because of this, `App::createClientsForNetwork()` builds a different (primary, fallback) client
pair per network rather than one shared pair for everything: Ethereum gets Routescan + an optional
Etherscan fallback, Base gets `BaseBlockscoutApiClient` with no fallback configured yet (since
neither Routescan nor Etherscan covers it as a backup option). If Base's Blockscout instance ever
has the same kind of persistent, wallet-specific failure Routescan had on Ethereum (see below),
there's currently no second provider to fall back to for Base specifically.

## A real bug this surfaced: Blockscout's "not yet indexed" status was silently treated as success

While testing a real Base wallet, native ETH and a few token balances came back negative —
impossible, since a wallet can't spend more than it ever received. The actual cause: Blockscout's
internal-transactions endpoint can return `{"status":"2","message":"Some internal transactions
within this block range have not yet been processed",...}` for a block range that hasn't finished
indexing yet. That's a meaningfully different signal from `status: "0"` ("this address genuinely
has no internal transactions"), but the code only ever checked for `status === "0"`, so a
`status: "2"` response fell through and was treated as a normal, successful, empty result.

That's a serious bug, not a cosmetic one: once a block range is synced, `WalletSyncService` marks
it complete and never re-fetches it. Any real incoming internal transfers in a range that returned
`status: "2"` were silently and *permanently* dropped from the balance calculation — which is
exactly what produces an impossible negative balance (real ETH spent, but some real ETH received
never counted).

### Why the fix doesn't try to "use what data is available"

Direct testing showed `status: "2"` can come back two ways: with an empty `result`, or with real,
non-empty partial data alongside the same warning (confirmed against an unrelated, heavily-used
contract address, so this isn't specific to one wallet). The instinct is to use that partial data
rather than discard it — it's real, after all. That instinct doesn't actually hold up under what
this API needs: internal-transaction rows feed directly into a *signed sum* (positive for
incoming, negative for outgoing), and a partial result can be missing rows from either side.
Missing incoming rows understates the balance; missing outgoing rows overstates it. Either way,
using the partial data doesn't move the result *closer* to correct — it just produces a
*different* wrong number, and an overstated balance is arguably worse than the original
impossible-negative bug, since it wouldn't visibly signal that anything was wrong.

Retrying within the same request doesn't help either: testing the exact same call twice, on two
unrelated wallets, showed `status: "2"` reproducing consistently for the same block range, not
intermittently — this appears to be a known, possibly long-lived characteristic of Blockscout's
legacy API (its own per-transaction status is literally "Awaiting internal transactions for
status", and the same behavior has been reported as far back as 2021 and is still being discussed
in current Blockscout API migration threads).

Given the API's purpose is a *correct* balance for a given date, `status: "2"`
(`EtherscanCompatibleClient::ERROR_NOT_YET_INDEXED`) is therefore treated as a hard failure: no
retry, and no holdings returned, rather than risk a number that looks plausible but might not be
right. There's no reliable wait time to suggest, since this has been observed to persist rather
than resolve quickly — if you hit this `503`, that specific wallet's history on that network
currently can't be fully verified through this codebase.

## Why Polygon and BNB aren't active yet

* **Polygon**: `"chain not supported"` returned directly by Routescan's live API for chain ID
  137, when actually tested. (An earlier version of this README claimed Polygon was "confirmed
  working" based on finding a Routescan *web explorer* page for it; that was a mistake — a
  human-facing explorer page existing is not the same as the free *API* tier serving that chain,
  and this is exactly the kind of claim that needs testing against the live API directly rather
  than inferred from a webpage. For when it's re-enabled: `Network::nativeSymbol()` already
  handles Polygon's native coin being `MATIC` before its 2024-09-04 1:1 rebrand and `POL` after,
  so that logic doesn't need to be redone.)
* **BNB Smart Chain**: never actually tested against a live API at all in this project so far —
  it was assumed to work based on the existence of a `56.routescan.io` subdomain, which has the
  same problem as the Polygon mistake above: it's evidence a web explorer exists, not that a
  keyless API tier serves it.

To re-enable either later, the safest process is to test directly first, rather than re-enable
based on documentation or a web page — the same process that worked for Base:

1. Try the relevant chain ID against Routescan: `https://api.routescan.io/v2/network/mainnet/evm/{chainId}/etherscan/api?module=account&action=txlist&address={anyRealAddress}&page=1&offset=10`
   and confirm it returns real transaction data, not `"chain not supported"`.
2. If Routescan doesn't serve it, check whether the chain has its own free, keyless Blockscout
   instance (many L2s and EVM-compatible chains do) the same way Base did, and test that directly
   too.
3. Only once a working provider is confirmed, add the network back into `Network::ALL` in
   `src/Network.php`, and wire its client pair into `App::createClientsForNetwork()`.
4. Restore its entry in the OpenAPI spec in `App::renderOpenApiDoc()` (two places: the top-level
   `info.description` and the `HoldingsResult` schema's `holdings.properties`).

## Setup

1. `composer install`
2. `cp config.example.php config.php` and fill in your DB credentials
3. (Optional, but recommended) Register a free Etherscan API key at
   [etherscan.io/myapikey](https://etherscan.io/myapikey) (no credit card) and set it as
   `etherscan.api_key` in `config.php`. This is used as a fallback when Routescan persistently
   fails for a specific wallet — see below for why that's a real, observed scenario, not just a
   theoretical one. Leave it blank to skip the fallback entirely.
4. Ensure the `bcmath` PHP extension is enabled (used throughout for precise arbitrary-size
   integer arithmetic on wei-level amounts, which exceed native PHP int/float precision)
5. Run the migration below on your database
6. Point your webserver's document root to `public/`, or use the provided `.htaccess` with Apache

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
  `token_symbol` varchar(128) NOT NULL,
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

## A note on the Routescan keyless tier, and the Etherscan fallback

This API calls Routescan's keyless public tier (2 requests/second, 10,000 calls/day) for
Ethereum's primary data source. Base uses Blockscout's keyless tier instead (see above) — both
have their own separate, independent rate limits. Each active network needs up to 3 calls (normal
tx, internal tx, token transfers) per sync, so a single never-before-seen wallet query currently
uses up to 6 calls total across both networks (would scale with each additional network confirmed
and re-enabled); cached/already-synced wallets use 0.

Any single data source (normal tx, internal tx, or token transfers) is capped by the upstream API
at 10,000 records *per request*, regardless of pagination. If a wallet has more activity than that
within the range being synced, the API returns a `502` rather than silently returning an incomplete
(and therefore wrong) balance. There's currently no automatic narrower-range retry for this case.

### A real upstream data issue, found and worked around: Etherscan as a fallback provider

While testing, one real wallet's `txlistinternal` (internal transactions) call on Ethereum was
found to fail with `{"status":"0","message":"An error occurred"}` *every single time*, regardless
of parameters (tested with and without `startblock`/`endblock`, across several `offset` values),
while the exact same endpoint worked instantly for a different wallet, and every other endpoint
(`txlist`, `tokentx`) worked fine for the *same* wallet. That combination of evidence pointed to a
genuine upstream indexing issue specific to that one address on Routescan's side — confirmed by
testing the same wallet against Etherscan's own official API (a completely independent backend),
which returned correct, real data immediately.

Because of this, `EtherscanCompatibleClient` is now an abstract base class shared by two concrete
clients: `RoutescanApiClient` (primary, keyless) and `EtherscanApiClient` (fallback, needs a free
API key — see Setup below). `WalletSyncService` tries the primary first for every call; if and only
if it fails with a generic, persistent error after exhausting its own retries (`ERROR_UPSTREAM`),
it retries that one specific call against the fallback instead. Rate-limit, invalid-address, and
truncated-result errors are *not* retried against the fallback, since switching providers wouldn't
fix any of those — they're about volume or input validity, not data correctness.

If no Etherscan API key is configured, the fallback is simply skipped (`null`), and a persistent
Routescan failure surfaces exactly as it did before this fallback existed: a clear `502` naming the
specific action that failed, with the exact upstream response in the server log.

### A note on timeouts and PHP's execution time limit

Both providers' free tiers have been observed to occasionally fail transiently on a heavy query —
a generic `{"status":"0","message":"An error occurred"}`, or an outright connection timeout, even
when the exact same request succeeds moments later on retry. `EtherscanCompatibleClient` retries a
failed call up to 4 times with a short delay between attempts before giving up (and only then does
`WalletSyncService` consider falling back to the other provider, as described above), which resolves
most of these.

A first-ever sync across all active networks can involve many sequential upstream calls (worse if
some need retries), which can legitimately take a while. `App::run()` calls `set_time_limit(0)` for
this reason, removing PHP's own script execution time limit specifically for the `/holdings`
endpoint, so a slow first sync is allowed to simply keep running rather than being killed partway
through.

This works reliably with PHP's built-in CLI development server (`php -S`) and with most traditional
Apache+mod_php setups. It's worth knowing that `set_time_limit()` does **not** override every
possible timeout layer: PHP-FPM's own `request_terminate_timeout`, and some reverse proxy or load
balancer timeouts in front of PHP, are enforced independently and would still need to be raised
separately in a production deployment behind one of those.
