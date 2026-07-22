# Multichain Historical Reconstruction — Testing Notes

This document tracks pre-implementation validation for extending this API's historical
reconstruction feature (currently SUI-only — see the SUI section of `AGENTS.md`) to EVM chains:
native balances, ERC-20 token holdings, and Compound/Aave positions, for **both** current-state
tracking and arbitrary-past-date reconstruction.

**No implementation has started.** Every technique below was proven against a real wallet with real,
multi-chain, multi-year history before any code gets written, following the discipline that made the
SUI reconstruction reliable. Several "obvious" first approaches turned out wrong or incomplete —
those mistakes are recorded here, with how they were caught, so they aren't repeated.

Test wallet used throughout: `0x0ed2aDcC25ab3576928C1b4F47bAC3e8F30AfEDe` — a real MetaMask EOA (not
a contract; verified via `eth_getCode` returning `0x` on every chain tested) with real multi-year,
multi-chain activity.

Two distinct goals, requiring different amounts of work — **keep these separate**:
- **Current-state tracking** ("what does this wallet hold right now") — needs only direct
  point-in-time reads (`eth_call`/`eth_getBalance` at `"latest"`). No log scanning required.
- **Historical reconstruction** ("what did this wallet hold on some past date") — needs log-based
  discovery (`eth_getLogs`) to find *which* tokens/positions ever existed, plus point-in-time reads
  at the resolved historical block to get the actual values.

---

## ⚠️ THE SINGLE MOST IMPORTANT FINDING — provider block-range caps are not what they claim, and vary by era

Every free RPC provider tested advertises (via its own error messages) a maximum `eth_getLogs`
block-range per call — e.g. `drpc.org` claims 10,000 blocks. **These claims cannot be trusted.**
Through direct bisection (see reproduction steps below), the real, enforced caps were found to be:

| Provider | Chain | Advertised cap | Real cap (bisected) |
|---|---|---|---|
| `drpc.org` | Ethereum, Polygon | "10,000" | **101 blocks**, confirmed via two independent bisections converging on the identical number |
| `publicnode.com` | Ethereum | "50,000" (for logs) | Untested at scale — blocked by a separate archive-token requirement for old blocks regardless of size (see below) |
| NodeReal (free signup) | Ethereum, BSC | 50,000 | **Confirmed genuinely reliable** at 49,999 blocks, re-tested repeatedly with no degradation |
| Ankr (free signup) | Polygon | 50,000 (or similar) | **Varies by era**: ~50,000 works fine near recent blocks, but only **101 blocks** near Polygon's 2020-2021 genesis era |

**The Ankr/Polygon result is the most important nuance**: the real cap is not even a fixed number
*per provider* — it appears to depend on **log density in the queried range**, not block count. Early
Polygon (2020-2021) had extremely low gas fees and correspondingly enormous transaction/log volume
per block; a provider enforcing a "max logs scanned" limit internally would produce exactly this
symptom (tiny effective block-range cap in busy eras, large cap in quiet eras) even though the
*error message* always blames "block range."

