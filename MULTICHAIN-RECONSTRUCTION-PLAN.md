# Multichain holdings reconstruction — delivery plan

Working document for building the `/holdings/{address}?date=YYYY-MM-DD` reconstruction. It is the
**single source of truth for the build**: work from this file, tick deliveries off as they ship,
and record deviations here. The blueprint and every verified finding it relies on live in
[`MULTICHAIN-HOLDINGS.md`](MULTICHAIN-HOLDINGS.md) (all pre-build items there are closed or
explicitly scoped out); project conventions live in [`AGENTS.md`](AGENTS.md).

Status: **plan complete and all decisions confirmed (Oct 17 2026); delivery 1 shipped (Oct 18 2026), next: delivery 2.**
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
- [ ] Delivery 2 — Date → block resolver
- [ ] Delivery 3 — `EvmLogScanner`
- [ ] Delivery 4 — Snapshot builder
- [ ] Delivery 5 — Cursor, lock, reconstruction service
- [ ] Delivery 6 — Endpoint wiring, docs, end-to-end

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
