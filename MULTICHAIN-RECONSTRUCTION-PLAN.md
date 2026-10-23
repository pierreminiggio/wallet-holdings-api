# Multichain holdings reconstruction — delivery plan

Working document for building the `/holdings/{address}?date=YYYY-MM-DD` reconstruction. It is the
**single source of truth for the build**: work from this file, tick deliveries off as they ship,
and record deviations here. The blueprint and every verified finding it relies on live in
[`MULTICHAIN-HOLDINGS.md`](MULTICHAIN-HOLDINGS.md) (all pre-build items there are closed or
explicitly scoped out); project conventions live in [`AGENTS.md`](AGENTS.md).

Status: **all six deliveries shipped; `/holdings?date=` is wired to the reconstruction. Remaining: the user's real end-to-end run (`bin/e2e-ladder.php`) and the real-RPC check of delivery 5 on the other chains.**
The build happens in later conversations, one delivery at a time, starting with delivery 1 (see
section 8 for how to pick the work up).

---

## 1. Goal and behavior

`GET /holdings/{address}?date=D`:

1. **Cache hit** for `(address, D)` in `multichain_holdings_cache` → return it, exactly as today.
2. **D is today (UTC)** and no row exists → `/holdings-now` path, exactly as today.
3. **Cache miss for a past date** → reconstruct, either from the wallet's genesis (no cursor yet) or
   from the most recent reconstructed point before D (the cursor), walking **day by day** up to D.
   **Every day walked gets its own `multichain_holdings_cache` row** (`source = 'reconstructed'`),
   so any later request inside that range is a pure cache hit. Then return the holdings of day D.

No background jobs, no scheduler, no status endpoint (decided, see `MULTICHAIN-HOLDINGS.md`,
"Execution model (decided)"). A first request for an old date may run for hours and be cut off by
the caller's or server's timeout; the caller simply retries and each retry resumes from the cursor.

### Why the resume point is a cursor, not the cache rows

A cache row only lists tokens with a current balance; a token that was held and then sold to zero
disappears from later rows. The set of discovered token contracts, and how far logs have been
scanned, therefore cannot be recovered from the rows. They live in the cursor table (section 3).

### The walk, per request

For each supported chain, in lockstep per day:

1. **Phase 0 (no cursor yet): find the wallet's first activity on the chain.** Scan logs forward
   from the chain's history-start block (block 0, except Polygon: **80,406,107**) until the first
   Transfer touching the wallet, and bisect `eth_getBalance` for native genesis. Stop at the block of
   the requested date: if nothing is found by then, the chain is empty for D. The wallet's first
   day is the earliest first-activity date across chains.
2. **For each day `X` from the first day (or `cursor_date + 1`) to `D`:**
   1. resolve `end_block(X)` (section 3, table 1);
   2. scan logs from `last_scanned_block + 1` to `end_block(X)`, extending the discovered token set
      (progress is saved to the cursor every N chunks, so a multi-hour scan survives a cut-off);
   3. if `(address, X)` already has any cache row (live rows win, see section 2) skip the snapshot;
      otherwise take the snapshot at `end_block(X)` (native `eth_getBalance`, `balanceOf` for every
      discovered token, Compound markets, Aave via direct aToken/debt-token `balanceOf`);
   4. write the combined per-day row to `multichain_holdings_cache`;
   5. **only then** advance `cursor_date` to `X` (a cursor that advances without its rows is the
      exact bug found on the SUI side).
3. Return the row for D.

---

## 2. Decisions

| # | Decision | Status |
|---|---|---|
| 1 | **"Holdings on date D" = end of day D**: state at the last block whose timestamp is before `D+1 00:00:00 UTC`. Today still goes through `/holdings-now`. | **Confirmed** |
| 2 | **Supported chains: Ethereum, Base, Polygon, BSC** (the four tested). | **Confirmed** |
| 3 | **Unsupported chains are returned as if the wallet held nothing there, with an extra key** marking them. Reconstructed rows contain an entry for each chain in `UNSUPPORTED_CHAIN_KEYS` (initially `avalanche`, `xdai`, the two non-tested chains the live cache has returned) shaped exactly like an empty chain plus `"reconstruction_supported": false`. See the example below. | **Confirmed** (key name is a proposal) |
| 4 | **Existing rows win**: a date that already has any row (a `live` row from `/holdings-now`) is served as-is; the walk never writes a `reconstructed` row for it (otherwise `getCacheForDate()`, which returns the newest `cached_at`, would let the reconstructed row shadow the live one). The walk still advances its cursor through that date. | **Confirmed** |
| 5 | **Concurrency**: one lock per (chain, provider) (file lock, `flock`, directory in config). A concurrent request that cannot take it gets **409** with `Retry-After`; it is not a job status, just contention. The walk also uses `ignore_user_abort(true)` and no PHP time limit, so a client disconnect does not kill a day half-way. Assumes a single web server (shared-hosting style); revisit if the API is ever deployed on several instances. | **Confirmed** |
| 6 | **Before genesis**: `400` only if **no supported chain** has any activity by the requested date; otherwise chains with nothing yet are simply empty. `500` = cache inconsistency (cursor says the date is walked but no row exists), `502` = RPC/provider failure after bounded retries. Same shape as `/sui-holdings`. | **Confirmed** |
| 7 | **Per-chain history start**: block 0 for Ethereum, Base, BSC; **80,406,107 for Polygon** (decision of item 4, a documented limitation). Kept as a per-chain constant, not baked into the scanner. | Confirmed (item 4) |

