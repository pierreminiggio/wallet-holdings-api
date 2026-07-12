# AGENTS.md

This file covers three independent feature areas of this API. Read only the section relevant to
what you're touching — they don't share code or assumptions, beyond both living in `App.php`.

1. **SUI holdings** (`GET /sui-holdings-now/{address}`, `GET /sui-holdings/{address}?date=...`)
   — see "Part 1" below.
2. **`/holdings-now`'s on-chain `defi` enrichment** (Compound III + Aave V3, read directly from
   their contracts, no third-party API) — see "Part 2", further down.
3. **`/holdings`'s cache-only historical lookup**, and lessons from the historical
   reconstruction system that used to power it before it was removed — see "Part 3", at the end.

---

# Part 1 — SUI holdings

This part is for an AI agent (or a human moving fast) picking up work on the SUI-related
parts of this API later: `GET /sui-holdings-now/{address}` and
`GET /sui-holdings/{address}?date=YYYY-MM-DD`. Read this before touching
`SuiHoldingsCacheRepository.php`, `SuiHoldingsCursorRepository.php`,
`SuiWalletReconstructionActionClient.php`, `SuiHoldingsReconstructionService.php`, or the
SUI-related parts of `App.php`.

The companion project, `pierreminiggio/sui-navi-report` (a separate GitHub repo, triggered by
this API as a GitHub Action), has its own `AGENTS.md` covering how historical reconstruction
actually works on-chain — the checkpoint/GraphQL mechanics, the NAVI `main`/`rwa` object model,
every wrong guess that became a real bug there. **Read that one too** if you're touching
anything related to what the Action itself computes, not just how this API calls it.

## The two endpoints, in one paragraph each

**`GET /sui-holdings-now/{address}`** is simple: check a 2-hour freshness cache
(`SuiHoldingsCacheRepository::getFreshCache`), and on a miss, trigger the
`sui-navi-report` Action's live path (`SuiWalletReportActionClient`), cache the result, return
it. Nothing about this endpoint changed when historical reconstruction was added — it still
just writes rows with `source: 'live'` and `as_of_date` = today.