**Practical consequence for the real implementation:** never use a fixed `STEP` constant for a
genesis-to-present log scan. Use **adaptive step sizing**: start large, halve on any error (retry the
same starting block, don't skip forward), and grow back up geometrically on success. A working
reference implementation of this pattern is in the reproduction steps for Polygon below.

**A second, compounding bug made this worse and harder to detect**: every scan script used earlier in
this project's testing computed `to = from + 9999`, which is a **10,000-block inclusive range**
(off-by-one — `from` through `from+9999` is 10,000 numbers), not the intended 9,999. Combined with
scripts that only checked for the presence of `"blockNumber"` in a response (never explicitly
checking for a JSON `"error"` key), a failing chunk was **indistinguishable from a genuinely empty
one** — every early scan could report "coverage confirmed, zero results" while silently failing on
most or all of its chunks. **This was caught for certain** when two real, user-supplied transaction
hashes (a Polygon Aave Borrow and Supply, both fully valid and expected) turned out to fall inside a
range a prior scan had already claimed to fully cover with zero results.

**Any test result in this document not explicitly marked as re-verified below assumes the fixed
method (real per-chunk error checking, correct chunk sizing) and should be treated with appropriate
caution if that's not stated.**

---

## Reproducible test methodology — how to (re-)run everything

This section is written so any of these tests can be re-run from scratch, on this wallet or a
different one, by anyone (including a future Claude session) without re-deriving the approach.

### 0. Tools needed

- A shell with `curl` and `node` available (Git Bash on Windows works; `node` is used for exact
  BigInt-safe hex/decimal conversion — **do not** use plain bash arithmetic for values that might
  exceed ~9.2 × 10^18, e.g. token amounts in wei/18-decimals, since bash integers silently overflow).
- Free RPC endpoints. As of this testing: `https://eth.drpc.org` (Ethereum, keyless), `https://polygon.drpc.org`
  (Polygon, keyless — but see the cap caveats above), a NodeReal free-signup key (covers Ethereum and
  BSC: `https://{eth,bsc}-mainnet.nodereal.io/v1/<key>`), and an Ankr free-signup key for Polygon
  (`https://rpc.ankr.com/polygon/<key>` or similar, from the Ankr dashboard).
- No paid tier of anything was used or should be needed for this feature, per project constraint.

### 1. Finding a wallet's true genesis on a chain (both native and token)

**Do not assume native-currency genesis equals the wallet's true first activity.** On this test
wallet, TR3 (a token) arrived on Ethereum ~3 months *before* the wallet's first ETH; Polygon's real
DeFi activity is entirely gas-sponsored-independent of when native POL arrived. Always check both
independently, and check *before* any single found "genesis," not just forward from it.

**Native currency genesis** — balance is not monotonic, so don't bisect on it blindly; do a coarse
linear scan first to find the rough window, then bisect within it, then corroborate with the actual
funding transaction:

```bash
ADDR="0xYOUR_ADDRESS"
RPC="https://eth.drpc.org"   # or polygon.drpc.org, etc.

get_balance() {
  curl -s -X POST "$RPC" -H "Content-Type: application/json" \
    --data "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"eth_getBalance\",\"params\":[\"$ADDR\",\"0x$(printf '%x' $1)\"]}" \
    | grep -o '"result":"[^"]*"' | cut -d'"' -f4
}

# coarse pass: find rough window (adjust step/range to the chain's total block count)
step=500000
block=0
current=$(curl -s -X POST "$RPC" -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","id":1,"method":"eth_blockNumber","params":[]}' | grep -o '"result":"[^"]*"' | cut -d'"' -f4)
current=$((current))
while (( block <= current )); do
  bal=$(get_balance $block)
  if [[ "$bal" != "0x0" ]]; then
    echo "first nonzero balance found at block $block: $bal"
    break
  fi
  echo "block $block: 0"
  block=$((block + step))
done
```

Then bisect within the found window (`low` = last known-zero, `high` = first known-nonzero), and
finally fetch that block with full transaction details (`eth_getBlockByNumber` with `true`) to find
the actual funding transaction — confirm its `value` matches the balance exactly (proves a single,
clean first deposit, not something messier).

**Token genesis / discovery** — see section 3 below (it's the same mechanism, just also used for
discovery, not only genesis-finding).

### 2. Date → block resolution

Needed because reconstruction requests come in as a date, not a block number. Binary search on
`eth_getBlockByNumber` timestamps:

```bash
get_block_for_date() {
  local target_date=$1   # e.g. "2023-06-15"
  local TARGET=$(date -u -d "${target_date}T00:00:00Z" +%s)
  local low=0             # any block known to be before the target date
  local high=$(curl -s -X POST "$RPC" -H "Content-Type: application/json" \
    --data '{"jsonrpc":"2.0","id":1,"method":"eth_blockNumber","params":[]}' | grep -o '"result":"[^"]*"' | cut -d'"' -f4)
  high=$((high))
  while (( high - low > 1 )); do
    local mid=$(( (low + high) / 2 ))
    local ts_hex=$(curl -s -X POST "$RPC" -H "Content-Type: application/json" \
      --data "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"eth_getBlockByNumber\",\"params\":[\"0x$(printf '%x' $mid)\",false]}" \
      | grep -o '"timestamp":"[^"]*"' | cut -d'"' -f4)
    local ts=$((ts_hex))
    if (( ts < TARGET )); then low=$mid; else high=$mid; fi
  done
  echo $low   # resolves to "last block before the requested UTC day" — semantically correct
}
```

Verified correct by cross-checking the resolved block's human-readable date against a block
explorer's page (glancing at explorer *pages* is fine; depending on explorer *APIs* programmatically
is what this project deliberately avoids, per the lessons in the SUI section of `AGENTS.md`).

### 3. Token discovery (which ERC-20s has this wallet ever touched) — THE CORRECT, ADAPTIVE VERSION

This supersedes any fixed-`STEP` version. Use this pattern for any from-genesis log scan:

```bash
RPC="https://eth-mainnet.nodereal.io/v1/YOUR_KEY"   # use a provider+chain combo already confirmed reliable, see table above
ADDR="0xYOUR_ADDRESS"
ADDR_TOPIC="0x000000000000000000000000${ADDR:2}"
ADDR_TOPIC=$(echo "$ADDR_TOPIC" | tr 'A-Z' 'a-z')
SIG="0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef"   # Transfer(address,address,uint256) — verify independently via js-sha3 if reusing on a new signature, see section 6

END=$(curl -s -X POST "$RPC" -H "Content-Type: application/json" \
  --data '{"jsonrpc":"2.0","id":1,"method":"eth_blockNumber","params":[]}' | grep -o '"result":"[^"]*"' | cut -d'"' -f4)
END=$((END))

from=0
step=40000       # optimistic starting point; will self-correct
min_step=1
chunks=0
blocks_covered=0

while (( from <= END )); do
  to=$((from + step - 1))
  if (( to > END )); then to=$END; fi

  t_in=$(curl -s -X POST "$RPC" -H "Content-Type: application/json" \
    --data "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"eth_getLogs\",\"params\":[{\"fromBlock\":\"0x$(printf '%x' $from)\",\"toBlock\":\"0x$(printf '%x' $to)\",\"topics\":[\"$SIG\",null,\"$ADDR_TOPIC\"]}]}")
  t_out=$(curl -s -X POST "$RPC" -H "Content-Type: application/json" \
    --data "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"eth_getLogs\",\"params\":[{\"fromBlock\":\"0x$(printf '%x' $from)\",\"toBlock\":\"0x$(printf '%x' $to)\",\"topics\":[\"$SIG\",\"$ADDR_TOPIC\",null]}]}")

  if [[ "$t_in" == *'"error"'* ]] || [[ "$t_out" == *'"error"'* ]]; then
    if (( step <= min_step )); then
      echo "!!! CANNOT SHRINK FURTHER at block $from — real problem, stopping"
      break
    fi
    step=$(( step / 2 ))
    if (( step < min_step )); then step=$min_step; fi
    continue   # retry same 'from', do NOT advance — this is what guarantees no silent gaps
  fi

  if [[ "$t_in" == *'"blockNumber"'* ]]; then echo "FOUND incoming at $from-$to:"; echo "$t_in"; fi
  if [[ "$t_out" == *'"blockNumber"'* ]]; then echo "FOUND outgoing at $from-$to:"; echo "$t_out"; fi

  blocks_covered=$((blocks_covered + (to - from + 1)))
  chunks=$((chunks + 1))
  from=$((to + 1))

  step=$(( step * 3 / 2 ))   # grow back up on success
  if (( step > 40000 )); then step=40000; fi
done

echo "=== DONE: $chunks chunks, $blocks_covered / $((END+1)) blocks covered ==="
if (( blocks_covered != END+1 )); then echo "!!! COVERAGE INCOMPLETE, DO NOT TRUST !!!"; fi
```

