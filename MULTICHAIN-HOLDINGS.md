# Multichain Historical Reconstruction — Testing Notes

This document tracks the pre-implementation validation work for extending this
API's historical reconstruction feature (currently SUI-only, see the SUI
section of AGENTS.md) to EVM chains: native balances, ERC-20 token holdings,
and Compound/Aave positions, from genesis to any requested date.

**No implementation has started.** This is deliberate — every technique below
was proven against a real wallet with real, non-trivial history before any
code gets written, following the same discipline that made the SUI
reconstruction reliable. Several of the "obvious" first approaches turned out
to be wrong or incomplete; those mistakes are recorded here so they aren't
repeated.

Test wallet used throughout: `0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe`
(chosen because it has real, multi-year, multi-chain activity — tokens,
Compound, and Aave positions on more than one chain).

---

## ⚠️ CRITICAL — methodology bug found, invalidates prior "full coverage, zero results" claims

**UPDATE — root cause fully diagnosed and confirmed, not a quota:** through a structured bisection
(isolating range size as the only variable, re-testing previously-successful calls afterward to rule
out time-based degradation), the real, stable, reproducible cap on `drpc.org`'s free tier for
`eth_getLogs` is **101 blocks per call** — both for topics-only queries and address+topics queries,
tested independently and converging on the identical number. Size 101 succeeds, size 102 fails,
consistently, every time. **The error message text (`"ranges over 10000 blocks are not supported"`)
is wrong/misleading by roughly two orders of magnitude** — the actual enforced cap has nothing to do
with the number "10000" in that message. This is now certain, not a hypothesis: a previously-working
small call was re-tested immediately after a failing large call and still worked, ruling out any
account-wide quota, ban, or time-based throttling — it is a pure function of range size, and that
size is ~101, not ~10000.

**This finding, combined with the off-by-one bug below, means every chunked scan run this entire
session (Ethereum, BSC, Polygon — using STEP values of 9999 or similar) was requesting ranges
roughly 100x larger than this provider actually supports.** Given every scan's "success" detection
only checked for the *absence* of an error rather than logging failures explicitly, it is likely that
the overwhelming majority of chunks in every large scan this session **silently failed**, and the
"full coverage, zero results confirmed" claims throughout this document were built on a small
fraction of chunks that happened to succeed (or, in some cases, may have been entirely a sea of
silent failures with the reported "coverage" being pure block-arithmetic bookkeeping unrelated to
what was actually queried).

**Practical implication: every prior scan in this document must be re-run with a real per-call range
of ~100 blocks (safely: 100) and real error detection, before any of it can be trusted.** At ~100
blocks per call, a scan across tens of millions of blocks requires tens of thousands of RPC calls —
meaningfully slower and a real practical constraint worth knowing before committing to a from-genesis
reconstruction design built on this provider.

Original diagnosis (still relevant context, now superseded by the above as the primary cause):

Two compounding problems, found together:

1. **Off-by-one chunk sizing.** Every chunked scan script used in this project's testing computed
   `to = from + 9999`, intending a "9999-block-per-call" step to stay under providers' block-range
   caps. But `to = from + 9999` produces an inclusive range of **10,000 blocks** (`from` through
   `from + 9999` is 10,000 numbers), not 9,999. On `drpc.org`, this silently exceeded the real cap
   on at least one occasion (confirmed: a range of exactly 10,000 blocks was rejected with
   `"ranges over 10000 blocks are not supported"`).
2. **No real error detection.** Every scan script determined "found something" by checking whether
   the raw HTTP response *contained the substring* `"blockNumber"`. This means an **error response**
   (rate limit, range-too-large, malformed request, anything) and a **genuine empty result** were
   indistinguishable to every script written this session. A failing chunk silently counted as "we
   checked, nothing there" — with no visibility into how often this happened, on which chains, or
   for which event types.
3. **The "coverage self-check" didn't actually check coverage.** Every scan's self-verification
   compared *expected total block count* against *summed block-count arithmetic from the loop* —
   but that arithmetic runs regardless of whether the underlying RPC call for a given chunk actually
   succeeded. A scan could (and evidently did) report "coverage confirmed, matches expected" while
   some unknown number of its chunks had silently failed.

