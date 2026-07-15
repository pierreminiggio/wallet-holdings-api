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

8. **All chunked/bisection scripts must self-verify.** Every scan script
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

### Polygon — in progress, one open discrepancy

| Item | Status | Notes |
|---|---|---|
| Free RPC | ✅ | `drpc.org`, keyless, archive-capable (verified against the wallet's own real balance change, not just a zero result). `eth_getLogs` capped at 10,000 blocks/call, same as Ethereum. NodeReal's endpoint pattern from BSC did not resolve for Polygon (DNS failure) — not investigated further since drpc already works. |
| Native genesis | ✅ | Block 80,406,107 (Dec 16 2025, 22:18:19 UTC), tx-confirmed: 41.13 POL, single incoming deposit. Notably ~10 minutes before the wallet's first outgoing Polygon tx — consistent "fund then use" pattern. |
| Token discovery | 🔴 **Contradicted — unresolved** | A full coverage-checked scan across the wallet's entire active range (block 80,406,107 → current) found **zero** ERC-20 Transfer events. But a direct, independent `getUserAccountData` read against Aave's Polygon Pool shows a real, non-trivial position ($227.78 collateral / $112.61 debt / 1.578 health factor) — which is impossible without the wallet having received an ERC-20 token at some point. **The log scan result cannot be trusted as-is.** Not yet diagnosed: could be a malformed request silently returning an empty-but-valid result, an encoding issue specific to this chain, or something else. This is the top priority before Polygon can be considered proven. |
| Compound | Live-checked, zero | Direct `balanceOf` on the Comet USDC market returned zero — consistent (no reason to doubt this one, unlike the log-scan result, since it's a direct point read not a derived scan). |
| Aave historical reads | Not yet tested | Live position confirmed real (see above); historical trend across dates not yet attempted — blocked on resolving the token-discovery scan issue first, since token collateral history needs the same `balanceOf`-at-block technique. |

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