**Critical property of this script, and why it's correct where earlier ones weren't:** on any error,
it retries the *exact same* `from` with a smaller step rather than advancing — so it is architecturally
impossible for it to silently skip a block range, regardless of how the true cap varies. The tradeoff
is speed: in a dense-log era (e.g. early Polygon), this can be *very* slow (empirically: ~74 blocks
covered per chunk in Polygon's 2020-2021 era, meaning a full historical scan of Polygon's ~90M blocks
was estimated at **~20 days** at the observed rate — genuinely impractical to run exhaustively via
this manual/chat-driven process; see "What's left to test" below for how this was handled instead).

**A second, distinct failure mode exists on top of the plain block-range cap**: some providers (seen
on `drpc.org`) return a *different* error — a request timeout (e.g. `"Request timeout on the free
tier..."`) — when a query's *result volume* is too large for the range requested, even if the range
itself is under the nominal cap. This is a density problem, not a range-size problem, and it needs
the same fix (shrink and retry) but must be triggered by a different error-message match than a
rate-limit ("too many requests") error, which instead needs backoff-and-retry-same-size, not
shrinking. A production implementation should handle both: match on the specific error text to
decide "shrink the range" vs. "wait and retry the same range" rather than treating every error
identically.

**A third variant of the rate-limit error message was found on Base**, beyond the two already
documented above: `"You reached Public endpoint rate limit, please upgrade to paid plan"` — same
underlying cause (backoff-and-retry-same-size, not shrink), just yet another different wording. Any
production retry logic should match broadly (e.g. on the substring `"rate limit"` case-insensitively)
rather than an exact phrase, since providers evidently don't use a single consistent message even for
the same underlying condition.

### 4. Verifying token-balance correctness — NEVER trust summed Transfer deltas

Once tokens are discovered via logs, **do not** compute their historical balance by summing Transfer
amounts. Proven false on this wallet: NAFTY (BSC) increased in balance by ~3.84% with **zero**
corresponding Transfer events anywhere in its full history (a rebasing/reflection-style token) —
confirmed via a from-scratch coverage-checked scan finding exactly one Transfer, while `balanceOf`
shows a materially larger number. TR3 (Ethereum), by contrast, matches its Transfer history exactly.

**The only reliable method: direct `balanceOf(wallet)` at the resolved historical block**, for every
discovered token, at every requested date. Logs are for *discovery* only.

```bash
WALLET_ENC=$(printf '%064s' "$(echo "${ADDR:2}" | tr 'A-Z' 'a-z')" | tr ' ' '0')
curl -s -X POST "$RPC" -H "Content-Type: application/json" \
  --data "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"eth_call\",\"params\":[{\"to\":\"$TOKEN_CONTRACT\",\"data\":\"0x70a08231$WALLET_ENC\"},\"$BLOCK_HEX_OR_LATEST\"]}"
```

### 5. Compound positions (current or historical)

Reuses this project's existing `CompoundHoldingsClient` logic, just with a block parameter added for
historical reads. **Check every asset separately** — base-asset `balanceOf`/`borrowBalanceOf` is not
enough; collateral in *other* assets needs `userCollateral(wallet, assetAddress)`:

```bash
COMET="0x..."   # from CompoundHoldingsClient::MARKETS for the chain in question
WALLET_ENC="..."   # 32-byte padded wallet address, lowercase, no 0x prefix needed inside this string
ASSET_ENC="..."    # 32-byte padded asset (e.g. WBTC) address, same format

# base asset supplied
curl ... --data "{...,\"data\":\"0x70a08231$WALLET_ENC\"...}"
# base asset borrowed
curl ... --data "{...,\"data\":\"0x374c49b4$WALLET_ENC\"...}"
# collateral in a specific other asset
curl ... --data "{...,\"data\":\"0x2b92a07d$WALLET_ENC$ASSET_ENC\"...}"
```

**Lesson learned the hard way**: a real WBTC-as-collateral Compound position on this wallet was
initially misattributed as an Aave transaction from a casual glance — always confirm the `to` address
of a transaction against known contract addresses before assuming which protocol it belongs to.

### 5b. Multi-market Compound support — what's tested, what isn't

Compound III has multiple isolated markets per chain (one per base asset), not one. The response
schema was changed from a single `compound` object per chain to a list — `defi.compound` is now an
array, one entry per market the wallet has a non-zero position in, matching how `defi.aave.reserves`
already only lists non-zero reserves. The discovery mechanism itself (`numAssets()` +
`getAssetInfo()` loop, section 5 above) needed **zero changes** to support this — it was already
fully generic per-market; only the config (`CompoundHoldingsClient::MARKETS`, now a list of markets
per chain instead of one) and the response assembly (list instead of single object) changed.

