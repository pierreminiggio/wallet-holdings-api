# wallet-holdings-api

Current and historical wallet holdings and DeFi positions, for EVM and SUI wallets.

**If you're about to touch the SUI endpoints (`/sui-holdings-now/{address}` or
`/sui-holdings/{address}`), or the Compound/Aave on-chain enrichment in `/holdings-now`, read
`AGENTS.md` first.** It covers the caching/reconstruction architecture for both, real bugs
already hit and fixed, and concrete regression-test checklists with real validated values.

## Endpoints

* `GET /holdings/{address}` - Holdings for this address as of today (UTC): served from the cache, or
  by `/holdings-now` when nothing is cached yet today.
* `GET /holdings/{address}?date=YYYY-MM-DD` - Holdings as of the **end of the given UTC day**, same shape as
  `/holdings-now` (`compound`/`aave` DeFi positions included). If a row for that exact date already exists
  (cached by a `/holdings-now` call that day, or written by an earlier reconstruction) it is returned as-is, no
  RPC call at all. Otherwise, for a **past** date, the **multichain historical reconstruction** runs on
  Ethereum, Base, Polygon and BNB Smart Chain: see "Multichain historical reconstruction" below. **A first
  request for an old date can take hours**, and may be cut off by your web server or a proxy: progress is saved
  after every day, so retrying the same request resumes where it stopped. The `source` field says where the row
  comes from: `multichain_cache` (a live `/holdings-now` snapshot) or `reconstructed`. A future date is a `400`.
  Status codes: `200`, `400` (invalid input, or no activity at all on any supported chain by that date), `409`
  (another reconstruction holds the same RPC providers; has `Retry-After`; retry), `500`, `502` (an RPC provider
  failed; progress saved; retry).
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

A date on which `/holdings-now` was called for this address is returned from that cached row (`source`:
`multichain_cache`); any other past date is reconstructed (`source`: `reconstructed`). See the `/holdings`
bullet above and "Multichain historical reconstruction" below.

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
all of it — the classes, and the tables — has been removed. For a while `/holdings` was a pure
cache lookup with a clean `404` for any other date. It has since gained a different, much more
heavily validated reconstruction (EVM archive RPC reads and event logs, not a block explorer's
transaction history): see "Multichain historical reconstruction" below.

If historical reconstruction is revisited later with a different approach, `AGENTS.md` has a
concise summary of the specific, hard-won lessons from this attempt (the Blockscout indexing-
status bug in particular) worth not re-learning from scratch.

## Multichain historical reconstruction

`GET /holdings/{address}?date=YYYY-MM-DD` reconstructs, for a **past** date with no cached row, what the wallet
held at the **end of that UTC day** on **Ethereum, Base, Polygon and BNB Smart Chain** (Avalanche and Gnosis are
not reconstructed: every reconstructed row has an empty `avalanche` and `xdai` entry with
`"reconstruction_supported": false`). It is built from `MULTICHAIN-RECONSTRUCTION-PLAN.md` (the source of truth for
decisions, reference values and per-delivery notes) on top of the techniques validated in
`MULTICHAIN-HOLDINGS.md`.

**How it works.** There is no background job: the request itself does the work. For each chain it finds the
wallet's first activity (first ERC-20 Transfer touching it, or the native-coin genesis), then walks forward one
day at a time: it scans `eth_getLogs` Transfer events to discover every token the wallet ever touched, then reads
the native balance, `balanceOf` of every discovered token, and the Compound III / Aave V3 positions **at that
day's last block**, and writes one `multichain_holdings_cache` row per day (`source = 'reconstructed'`). So the
first request for an old date is slow (hours for several years), and every later request inside the walked range
is an instant cache hit. A cursor per (address, chain) makes the walk resumable: it advances only after a day's
row is written, so a cut-off request loses at most the day in progress, and retrying resumes.