**Attempting to fix the range size alone did not resolve the specific failing case** — a corrected,
genuinely-9999-block-inclusive window still failed with the same error message. This suggests the
real constraint may not be a fixed range size at all — possibly a request-volume/rate quota on the
free tier (this session has made many thousands of `eth_getLogs` calls to `drpc.org` over its
duration), mislabeled with the same generic error text as the range-size case. This needs proper
isolation (e.g., wait and retry after a cooldown, test with a fresh/different provider, or test at a
time with no other traffic) before concluding anything further.

**Practical fixes required before any further scanning is trusted:**
- Use `to = from + 9998` (or equivalent) for genuinely-9999-block-inclusive windows, and treat any
  provider's stated cap as needing an explicit off-by-one check, not an assumed round number.
- Every script must explicitly check for a JSON `"error"` key in each response and **halt or retry**
  on error — never silently continue as if an error response were an empty result.
- Every scan should log/count *failed* chunks separately from *empty* chunks, and refuse to report
  "coverage confirmed" unless the failed-chunk count is zero.
- **Every "full coverage, zero results" claim elsewhere in this document (Ethereum, BSC, Polygon —
  all of them) is retroactively unverified** until re-run with the above fixes. This includes TR3
  and NAFTY genesis discovery, Ethereum's Compound/Aave historical trend checks, and every "zero
  ERC-20 activity" conclusion. None of these should be treated as settled until re-confirmed.

This is now the top-priority item before any further chain is tested or any implementation work
begins.

---

## Core design conclusions (apply to every chain)

1. **Split the problem by data shape, same as the SUI side did:**
   - Native currency balance → direct point-in-time read (`eth_getBalance` at
     a historical block). No replay needed.
   - Compound/Aave positions → direct point-in-time read (`eth_call` at a
     historical block, reusing this project's existing `CompoundHoldingsClient`
     / `AaveHoldingsClient` logic with a block parameter added).
   - ERC-20 token *discovery* (which tokens has this wallet ever touched) →
     sequential log scan (`eth_getLogs` for `Transfer` events), chunked to fit
     provider limits.
   - ERC-20 token *balance at a date* → **must be a direct `balanceOf` read at
     the resolved historical block, not a sum of Transfer log deltas.** See
     the NAFTY finding below — this was the single most important correction
     made during testing.

2. **Delta-summing from Transfer logs is unsound as a general method.**
   A real token held by the test wallet (NAFTY, on BSC) increased in balance
   by ~3.84% with zero corresponding Transfer events anywhere in the wallet's
   history — almost certainly a rebasing/reflection-style token. Logs are
   reliable for *discovering* which tokens to check, never for *computing*
   the balance itself. Every token's balance, at every requested date, must
   be a direct `balanceOf(wallet)` call at the resolved block.

3. **Native currency transfers are invisible to `eth_getLogs`.** Only
   contract events show up in logs; a plain native transfer touching a
   contract-free wallet emits nothing. Native genesis must be found via
   balance inspection (coarse linear scan, then bisection), never via log
   scanning. Confirmed the hard way after an initial scan wrongly implied "no
   activity" for ~500k blocks that, in fact, contained the wallet's real
   native-currency genesis.

4. **Nonce-based "first activity" only sees outgoing transactions.** A wallet
   can hold real value and real DeFi positions for a long time before ever
   sending its first transaction (this happened on both Ethereum and Polygon
   for the test wallet — token/native genesis significantly predated the
   first outgoing tx in both cases). Never treat "first outgoing tx" as "wallet
   genesis."