**Markets added, and their verification status:**

| Chain | Market (base asset) | Comet address | Verification |
|---|---|---|---|
| Base | USDC (pre-existing) | `0xb125E6687d4313864e53df431d5425969c15Eb2F` | Live in production before this change; not independently re-verified as part of this work. |
| Base | USDS | `0x2c776041CCFe903071AF44aa147368a9c8EEA518` | ✅ **Fully tested** against a real wallet position: 402.608089649583865983 sUSDS collateral, 40.566139307172975914 USDS borrowed — matches the account owner's own description exactly. |
| Base | USDbC | `0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf` | ⚠️ **Not tested against a real position.** Address confirmed correct via Compound's own official governance-proposal market list (not a guess), and the discovery mechanism has been proven correct twice already on structurally different real markets (2 vs. 5 collateral assets, different decimals) — but this specific market's numbers have never been independently produced and checked against ground truth. A search for a real BaseScan holder of this market's position to test against did not turn up a usable address (explorer holder lists aren't accessible via the tools available here, consistent with this project's general avoidance of depending on explorer APIs). **If a real position in this market is ever available to test against, do that before fully trusting this row.** |
| Polygon | USDC (pre-existing) | `0xF25212E676D1F7F89Cd72fFEe66158f541246445` | Live in production before this change; not independently re-verified as part of this work. |
| Polygon | USDT0 | `0xaeB318360f27748Acb200CE616E389A6C9409a07` | ✅ **Fully tested** against a real wallet position: 0.2 WETH + 0.00725985 WBTC collateral, 344.508106 USDT0 borrowed — matches the account owner's own description ("various cryptos... borrow USDT0") exactly. |

**A real, confirmed-legitimate address coincidence worth knowing about**: `0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf` (Base's USDbC market) is the *same address* as Arbitrum's native USDC market, already in this project's pre-existing config. Verified via two independent sources (real Arbiscan transaction logs showing genuine Supply/Withdraw activity, and Compound's own governance-proposal JSON listing the exact per-chain mapping) that these are two real, unrelated, legitimate deployments that happen to share an address across chains — not a bug, not a copy-paste error, but a genuine trap for assuming "same address = same market" when cross-referencing chains.

**Not investigated as part of this work, noticed only in passing**: Compound's official market list (surfaced while researching the above) shows Arbitrum also has an untracked WETH market (`0x6f7D514bbD4aFf3BcD1140B7344b32f063dEe486`) and Ethereum/Optimism weren't re-checked for additional markets beyond their existing single USDC entries. Worth a pass later, out of scope for this round since it wasn't asked for.



### 6. Aave positions (current or historical) — and its own pitfalls

- **Live/current reads**: `getUserAccountData(wallet)` on the chain's Aave V3 Pool address, selector
  `0xbf92857c`. Returns 6 words: `totalCollateralUsd`, `totalDebtUsd`, `availableBorrowsUsd` (all
  8-decimal USD), `currentLiquidationThreshold`/`ltv` (bps), `healthFactor` (18-decimal, or all-`f`s
  = infinite/no debt).
- **Historical convenience reads** (`getUserReserveData` on the `AaveProtocolDataProvider`, selector
  `0x28dd2d01`) are **not safe for arbitrary historical dates, on any chain** — this was first found
  on Ethereum (DataProvider redeployed at block 22,686,778, June 5 2025 — confirmed via `eth_getCode`
  returning `0x` before that block, real bytecode after) but **directly confirmed to be a
  coordinated, cross-chain event**, not Ethereum-specific: Polygon's and Base's DataProviders both
  redeployed June 10, 2025, and BSC's redeployed June 11, 2025 — all four chains within a single
  week. Any pre-redeployment historical read via this convenience method will silently fail (empty
  `eth_call` result, not an error) **on every chain**, not just Ethereum. **Use direct `balanceOf` on
  the aToken/debt-token contracts instead** for historical Aave reads — proven to work uninterrupted
  across this boundary on every chain checked so far, and should be treated as the default method,
  with the convenience method as an optimization only valid after each chain's specific
  redeployment date (all currently known to fall in the June 5-11, 2025 window, but worth
  re-verifying per-chain rather than assuming that exact week applies universally forever).
- **Verify event signatures independently, never purely from memory** — even signatures that "should"
  be right have been wrong before in this exact codebase's testing (see the wrong `aEthWETH` address
  guess below). To compute/verify a signature:
  ```bash
  node -e "const { keccak256 } = require('js-sha3'); console.log('0x' + keccak256('Supply(address,address,address,uint256,uint16)'));"
  ```
  (requires `npm install js-sha3` first). Cross-check against a block explorer's own decoded event log
  for the same contract when in doubt — that's ground truth, since it comes from the contract's
  verified ABI, not from anyone's memory.
- **A wrong contract address often returns `0x` (empty), not a clear error.** Always sanity-check a
  newly-used address with `eth_getCode` (should return real bytecode, not `0x`) before trusting any
  result — caught once when a guessed `aEthWETH` address turned out not to be a deployed contract at
  all, at either the genesis block or `latest`.

### 7. Cross-checking a "zero result" against reality

Never fully trust a scan that reports "zero found, full coverage" without an independent sanity
check, especially before treating it as evidence of anything. The cheapest, most reliable check: ask
whoever owns the wallet for a real transaction hash they remember, and decode its receipt directly:

```bash
curl -s -X POST "$RPC" -H "Content-Type: application/json" \
  --data "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"eth_getTransactionReceipt\",\"params\":[\"$TX_HASH\"]}"
```

This is exactly what broke open both the NAFTY genesis discovery and the Polygon Aave "mystery"
(which turned out to be a scanning bug, not a real anomaly) — a known-real transaction is strictly
more informative than any amount of further blind scanning.

---

## Chain-by-chain status

### Ethereum — closed, fully re-verified

| Item | Status |
|---|---|
| Free RPC | ✅ `drpc.org` keyless for point-reads/small ranges; **NodeReal (free signup) for any log scanning**, confirmed reliable at 49,999-block chunks with zero failures across two large re-verification runs (see below). `publicnode.com` is keyless but refuses log queries on old blocks with an archive-token error, regardless of range size — not usable for this without a key. |
| Native genesis | ✅ Block 13,024,431 (Aug 14 2021, 16:43:39 UTC), tx-confirmed: single incoming deposit of ≈0.444 ETH, exact value match to the balance at that block. |
| Token discovery | ✅✅ **Re-verified twice** with the correct adaptive/error-checked method (NodeReal, 49,999-block chunks): 210 chunks (native genesis → first outgoing tx) and 261 chunks (block 0 → native genesis), **zero failed chunks in either run**. Found exactly one token, TR3, genesis block 12,420,246 — which predates native ETH funding by ~3 months. Nothing else, confirmed with real, trustworthy coverage. |
| Token balance correctness | ✅ TR3's `balanceOf` exactly matches its single Transfer-in amount (well-behaved, non-rebasing token). |
| Date→block resolution | ✅ Verified against a real block explorer date. |
| Compound historical reads | ✅ Real position (Comet USDC market, WETH collateral): live value matched an independently-documented figure elsewhere in this codebase exactly; historical trend across 8 sampled dates (Nov 2025 – June 2026) is coherent (position opened, grew, wound down to dust) and matches the account owner's own memory. |
| Aave historical reads | ✅ (bounded) Real position (WETH collateral, USDC variable debt) confirmed via direct `balanceOf` on the debt/collateral token contracts across 7 dates, coherent trend. **Only reliable from block 22,686,778 (June 5 2025) onward** if using the DataProvider convenience method — see section 6. Pre-2025 Aave history is out of scope for this wallet (confirmed with the account owner) and wasn't pursued further. |
| Throttling | ✅ 200 sequential calls to `drpc.org`, 200/200 succeeded. |

### BNB Smart Chain — closed, fully re-verified

| Item | Status |
|---|---|
| Free RPC | ✅ **NodeReal (free signup)** — no working *keyless* option exists (tried and rejected: `drpc.org` rate-limited, `publicnode` archive-gated, official `bsc-dataseed` genuinely prunes old state (`"missing trie node"`), `1rpc.io`, `ankr` (needs key), `meowrpc`, `llamarpc`, `blockpi` down/unreachable). NodeReal confirmed reliable for archive reads and `eth_getLogs` at 49,999-block chunks. |
| Native genesis | Not directly pinned down (wallet's BSC activity investigated was token-focused; native genesis not yet bisected — low priority, doesn't block anything currently understood). |
| Token discovery | ✅✅ **Re-verified** with the correct method: 2,010 chunks across the full genesis-to-latest range, **zero failed chunks**. Found exactly one Transfer — NAFTY's genesis, tx-confirmed (block, timestamp, amount, and swap-router transaction hash all cross-checked). Nothing else. |
| Token balance correctness | ⚠️ **Key finding, now on solid footing**: NAFTY's current `balanceOf` (29,451.567...) does not match its Transfer history (28,363.005... from the one discovered transfer) — a ~3.84% unexplained increase, zero matching Transfer events, confirmed with full trustworthy coverage. Treat as a rebasing/reflection-style token; this is the finding behind "never trust summed deltas" in section 4. |
| Aave | ✅ **Fixed** — Aave V3 *is* deployed on BSC (Pool: `0x6807dc923806fE8Fd134338EABCA509979a7e0cB`, DataProvider: `0xc90Df74A7c16245c5F5C5870327Ceb38Fe5d5328`, both from the canonical `aave-address-book` registry). This project's `AaveHoldingsClient::POOLS` previously had no BSC entry, meaning `/holdings-now` was under-reporting this wallet's BSC Aave position — **now added**, along with a `DEFAULT_RPC_URLS` entry for BSC (`https://bsc-rpc.publicnode.com`, confirmed working for `"latest"`-only reads, which is all `/holdings-now` needs). The full mechanism (`getAllReservesTokens` → per-reserve `getUserReserveData` → `getUserAccountData` summary) was tested end-to-end against the real wallet, producing a correct, schema-matching result cross-checked against Zerion's own raw token amounts in the cache (`aBnbWBNB`/`variableDebtBnbUSDC`), which agreed within the expected interest-accrual drift. |
| Compound | ✅ N/A — Compound III is not deployed on BSC (confirmed via search of official docs). |

### Polygon — current-state tracking fully verified; historical reconstruction partially open

**Current holdings, right now, on this wallet — fully verified, no scanning needed:**

| Asset | Amount |
|---|---|
| Native POL | 75.215159839453963114 |
| Compound — USDC market: WBTC collateral | 0.00048543 WBTC |
| Compound — USDC market: USDC base | 0 supplied, 0 borrowed |
| Compound — USDT0 market: collateral | 0.2 WETH, 0.00725985 WBTC |
| Compound — USDT0 market: USDT0 base | 0 supplied, 344.508106 borrowed |
| Aave collateral | $225.61 |
| Aave debt | $112.67 |
| Aave health factor | 1.56 |