Decisions 1 to 7 are all confirmed. The only thing still open is cosmetic: the name of the extra key
(`reconstruction_supported`), which can be renamed at delivery 4 without any other impact.

**Chain keys** used everywhere (response bodies, `evm_block_by_date.chain`, the cursor table, config)
are the live cache's keys: `ethereum`, `base`, `polygon`, `binance-smart-chain` (supported) and
`avalanche`, `xdai` (unsupported).

### Response shape for unsupported chains

```json
"avalanche": {
  "native": null,
  "tokens": [],
  "defi": { "compound": [], "aave": null, "other": [] },
  "reconstruction_supported": false
}
```

Supported chains use the same shape as `/holdings-now` (`native`, `tokens`, `defi`), with no extra key.

---

## 3. Database changes

`multichain_holdings_cache` itself needs **no schema change**: `source` is `VARCHAR(16)` and
`'reconstructed'` fits (13 characters). Everything below is new and follows the project's existing
conventions: `id bigint AUTO_INCREMENT`, **append-only** (the query builder has no confirmed
`UPDATE`/`UPSERT`), "pick the latest in PHP", Unix timestamps in `bigint` columns.

All of it must be added to the **README "Migration" SQL block**, plus a new explanatory subsection
next to "Historical reconstruction: `as_of_date` vs `cached_at`, and the cursor table", in the same
delivery that introduces each table (see section 4). The README sentences saying `'live'` is the
only source of `multichain_holdings_cache` and that `/holdings` has no reconstruction also have to
be rewritten, and so do the matching docblocks in `HoldingsNowCacheRepository`.

### Table 1 — `evm_block_by_date` (delivery 2)

Date-to-block cache, **per (chain, date), not per wallet**: the answer is wallet-independent, so
every wallet shares it.

```sql
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
```

`end_block` = last block with timestamp before `day_date + 1 day 00:00:00 UTC`. Duplicate rows
from two racing requests are harmless (same value); the reader takes any.
Only **completed** days are stored (never today, whose end block does not exist yet).

### Table 2 — `multichain_reconstruction_cursor` (delivery 5)

One row per advancement per `(address, chain)`; the newest wins.

