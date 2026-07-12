# wallet-holdings-api

Current and historical wallet holdings and DeFi positions, for EVM and SUI wallets.

**If you're about to touch the SUI endpoints (`/sui-holdings-now/{address}` or
`/sui-holdings/{address}`), or the Compound/Aave on-chain enrichment in `/holdings-now`, read
`AGENTS.md` first.** It covers the caching/reconstruction architecture for both, real bugs
already hit and fixed, and concrete regression-test checklists with real validated values.

## Endpoints

* `GET /holdings/{address}` - Cached historical holdings for this address, as of today (UTC).
* `GET /holdings/{address}?date=YYYY-MM-DD` - Cached historical holdings as of the given UTC day.
  **This is a pure cache lookup, not a live computation** — it never calls Zerion or does any
  on-chain reads itself, only reads back what a prior `/holdings-now/{address}` call already
  cached, from one of two sources, checked in this order: (1) the full `/holdings-now` response
  cache (richer — includes the same `compound`/`aave`/`other` `defi` split as `/holdings-now`
  itself — but only available for the single most recent date this address happened to be
  fetched on, since that cache holds one row per address, not per-date history), falling back to
  (2) Zerion-only position history (no `compound`/`aave`, just the flat Zerion `defi` list, but
  covers any date this address was ever fetched on, since that cache genuinely accumulates a
  row per day). The response's `source` field tells you which one served the request. A `404`
  means neither source has anything cached for that exact date — there is currently no
  historical reconstruction for dates that were never cached this way. An earlier
  transaction-replay approach (walking each network's full history to derive a past balance) was
  built, found to have real correctness problems, and has been removed; see `AGENTS.md` for what
  was learned if reconstruction is attempted again with a different approach later.
* `GET /holdings-now/{address}` - **Current** holdings and DeFi positions right now, powered by
  Zerion's portfolio API. Covers 60+ chains simultaneously (Ethereum, Base, Polygon, BNB, Avalanche
  and more) in two calls — wallet tokens/native coins in one, DeFi protocol positions in the other.
  Each chain's `defi` key is further split into `compound`, `aave`, and `other`: `compound`/`aave`
  are **directly-verified on-chain** Compound III / Aave V3 positions for that chain — read straight
  from each protocol's own contracts via `eth_call`, no third-party API involved for that part —
  while `other` is whatever misc protocol positions (staking, LP, etc.) Zerion itself reported for
  that chain. This split exists because Zerion's own data typically doesn't surface Aave/Compound at
  all (they show up as plain aToken balances instead), so `compound`/`aave` fill that gap with
  verified data alongside whatever Zerion does report under `other`. The entire response (Zerion
  holdings + the on-chain enrichment) is cached per address for **2 hours**: a request within that
  window returns the cached response as-is, skipping both the Zerion calls and the on-chain reads
  entirely; once it's stale, everything is recomputed and re-cached. (Zerion's own data additionally
  has its own separate, shorter 10-minute cache used internally — see `ZerionPositionRepository` —
  which only matters when the outer 2-hour cache has just expired.) **This is also what populates
  `/holdings`'s two cache sources** — every `/holdings-now` call caches both the full response
  (address's single latest snapshot, including `compound`/`aave`) and the individual Zerion
  positions (per-address-per-day, accumulating history), which `/holdings?date=...` later reads
  back — see that endpoint's own description above for exactly how the two are used together.
  Requires a Zerion API key in `config.php` (free tier: 2,000 requests/day, no credit card —
  register at [dashboard.zerion.io](https://dashboard.zerion.io/)); the on-chain `compound`/`aave`
  enrichment works without any config at all, using built-in public RPC defaults (overridable per
  chain via `config.php`'s `rpc` section). Neither `/holdings` nor `/holdings-now` require the
  `bcmath` PHP extension — see "Why bcmath isn't needed anywhere in this project" below.
* `GET /sui-holdings-now/{address}` - **Current** SUI wallet coin holdings and NAVI
  Protocol lending/borrowing positions. `{address}` here is a 0x-prefixed,
  64-hex-character SUI address (not an EVM address). Serves a cached snapshot if one
  younger than **2 hours** exists for that address; otherwise triggers a live run of the
  [`pierreminiggio/sui-navi-report`](https://github.com/pierreminiggio/sui-navi-report)
  GitHub Action (can take roughly a minute), caches the result, and returns it. Every
  fresh fetch is appended as a new row rather than overwriting the previous cache, so a
  history of snapshots builds up per address over time. Requires a GitHub token in
  `config.php` under `github.token` with permission to trigger workflow runs and read
  Actions artifacts on that repo. See that project's own README for the response schema
  (`wallet.coins[]`, `navi.positions[]`, `navi.healthFactor`).
* `GET /sui-holdings/{address}?date=YYYY-MM-DD` - This wallet's SUI + NAVI holdings as of a
  given UTC day (defaults to today, UTC, if `date` is omitted), live or historical. On a
  cache hit (whether from a prior `/sui-holdings-now` call or a prior call to this endpoint),
  returns it immediately. On a miss, triggers the `sui-navi-report` repo's historical
  reconstruction workflow — resuming from wherever this address's reconstruction previously
  left off (or from genesis on a first request), which can take anywhere from under a minute
  to several minutes depending on how much history needs to be walked. Every day crossed
  along the way gets cached, including quiet days with no on-chain activity (which carry
  forward the most recent known state), so a later request for any date in that range is a
  fast cache hit rather than triggering reconstruction again. Returns `400` if the requested
  date predates this wallet's earliest on-chain activity, `500` on an internal cache
  inconsistency, `502` if the reconstruction run itself failed, and `503` if no GitHub token
  is configured. See "Historical reconstruction" in the Migration section below for the
  caching design, and `sui-navi-report`'s own `AGENTS.md` for how reconstruction itself
  works. Note this SUI reconstruction mechanism is unrelated to (and, unlike) the removed
  EVM one described under `/holdings` above — it works via a completely different Action-based
  approach, and has not been removed.
* `GET /openapi` - Interactive API documentation (Swagger UI).

`{address}` must be a `0x`-prefixed, 40-hex-character EVM address for the EVM endpoints, or a
`0x`-prefixed 64-hex-character SUI address for the SUI ones.

### Example

```
GET /holdings/0x1234567890123456789012345678901234567890?date=2026-06-15
{
  "address": "0x1234567890123456789012345678901234567890",
  "date": "2026-06-15",
  "source": "zerion_cache",
  "holdings": {
    "ethereum": {
      "native": { "symbol": "ETH", "amount": "1.5" },
      "tokens": [
        { "symbol": "USDC", "contract": "0xa0b8...", "amount": "500" }
      ],
      "defi": []
    },
    "base": {
      "native": { "symbol": "ETH", "amount": "0.02" },
      "tokens": [],
      "defi": []
    }
  }
}
```

If no `/holdings-now` call was ever made for this address on 2026-06-15, this returns a `404`
instead — see the `/holdings` bullet above.

## Why `bcmath` isn't needed anywhere in this project

This project used to depend on the PHP `bcmath` extension in two places: the now-removed EVM
transaction-replay reconstruction (`HoldingsCalculator`/`WalletDataRepository`, deleted along with
the rest of that system — see "A previous EVM reconstruction attempt" below), and originally
`AbiCodec` (the Compound/Aave on-chain reader), which crashed production with `Call to undefined
function App\bcadd()` when it turned out `bcmath` wasn't actually enabled there and wasn't going
to be. `AbiCodec` was rewritten to do the same big-integer arithmetic using only native PHP string
manipulation (see `AbiCodec::bigMulAdd`/`hexToDec`/`formatUnits` — worth reading before touching
that file, and see `AGENTS.md` for the full story). With the old reconstruction system now removed
entirely, `bcmath` is not used **anywhere** in this codebase anymore, on either the EVM or SUI
side — nothing here requires enabling it.

## A previous EVM reconstruction attempt (removed)

An earlier version of `/holdings` didn't just read a cache — it derived historical balances
itself by fetching a wallet's entire transaction history from a blockchain explorer API
(Routescan/Etherscan/Blockscout, depending on network) and replaying it, the same approach a
block explorer's own indexer uses internally. This involved `WalletSyncService`,
`WalletDataRepository`, `HoldingsCalculator`, `Network`, `EtherscanCompatibleClient` and its
subclasses (`RoutescanApiClient`, `EtherscanApiClient`, `BaseBlockscoutApiClient`), plus two
orphaned, never-actually-wired-up "current balance" clients (`RoutescanCurrentBalanceClient`,
`BlockscoutCurrentBalanceClient`) and `RoutescanClient` from an even earlier abandoned attempt
before that.

This was found not to be reliable enough to keep — real, hard-to-fully-resolve issues included
Blockscout silently returning a "not yet indexed" status that the code didn't distinguish from
"genuinely empty," and only Ethereum and Base ever having a working, confirmed keyless data
source at all (Polygon and BNB Smart Chain were never confirmed against a live API despite
looking supported on paper). Rather than keep partially-working reconstruction code and its three
supporting database tables (`wallet_sync`, `wallet_native_event`, `wallet_token_event`) around,
all of it — the classes, and the tables — has been removed. `/holdings` is now a pure cache
lookup (see the Endpoints section above): it only ever returns what a prior `/holdings-now` call
already cached for that exact address and UTC date, and returns a clean `404` otherwise, rather
than attempting a reconstruction that couldn't be fully trusted.

If historical reconstruction is revisited later with a different approach, `AGENTS.md` has a
concise summary of the specific, hard-won lessons from this attempt (the Blockscout indexing-
status bug in particular) worth not re-learning from scratch.

## Setup

1. `composer install`
2. `cp config.example.php config.php` and fill in your DB credentials
3. (Required for `/holdings-now`, and therefore for `/holdings` too — see above) Register a free
   Zerion API key at [dashboard.zerion.io](https://dashboard.zerion.io/) (no credit card, 2,000
   requests/day free) and set it as `zerion.api_key` in `config.php`. Without this, `/holdings-now`
   returns a `503`, and `/holdings` will never have anything cached to return.
4. (Required for `/sui-holdings-now`) Generate a GitHub personal access token with permission to
   trigger workflow runs and read Actions artifacts on `pierreminiggio/sui-navi-report`, and set
   it as `github.token` in `config.php`. Without this (and without an existing fresh-enough
   cached result), `/sui-holdings-now` returns a `503`.
5. (Optional) The `rpc` section in `config.php` overrides the built-in public RPC defaults used
   for `/holdings-now`'s on-chain `compound`/`aave` position reads (per chain, under each chain's
   `defi` key). This step can be skipped entirely -- the defaults work out of the box -- unless
   you want your own provider (Alchemy/Infura/QuickNode) for a specific chain, e.g. because the
   public default is rate-limiting you.
6. Run the migration below on your database
7. Point your webserver's document root to `public/`, or use the provided `.htaccess` with Apache

## Migration

```sql
-- Cleanup: drop the tables from the removed EVM reconstruction attempt (see "A previous EVM
-- reconstruction attempt" above). Skip these three DROP statements if you're setting this project
-- up fresh and never created them in the first place.
DROP TABLE IF EXISTS `wallet_native_event`;
DROP TABLE IF EXISTS `wallet_token_event`;
DROP TABLE IF EXISTS `wallet_sync`;

CREATE TABLE `zerion_position` (
  `id` bigint(20) NOT NULL,
  `address`          varchar(42)  NOT NULL,
  `chain_id`         varchar(32)  NOT NULL,
  `symbol`           varchar(128) NOT NULL,
  `contract_address` varchar(42)  NULL,
  `position_type`    varchar(16)  NOT NULL,
  `protocol_id`      varchar(64)  NULL,
  `amount`           varchar(64)  NOT NULL,
  `updated_at`       datetime     NULL,
  `fetched_at`       datetime     NOT NULL,
  `updated_at_block` bigint(20)   NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `zerion_position`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `position_snapshot`
    (`address`, `chain_id`, `contract_address`, `position_type`, `protocol_id`, `updated_at`),
  ADD KEY `address_fetched_at` (`address`, `fetched_at`);

ALTER TABLE `zerion_position`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

CREATE TABLE `sui_holdings_cache` (
  `id` bigint(20) NOT NULL,
  `address` varchar(66) NOT NULL,
  `report_json` longtext NOT NULL,
  `cached_at` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `sui_holdings_cache`
  ADD PRIMARY KEY (`id`),
  ADD KEY `address_cached_at` (`address`, `cached_at`);

ALTER TABLE `sui_holdings_cache`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

-- Added to support GET /sui-holdings/{address}?date=... backfilling from the sui-navi-report
-- reconstruction Action, in addition to live /sui-holdings-now/{address} snapshots. See
-- "Historical reconstruction" below for why `as_of_date` has to be a separate column from
-- `cached_at` rather than derived from it.
ALTER TABLE `sui_holdings_cache`
  ADD COLUMN `as_of_date` DATE NULL AFTER `address`,
  ADD COLUMN `source` VARCHAR(16) NOT NULL DEFAULT 'live' AFTER `report_json`;

UPDATE `sui_holdings_cache`
  SET `as_of_date` = FROM_UNIXTIME(`cached_at`, '%Y-%m-%d')
  WHERE `as_of_date` IS NULL;

ALTER TABLE `sui_holdings_cache`
  MODIFY `as_of_date` DATE NOT NULL;

ALTER TABLE `sui_holdings_cache`
  ADD KEY `address_as_of_date` (`address`, `as_of_date`);

CREATE TABLE `sui_holdings_reconstruction_cursor` (
  `id` bigint(20) NOT NULL,
  `address` varchar(66) NOT NULL,
  `checkpoint` bigint(20) NOT NULL,
  `wallet_balances_json` longtext NOT NULL,
  `cursor_date` DATE NOT NULL,
  `updated_at` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `sui_holdings_reconstruction_cursor`
  ADD PRIMARY KEY (`id`),
  ADD KEY `address_updated_at` (`address`, `updated_at`);

ALTER TABLE `sui_holdings_reconstruction_cursor`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

CREATE TABLE `multichain_holdings_cache` (
  `id` bigint(20) NOT NULL,
  `address` varchar(42) NOT NULL,
  `response_json` longtext NOT NULL,
  `cached_at` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `multichain_holdings_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `address` (`address`);

ALTER TABLE `multichain_holdings_cache`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;
```

`multichain_holdings_cache` caches the *entire* `/holdings-now/{address}` response body (Zerion-derived
holdings plus the per-chain on-chain `compound`/`aave` enrichment) for 2 hours per address --
`HoldingsNowCacheRepository`, mirroring `sui_holdings_cache`'s `cached_at`-as-Unix-timestamp
pattern. Unlike `sui_holdings_cache`, this table is deliberately **not** append-only: `address` is
unique, and every fresh fetch overwrites the previous row (`ON DUPLICATE KEY UPDATE`) rather than
accumulating history -- there's no per-address history here, only ever "whenever this address was
last fetched," and upserting keeps the table small regardless of query frequency.

`/holdings?date=...` does read this table (`HoldingsNowCacheRepository::getCacheForDate()`), but
only ever gets a hit for the single most recent date each address happens to have been fetched
on, precisely because there's no history here to query by date. For any other date it falls back
to `zerion_position` (via `ZerionPositionRepository::getPositionsForDate()`) instead, which stores
individual positions with their own `fetched_at` timestamp and naturally accumulates history as
`/holdings-now` gets called on different days -- that's what makes genuine per-date lookups
possible, at the cost of not including the on-chain `compound`/`aave` data, which is only ever
cached in `multichain_holdings_cache`, not `zerion_position`.

`sui_holdings_cache` is deliberately append-only: `address` is **not** unique, and every
fresh fetch for `/sui-holdings-now/{address}` inserts a new row rather than overwriting
the previous one, so a history of past snapshots builds up per address instead of only
ever keeping the latest. `cached_at` is a Unix timestamp (consistent with the other
timestamp columns in this schema) rather than a `DATETIME`, so freshness (the 2-hour
cache window) is a plain integer comparison in PHP.

### Historical reconstruction: `as_of_date` vs `cached_at`, and the cursor table

`as_of_date` is deliberately a separate column from `cached_at`, not derived from it at read
time. For a live `/sui-holdings-now` snapshot the two always fall on the same calendar day
(it's written the moment it's fetched), but for a backfilled historical snapshot they
genuinely differ: a row backfilled *today* to represent September 14th, 2025 has
`cached_at = now` (when the API actually wrote the row) and `as_of_date = 2025-09-14` (the
date the data represents). Reusing `cached_at` for both, as the original schema did, would
make every backfilled row look like it belongs to today — `getCacheForDate()` now queries
`as_of_date` directly instead of deriving day boundaries from `cached_at`.

`sui_holdings_reconstruction_cursor` is also append-only, for the same reason as
`sui_holdings_cache` and everything else in this schema: no confirmed `UPDATE`/`UPSERT`
support in the query builder used here, so instead of updating a row in place, each
reconstruction run inserts a new cursor row and `SuiHoldingsCursorRepository` picks the most
recent one per address in PHP (same aggregate-in-PHP pattern as `getFreshCache()`/
`getCacheForDate()`). It stores just enough state to resume the sequential wallet-coin replay
(`reconstruct.js`'s `newCursor.checkpoint` / `newCursor.balances`) plus `cursor_date`, the
date reconstruction has been verified complete through — **not** derived from the cache
table, since deriving "how far have we backfilled" from scattered cache rows would be far
more fragile than just storing it directly as the single source of truth it is.