**What you get**: the same per-chain shape as `/holdings-now` (`native`, `tokens`, `defi.compound/aave/other`),
with these differences: `defi.other` is always `[]` (it comes from Zerion, which has no history); each Aave reserve
has `usedAsCollateral: null` (the only source for it is not reliable historically); a chain with nothing on that day
is omitted from the row; a token held only for part of the history appears only on the days it was held; and a
`reconstruction.skipped_tokens` key lists contracts that could not be read as ERC-20s (spam, broken contracts).
Existing rows always win: a day that already has a row (for instance from a `/holdings-now` call) is never
overwritten.

**Known limits.** Tokens received before a chain's scan start (Polygon: block 80,406,107) and never touched again
are not discovered. A native-only wallet whose funds came and went entirely between two coarse samples (500,000
blocks) and left no token log is not seen. A handful of blocks that free-tier providers cannot serve even alone are
known holes (`EvmReconstructionChains::SKIPPED_BLOCKS`, and the unresolved-block table below): a third-party token
transfer *to* the wallet inside one is missed if that token appears nowhere else. NFTs are ignored. The skipped
blocks were found on one wallet: re-check a listed block the first time another wallet's scan reaches it.

**Operating it.**
- **Providers.** Ethereum, Base and Polygon have keyless `drpc.org` defaults (archive reads and `eth_getLogs`).
  **BNB Smart Chain has no keyless archive provider: add a free NodeReal URL under `reconstruction.rpc` in
  `config.php`**, otherwise every reconstruction fails with a `502` that names the missing chain (a silently skipped
  chain would be wrong data). Per chain you can configure several providers in priority order; they are used as
  failover. Keyless providers are rate limited and cap `eth_getLogs` to about 101 blocks on Ethereum and Polygon:
  the scanner adapts, but a multi-year first request is long. A keyed provider is much faster.
- **Locks.** One `flock` file per (chain, provider) in `reconstruction.lock_directory` (writable by the web server,
  outside the web root; defaults to a directory under the system temp dir). A second reconstruction that needs a
  lock already held gets a `409` with `Retry-After`. The OS releases a lock if the process dies. This assumes a
  **single web server**.
- **Timeouts.** The service calls `ignore_user_abort(true)` and `set_time_limit(0)`, but a web server, PHP-FPM
  (`request_terminate_timeout`) or a proxy may still cut a request that runs for hours. That is safe: every walked day
  is persisted and a retry resumes. For a very old date, consider warming the cache with a first request for a recent
  date from a command line client with no timeout, then asking for the older one.
- **Unresolved blocks.** When the scanner cannot fetch a block even alone it records it in
  `multichain_reconstruction_unresolved_block` and moves on (only after proving the failure is specific to that block).
  Review those rows on a block explorer and promote acceptable ones into `EvmReconstructionChains::SKIPPED_BLOCKS`.
- **Tables to create** (migration block below): `evm_block_by_date`, `multichain_reconstruction_unresolved_block`,
  `multichain_reconstruction_cursor`.

**Checks** (each delivery has a script that runs against real RPCs, plus offline tests):

```
php tests/run.php              # offline unit and integration tests (also passes with: php -n tests/run.php)
php bin/check-1-reads.php      # Compound/Aave reads at a historical block
php bin/check-2-blocks.php     # date -> end block resolver
php bin/check-3-scan.php       # log scanner (add --long for the 340k-block Polygon window)
php bin/check-4-snapshot.php   # snapshot builder: balances, Compound/Aave, native genesis
php bin/check-5-service.php    # the service end to end on Polygon, in-memory repositories
php bin/e2e-reconstruction.php # the real thing: your database, your providers, a real wallet (see its header)
```

### How it is built

`/holdings` behavior was unchanged until the last delivery; these are the components, in the order they were built.

**Delivery 1 (foundations), no behavior change for the existing endpoints:**

- `EvmRpcClient` takes an optional block on `ethCall` (default `latest`, unchanged) and gained
  `getBalance`, `getLogs`, `blockNumber`, `getBlockByNumber`, `getCode`/`hasCodeAt`. Responses
  are validated strictly (empty, malformed or key-less bodies are failures, never empty
  successes; a historical `eth_call` returning `0x` is an error, never a zero). Failures are
  classified (`EvmRpcError`: transient / range cap / auth); only transient ones are retried, and
  only if the caller asks (default: no retry, as before).