```sql
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

- `last_scanned_block`: highest block whose logs have been fully and successfully fetched. The
  scanner never advances it past a range whose calls did not succeed (no hole at a resume boundary).
- `step_size`: the scanner's current adaptive chunk size, so a resume does not re-learn the cap.
- `tokens_json`: every token contract discovered so far (addresses only, never balances).
- `first_activity_block`: the chain's first activity found by Phase 0 (`NULL` until found).
- `cursor_date`: the date the walk has **fully completed through** (all rows written); `NULL` until
  the first day is done. Mid-scan progress rows keep `cursor_date` unchanged and only move
  `last_scanned_block`/`tokens_json`.
- The newest row is the one with the highest `id` (not `updated_at`: several rows can share a
  second). Growth: one row per chain per day walked plus progress rows; acceptable, prune later
  only if it ever matters.

### Table 3 — `multichain_reconstruction_unresolved_block` (delivery 3)

The persistent "unresolved blocks" record from the blueprint: a block that could not be fetched
even alone, on every configured provider, after bounded retries. The scan records it and moves on;
a human verifies it on a block explorer and promotes it into the hardcoded skipped-blocks constant.

```sql
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
```

### Not in the database

- **The lock**: a file lock per (chain, provider), directory set in `config.php`.
- **Skipped blocks**: a hardcoded constant per chain, per the blueprint. Initial entries (Base):
  `44661819`, `44661820`, `44692818`, `44692819`.

---

## 4. Deliveries

Each delivery is a zip with the modified and new files at their project paths, deployable on its
own. **`/holdings` behavior does not change until delivery 6.** PHP is not installed in the
authoring sandbox, so nothing can be executed there: every delivery ships a small CLI check script
(`bin/check-N-<name>.php`) that is run locally against real RPCs, and its output is pasted back
for review, the same way the Node verification scripts were used.

### Delivery 1 — Foundations (no behavior change)

- `EvmRpcClient`: optional block parameter on `ethCall` (default `latest`, so `/holdings-now` is
  unchanged), plus `getBalance`, `getLogs`, `blockNumber`, `getBlockByNumber`.
- **Strict response validation**: a response counts only if it is well-formed JSON-RPC with a
  `result` or an `error` key; empty/malformed is a failure, never an empty success.
  For historical `eth_call`, a `"0x"` result is an error, never a zero.
- **Error classes**: transient (back off and retry the same input), deterministic range/result cap
  (shrink immediately, no backoff), auth (provider unusable for that call; neither of the other two).
- `CompoundHoldingsClient` and `AaveHoldingsClient`: optional block parameter; Aave gets a
  `balanceOf`-on-aToken/debt-token mode (aToken/debt-token addresses resolved once from the
  DataProvider at `latest`, then reused for any date).
- Reconstruction config in `config.example.php` (kept apart from `DEFAULT_RPC_URLS`): per-chain
  archive/log-capable endpoints, lock directory; per-chain constants (history start, skipped blocks,
  `UNSUPPORTED_CHAIN_KEYS`).
- **Acceptance**: `bin/check-1-reads.php` reproduces the reference values in section 5 (Ethereum
  Compound, BSC Aave, Polygon Aave) and fails loudly on a deliberately wrong address.

### Delivery 2 — Date → block resolver

- `EvmBlockResolver` (bisection on block timestamps, bounded by the chain's history start) and
  `EvmBlockByDateRepository`; Table 1; README migration block + subsection updated.
- **Acceptance**: resolves the reference blocks in section 5 (mind the mapping note there).

### Delivery 3 — `EvmLogScanner`

- Adaptive chunking per the blueprint (section 3 of `MULTICHAIN-HOLDINGS.md`): the two failure
  classes, 1-block shrink floor, skipped-blocks list honored, unresolvable block recorded (Table 3
  and its repository are introduced here; README migration block + subsection updated) and the scan
  continues.
- Resumable state object (`last_scanned_block`, `step_size`, tokens found) serializable to the cursor.
- **Tests with a fake RPC** for: transient error then success, range cap, auth error, empty body,
  malformed body, a single block failing on every provider (recorded as a gap, scan continues), a
  skipped block (no request ever sent for it), resume at an interruption point with no hole.
- **Acceptance on real RPCs, tiny windows**: Polygon blocks 93,167,662 → 93,167,973 must yield
  exactly the 7 USDT0 transfers listed in section 5; blocks 80,406,376 → 80,745,873 the 10 native-USDC ones.

### Delivery 4 — Snapshot builder

- `EvmHoldingsSnapshotBuilder::snapshot(address, chain, block, tokens)` → the per-chain
  `native` / `tokens` / `defi` structure, same shape as `/holdings-now`. `balanceOf` for every
  discovered token (never summed deltas), Compound for all markets per chain, Aave via direct
  `balanceOf` (the DataProvider convenience method is only valid after each chain's redeployment).
- Unsupported chains produce the empty shape with `"reconstruction_supported": false`.
- **Acceptance**: the section 5 reference values, including the Polygon USDT0 residual
  (0.132953) and a token whose balance is non-zero only in a past window.

### Delivery 5 — Cursor, lock and reconstruction service

- Table 2, `MultichainReconstructionCursorRepository`,
  `MultichainHoldingsReconstructionService` (Phase 0, the day loop, per-day row write, cursor
  advance only after the row, outcomes `found` / `before_genesis` / `inconsistent` / `provider_error`
  / `busy`), the per-(chain, provider) file lock. README migration block + subsection updated.
- **Tests with a fake scanner and fake repositories**: interrupt after day N and resume (no hole,
  cursor never ahead of its rows); a date that already has a live row is skipped but the cursor
  moves past it; `before_genesis`; `inconsistent`; two overlapping requests (the second gets `busy`).

### Delivery 6 — Endpoint wiring, docs, end-to-end

- `/holdings/{address}?date=` on a past-date miss calls the service; status mapping 400/409/500/502;
  `source` in the response; the OpenAPI document in `App.php` updated; the stale comment block in
  `App.php` ("There is deliberately no historical reconstruction here") rewritten.
- `README.md`, `AGENTS.md` and `MULTICHAIN-HOLDINGS.md` brought in line (what exists now, the
  `unsupported` key, the lock, the 409).
- **End-to-end on the real wallet**: request an old date from an empty cursor, interrupt, retry,
  then compare the rows against the section 5 reference values and the saved 2026-07-19 live cache
  (the reconstructed neighbours of that date must bracket its Aave values).

---

## 5. Reference values for acceptance tests

All verified during the pre-build testing, wallet `0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe`.

**Mapping note.** Those verification runs resolved "the last block before `D 00:00 UTC`", i.e. the
state at the **end of the previous day**. Under decision 1 (end of day) that block belongs to the
row for **`D − 1`**. A reference below labelled "block for 2025-11-01" is therefore the
`end_block` of **2025-10-31**.

| What | Block (resolved for 00:00 UTC of the date) | Expected |
|---|---|---|
| Ethereum Compound USDC, 2025-11-01 | 23,700,766 | borrowed 933.435002 USDC, WETH collateral 0.4 |
| Ethereum Compound USDC, 2025-12-01 | 23,914,920 | borrowed 937.675240, WETH 0.476818727900527526 |
| Ethereum Compound USDC, 2026-01-01 and every later date | 24,136,052 … | no borrow, WETH 0.000000000057157522 |
| Ethereum Aave, 2025-12-01 | 23,914,920 | aEthWETH 0.028014789715922191, no debt |
| BSC Aave, 2025-11-01 | 66,605,720 | WBNB 0.170012507196328142, USDC debt 60.118707451980723546, account $185.09 / $60.11, HF 2.309450654857429632 |
| BSC Aave, 2026-10-01 and latest | 125,000,754 / 128,181,904 | all zero |
| Polygon Aave, 2026-02-15 | 82,996,788 | WBTC 0.00352710, USDT0 debt 73.179844, USDC debt 41.790999, HF 1.669568345605074184 |
| Polygon Aave, 2026-10-01 and latest | 94,738,439 / 95,693,425 | all zero |
| Native genesis | ETH 13,024,431 · BSC 10,027,014 · Polygon 80,406,107 · Base 35,321,858 | |
| Polygon native USDC balance | at 80,406,106 | 18.993040 (received before history start; moved at 80,406,376) |

Polygon USDT0 (`0xc2132d05d31c914a87c6611c10748aeb04b58e8f`) wallet transfers, one log each:
block 93,167,662 `+75.598753` · 93,167,710 `+0.120354` · 93,167,732 `-75.679396` ·
93,167,886 `+345.052550` · 93,167,919 `+0.801676` · 93,167,972 `-345.761329` ·
93,167,973 `+0.000345`; balance at latest 0.132953.

Polygon native USDC (`0x3c499c542cef5e3811e1192ce70d8cc03d5c3359`) wallet transfers: blocks
80,406,376 `-17.990703` · 80,511,190 `+53.709343` · 80,511,229 `-54.611680` · 80,512,567 `+160.964707` ·
80,512,690 `-161.064707` · 80,636,033 `+235.168996` · 80,636,107 `-175.168996` ·
80,636,216 `-58.955012` · 80,745,841 `+60.001012` · 80,745,873 `-61.046000`.

---

## 6. Risks and open points

- **Cost per day.** Each walked day makes roughly (discovered tokens + Compound reads + Aave reads)
  RPC calls per chain. Tested volume (a few hundred sequential calls on `drpc.org`) was fine; a
  multi-year walk is thousands. If throttling bites: skip Aave reserve reads when
  `getUserAccountData` shows zero collateral and zero debt (consistent with every date tested), and
  consider JSON-RPC batching (not tested yet). Neither is assumed in the first version.
- **First segment is the slow one.** The scan from the history start to the first day (Ethereum
  block 0, Base block 0, BSC block 0) is the bulk of the work; Polygon is bounded by its history start.
- **One wallet tested.** Skipped-block lists and provider caps were found on one wallet; re-check a
  listed block the first time another wallet's scan reaches it.
- **Untested token classes.** WBTC/WETH/WPOL/bridged USDC on Polygon and 12 other discovered Polygon
  tokens were not exercised by the balance spot-check (item 8); safe by design because balances are
  always read with `balanceOf`, never summed.
- **Polygon history start** is a documented limitation: tokens received before 80,406,107 and never
  touched again are not discovered.
- **Lock assumes one server** (decision 5).
- **Aave DataProvider convenience reads** are only valid after each chain's redeployment date
  (2025-06-05 to 2025-06-11); the first version uses direct `balanceOf` everywhere.

---

## 7. Working rules for every delivery

- **Deliver a zip** containing every modified and new file at its correct project path (even when only
  documentation changed), so it can be dropped straight into the project.
- **README "Migration" SQL block and its explanatory subsection** are updated in the same delivery that
  introduces a table (deliveries 2, 3 and 5), following the existing style (`CREATE TABLE`, then
  `ALTER TABLE ... ADD PRIMARY KEY / ADD KEY`, then `MODIFY id ... AUTO_INCREMENT`).
- **`AGENTS.md` conventions** apply (documentation of what exists, no silent behavior changes).
  `MULTICHAIN-HOLDINGS.md` is only edited when a verified fact changes or at delivery 6.
- **PHP cannot be executed in the authoring sandbox**: each delivery ships its own
  `bin/check-N-<name>.php` plus unit tests; the user runs them against real RPCs and pastes the output.
- **Git Bash quirk**: run Node helper scripts as `node.exe script.js 2>&1 | tee out.txt` (the plain
  `node` alias goes through `winpty` and fails with "stdin/stdout is not a tty" when piped).
- **Never trust a remembered address, selector or event signature**: verify with `eth_getCode`,
  `symbol()` or a known transaction, as the blueprint demands.
- Tick the progress list below and note any deviation from this plan in the delivery's own entry.

---

## 8. Picking the work up in a new conversation

Upload the current project zip (it must contain this file, `MULTICHAIN-HOLDINGS.md`, `AGENTS.md`,
`README.md` and `src/`), say which delivery to build (the next unticked one in section 9), and
mention that this plan is the reference. Everything decided and verified so far is in these files;
nothing else has to be re-explained. The verification scripts used during pre-build testing
(`eth-recheck.js`, `multichain-recheck.js`) are not part of the project; they are only needed if a
reference value in section 5 has to be re-read.

---

## 9. Progress

- [x] Delivery 1 — Foundations (see its deviations below)
- [x] Delivery 2 — Date → block resolver (acceptance output to confirm, see its notes below)
- [x] Delivery 3 — `EvmLogScanner`
- [x] Delivery 4 — Snapshot builder
- [x] Delivery 5 — Cursor, lock, reconstruction service
- [x] Delivery 6 — Endpoint wiring, docs, end-to-end

### Delivery 1 — notes and deviations

- PHP 8.3 *was* installable in the authoring sandbox this time, so the unit tests were actually
  run (56 tests, also with `php -n`). Only the real-RPC acceptance (`bin/check-1-reads.php`) still
  has to be run locally: the sandbox has no access to RPC providers.
- **Added, not in the plan: `EvmRpcClient::hasCodeAt()` (`eth_getCode`, memoized).** Without it the
  rule "historical `0x` is an error" would make every day before a Comet market / Aave token exists
  fail. Absence is now proven by `eth_getCode`, never inferred from an empty call.
- **Aave historical `usedAsCollateral` is `null`**: its only source is `getUserReserveData`, which is
  unreliable historically. Delivery 4 can decide whether that is acceptable or whether to add
  `Pool.getUserConfiguration` (selector and bit layout to be verified first).
- `EvmRpcError` classes also treat "missing trie node" / "header not found" (non-archive node) as
  *auth* (provider unusable for the call), and "invalid params" as *auth*, per the blueprint.
- Per-chain constants live in `EvmReconstructionChains`; config in `EvmReconstructionConfig`
  (providers are `{name, url}` lists, the name is used for the lock file). BSC has no keyless default.
- Compound/Aave clients keep one `EvmRpcClient` per URL so the `eth_getCode` memo and the Aave reserve
  list are paid once per walk. The clients return only `upstream_error` strings for failures; the
  error class is available via the injected client's `getLastError()` if delivery 4 needs it.
- Tests: `tests/run.php` (no PHPUnit, no vendor).
- `bin/check-1-reads.php` applies the blueprint's retry policy itself: transient errors are retried on the
  same input with exponential backoff (per call, then per check in rounds across all configured providers),
  an auth error drops that provider for the check, a data mismatch is never retried, and a check that stays
  unreachable is reported as INFRASTRUCTURE, distinct from a wrong value. Date-to-block discovery
  (bisection, its own retry handling) belongs to delivery 2.

### Delivery 2 — notes and deviations

- Added a result type, `EvmBlockResolution`, with four outcomes instead of a bare int: `resolved`,
  `before_start` (day earlier than the chain's history-start block: not an error), `day_not_complete`
  (today/future: never stored) and `error` (every provider failed). The walk (delivery 5) must treat them
  differently.
- Search = alternating interpolation and bisection steps over block timestamps (strict `<`: a block exactly
  at the day's end belongs to the next day). Tested against brute force on 25 random irregular chains
  (stalls, equal timestamps) and a 1M-block chain with a stall (≤ 45 reads).
- `endBlockForDate($date, $lowerBoundHint)`: the walk can pass the previous day's end block; it is verified
  before use and ignored when wrong.
- Provider failover is per read; the head is the **highest** head any provider reports (a lagging provider
  must not make a finished day look incomplete). This was a real bug caught by a test.
- `bin/check-2-blocks.php` resolves the section 5 references per the mapping note (reference date D is the
  end block of D-1) and independently verifies `ts(b) < end of day <= ts(b+1)`; same retry policy as check 1.
- New table `evm_block_by_date` is in the README migration block; nothing reads it until delivery 5 but it can
  be created now.

### Delivery 3 — notes and deviations

- `EvmLogScanner`, `EvmLogScanState`, `EvmLogScanResult`, `EvmUnresolvedBlockRepository` (Table 3). The scanner returns
  one of `complete` / `time_budget` / `first_activity` (Phase 0: stop after the first chunk with a matching log) /
  `provider_error` (resumable, nothing recorded as a gap).
- **Reading of "transient never shrinks"**: the client does the bounded same-range backoff; if every provider still
  fails, the scanner first checks that some provider is alive (otherwise `provider_error`, no shrinking, no gaps), then
  splits the range to isolate the block that cannot be served. This is the only case where a transient leads to
  shrinking, and it is needed for the dense Base blocks that time out even alone.
- **Added safeguards (not in the plan), because a total outage must never look like a lot of bad blocks:** a gap is
  recorded only if a nearby block (+1, +2, +4 ... +64, backward only near the end of the scan) succeeds with the same
  query; more than 5 gaps in a row stops the scan. Adjacent bad blocks (the Base pairs) are tolerated.
- Range-cap handling bisects between the largest size that worked and the smallest that failed, and probes ~12% above
  the converged cap only every 200 successes (about 2% of requests), instead of the blueprint's plain x1.5 growth which
  hit the cap every few chunks. Growth is `max(step + 1, step * 3 / 2)` (the integer x1.5 alone never leaves 1).
- **NFTs are out of scope (decided Oct 18 2026).** A Transfer log with 4 topics (ERC-721-like) is dropped right after the
  fetch: it never reaches the token set, the first-activity block or `stopAtFirstLog`. Earlier manual scans (459 Base
  tokens) did not make this distinction, so counts may be lower than those numbers.
- The `tokens_json` column holds the token addresses (JSON list); the other cursor fields have their own columns.
- `bin/check-3-scan.php` covers the two Polygon windows of section 5 (the 340k-block one behind `--long`), a resume in
  the middle, a wrong-wallet negative control and the Base skipped blocks. Same retry policy as checks 1 and 2.

### Delivery 4 — notes and deviations

- `EvmHoldingsSnapshotBuilder` (one `EvmRpcClient` per chain, kept for the whole walk so the `eth_getCode` memo, the
  token metadata and the Aave reserve list are paid once) and `EvmHoldingsSnapshotResult`. Provider failover is the
  service's job (delivery 5): it builds one builder per provider.
- **New error class `KIND_REVERT` in `EvmRpcError`** (JSON-RPC code 3, or "execution reverted"): a contract's own answer,
  never retried and not blamed on the provider. Without it a reverting spam token would have been retried and then
  failed every snapshot forever as a "transient".
- Unreadable token contracts (revert, no `balanceOf`/`decimals`, empty answer from a contract with code at that block)
  are skipped and reported, not a failure. An empty answer is only accepted after `eth_getCode` confirms code at that
  exact block (not the memo); otherwise the snapshot fails. A failing `symbol()` falls back to `UNKNOWN`.
- Symbols: dynamic `string` and legacy `bytes32` are decoded, control characters stripped.
- Zero native and zero token balances are omitted (`native: null`), like the live cache. `isEmpty()` tells the service
  when a chain has nothing at all (the live cache omits such chains; whether a reconstructed row does too is delivery 5).
- Aave `usedAsCollateral` is `null` (decided in delivery 1). Polygon native symbol is `POL`.
- `bin/check-4-snapshot.php`: USDT0 residual and the balances between its 7 transfers, native USDC held only in a past
  window, Compound (Ethereum), Aave (Ethereum, BSC, Polygon), the zero cases, native balance at each chain's genesis
  (zero one block before, non-zero at it), negative controls.

### Findings from the first real runs of checks 3 (long) and 4

- **Section 5 list of native-USDC transfers is incomplete.** The scanner finds 12 transfers in Polygon blocks
  80,406,376 to 80,745,873, not 10: an extra pair `+243.550038` at 80,526,467 and `-243.550038` at 80,526,491. It nets
  to zero, which is why the earlier balance reconciliation never showed it. Check 3 now expects the 12, and check 4 asserts
  `balanceOf` around the pair (0, 243.550038, 243.550038, 0): **pending the user's run**, that is what confirms the extra
  pair is real and not a scanner artefact. (If it fails, the scanner is wrong, not the list.)
- **"Polygon Aave all zero" means Aave only**: the wallet has Compound positions on Polygon at 94,738,439. Check 4 had
  asserted both; it now asserts Aave only and prints the Compound count.
- Native genesis pairs are self-controlling (zero one block before, non-zero at genesis); a burn address is not a valid
  wrong-wallet control because it holds funds, so the wrong-wallet control no longer applies to them.

### Delivery 5 — notes and deviations

- Added `MultichainChainWalk` (per-chain mutable state of one request, an object so the scanner's progress callback sees the
  current cursor date), `MultichainReconstructionOutcome`, `EvmReconstructionLock`, `MultichainReconstructionCursorRepository`
  (Table 2) and `MultichainHoldingsReconstructionService`.
- **A sixth outcome, `storage_error` (500)**, for a database or lock-directory failure: not one of the five in the plan,
  kept apart from `inconsistent` (which means cursors and rows disagree) and from `provider_error`.
- **Tests use the real scanner, resolver and builder over a fake RPC chain** instead of a fake scanner: it exercises the
  whole stack (12-second blocks, a sold-to-zero token, a token created mid-walk, Polygon's history start). They cover every
  case of the plan: interrupt and resume (no hole, no duplicate row, equal to an uninterrupted walk), a crash between a row and
  its cursor, a live row skipped while the cursor moves past it, `before_genesis`, `inconsistent`, overlapping requests
  (`busy`, locks released after busy and after failure), provider failover, and the invariant "a cursor never saved without
  its row" enforced by the in-memory cursor repository.
- **Phase 0 detail found while designing it:** `EvmLogScanner` now returns `first_activity` BEFORE its progress callback, so a
  Phase 0 progress row can never be saved past the first log while `first_activity_block` is still null (a crash right after would
  have lost the first activity for good). Tested.
- **Native genesis** = coarse balance samples every 500,000 blocks (plus the target block), then a bisection in the first
  zero-to-non-zero window, as in the blueprint. First activity = min(native genesis, first log). Limitation: a native-only wallet
  whose funds came and went entirely between two samples and left no token log is not seen.
- **Which chains appear in a row**: a supported chain with nothing at all on a day is omitted (the live cache omits it too);
  `avalanche` and `xdai` are always there, empty and flagged. Decision 6's "simply empty" is read this way: **to confirm in delivery 6**.
- Locks: all (chain, provider) locks of every walked chain are taken up front, non-blocking; any one taken means `busy` with
  `BUSY_RETRY_AFTER_SECONDS = 60`. A chain with no provider (BSC without a key) fails the walk (`provider_error`), never a silent skip.
- A discovered token set can include tokens first seen after the day being snapshotted (a chunk can span days): harmless, balances
  come from `balanceOf` at the day's block.
- `bin/check-5-service.php` runs the real service on Polygon only, with in-memory repositories (no database), against real RPCs.
  Not covered there, left to delivery 6's end-to-end: Ethereum/Base/BSC walks, a real database, the HTTP layer.

### Delivery 6 — notes and deviations

- `GET /holdings/{address}?date=`: a cached row is served as-is whatever its source; a miss for a **past** date runs
  `MultichainHoldingsReconstructionService`; a miss for today still goes through `/holdings-now`.
- **Response `source`**: `reconstructed` for reconstructed rows; live rows keep `multichain_cache` (the value the endpoint has
  always returned, so existing clients do not break). `HoldingsNowCacheRepository::getCacheForDate()` now also returns the
  row's own `source`.
- **Status mapping** (pure, unit-tested in `HoldingsForDateResponse`): found 200, before_genesis 400, busy 409 + `Retry-After`,
  provider_error 502 (`detail`, `walked_through`), inconsistent and storage_error 500 (detail logged, never sent to the client).
- **Behavior changes for existing callers**: a past date that has no cached row no longer returns `404`, it is reconstructed
  (that is the feature). A **future** date, which used to be a `404`, is now a `400` ("date cannot be in the future").
- Reconstructed rows may carry a top-level `reconstruction.skipped_tokens` key; it is passed through in the response.
- OpenAPI updated (description, `409`/`500`/`502`, `ReconstructionError`, `source` enum, notes on reconstructed rows); the `404`
  of this path was removed. All `$ref`s were checked to resolve.
- README (overview, operating notes, setup step, migration), `AGENTS.md` (header and Part 3 status) and a status banner on
  `MULTICHAIN-HOLDINGS.md` were brought in line with what exists.
- `bin/e2e-ladder.php` (replaces an earlier parameterised script): nine fixed rungs, each a date with a fixed expectation, from
  cheap to expensive, on the real database and providers from `config.php`; nothing to pass. Rung 1 (2021-08-01) expects
  `before_genesis`; rung 2 (2021-08-25) is the first walk and kills a child process after 60 s to prove the resume; rung 3
  (2021-12-31) measures the per-day cost; rung 4 (2023-01-31) crosses the Compound III and Aave V3 deployments; rung 5 (2025-09-30)
  crosses Base's genesis; rungs 6 to 9 check the section 5 references as end-of-day rows, Polygon's native USDC day by day, the live
  row of 2026-07-19 against its reconstructed neighbours, and the integrity of the rows and cursors. It always runs all four chains
  (an existing row is never overwritten, so a restricted run would leave days permanently incomplete), stops at the first failing
  rung, is safe to re-run (a walked rung is almost free), and has `--list` and `--through=N`. **Not run by the author.** The genesis
  dates in the rung descriptions are estimates from block numbers; the checks themselves use exact blocks.
- **Cost warning, unchanged from the risks list**: a first request for an old date on a multi-year wallet makes thousands of RPC
  calls per chain per year of history with keyless providers; hours is the expected order. Skipping Aave reserve reads when
  `getUserAccountData` shows zero was considered and rejected: that figure counts only reserves enabled as collateral, so a
  supplied-but-not-collateral position would be silently dropped.

### Finding from the first run of the e2e ladder (rung 1), and the fix

- **What happened.** Rung 1 (a date before the wallet's first activity) makes Phase 0 scan Ethereum's logs from block 0. On a keyless
  provider (`drpc.org`, ~101 blocks per `eth_getLogs`) that is ~13 million blocks, i.e. ~130,000 requests: **about half a day**, even
  for a "cheap" date, and the scanner wrote a cursor row every 20 chunks (one every ~6 s: 241 rows, `last_scanned_block` growing by
  2,020 each, `tokens_json` `[]`, `first_activity_block` and `cursor_date` NULL). That was the design working as specified, but it is
  too many rows (~64,000 for this wallet's Ethereum Phase 0 alone).
- **Fix.** Mid-scan progress rows are now throttled by TIME: at most one per chain per `PROGRESS_SAVE_INTERVAL_SECONDS` (60), about
  720 rows for a 12-hour scan; a crash costs at most a minute of scanning. Rows for first activity, for each completed day and on
  every provider error are unchanged. The service also takes an optional logger, which the ladder prints with timestamps so that a long
  silence never looks like a hang.
- **Operational consequence, to state plainly:** a keyless Ethereum provider makes even the first segment take hours; a keyed one
  (NodeReal, ~50,000 blocks per request) makes it minutes. Configure one before running the ladder.
- The existing cursor rows are harmless (the newest row per address and chain wins, and a resumed run continues from the last one).

### Finding from `bin/check-providers.php` on the user's providers (Oct 2026)

- **The keyless providers do not scale to this feature.** Measured: `eth.drpc.org` accepts a 97 to 194-block range and answered 2 of 12
  sample requests (10 "Request timeout on the free plan"): projected Phase 0 for Ethereum ~41 days. `base.drpc.org` and
  `polygon.drpc.org` also accept under 200 blocks (the blueprint's "~10,000 on Base" no longer holds, or depends on density): ~2.5
  requests per second, Base's Phase 0 projects to ~3.4 days. NodeReal on BNB Smart Chain accepts 50,000 blocks: ~95 s.
- The ladder's first run showed it too: ~23 blocks per second on Ethereum in the later part of the run (about 13 times slower than the
  first run), consistent with heavy timeouts.
- **Consequence.** Everything below holds, but the cost of a first request for an old date is set by the providers' `eth_getLogs` range, not
  by the code: keyed providers with large ranges are a precondition for a multi-year wallet on Ethereum, Base and Polygon. The benchmark now
  also projects the daily log scan (blocks per day over the cap, two requests per chunk) and the total.
- Not addressed by code, deliberately: no provider-specific tricks, and no cap assumptions in the scanner (it learns the cap at runtime).

### Findings from the first successful Phase 0 on real providers (e2e ladder, rung 1)

- With NodeReal on Ethereum and BSC, Phase 0 took ~5 minutes on Ethereum (13 million blocks) and ~1.5 minutes on BSC (9.7 million),
  as `bin/check-providers.php` had projected.
- **The wallet's first Ethereum activity is block 12,427,798, not its native genesis 13,024,431**: a token arrived ~597,000 blocks
  (about 3 months) before the first ETH, exactly the warning of `MULTICHAIN-HOLDINGS.md` ("Do not assume native-currency genesis equals
  the wallet's true first activity"). The scanner was right and the ladder's first version was wrong: its rung 1 (2021-08-01) expected
  `before_genesis` and its rung 2 expected the first row near mid-August 2021. The ladder now uses 2021-04-15 for the 400 case and
  2021-06-15 for the first walk, and checks that the first activity is AT OR BEFORE the native genesis and that the earliest row is the
  day that contains it (computed from the stored end blocks, not from an estimate). BSC found nothing up to block 9,671,594, consistent.
- The run then stopped with `SQLSTATE[42S22] Unknown column 'source'`: the user's `multichain_holdings_cache` predates the
  `as_of_date` / `source` columns (the README's "in-place migration"). The ladder now checks the schema of all four tables BEFORE
  scanning and says exactly what is missing. The Phase 0 results were already saved in the cursor, so nothing was scanned twice.

### Ladder rungs 1 to 3 passed on the real database (Oct 21 2026), and the per-day cost

- Rung 1 (2021-04-15) `before_genesis` in 38 s (Phase 0 results were already in the cursor); rung 2 (2021-06-15) walked 34 days from the
  first activity (2021-05-13, the day containing block 12,427,798) in 19 min, after a child process killed at 60 s resumed from its cursor;
  rung 3 (2021-12-31) walked 199 more days in 1 h 57 min (BSC joined at block 10,027,014, its native genesis). Rows contiguous and unique,
  cursors at the date, first-activity checks green.
- **Per-day cost is flat at about 34 to 36 seconds** (33.6 s/day on Ethereum alone, 35.4 s/day once BSC is active), even in 2021 when the
  wallet holds almost nothing and neither Compound nor Aave exists yet. That points at a fixed per-day cost (end-block resolution,
  `eth_getCode` probes of contracts not deployed yet, a few balance reads) rather than at the size of the holdings, but this is a guess:
  it was not measured. Projected for the remaining ~1,750 days of Ethereum and BSC: ~17 hours if the cost stays flat, more after the
  Aave V3 and Compound III deployments (many more reads per day).
- **Instrumentation added to find out instead of guessing:** `EvmRpcClient::takeStats()` / `describeStats()` count HTTP attempts and time
  per call kind (`eth_getBalance`, `eth_call:0x70a08231` = balanceOf, ...), process-wide, and the service's per-day log line now ends with
  that day's breakdown. Optimise only from those numbers. Candidate levers, in order of expected value and with their risk: JSON-RPC batching
  (one HTTP request for many calls; needs a per-provider check that batches are accepted and not rate-limited per call), a cheaper end-block
  search seeded with the previous day's end block plus the expected blocks per day, and fewer `eth_getCode` probes before a deployment.

### Ladder rung 4 passed (Oct 21 2026): the per-day cost steps up at each DeFi deployment

- Rung 4 (2023-01-31) walked 395 days in 4 h 43 min with no failure; rows contiguous and unique, cursors at the date. The stored row of
  2023-01-31 looks right (ETH 0.44322845 and the TR3 token on Ethereum; six BNB Smart Chain tokens; `defi` empty; Avalanche and Gnosis present,
  empty, flagged `reconstruction_supported: false`). Re-running the ladder over already walked rungs costs ~20 s per rung, no new row.
- **Per-day cost from the log timestamps:** ~35 s/day until 2022-08-12, **~41 s/day from 2022-08-13** (Compound III markets now deployed: a handful of
  extra reads), then **~97 s/day from 2022-12-29** (the Aave V3 Pool now deployed: about 100 `balanceOf` reads over every reserve's aToken and
  debt tokens). The jump to 97 s is the dominant cost for every later day, on every chain where Aave V3 exists.
- **Projection, not a measurement:** the remaining ~1,360 days of Ethereum at ~97 s/day are ~37 hours, before BNB Smart Chain's own Aave V3 days and before
  Base and Polygon (small `eth_getLogs` ranges on the keyless providers; Base's Phase 0 alone projects to ~3 days there). A full multi-year
  reconstruction is therefore days, not hours, unless the per-day reads get cheaper.
- **Rung numbering changed:** a measuring rung was inserted as rung 5 (2023-02-28, a month with Aave V3 and Compound III live; each day's log line ends
  with the RPC breakdown), so the Base-crossing rung is now 6 and the last one is 10. **Do not run rung 6 before configuring a keyed Base provider**:
  it triggers Base's Phase 0 (35 million blocks).
- **Next lever, to be decided from measurements:** JSON-RPC batching (one HTTP request carrying many reads; the Aave and token balance reads of a day
  are independent). `bin/check-providers.php` now probes it per provider (accepted batch size, speed-up versus sequential reads) because
  support and limits differ by provider and must not be assumed; the code would fall back to sequential reads where a batch is refused or answered
  incompletely.

### Incident: a BNB Smart Chain URL configured under `base` (Oct 22 2026), and the guard it led to

- **What happened.** During the first run of rung 1 after adding a "Base" provider, the log showed `base: looking for the first activity, scanning
  logs from block 0 to 6600991`. Base had no block 6.6 million in 2021 (its genesis is June 2023): 6,600,991 is BNB Smart Chain's end block for
  2021-04-15. The Base provider list contained a `bsc-mainnet.nodereal.io` URL, which answered the block-by-date search with BSC data, and the
  result was cached under `('base', '2021-04-15')` in `evm_block_by_date`. The provider looked healthy: nothing in the data says which chain it
  serves.
- **Guard.** `EvmRpcClient::chainId()` and `EvmReconstructionChains::CHAIN_IDS` (1, 8453, 137, 56): the service checks every configured provider
  of every walked chain at the start of each request, before any block is resolved, and refuses to run (`provider_error`, naming the provider,
  the chain id it reports and the one expected). Tested with a wrong provider alone and among several (nothing cached, no cursor, no row).
  `bin/check-providers.php` prints each provider's chain id and flags a wrong one.
- **Cleanup the user must do** (the wrongly cached rows are not removed by code): delete the `evm_block_by_date` rows of chain `base`, the
  `multichain_reconstruction_cursor` rows of chain `base` for the wallet, and any `multichain_reconstruction_unresolved_block` rows of chain `base`;
  then check that no reconstructed row mentions Base before September 2025. See the SQL in the reply to this finding.
- **JSON-RPC batching, core only.** `EvmRpcClient::requestBatch()`, `ethCallMany()` and `hasCodesAt()` exist and are tested (answers matched by
  id, a dropped entry retried alone, a refused batch turns batching off, a revert kept as that entry's answer), and a provider entry takes an
  optional `batch_size`. Measured: NodeReal accepts 100 per request (60 to 95 times faster than sequential reads), drpc's free plan 3.
  **Not used yet by the Aave, Compound and token reads**: that wiring comes next, with an equivalence test against the sequential path.