All values above are direct point reads (`eth_getBalance`/`eth_call` at `"latest"`), each
independently verified — no log scanning involved, none of the caveats below apply to current-state
tracking. The two Compound markets are genuinely separate, isolated positions (see section 5b) —
Polygon's `defi.compound` is now a 2-entry array in the actual API response, not a single object.

**Historical reconstruction — status:**

| Item | Status |
|---|---|
| Free RPC | ✅ `drpc.org` keyless for point-reads (archive confirmed working via a real wallet-specific balance change at an old block). For log scanning: `drpc.org`'s real cap is **101 blocks**, confirmed via bisection (not the "10,000" advertised) — impractical for exhaustive scanning. **Ankr (free signup)** was tested as an alternative: reliable at ~50,000 blocks near recent history, but **also drops to a ~101-block real cap near Polygon's 2020-2021 genesis era** — this is the finding described in the top section (cap appears to depend on log density, not a fixed number). |
| Native genesis | ✅ Block 80,406,107 (Dec 16 2025, 22:18:19 UTC), tx-confirmed: 41.13 POL, single incoming deposit — notably only ~10 minutes before this wallet's first-ever outgoing Polygon transaction. |
| Aave live position | ✅ Confirmed real via direct `getUserAccountData` read — see current-holdings table above. |
| Aave/Compound historical events (Supply, Borrow) | ✅ Confirmed real and locatable **once the correct chunk size is used** — two independently-supplied real transaction hashes (a Borrow and a Supply) were successfully decoded and matched exactly the expected event signature (independently re-verified against Aave's real GitHub source and a block explorer's own decoded log, not just memory), exact Pool address, exact wallet address. The original "zero results" scan for this exact range was wrong due to the chunking/error-detection bug described at the top of this document — not a real anomaly. |
| Token/event discovery, genesis (block 0) → native genesis (80,406,107) | 🔴 **Not completed** — this is Polygon's 2020-2021 low-fee era, where the real safe chunk size is only ~101 blocks; the adaptive scan script (section 3) was run against this range and, after covering only ~3% of it over roughly 15 hours, was projected to need **~20 days** to finish exhaustively. **Stopped as impractical for this process.** See "What's left to test" below for the recommended approach. |
| Token/event discovery, native genesis (80,406,107) → current | 🔴 **Not yet re-run with a provider that holds up at scale in this range** — this range is NOT part of the dense low-fee era (it's Dec 2025–present), so it should behave like Ethereum/BSC's successful re-verifications once pointed at a reliable provider/chunk-size combination for this specific range. This is a cheap, high-value re-run to do next (see below). |
| Compound on Polygon | ✅ Closed — see current-holdings table; the "WBTC supply" transaction that originally seemed to be Aave was actually Compound (see section 5's lesson). |

### Base — Compound multi-market tested; native genesis found; rest in progress

**RPC**: `drpc.org`, keyless. Archive confirmed working (verified against this wallet's own real
balance change between an old block and `latest`, not just a zero result). **`eth_getLogs` real cap
bisected at ~10,000 blocks** — genuinely close to the advertised limit here, unlike Ethereum/Polygon's
`drpc` endpoints where the same advertised "10,000" turned out to really be ~101. Caps are evidently
per-chain (and, per the Polygon finding, per-era) even on the same provider — never assume a cap
carries over from one chain to another, always re-bisect. `publicnode` is keyless but gates archive
reads behind a personal token (same pattern as Ethereum/BSC); not used since `drpc` already works.
NodeReal's Base hostname guess didn't resolve — not pursued since `drpc` was sufficient.

**Native ETH genesis**: block 35,321,858 (Sept 9, 2025, 15:51:03 UTC), tx-confirmed — single clean
incoming deposit of 0.00359307 ETH, exact match to the balance at that block. Notable: the wallet's
balance fluctuated through several different nonzero values *during* the bisection search itself
(narrowing window kept finding different real amounts, not just 0-vs-nonzero) — meaning the wallet
was in a burst of activity right around its own genesis, same "bridge funds in, immediately start
using them" pattern seen on Polygon and Ethereum. This doesn't break the bisection (it still
correctly converges on the first zero→nonzero transition regardless of what specific nonzero values
appear along the way), but is worth knowing if the raw bisection trace looks confusing on a future
read-through.

**Nonce at time of testing**: 1,084 — a genuinely active wallet on Base, more so than any other
chain tested so far.

**Token discovery**: full-history scan (block 0 → current, ~48.8M blocks) completed, using the
adaptive shrink-on-timeout + retry-on-rate-limit script (section 3, extended with a second failure
mode — see the new note below section 3). **Found 419 unique token contracts** this wallet has ever
been sent or has sent on Base — consistent with the many airdropped/spam tokens already visible in
the live `/holdings-now` cache (`$HALLOWEEN`, `DEGEN`, `GOKU`, etc.). Zero unrecoverable errors.

One honest gap versus earlier chains' scans: this run's output only logged chunks where something
was *found* — empty-but-successful chunks left no trace, so there's no explicit final "blocks
covered vs. expected" arithmetic to point to the way Ethereum/BSC's re-verified scans have. Coverage
completeness here rests on the adaptive script's *design* (it only ever advances past a block range
after that range's calls succeed, so a gap is structurally impossible by construction) rather than
an explicit printed self-check. That's a real, defensible guarantee, but a weaker form of evidence
than the explicit arithmetic checks used elsewhere in this document — worth knowing if this ever
needs re-litigating.

**New provider failure mode discovered on this chain's `drpc` endpoint, on top of the earlier
rate-limit case**: `"Request timeout on the free tier, please upgrade..."` (distinct error code from
the rate-limit message) — appears tied to log density in a given range, same underlying cause as
Polygon's tiny-cap-in-dense-eras finding, just manifesting as a timeout instead of a hard range-size
rejection here. The fix is the same: shrink the range and retry, don't just back off and retry the
same size. Any future scan script should handle both failure modes (rate-limit → backoff-and-retry
same size; timeout/density → shrink range and retry) rather than just one.

**Aave DataProvider redeployment**: confirmed present on Base too, deployed June 10, 2025 — see
section 6 above for the full cross-chain finding (all four chains tested redeployed within the same
week).

**Aave historical reads (`balanceOf`-on-aToken/debt-token method)**: ✅ Fully verified. Real aToken
(`aEthWETH`, `0xd4a0e0b9149bcee3c920d2e00b5de09138fd8bb7`) and debt-token
(`variableDebtBasUSDC`, `0x59dca05b6c26dbd64b5381374aaac5cd05644c28`) addresses independently
cross-validated against the account owner's real live cache export (exact address match, not just a
plausible guess). Historical trend sampled across 6 dates (Oct 2025 - Jul 2026) shows a coherent,
real story — WETH collateral fully withdrawn then rebuilt, USDC debt cycling through a full
borrow→repay→re-borrow pattern — converging naturally to the live cache's current values with no
gaps or implausible jumps. Confirms the same method proven on Ethereum generalizes correctly to Base.