- `CompoundHoldingsClient::getHoldings($address, $block)` and
  `AaveHoldingsClient::getHoldings($address, $block)` accept a block. Historical Aave reads use
  `balanceOf` on aTokens/debt tokens (addresses resolved once at `latest`), never the
  DataProvider convenience method; `usedAsCollateral` is `null` in that mode (unknown).
  A contract that does not exist yet at the block (checked with `eth_getCode`) is a genuine zero.
- `EvmReconstructionChains` (history start blocks, skipped blocks, unsupported chain keys) and
  `EvmReconstructionConfig` (providers per chain, lock directory).
- New optional `reconstruction` section in `config.php` (see `config.example.php`): archive-capable
  RPC providers per chain and the lock directory. Ethereum/Base/Polygon have keyless drpc.org
  defaults; **BSC needs a (free) NodeReal URL**. No database change in this delivery.

**Delivery 2 (date to block):** `EvmBlockResolver` finds a day's end block by searching block
timestamps (interpolation and bisection steps alternating, so a few reads on regular chains and at most
about twice a bisection on irregular ones), bounded below by the chain's history start, over the chain's
providers in priority order (a provider failing or lagging is skipped; the head used is the highest any
provider reports). It returns one of four outcomes (`EvmBlockResolution`): resolved, before history
start, day not complete, error. `EvmBlockByDateRepository` caches resolved days in the new
`evm_block_by_date` table, see the migration block below (create it with the migration block below).

**Delivery 3 (log scanner):** `EvmLogScanner` discovers the ERC-20 contracts a wallet ever touched by
scanning `eth_getLogs` Transfer events in both directions, in adaptive resumable chunks. A range cap shrinks the
chunk immediately (no backoff) and the scanner converges on the cap instead of re-probing it; a transient error
(rate limit, `Request timeout on the free plan`, empty or malformed body) is retried on the same range with the
client's bounded backoff, then on the next provider; an auth error drops the provider; the shrink floor is one
block; skipped blocks (`EvmReconstructionChains`) are never requested; a block that cannot be fetched alone is
recorded (table below) and skipped, but only after proving the failure is block-specific. The state
(`EvmLogScanState`: last scanned block, step size, tokens, first activity block) only moves past a chunk once all
its calls succeeded, and serialises to the cursor columns, so an interrupted scan resumes with no hole. Only
3-topic Transfers (ERC-20 shape) count: NFT-shaped Transfers (4 topics) are ignored by design. The unresolved-block table is in the migration block below.

**Delivery 4 (snapshot builder):** `EvmHoldingsSnapshotBuilder::snapshot($address, $chain, $block, $tokens)`
returns one chain's holdings at one block in the same shape as `/holdings-now` (`native`, `tokens`,
`defi: {compound, aave, other}`); `other` is always `[]` in a reconstruction. Native comes from `eth_getBalance`
(symbols ETH / ETH / POL / BNB for Ethereum / Base / Polygon / BSC), every discovered token from `balanceOf` at
that block (never summed Transfer deltas), Compound and Aave from the block-aware clients of delivery 1. Zero
balances are omitted and a token whose contract does not exist yet at the block is a verified zero. There is no
partial success: any read that cannot be trusted makes the whole snapshot fail (`EvmHoldingsSnapshotResult`).
The one exception is a token contract that cannot be read as an ERC-20 (reverts, no `balanceOf`/`decimals`,
empty answer from a contract that has code): spam and broken contracts are routine among discovered tokens, so it
is skipped and reported (`getSkippedTokens()`), not retried and not an outage. `unsupportedChainShape()` gives the
empty shape with `"reconstruction_supported": false` for `avalanche` / `xdai`.

