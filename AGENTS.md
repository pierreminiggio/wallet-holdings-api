# AGENTS.md

This file is for an AI agent (or a human moving fast) picking up work on the SUI-related
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

### Not yet independently re-verified after the schema migration

`/sui-holdings-now/{address}` (the live path) wasn't explicitly re-tested against the new
`as_of_date`/`source` columns during this work, beyond the one code review confirming its
`store()` call site was updated correctly. The change there is small (two extra constructor
arguments), but if something historical seems subtly wrong, don't assume the live path is
unaffected just because it wasn't the focus of this work — check it too.