**Not yet done on Base**: Compound USDbC market's historical trend (no real position on this wallet
to test against, same caveat as its current-state verification — see section 5b), and a repeat of
the "does drpc's real cap match the advertised one" bisection specifically for the dense/early
portion of Base's history (not yet found to be a problem here the way it was on Polygon, but also
not explicitly ruled out with the same rigor).

**Compound USDS market historical reads**: ✅ Fully verified. Market deployment block found (Jan 20,
2025, well before this wallet's Base presence began), confirming "not yet deployed" would correctly
read as no-position rather than an error for any date before that. Historical trend across 5 dates
(Oct 2025 - Jun 2026) shows a clean, textbook pattern: no position through Feb 2026, then the
position opens with exactly 402.608089649583865983 sUSDS collateral — which **never changes** across
every subsequent date sampled, including the live cache's current value — while the USDS borrow
smoothly accrues interest with each successive read (40.20 → 40.52 → 40.57), matching a single
deposit-then-hold pattern with no further deposits/withdrawals. This is about as clean a confirmation
as this testing effort has produced anywhere.

**Base testing is now substantially complete**: RPC, native genesis, token discovery (full history,
419 tokens), Aave (config, DataProvider redeployment check, historical trend), and one of two new
Compound markets (USDS) are all independently verified. The only real gaps left are the USDbC
market's historical trend (blocked on finding a real test wallet, same as its current-state
verification) and Base's overall token-balance correctness spot-check (TR3/NAFTY-style — hasn't been
explicitly redone for any specific Base token yet, though the underlying mechanism is proven
generically at this point across four chains).

Confirmed with the account owner: no meaningful holdings on Avalanche for the test wallet.
Deprioritized; reuse the same proven method later if ever needed, without dedicated testing.

---

## Target implementation architecture (for whoever builds this once testing is complete)

This section captures the actual build plan, decided early in this project's design discussion,
that the rest of this document's testing has been validating piece by piece. **Nothing below has
been built yet** — this is the blueprint, written down so it survives independently of any one
conversation's history.

### High-level approach

Mirror the existing SUI reconstruction's *shape*, not its exact mechanics — EVM chains don't need a
separate Node-based GitHub Action the way SUI's NAVI SDK dependency required, since everything here
is plain JSON-RPC (`eth_call`/`eth_getLogs`/`eth_getBalance`), which `EvmRpcClient` (PHP) already
speaks. **Explicit decision: stay in-process PHP, no GitHub Action**, unless testing surfaces a real
need for a non-PHP dependency (none has, so far).

### New PHP components needed

- **A log-scanning utility** (e.g. `EvmLogScanner`) implementing the adaptive chunking algorithm from
  section 3 as a single, shared, reusable piece — not reimplemented ad hoc per chain or per call site.
  Must support both failure modes found during testing: rate-limit errors (backoff, retry same range)
  and density/timeout errors (shrink range, retry same start block). This is the single most
  load-bearing piece of new code, since nearly every bug found during testing was a flaw in this
  exact logic.
- **A date→block resolver**, per chain, using the bisection approach from section 2. Cache the result
  **per (chain, date)**, not per (wallet, date) — the answer is wallet-independent, so this is a huge,
  free optimization once more than one wallet uses the feature (every wallet asking about the same
  chain/date shares one resolved block instead of re-bisecting).
- **Token discovery**, per (wallet, chain): run `EvmLogScanner` for `Transfer` events touching the
  wallet, from the chain's genesis (or a resumed cursor position) to the target block. Output is a
  *candidate list of token contract addresses* only — never a running balance ledger (see the next
  point for why).