**Delivery 5 (cursor, lock, reconstruction service):**
`MultichainHoldingsReconstructionService::reconstruct($address, $date)` walks day by day from the wallet's first
activity (or from the cursor) to a past date, writing one `reconstructed` row per day and advancing the cursors only
after the row. It returns a `MultichainReconstructionOutcome`: `found` (200) / `before_genesis` (400) /
`inconsistent` (500) / `storage_error` (500) / `provider_error` (502, progress saved, a retry resumes) / `busy`
(409 with `Retry-After`). Existing rows win: a day that already has a row (live, or left by an interrupted walk) is
never overwritten, but its logs are still scanned and the cursors move past it. A chain with nothing on a day is
omitted from that row like the live cache omits it; `avalanche` and `xdai` are always present, empty, with
`"reconstruction_supported": false`. A chain with no configured provider fails the walk loudly (BSC needs a
NodeReal URL in `config.php`). `EvmReconstructionLock` takes one `flock` file per (chain, provider) in the
`reconstruction.lock_directory`, non-blocking, released at the end of every request and by the OS if the process
dies; it assumes a single web server. The service calls `ignore_user_abort(true)` and `set_time_limit(0)`.

Checks (PHP 8.0+, no composer needed for the tests):

```
php tests/run.php            # offline unit tests (also passes with: php -n tests/run.php)
php bin/check-1-reads.php    # delivery 1 acceptance against real RPCs, paste the output back
php bin/check-2-blocks.php   # delivery 2 acceptance (reference blocks), same
php bin/check-3-scan.php     # delivery 3 acceptance (add --long for the 340k-block Polygon window)
php bin/check-4-snapshot.php # delivery 4 acceptance (reference balances, Compound/Aave, native genesis)
php bin/check-5-service.php  # delivery 5 acceptance (Polygon walk with in-memory repositories; add --long for 4 days)
```

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
6. (Required for `/holdings` on a past date that was never cached) Configure the `reconstruction`
   section of `config.php`: **a free NodeReal URL for BNB Smart Chain** (it has no keyless archive provider,
   and a reconstruction fails loudly without it), optionally keyed providers for the other chains (faster
   than the keyless `drpc.org` defaults), and a writable `lock_directory` outside the web root. See
   "Multichain historical reconstruction" above.
7. Run the migration below on your database (it includes the three reconstruction tables)
8. Point your webserver's document root to `public/`, or use the provided `.htaccess` with Apache

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