5. **Convenience/aggregator contracts (e.g. Aave's `AaveProtocolDataProvider`)
   can be redeployed.** On Ethereum, the current DataProvider address has no
   code at all before block 22,686,778 (June 5, 2025) — a full redeployment,
   not a proxy upgrade (the Pool contract's bytecode was unchanged across the
   same range, confirming this is DataProvider-specific). Historical Aave
   reads before a chain's DataProvider deployment either need the prior
   contract address or must be treated as "no data available," clearly
   distinguished from "no position."

6. **Never trust a "coverage confirmed" scan as proof of a correct zero
   result.** Block-range arithmetic matching the expected total does not mean
   every individual `eth_getLogs` call succeeded — a provider could return an
   empty-but-valid result for a reason other than "genuinely no logs" (see
   the open Polygon discrepancy below). Any all-zero scan result needs a
   second, independent verification (e.g. a live `balanceOf`/position read)
   before being trusted.

7. **A wrong contract address often returns `0x` (empty), not an error.**
   Always sanity-check any newly-used address against a call expected to
   return real data before trusting a "zero" result from it. (Happened once
   already with a guessed `aEthWETH` address that turned out not to be a
   deployed contract at all.)

8. **A DeFi position is not guaranteed to trace back to a standard Supply/Borrow event or a plain ERC-20 Transfer.** Aave in particular has a distinct `MintUnbacked`/Portal bridging pathway that can create position state on a chain without either. Any discovery approach that assumes "every position starts with a Transfer or a Supply/Borrow event" needs to also account for this, or it will systematically miss real positions the way it did during testing on Polygon (see that chain's section below for the specific unresolved case this was discovered through).

9. **All chunked/bisection scripts must self-verify.** Every scan script
   should track total blocks covered vs. expected range size and flag a
   mismatch explicitly. This caught two real scanning bugs during testing
   (a wrong loop-iteration-count assumption, and an unvalidated bisection
   bound) before they became silent wrong answers.

---

## Chain-by-chain status

### Ethereum — mostly closed

| Item | Status | Notes |
|---|---|---|
| Free RPC | ✅ | `drpc.org`, fully keyless. Archive `eth_getBalance`/`eth_call` confirmed working (verified against a real historical balance change, not just a zero result). `eth_getLogs` capped at 10,000 blocks/call. `publicnode.com` also keyless but blocks archive reads (needs a personal token) — usable for non-archive calls only. |
| Native genesis | ✅ | Block 13,024,431 (Aug 14 2021), tx-confirmed: single incoming deposit, exact value match to balance at that block. |
| Token discovery | ✅ | Full coverage-checked scan across the wallet's entire active range (block 0 → first outgoing tx) found exactly one token (TR3), genesis block 12,420,246 — note this **predates** the native-currency genesis, confirming point 4 above. |
| Token balance correctness | ✅ | TR3's `balanceOf` exactly matches its one Transfer-in amount (well-behaved token). Cross-checked against NAFTY on BSC, which does *not* behave this way — see point 2 above. |
| Date→block resolution | ✅ | Binary search on `eth_getBlockByNumber` timestamps against a known real block/timestamp pair. Resolves to "last block before the requested UTC day," which is the semantically correct choice. |
| Compound historical reads | ✅ | Real position on the test wallet (Comet USDC market, WETH collateral): confirmed exact match to a value independently documented elsewhere in this codebase's own notes, plus a coherent multi-month trend (open → grow → wind down to dust) matching the account owner's own memory of events. |
| Aave historical reads | ✅ (partial) | Real position (WETH collateral, USDC variable debt) confirmed via direct `balanceOf` calls on the debt/collateral token contracts across 7 dates — coherent trend. The convenience `getUserReserveData` call on the DataProvider is **only valid from block 22,686,778 (June 5 2025) onward** — see point 5. Pre-2025 Aave history is out of scope for this wallet (confirmed with the account owner) and was not investigated further. |
| Throttling | ✅ | 200 sequential calls to `drpc.org`, 200/200 succeeded. |

### BNB Smart Chain — mostly closed

| Item | Status | Notes |
|---|---|---|
| Free RPC | ✅ (needs signup) | No working *keyless* archive option found — tried `drpc.org` (rate-limited), `publicnode` (archive gated), official `bsc-dataseed` (`"missing trie node"` — genuinely doesn't retain old state), `1rpc.io`, `ankr` (needs key), `meowrpc`, `llamarpc`, `blockpi` (down/unreachable). **NodeReal free tier confirmed working** for both archive `eth_getBalance` and `eth_call`, plus `eth_getLogs` (50,000-block cap, more generous than Ethereum's drpc). Free-tier signup, no payment — consistent with the project's "free only" constraint. |
| Native genesis | Not directly tested | (Wallet's BSC activity investigated was token-focused; native genesis on BSC not yet pinned down.) |
| Token discovery | ✅ (with a caveat) | Full coverage-checked scan (genesis block 10,027,281 → latest) for NAFTY found exactly one Transfer event, a swap-router transaction, tx-confirmed with block/timestamp/amount all cross-checked. |
| Token balance correctness | ⚠️ **Key finding** | NAFTY's current `balanceOf` (29,451.567...) does **not** match the sum of its Transfer history (28,363.005... from the single discovered transfer) — a ~3.84% unexplained increase with zero corresponding Transfer events. This is the finding behind point 2 above; treat as a rebasing/reflection-style token. Confirmed this is NAFTY-specific by cross-checking TR3 on Ethereum, which matches its Transfer history exactly. |
| Aave | ⚠️ Real gap found | Aave V3 **is** deployed on BSC (Pool: `0x6807dc923806fE8Fd134338EABCA509979a7e0cB`), confirmed via a real position on the test wallet ($110.64 collateral / $45.21 debt / 1.835 health factor, decoded and sane) — **but this project's existing `AaveHoldingsClient` has no BSC entry in its `POOLS` config at all.** This means `/holdings-now` is likely already under-reporting this wallet's BSC Aave position today, independent of anything to do with historical reconstruction. Worth fixing regardless of this feature's timeline. |
| Compound | ✅ N/A | Compound III is not deployed on BSC (confirmed) — no work needed here. |

### Polygon — Aave mystery resolved (scanner bug, not a chain anomaly); broader re-verification now required

**Resolution:** the wallet's real Polygon Aave activity (confirmed via two independently-supplied
real transaction hashes — a Borrow and a Supply, both decoding to exactly the expected event
signature, exact Pool address, and exact wallet address in the `onBehalfOf` topic) fell inside the
block range that was only ever affected by the off-by-one/error-swallowing bug described above.
This was **not** a chain-level anomaly, a wrong signature, a wrong address, or an exotic Aave
bridging mechanism — it was this project's own scanning methodology silently failing on real data.
See the critical section at the top of this document for full detail and required fixes.

One genuine, correct finding survived this investigation: **the "WBTC supply" transaction the
account owner initially pointed to was actually a Compound (not Aave) collateral deposit**, to the
same Polygon USDC Comet market already known from `CompoundHoldingsClient`'s config. This means the
earlier "Compound on Polygon: live-checked, zero" conclusion was **incomplete** — it only checked
the base asset's `balanceOf()`, never per-asset `userCollateral()` the way Ethereum's Compound check
correctly did. This still needs to be redone properly (in progress).

| Item | Status |
|---|---|
| Free RPC, native genesis, `eth_getLogs` mechanism itself | ✅ Still considered valid — these were direct point-reads or single-call sanity checks, not chunked scans, so unaffected by the bug above |
| Token discovery (plain ERC-20 Transfers) | 🔴 Needs full re-scan with fixed chunking + real error detection |
| Aave Supply/Borrow/MintUnbacked event scans | 🔴 Needs full re-scan — confirmed real events exist that a prior "complete" scan missed |
| Aave live position (`getUserAccountData`) | ✅ Still valid — direct point read, unaffected |
| Compound on Polygon | 🔴 Needs proper per-asset (`userCollateral`) check, not just base-asset `balanceOf` |

### Base — not started

Existing `CompoundHoldingsClient`/`AaveHoldingsClient` config already has Base
addresses (used by `/holdings-now` today), so live reads are presumably
already correct. No RPC survey, no genesis discovery, no historical
correctness testing has been done yet for Base.

### Avalanche — explicitly out of scope

Confirmed with the account owner: no meaningful holdings on Avalanche for the
test wallet. Deprioritized; will reuse the same proven method later if ever
needed, without dedicated testing.

---

## Immediate next steps (in priority order)

1. **Diagnose the Polygon log-scan discrepancy.** This is a correctness bug
   in the testing method itself, not just a missing data point — it must be
   understood before any chain's "zero results" can be trusted, including
   ones already marked ✅ above (worth a light re-check once the cause is
   known).
2. Resolve Polygon token discovery once the above is fixed, then test
   historical `balanceOf` correctness and Aave historical trend, mirroring
   what was done for Ethereum/BSC.
3. Add a BSC entry to `AaveHoldingsClient`'s `POOLS` config — this is a live
   product gap, independent of the reconstruction feature, and should
   probably be fixed sooner rather than later.
4. Run the full test sequence (RPC survey → native genesis → token discovery
   → token correctness → Compound/Aave historical) for Base.
5. Only after all of the above are closed: design the actual PHP
   implementation (cursor/reconstruction-state repository, endpoint wiring,
   caching), reusing the SUI reconstruction's architecture as the template
   per the design discussion that preceded this testing phase.

No code for the multichain reconstruction feature should be written until
this document has no 🔴 or unstarted rows left, per the project's own
"remove every shadow before building" approach.