- **Token balance reads**: for every discovered token, at the resolved historical block, a direct
  `balanceOf(wallet)` call. **This is a hard requirement, not an optimization choice** — section 4
  proved that summing Transfer deltas silently produces wrong answers for rebasing/reflection tokens
  (real example: NAFTY on BSC). Never implement a delta-summing shortcut, even as a "fast path."
- **Compound/Aave historical reads**: reuse the *existing* `CompoundHoldingsClient`/`AaveHoldingsClient`
  logic almost as-is — both already do the right kind of point-in-time `eth_call`, they just need a
  block-number parameter threaded through instead of a hardcoded `"latest"`. For Aave specifically,
  default to the direct `balanceOf`-on-aToken/debt-token method (section 6), and only use the
  `getUserReserveData` convenience method for dates after that chain's specific DataProvider
  redeployment date (all four chains tested so far redeployed within June 5-11, 2025 — see section 6
  and finding #10 in that section for the cross-chain confirmation).

### Database schema

- **Reuse `multichain_holdings_cache` as-is** for storing reconstructed snapshots (`source =
  'reconstructed'`) — it's already `as_of_date`/`source`-shaped, append-only, and built for exactly
  this; no schema change needed here.
- **New cursor table**, e.g. `multichain_holdings_reconstruction_cursor`, keyed by `(address, chain)`
  — one row per chain being scanned for a given wallet (not one row per wallet, since each chain's
  scan progress is independent). Store at minimum: last-scanned block, and the running list of
  discovered token contract addresses so far (so a resumed scan doesn't need to re-discover tokens
  already found in an earlier, incomplete run). Given this codebase's query builder doesn't support
  upsert or multi-`where()` (per the SUI section of `AGENTS.md`), follow the same append-only /
  "pick latest in PHP" pattern already used for `sui_holdings_reconstruction_cursor`.
- **Per-chain RPC config for reconstruction** needs to be distinct from `DEFAULT_RPC_URLS` (which is
  keyless-`publicnode`-style and only needs `"latest"` reads for `/holdings-now`). Reconstruction
  needs archive-capable, log-scanning-capable endpoints, which are chain-specific based on testing
  so far: `drpc.org` for Ethereum and Base (both confirmed reliable there), NodeReal (free signup)
  for Ethereum and BSC, Ankr (free signup) for Polygon. This mapping should live in its own config
  array, not overload `DEFAULT_RPC_URLS`.

### Endpoint

Reactivate `/holdings` (currently a deliberate cache-only dead end, per the README/AGENTS.md history
of the removed EVM reconstruction attempt) to trigger reconstruction on a cache miss, mirroring
`/sui-holdings`'s existing 400 (before genesis) / 500 (cache inconsistency) / 502 (run failed) error
shape, so the two multichain surfaces behave consistently to callers.

**Open design question, not yet resolved**: unlike SUI's GitHub-Action-based async model, an
in-process PHP reconstruction can't realistically block a single HTTP request for a scan that might
take hours (see Polygon's ~20-day worst-case estimate for its dense 2020-2021 era). This needs some
kind of background/queued execution model *within* PHP (e.g. a cron-triggered worker script polling
a job table) rather than a synchronous request-response cycle — this hasn't been designed yet and is
a real gap to close before implementation starts, not an afterthought.

---



1. **Polygon, native-genesis → current, log discovery** — re-run with the adaptive script (section 3)
   pointed at a provider confirmed reliable for *this* range specifically (Ankr held up fine near
   this era in earlier spot checks; confirm at full scale). This range is NOT the slow, dense-log era,
   so this should be fast and cheap, unlike the pre-genesis range. This is the single most
   valuable next test — it would close out real historical reconstruction for the period that
   actually matters for this wallet's real Polygon holdings.
2. **Polygon, block 0 → native genesis, log discovery** — the ~20-day-at-current-rate problem. Options,
   not yet decided:
   - Accept a **documented, disclosed limitation**: treat pre-native-genesis Polygon history as
     "not reconstructable in reasonable time on free infrastructure" and scope the feature
     accordingly (this wallet's real holdings likely don't need it — its Polygon native genesis is
     recent, and nothing found so far suggests years-earlier activity, unlike TR3 on Ethereum).
   - Or: **coarse sampling** instead of exhaustive coverage (e.g., every 500,000 blocks, adaptive
     window) — catches anything TR3/NAFTY-sized but explicitly is not full coverage, and must be
     labeled as such if used, not silently treated as equivalent to a real scan.
   - Or: pursue a fundamentally faster method for this specific era (e.g., a provider with a genuinely
     larger real cap even in dense-log periods — not yet found; every free option tried so far
     degrades to ~101 blocks near Polygon's genesis).
3. ~~Add a BSC entry to `AaveHoldingsClient`'s `POOLS` config~~ — **done**, see the BSC section above.
4. **Full test sequence for Base** — RPC survey, native genesis, token discovery, token correctness,
   Compound/Aave historical — mirroring what's done for the other three chains.
5. **Re-verify Ethereum's Aave/Compound historical trend checks** with the corrected methodology —
   these were direct point-reads (not chunked scans), so they're likely unaffected by the chunking bug,
   but haven't been explicitly re-confirmed post-discovery the way the log-scan-based findings were.
6. Only after the above: design the actual PHP implementation (cursor/reconstruction-state
   repository, endpoint wiring, caching, and **adaptive chunk sizing baked in as a first-class
   requirement**, not an afterthought), reusing the SUI reconstruction's architecture as the template.

No code for the multichain reconstruction feature should be written until this list is empty or its
remaining items are explicitly, consciously scoped out — per this project's "remove every shadow
before building" approach.