-- Date-to-block cache for the multichain historical reconstruction (delivery 2). Per (chain, day),
-- NOT per wallet: every wallet shares it. Only completed days are ever stored.
CREATE TABLE `evm_block_by_date` (
  `id` bigint(20) NOT NULL,
  `chain` varchar(32) NOT NULL,
  `day_date` date NOT NULL,
  `end_block` bigint(20) NOT NULL,
  `resolved_at` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `evm_block_by_date`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chain_day_date` (`chain`, `day_date`);

ALTER TABLE `evm_block_by_date`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

-- Blocks the log scanner could not fetch even alone, on every provider (delivery 3). A human reviews
-- them on a block explorer, then promotes them into EvmReconstructionChains::SKIPPED_BLOCKS.
CREATE TABLE `multichain_reconstruction_unresolved_block` (
  `id` bigint(20) NOT NULL,
  `chain` varchar(32) NOT NULL,
  `block_number` bigint(20) NOT NULL,
  `address` varchar(42) NOT NULL,
  `last_error` text NOT NULL,
  `recorded_at` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `multichain_reconstruction_unresolved_block`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chain_block_number` (`chain`, `block_number`);

ALTER TABLE `multichain_reconstruction_unresolved_block`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

-- Resume state of the multichain historical reconstruction, per (address, chain) (delivery 5).
CREATE TABLE `multichain_reconstruction_cursor` (
  `id` bigint(20) NOT NULL,
  `address` varchar(42) NOT NULL,
  `chain` varchar(32) NOT NULL,
  `last_scanned_block` bigint(20) NOT NULL,
  `step_size` int(11) NOT NULL,
  `tokens_json` longtext NOT NULL,
  `first_activity_block` bigint(20) NULL,
  `cursor_date` date NULL,
  `updated_at` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `multichain_reconstruction_cursor`
  ADD PRIMARY KEY (`id`),
  ADD KEY `address_chain_id` (`address`, `chain`, `id`);

ALTER TABLE `multichain_reconstruction_cursor`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;
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

`/holdings?date=...` reads this table (`HoldingsNowCacheRepository::getCacheForDate()`, which also returns
the row's `source`) and only this table -- there is no separate Zerion-level cache anymore. A hit is
returned as-is whatever its source (`live`, from a `/holdings-now` call, or `reconstructed`, written by the
historical reconstruction); a miss for a past date runs the reconstruction, which writes one `reconstructed`
row per day it walks.

`sui_holdings_cache` is deliberately append-only: `address` is **not** unique, and every
fresh fetch for `/sui-holdings-now/{address}` inserts a new row rather than overwriting
the previous one, so a history of past snapshots builds up per address instead of only
ever keeping the latest. `cached_at` is a Unix timestamp (consistent with the other
timestamp columns in this schema) rather than a `DATETIME`, so freshness (the 2-hour
cache window) is a plain integer comparison in PHP.

### Date-to-block cache: `evm_block_by_date`

`evm_block_by_date` maps `(chain, day_date)` to `end_block`: the last block whose timestamp is strictly
before `day_date + 1 day 00:00:00 UTC`, i.e. the block the end-of-day snapshot of that day is taken at.
It is **per chain and day, not per wallet**: the answer is wallet-independent, so every wallet asking
about the same chain and date shares one row instead of re-searching. `EvmBlockByDateRepository`
(`find()` / `store()`) is append-only like the rest of this schema; two racing requests can insert the
same row twice with the same value, which is harmless, so `find()` takes any. Only **completed** days are
stored: `EvmBlockResolver` returns `DAY_NOT_COMPLETE` for today or a future date (the chain head has not
reached the end of that day) and never stores it; `BEFORE_START` (a day before the chain's history-start
block) and provider failures are not stored either.

### Unresolved blocks: `multichain_reconstruction_unresolved_block`

When `EvmLogScanner` meets a block that cannot be fetched even alone, on every configured provider, after
bounded retries, it must not halt a multi-day scan. It records the block here (`EvmUnresolvedBlockRepository::record`,
fed by the scanner's `onGap` callback) and moves on. It does so only if a nearby block of the same query shape
succeeds, which proves the failure is specific to that block and not a provider outage; otherwise the scan stops
with `provider_error` and resumes later. A human then checks the block on a block explorer and, if acceptable,
adds it to `EvmReconstructionChains::SKIPPED_BLOCKS` with a comment saying what was verified. **A recorded or
skipped block is a known, accepted hole**: a third-party transfer *to* the wallet inside it is only visible in
event logs and is missed if that token appears nowhere else in the history.

### Reconstruction cursor: `multichain_reconstruction_cursor`

One row per advancement per `(address, chain)`, the newest (highest `id`, not `updated_at`: several rows can share
a second) wins. It exists because the cache rows cannot tell the walk where to resume: a row lists only tokens with
a current balance, so a token that was held and then sold to zero disappears from later rows. Columns:
`last_scanned_block` (highest block whose logs were fully fetched; never advanced past a failed range),
`step_size` (the scanner's adaptive chunk size, so a resume does not re-learn the provider's cap), `tokens_json`
(every token contract discovered so far, addresses only), `first_activity_block` (the chain's first activity, `NULL`
until Phase 0 finds it) and `cursor_date` (the date the walk has **fully completed through**, all its rows written;
`NULL` until the first day is done). Progress rows saved while a long scan runs keep `cursor_date` unchanged and only
move the scan fields. **A cursor never advances without its row**: `cursor_date` moves to a day only after that day's
`multichain_holdings_cache` row exists. `MultichainReconstructionCursorRepository` is the only reader and writer.

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
