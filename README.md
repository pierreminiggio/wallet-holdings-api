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
  on-chain reads itself, only reads back a past `/holdings-now` response for this address
  (`compound`/`aave` DeFi positions included, same shape as `/holdings-now` itself), if
  `/holdings-now` happened to be called for this address on the exact requested UTC date. Since
  every `/holdings-now` call is cached as its own row (not overwriting the previous one), this
  genuinely covers any date this address was ever fetched on — coverage depends entirely on how
  often `/holdings-now` gets called for it, not on some fixed lookback window. A `404` means
  `/holdings-now` was never called for this address on that exact date. There is currently no
  historical reconstruction for dates that were never cached this way — an earlier
  transaction-replay approach (walking each network's full history to derive a past balance)
  was built, found to have real correctness problems, and has been removed; see `AGENTS.md` for
  what was learned if reconstruction is attempted again with a different approach later.
* `GET /holdings-now/{address}` - **Current** holdings and DeFi positions right now, powered by
  Zerion's portfolio API. Covers 60+ chains simultaneously (Ethereum, Base, Polygon, BNB, Avalanche
  and more) in two calls — wallet tokens/native coins in one, DeFi protocol positions in the other.
  Each chain's `defi` key is further split into `compound`, `aave`, and `other`: `compound`/`aave`
  are **directly-verified on-chain** Compound III / Aave V3 positions for that chain — read straight
  from each protocol's own contracts via `eth_call`, no third-party API involved for that part —
  while `other` is whatever misc protocol positions (staking, LP, etc.) Zerion itself reported for
  that chain. `compound` is a list, not a single object: Compound III has multiple isolated markets
  per chain (one per base asset, e.g. Base has separate USDC/USDS/USDbC markets), and a wallet can
  hold a position in more than one at once — each gets its own entry.
  This split exists because Zerion's own data typically doesn't surface Aave/Compound at
  all (they show up as plain aToken balances instead), so `compound`/`aave` fill that gap with
  verified data alongside whatever Zerion does report under `other`. The entire response (Zerion
  holdings + the on-chain enrichment) is cached per address for **2 hours**: a request within that
  window returns the most recently cached response as-is, skipping both the Zerion calls and the
  on-chain reads entirely; once the most recent cached response is stale, everything is recomputed
  and a new snapshot is cached (appended, not overwritten — see the Migration section below).
  **This is also what populates `/holdings`'s cache** — every fresh `/holdings-now` call appends a
  new cached snapshot (including `compound`/`aave`) tagged with today's date, which
  `/holdings?date=...` later reads back for that exact date — see that endpoint's own description
  above. Requires a Zerion API key in `config.php` (free tier: 2,000 requests/day, no credit card —
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
  "source": "multichain_cache",
  "holdings": {
    "ethereum": {
      "native": { "symbol": "ETH", "amount": "1.5" },
      "tokens": [
        { "symbol": "USDC", "contract": "0xa0b8...", "amount": "500" }
      ],
      "defi": { "compound": [], "aave": null, "other": [] }
    },
    "base": {
      "native": { "symbol": "ETH", "amount": "0.02" },
      "tokens": [],
      "defi": {
        "compound": [
          { "base": "USDC", "market": "0xb125...", "supplied": "0", "borrowed": "7499.36", "collateral": [] },
          { "base": "USDS", "market": "0x2c77...", "supplied": "0", "borrowed": "40.57", "collateral": [{ "symbol": "sUSDS", "amount": "402.61" }] }
        ],
        "aave": null,
        "other": []
      }
    }
  }
}
```

This only returns data if `/holdings-now` for this address was called on exactly 2026-06-15
(UTC) — any date on which it was called works (the cache is append-only, one row per call, not
just the latest), but a date on which it was never called returns a `404` instead, since there's
no reconstruction to fall back on. See the `/holdings` bullet above.

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

-- Cleanup: zerion_position and ZerionPositionRepository (which used to serve as /holdings-now's
-- own internal 10-minute rate-limit cache, and as a same-day-history fallback for /holdings) have
-- both been removed entirely. multichain_holdings_cache (below) is now the only cache either
-- endpoint uses. Skip this DROP statement if you're setting this project up fresh and never
-- created this table in the first place.
DROP TABLE IF EXISTS `zerion_position`;

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
  ADD KEY `address_cached_at` (`address`, `cached_at`);

ALTER TABLE `multichain_holdings_cache`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

-- If you already have a multichain_holdings_cache table from before this table was made
-- append-only (i.e. it currently has a UNIQUE KEY on `address`), migrate it in place instead of
-- dropping and recreating it, mirroring sui_holdings_cache's own as_of_date/source migration
-- above exactly:
ALTER TABLE `multichain_holdings_cache`
  DROP INDEX `address`;

ALTER TABLE `multichain_holdings_cache`
  ADD COLUMN `as_of_date` DATE NULL AFTER `address`,
  ADD COLUMN `source` VARCHAR(16) NOT NULL DEFAULT 'live' AFTER `response_json`;

UPDATE `multichain_holdings_cache`
  SET `as_of_date` = FROM_UNIXTIME(`cached_at`, '%Y-%m-%d')
  WHERE `as_of_date` IS NULL;

ALTER TABLE `multichain_holdings_cache`
  MODIFY `as_of_date` DATE NOT NULL;

ALTER TABLE `multichain_holdings_cache`
  ADD KEY `address_as_of_date` (`address`, `as_of_date`);
```

`multichain_holdings_cache` caches the *entire* `/holdings-now/{address}` response body
(Zerion-derived holdings plus the per-chain on-chain `compound`/`aave` enrichment) --
`HoldingsNowCacheRepository`. It's structured identically to `sui_holdings_cache` (see below),
including the same `as_of_date`/`source`/`cached_at` columns and the same append-only behavior
(a new row per `/holdings-now` call, not an overwrite) -- `/holdings-now` always writes with
`source = 'live'`, the only source that currently exists for this table, kept as an explicit
column (rather than a hardcoded assumption) the same way SUI's `source` column anticipated
`'reconstructed'` before that source existed yet. This table was originally a single row per
address (`UNIQUE KEY` on `address`, overwritten on every fetch) -- it was changed to append-only
specifically so `/holdings?date=...` could serve genuine historical dates rather than only ever
the single most recent fetch; see `AGENTS.md` Part 3 for the full history of that decision,
including an intermediate two-source design (this table plus a `zerion_position`-based fallback)
that was tried and rejected before landing here.

`/holdings?date=...` reads this table (`HoldingsNowCacheRepository::getCacheForDate()`) and only
this table -- there is no separate Zerion-level cache anymore. It returns a hit for any date on
which `/holdings-now` actually happened to be called for that specific address, and a `404`
otherwise; there is still no reconstruction for a date that was simply never fetched.

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
`as_of_date` directly instead of deriving day boundaries from `cached_at`. `multichain_holdings_cache`
copied this same column design even though EVM has no reconstruction/backfill source right now
(`as_of_date` and `cached_at` always fall on the same day in practice, currently) -- purely so the
schema doesn't need another migration if a second source is ever added there too.

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