**`GET /sui-holdings/{address}?date=...`** is the historical one. On a cache hit (exact
`as_of_date` match — see below for why that's a separate column from `cached_at`), it returns
immediately. On a miss, `SuiHoldingsReconstructionService` decides whether to trigger the
Action's reconstruction workflow (resuming from a saved cursor, or from genesis) or to fail
with a `400`/`500` depending on whether the miss is "before this wallet's history exists" or
"a genuine data inconsistency." When the Action does run, every day it walks through gets its
own cache row — not just the requested date — so a later request anywhere in that range is a
pure cache hit, never a second reconstruction.

## Critical, hard-won bug: don't chain `->where()` calls

**The single most important thing to know before writing any new query in this codebase:**
the `DatabaseFetcher` query builder used throughout does **not** support chaining multiple
`->where()` calls on one query. This was assumed to work (`->where('address = :address')
->where('as_of_date = :as_of_date')`), shipped, and broke in production with a real error:

```
SQLSTATE[HY093]: Invalid parameter number: number of bound variables does not match
number of tokens
```

The fix: combine every condition into a **single** `->where()` call with an inline `AND`:

```php
->where('address = :address AND as_of_date = :as_of_date')
```

This matches the only pattern actually proven to work everywhere else in this codebase
(every other query in this project, before and after this bug, uses exactly one `->where()`
call). If you're about to write a query with more than one condition, use this pattern from
the start — don't rediscover this the hard way again. More generally: this query builder's
feature set is not well-documented and multiple things about it have had to be learned by
hitting real errors (see `sui-navi-report`'s own `AGENTS.md` for the same pattern of "verify,
don't guess" applied to GraphQL) — no `UPDATE`/`UPSERT`, no confirmed `ORDER BY`/`LIMIT`/
`GROUP BY`, and now no multi-`where()` chaining. Assume nothing beyond what's already
demonstrated working in this codebase.

## Critical, hard-won bug: a quiet resume window wrote nothing, then wrongly advanced the cursor

Found in production on a real request: a wallet with a cursor at `2026-06-28` was asked for
`2026-07-07`. The Action correctly found zero new transactions in that window (confirmed via
the workflow's own console output) and returned an unchanged checkpoint/balances with an
**empty** `dailySnapshots` — entirely correct behavior on the Action side.

The bug was in `SuiHoldingsReconstructionService::backfillAndAdvanceCursor()`: the
day-by-day carry-forward loop seeded `$lastKnownReport` from `null`, only ever setting it
from `reconstruct.js`'s own `dailySnapshots`. With `dailySnapshots` empty, every day in the
range hit the "nothing known yet, skip" branch — writing **zero** cache rows, not even for
the requested date itself. The method still advanced the cursor to the target date
regardless. `resolve()` then re-checked the cache for that date, found nothing, and concluded
`OUTCOME_BEFORE_GENESIS` — reporting a wallet nine months into its history as predating its
own genesis.

The fix: when resuming from an existing cursor (`$fromDateExclusive !== null`), seed
`$lastKnownReport` from the **already-cached** report at that date
(`$cacheRepository->getCacheForDate($address, $fromDateExclusive)`), not from `null`. A quiet
window then correctly carries forward the last known state through every day up to the
target, instead of silently writing nothing while still claiming the range was covered.

**If you ever see a `400 predates the wallet's earliest on-chain activity` for a date that's
obviously not near a wallet's genesis, suspect this exact failure mode first** — check
whether the cursor immediately before it has an identical `checkpoint` to some later cursor
row (the signature of "zero transactions found, but the backfill silently failed anyway").
A stale bad cursor row from before this fix was found and manually deleted from production;
if this regresses, check for orphaned cursor rows the same way.

## Architecture: five classes, how they fit together

- **`SuiHoldingsCacheRepository`** — the cache table (`sui_holdings_cache`). `getFreshCache()`
  is the live-path lookup (unchanged, `cached_at`-based). `getCacheForDate()` is the
  historical-path lookup — an **exact match on `as_of_date`**, not a fuzzy "nearest prior day"
  (see below for why). `getEarliestCachedDate()` powers the 400-vs-500 decision.
  `store()` now requires `$asOfDate` and `$source` explicitly.
- **`SuiHoldingsCursorRepository`** — a separate table (`sui_holdings_reconstruction_cursor`)
  tracking, per address, how far reconstruction has progressed: the checkpoint and running
  wallet-coin balances needed to resume `reconstruct.js`'s sequential replay, plus the date
  that's been verified complete through. Append-only, same "no UPDATE support" reasoning as
  the cache table — `getCursor()` picks the most recent row per address in PHP.
- **`SuiWalletReconstructionActionClient`** — triggers `wallet-reconstruct.yml` (not
  `wallet-report.yml`, which `SuiWalletReportActionClient` already handled). Polls every 60s
  (not 30s — reconstruction runs take much longer than a live fetch), and reuses the same
  "keep the undocumented library parameter at 0" convention already established by
  `SuiWalletReportActionClient`.
- **`SuiHoldingsReconstructionService`** — the actual decision logic. `resolve()`: exact-date
  cache check happens in `App.php` *before* this is even instantiated (a pure cache hit should
  never touch this class at all); this class only runs on a genuine miss, and decides between
  "trigger a walk" vs "classify why nothing will ever exist here." See its own docblocks for
  the 400/500 reasoning — don't relitigate it without rereading them first, since it took a
  careful back-and-forth to get right (see the conversation history if the reasoning docs
  aren't enough).
- **`App.php`**: `handleSuiHoldingsNow` (live path, basically untouched) and
  `handleSuiHoldingsForDate` (historical path, thin — validate, cheap cache check, delegate to
  the service, map the outcome to an HTTP response).

## Why `as_of_date` is a separate column from `cached_at`

For a live snapshot, "when it was written" and "which day it represents" are always the same
day. For a **backfilled** snapshot, they're completely different: a row backfilled *today* to
represent September 2025 has `cached_at = now` and `as_of_date = 2025-09-14`. Reusing
`cached_at` for both (the original schema's design) would make every backfilled row look like
it belongs to today. `getCacheForDate()` queries `as_of_date` directly for this reason.

## Why every day gets its own cache row, even quiet ones

`reconstruct.js` only returns a snapshot for a day something actually changed on-chain — a
quiet week produces zero entries for those days. But the whole point of this design (per the
project owner's explicit requirement) is that reconstructing date B should never require
replaying from date A again just because B happened to be quiet. So
`SuiHoldingsReconstructionService::backfillAndAdvanceCursor()` walks every day from the prior
cursor to the target date and writes a row for **all** of them — real snapshot where one
exists, a copy of the most recent known state (with `asOfDate`/`generatedAt` corrected)
otherwise. This means quiet stretches produce genuine duplicate rows, a deliberate storage/
simplicity trade-off, not an oversight.

## Why there's no concurrency lock on reconstruction

Two overlapping requests for the same wallet triggering two parallel Action runs (which would
race to write the cursor) was a real design question — resolved by the project owner: this
API's own infrastructure (Apache + PHP without FPM) can't handle concurrent requests at all,
so the web server itself already serializes everything. No lock needed in this codebase as a
result. If this API's infrastructure ever changes (e.g. adding PHP-FPM), this assumption
should be revisited.

## Regression checklist — real wallet, real validated dates

Everything below was tested against a real wallet during development — referred to here as
**"Pierre's Wallet"** rather than printing the address. If you change anything in
`SuiHoldingsCacheRepository`, `SuiHoldingsCursorRepository`,
`SuiWalletReconstructionActionClient`, `SuiHoldingsReconstructionService`, or the SUI parts of
`App.php`, re-run these and confirm the numbers still match — they're not arbitrary, they were
independently cross-checked against a live ground-truth report during the original build (see
`sui-navi-report`'s `AGENTS.md` for how that validation was done on the Action side).

**Reminder before re-testing:** these numbers were correct *at the time of testing*. Interest
accrues continuously on NAVI positions, so if you're re-validating against a *fresh*
reconstruction (not reusing already-cached rows), expect supply/borrow amounts to have grown
very slightly from what's listed below — that's correct behavior, not a mismatch. What
shouldn't change: which assets appear, which market they're in, and each `assetId`.

### Test 1 — cold cache, from-genesis reconstruction

Request `date=2025-09-22` for a wallet with **no prior cursor at all**. Confirmed working:
correctly triggers a from-genesis Action run, backfills, returns `200` with `source:
"reconstructed"`.

Expected (Pierre's Wallet): wallet SUI `16.817106766`; NAVI `main` only (too early for `rwa`)
— USDC borrow `509.49509073` (assetId 10), SUI supply `404.459649721` (assetId 0).

### Test 2 — cache hit for a date inside an already-backfilled range

Request `date=2025-10-06` (after Test 1 has run and its cursor covers this date). Confirmed
working: instant response, no Action triggered, `source: "reconstructed"`.

Expected: wallet SUI `4.811607827`; NAVI `main` — USDC borrow `814.73919724`, NAVX supply
`0.000373038`, haSUI supply `3.711679886`, vSUI supply `0.37160719`, SUI supply
`545.10591389`.

### Test 3 — a date beyond the current cursor (resume, not genesis)

Request a date well past the current cursor (in testing: cursor was at `2025-10-06`, request
was `2026-06-28` — an ~8-month walk). Confirmed working: triggers a **second** Action run that
resumes from the existing checkpoint rather than restarting from genesis, takes noticeably
longer (expect minutes, not seconds, for a walk this size), and correctly produces `rwa`
positions for the first time (this wallet's XAUm supply to the `rwa` market happened June 18,
2026, inside this walked range).

Expected: wallet SUI `5.117254324`, NAVX `0.007329887`, USDC `0.000004`; NAVI `main` — WBTC
`0.007277565`, LBTC `0.03549679`, suiUSDT borrow `1591.372809337`, BUCK borrow
`101.187115378`, USDY `339.45537419`, suiETH `0.149906108`, NAVX `0.001197227`, haSUI
`107.573691818`, vSUI `14.265855408`, SUI `1086.097469005`; NAVI `rwa` — XAUm supply
`0.238277338` (assetId 1), USDC borrow `322.634869335` (assetId 0).

### Test 4 — repeat of an already-served date

Re-request any date already returned above. Confirmed working: instant, from cache, no Action
call. This is the simplest test and the one most likely to silently break if someone
"simplifies" `getCacheForDate` back toward fuzzy date matching.

### Test 5 — before this wallet's genesis (NOT YET TESTED against the live API)

**Flag this honestly: this path is implemented and reasoned through carefully, but was never
actually exercised against the live production API during development** — only designed and
code-reviewed. Request a date clearly before this wallet's first-ever on-chain activity (e.g.
`2025-01-01`, well before its September 2025 genesis). Expected: `400`, with a message about
predating the wallet's earliest on-chain activity. If you touch this path, this is the first
thing to actually verify live, not just re-read the code for.

### Test 6 — the `500` inconsistency path (not easily testable, document instead)

This path only fires when a cache row is missing for a date that a cursor claims is already
covered — i.e. a genuine bug or manual data corruption, not something reachable through normal
API usage. Not practical to test by hitting the endpoint normally. If you need to verify this
logic, the honest way is to manually delete a cache row from the middle of an already-
reconstructed range for a test wallet, then request that exact date.

### Test 7 — resuming into a quiet window (the bug found above) — CONFIRMED FIXED

Request a date shortly after an existing cursor where the wallet had **no on-chain activity**
in between. Confirmed live in production after the fix: cursor at `2026-06-28`, requested
`2026-07-07` (zero transactions in between, same scenario that originally found this bug).
Result: `200`, `source: "reconstructed"`, `asOfDate: "2026-07-07"` — every single field in
`wallet.coins[]` and `navi.positions[]` came back **byte-identical** to the `2026-06-28`
snapshot (all 10 `main` positions, both `rwa` positions), with only `asOfDate` and
`generatedAt` correctly differing. That's the exact predicted carry-forward behavior,
confirmed against real data rather than just re-read from the code. Before the fix, this
same request returned `400` predating-genesis instead.

### Not yet independently re-verified after the schema migration

`/sui-holdings-now/{address}` (the live path) wasn't explicitly re-tested against the new
`as_of_date`/`source` columns during this work, beyond the one code review confirming its
`store()` call site was updated correctly. The change there is small (two extra constructor
arguments), but if something historical seems subtly wrong, don't assume the live path is
unaffected just because it wasn't the focus of this work — check it too.

---

# Part 2 — `/holdings-now`'s on-chain `defi` enrichment (Compound III + Aave V3)

This part is for an AI agent (or a human moving fast) picking up work on the Compound/Aave
parts of `GET /holdings-now/{address}` later. Read this before touching `AbiCodec.php`,
`EvmRpcClient.php`, `CompoundHoldingsClient.php`, `AaveHoldingsClient.php`,
`HoldingsNowCacheRepository.php`, or the parts of `App.php` referenced below
(`handleHoldingsNow`, `respondWithHoldingsNow`, `requireBcmath`, `DEFAULT_RPC_URLS`).

## The endpoint, in one paragraph

`GET /holdings-now/{address}` is primarily powered by Zerion (see the rest of this file's
sibling documentation in `README.md` for that side). Each chain's existing `defi` key in the
response was extended from a flat Zerion-sourced list into an object: `{compound, aave, other}`.
`compound`/`aave` are populated by directly reading Compound III / Aave V3's own contracts via
raw `eth_call` (`CompoundHoldingsClient` / `AaveHoldingsClient`) — no third-party API for that
part. `other` is whatever Zerion itself reported under that chain's `defi` (staking, LP, etc.,
unrelated to Compound/Aave) — this is the *previous* behavior, preserved under a new name rather
than removed. The **entire** `/holdings-now` response (Zerion holdings + this on-chain
enrichment) is cached per address for 2 hours in `multichain_holdings_cache`
(`HoldingsNowCacheRepository`), checked in `handleHoldingsNow` before any of the expensive work
below runs at all. This is a separate, outer cache from Zerion's own internal 10-minute cache
(`ZerionPositionRepository`) — the two don't know about each other; the outer one just wraps the
whole computation, however it happened to be produced.

## Critical, hard-won bugs — read before changing anything here

**1. `ltrim($hex, '0x')` looks right and is completely wrong.** PHP's `ltrim` treats its second
argument as a **character mask**, not a literal string — so `ltrim($hex, '0x')` strips any
leading `0`s *and* `x`s, not the `"0x"` prefix. Since `eth_call` results are almost always
zero-padded, this silently corrupts real data while looking correct in casual testing. Always
use `AbiCodec::strip0x()` instead. This bug was caught by an offline unit test comparing against
known-good `ethers.js`-encoded vectors, not by inspection — if you're refactoring `AbiCodec`,
re-run that kind of test rather than trusting a code read.

**2. This project's production server does not have the `bcmath` extension enabled, and it's
not going to.** `AbiCodec`, `CompoundHoldingsClient`, and `AaveHoldingsClient` were originally
written using `bcmath` (matching the pre-existing convention in the EVM transaction-replay
reconstruction system that used to live behind `/holdings`), which crashed production with
`Call to undefined function App\bcadd()` — a raw PHP fatal error leaking a stack trace into the
API response. They were then rewritten to use **only native PHP string/int arithmetic** — see
`AbiCodec::bigMulAdd()` (manual long multiplication for hex→decimal conversion) and
`AbiCodec::formatUnits()` (string slicing, which works because the divisor is always a power of
10 here, so no general big-integer division is ever needed). This was verified correct by
running the full test suite with `bcmath` forcibly disabled (`php -n`), including the 256-bit
max-uint boundary case, not just by reasoning about it. **Do not reintroduce `bcadd`/`bcmul`/
`bcdiv`/`bcmod`/`bccomp`/`bcpow`/`bcsub` anywhere in these three files** — that would silently
reintroduce this exact crash. (Update: the old reconstruction system — and with it, the only
other `bcmath` dependency in this project, plus the `App::requireBcmath()` guard that used to
protect it — has since been removed entirely; see "A previous EVM reconstruction attempt" in
`README.md`. `bcmath` is not used anywhere in this codebase anymore, EVM or SUI side, which is
one less thing to worry about if you're extending `AbiCodec` further.)

**3. RPC URLs need a code-level default, not just a `config.example.php` entry.** The first
version of this feature read RPC endpoints only from `$config['rpc']`. That works for a fresh
setup that copies the example file, but this project's actual `config.php` already existed
before this feature was added and doesn't have that key — so in production, every chain was
silently skipped and `compound`/`aave` came back empty for every wallet, including ones with
real positions. Fixed by adding `App::DEFAULT_RPC_URLS` as a code-level fallback,
merged with (and overridable by) `$config['rpc']`. If you add a new chain, add it to
**both** `App::DEFAULT_RPC_URLS` and `config.example.php` — the example file alone is not
sufficient for anything running against an existing `config.php`.

**4. The `defi` restructuring went in the wrong place on the first attempt.** The first version
added a new top-level `defi` key to the response, separate from `holdings`. That was wrong —
the correct design (confirmed by the project owner) is that each chain's *existing* `defi` key
under `holdings.<chain>` gets restructured in place into `{compound, aave, other}`, not
duplicated elsewhere. If you're tempted to hoist Compound/Aave data to the top level for
convenience, don't — that was already tried and explicitly rejected.

**5. `onDuplicateKeyUpdate()` is a real, working method — verified against the actual library
source, not assumed.** Part 1's "no confirmed UPDATE/UPSERT" warning made this worth checking
rather than assuming either way. The query builder (`neutronstars/database-sql`, pulled from
its GitHub source directly since no local `vendor/` copy was available at the time) documents
and supports exactly the pattern used in `HoldingsNowCacheRepository::store()`:
`->insertInto(columns, placeholders)->onDuplicateKeyUpdate(assignments)`. This doesn't
contradict Part 1's warning — it's a different, narrower claim: this *specific* single-row
upsert pattern is confirmed, not a blanket "UPDATE works fine everywhere" conclusion. If you use
a different UPDATE/UPSERT shape than this exact one, re-verify it the same way (check the
library source directly) rather than assuming it also works.

## Architecture: how the pieces fit together

- **`AbiCodec`** — pure, stateless ABI encode/decode helpers, no I/O. Shared by both clients.
  Notably: `hexToDec`/`formatUnits`/`isZero` are the native-PHP big-integer replacements for
  `bcmath` (see bug #2 above). `decodeReservesTokens()` decodes Aave's
  `getAllReservesTokens()` — a dynamic array of dynamic tuples, the one genuinely tricky piece
  of ABI decoding here (everything else is fixed-size words). Validated against known-good
  `ethers.js`-encoded vectors, not just written from the ABI spec by hand.
- **`EvmRpcClient`** — thin wrapper around a single JSON-RPC `eth_call`. Returns a hex string on
  success or `EvmRpcClient::ERROR_UPSTREAM` on failure (matches this codebase's existing
  error-constant convention, e.g. `ZerionClient`, rather than throwing).
- **`CompoundHoldingsClient`** — one Comet market per chain (currently USDC only — see "Known
  limitations" below). `getHoldings()` returns `{positions: [chain => data], errors: [chain =>
  error]}`; a chain with an RPC failure lands in `errors` and is simply absent from `positions`,
  rather than failing the whole call.
- **`AaveHoldingsClient`** — discovers the reserve list live via `getAllReservesTokens()` (not
  hardcoded, unlike Compound's per-chain market list), then reads each reserve's position via
  `getUserReserveData`, plus an aggregate summary via `getUserAccountData`. Same
  `{positions, errors}` shape as `CompoundHoldingsClient`.
- **`HoldingsNowCacheRepository`** — the outer whole-response cache (`multichain_holdings_cache`
  table). Deliberately **not** append-only, unlike `sui_holdings_cache` in Part 1: `address` is
  unique, every fresh fetch overwrites the previous row via `onDuplicateKeyUpdate` (see bug #5
  above), since there's no counterpart endpoint here that reads a past snapshot by date the way
  `/sui-holdings` does.
- **`App.php`** — `handleHoldingsNow` checks the outer cache first, then runs the existing
  Zerion logic (unchanged), then calls `respondWithHoldingsNow` on every success path.
  `respondWithHoldingsNow` merges `DEFAULT_RPC_URLS` with `config.php`'s overrides, calls both
  clients, restructures each chain's `defi` key into `{compound, aave, other}` (including
  synthesizing a chain entry for one that has a Compound/Aave position but zero Zerion-tracked
  activity — see bug #4's fix), assembles the final response, stores it in the outer cache, and
  echoes it. Only successful responses are cached — error paths (missing Zerion key, Zerion
  failure with no usable fallback) are not, matching Part 1's caching precedent.

## Known limitations — real, not hypothetical, but out of scope so far

- **Compound tracks each chain's USDC market only.** A wallet with a Compound position in a
  WETH-base or USDT-base market (Compound III has one market per base asset, not one pool) will
  not show it. To add one, pull the Comet proxy address from
  `deployments/<chain>/<asset>/roots.json` in `compound-finance/comet`'s GitHub repo and add a
  row to `CompoundHoldingsClient::MARKETS`.
- **No USD pricing on the Compound side.** Aave's `summary` includes USD totals (from Aave's own
  oracle, via `getUserAccountData`); Compound only returns raw token amounts. Not yet
  implemented — would need a price source (Chainlink feeds via Comet's `getAssetInfo().priceFeed`,
  or an external price API) if requested.
- **The `defi_errors` path (per-chain RPC failure surfaced in the response) is implemented and
  logically exercised by the error-constant plumbing, but was never actually observed against a
  real RPC outage during development** — only reasoned through and code-reviewed, the same
  honest caveat Part 1 applies to its untested paths. If you're touching error handling here,
  this is the first thing to actually verify live (e.g. by pointing a chain's RPC URL at
  something that will time out) rather than re-reading the code for it.
- **Public RPC endpoints (`publicnode.com`) rate-limit under real load.** Development testing
  was occasional single requests, not load-tested. If `/holdings-now` starts seeing frequent
  `upstream_error` entries in `defi_errors` in production, that's the first thing to suspect —
  the fix is overriding `config.php`'s `rpc` section with a paid provider (Alchemy/Infura/
  QuickNode), not a code change.

## Regression checklist — real wallet, real validated values

Everything below was tested against a real wallet during development — referred to here as
**"the test wallet"** rather than printing the address (same anonymization approach as Part 1's
"Pierre's Wallet"). If you change anything in `AbiCodec`, `CompoundHoldingsClient`,
`AaveHoldingsClient`, `HoldingsNowCacheRepository`, or the `/holdings-now` parts of `App.php`,
re-run a request for this wallet and confirm the shape and rough magnitude still match — exact
amounts will have drifted (see note below), but which chains/assets appear should not have.

**Reminder before re-testing:** these numbers were correct *at the time of testing*. Compound
and Aave both accrue interest continuously, so a fresh request will show slightly different
supplied/borrowed amounts than listed below (observed directly during development: the same
wallet's Base USDC borrow grew from `7496.997802` to `7498.864669` to `7499.360402` across three
requests made minutes apart) — that's correct behavior, not a bug. What shouldn't change: which
chains have positions, which assets appear, and roughly-stable fields like `usedAsCollateral`.

Confirmed working end-to-end against the live production API (unlike some of Part 1's paths,
this was fully exercised, not just code-reviewed):

- **Base**: Compound — no supply, ~7499 USDC borrowed, collateral `WETH` (~0.134) + `cbBTC`
  (~0.284). Aave — reserves `WETH` (~0.234, used as collateral), `cbBTC` (~0.0699, used as
  collateral), `USDC` (~1805 variable debt, not used as collateral); summary health factor
  ~2.26, LTV `80`, liquidation threshold `83`.
- **Polygon**: Compound — no supply/borrow, collateral-only: `WETH` (~0.0246) + `WBTC`
  (~0.000485). Aave — reserve `WBTC` (~0.00353, used as collateral), `USDT0` (~75 variable
  debt), `USDC` (~37.6 variable debt); summary health factor ~1.57, LTV `73`, liquidation
  threshold `78`.
- **Ethereum**: Compound — dust-only collateral, `WETH` ~`0.000000000057157522` (5.7e-11 WETH —
  confirms `formatUnits` handles very small amounts correctly, not just large ones). Aave —
  `null` (no position at all on this chain for this wallet; confirms the "no position" path
  returns `null` rather than an empty object).
- **BSC / Avalanche / xDai**: `compound: []`, `aave: null` on all three — chains this wallet has
  Zerion-tracked activity on (native balance, tokens) but zero Compound/Aave position. Confirms
  a chain existing in `holdings` for Zerion-only reasons doesn't accidentally get non-empty
  Compound/Aave placeholders.
- **Arbitrum / Optimism**: absent from `holdings` entirely for this wallet (no Zerion activity
  and no Compound/Aave position on either chain) — confirms chains genuinely absent everywhere
  don't get synthesized into the response.

### Not yet tested

- A wallet with a Compound/Aave position on a chain where it has **zero** other Zerion-tracked
  activity (the synthesized-chain-entry code path from bug #4's fix — `if (! isset($byChain
  [$chain]))` in `respondWithHoldingsNow`). Logically straightforward and code-reviewed, but
  never actually observed against a real wallet meeting that exact condition.
- Aave stable-debt (`stableDebt` in the reserve shape). The test wallet only ever had variable
  debt; Aave V3 still supports stable-rate borrowing on some deployments, and that field has
  never been observed non-zero in practice, only exercised by construction (the decode logic
  treats it identically to `variableDebt`, so this is a low-risk gap, but it is a gap).

---

# Part 3 — `/holdings` (historical) is now a pure cache lookup

This part is for an AI agent (or a human moving fast) picking up work on `GET
/holdings/{address}?date=YYYY-MM-DD` later — including anyone asked to reintroduce historical
reconstruction for it. Read this before touching the `/holdings` block in `App::run()`.

## What it does now, in one paragraph

`/holdings` used to derive historical balances itself, by fetching a wallet's full transaction
history from a blockchain explorer API and replaying it. That system (`WalletSyncService`,
`WalletDataRepository`, `HoldingsCalculator`, `Network`, `EtherscanCompatibleClient` and its
subclasses, plus the three database tables `wallet_sync`/`wallet_native_event`/
`wallet_token_event`) has been removed entirely — it wasn't reliable enough to keep, see the
lessons below. `/holdings` is now a pure cache lookup with no live computation at all, and a
single source: `HoldingsNowCacheRepository::getCacheForDate()` — the full `/holdings-now`
response cache, including the `{compound, aave, other}` `defi` split, same as `/holdings-now`
itself. A miss returns a `404`. This also means `bcmath` is no longer used anywhere in this
project (see bug #2 in Part 2, and "Why bcmath isn't needed anywhere in this project" in
`README.md`) — the only two things that ever needed it are both gone now (the reconstruction
system, and `AbiCodec`'s original `bcmath`-based implementation, separately rewritten — see
Part 2).

**This single-source design is a deliberate, explicit trade-off made by the project owner —
not an oversight, and not the obvious default.** An earlier version of this endpoint checked
two sources: the multichain cache above, falling back to `ZerionPositionRepository
::getPositionsForDate()` (Zerion-only position history, no `compound`/`aave`, but genuinely
accumulating a row per day, so it covered any date the address was ever fetched on — real
multi-day history, unlike the multichain cache's single row per address). That version was
shown to the project owner and explicitly rejected: **DeFi positions are a hard requirement
for this endpoint, not an optional nice-to-have** — a response that looks complete but is
silently missing `compound`/`aave` data was judged worse than a `404`. So the Zerion-only
fallback (and its now-unused `getPositionsForDate()` method) was removed entirely, and the
explicitly-accepted cost is that `/holdings?date=X` now only ever has a hit for the single
most recent date each address happens to have been fetched on via `/holdings-now` — not a
general historical lookup, whatever the endpoint's name might suggest. **Do not
reintroduce a Zerion-only (or any other DeFi-incomplete) fallback to "improve" date
coverage without checking with the project owner first** — that's the exact trade-off
already considered and rejected once. `zerion_position` and `ZerionPositionRepository`
remain in place and in active use — just not by `/holdings` — as `/holdings-now`'s own
internal 10-minute rate-limit cache for Zerion API calls; don't confuse "not used by
`/holdings` anymore" with "safe to remove."

## Lessons from the removed reconstruction system, if attempting this again

These are specific, hard-won findings from building and then removing the previous system —
worth not re-learning from scratch if reconstruction is revisited with a different approach:

- **A blockchain explorer API returning an ambiguous "not fully indexed yet" status is a trap.**
  Blockscout's legacy API can report internal transactions as not-yet-indexed for part of a
  requested range in a way that's easy to conflate with "genuinely no internal transactions here."
  Since incoming and outgoing transfers can both be missing from a partial result, treating it as
  empty can produce either an understated or overstated balance — not a closer approximation,
  just a different wrong number. Any reconstruction approach needs to treat this status as a hard
  failure requiring no-data-returned, not a soft "assume zero and continue."
- **Not every EVM chain has a working free/keyless historical data source at all.** Only Ethereum
  and Base ever had one confirmed working end-to-end (Routescan for Ethereum with an Etherscan
  fallback; Base needed a completely separate provider, its own Blockscout instance, since neither
  Routescan nor Etherscan's free tier covers Base). Polygon and BNB Smart Chain were never
  confirmed working against a live API despite looking supported on paper (Polygon returned
  "chain not supported" from Routescan). Confirm a provider actually works for a specific chain
  against a live call before assuming docs/pricing pages are accurate.
- **A single data source can be capped well below what a busy wallet needs.** Normal
  transactions, internal transactions, and token transfers were each capped at 10,000 records per
  request by the upstream API, regardless of pagination — a wallet with more activity than that
  in the requested range couldn't be fully reconstructed in one request at all.
- **Free-tier upstream calls fail transiently often enough that retries are not optional**, and a
  first-ever sync for an active wallet can involve enough sequential calls (with retries) to
  exceed PHP's default execution time limit — whatever replaces this will need the same kind of
  `set_time_limit(0)` treatment `App::run()` used to apply, scoped to just this endpoint rather
  than globally.
- **A provider-level failure can be wallet-specific, not just capacity-related.** One real
  wallet's internal-transactions call failed consistently on Routescan with a generic error while
  working fine for other wallets and other calls on the *same* wallet — confirmed as a genuine
  upstream issue (not a bug in this code) by testing the identical query against Etherscan's
  independent backend, which worked immediately. A single upstream provider, however reliable in
  general, is not guaranteed reliable for every specific address.
