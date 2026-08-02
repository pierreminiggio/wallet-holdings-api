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

## ⚠️ SECOND CRITICAL FINDING — silent data loss from empty/failed responses being treated as success

**Discovered via a real, independently-supplied transaction hash that proved a specific token
position existed, in a specific block, inside a range that an adaptive scan had already reported as
"fully covered, zero persistent errors."** This is the same class of problem as the first critical
finding above (silent gaps in scans that report success), but a different, additional root cause —
found *after* the off-by-one/error-detection fixes were already in place, meaning fixing the known
bugs was not sufficient on its own.

**The bug**: every retry-loop implementation used in this project's later testing (the adaptive
`scan_range` pattern used for Base's and Polygon's full-history token scans) checked for success by
verifying the response did **not** contain known rate-limit error text, then separately checked
whether it contained the substring `"error"` before deciding whether to shrink/retry. This has a
real gap: if the underlying `curl` call fails outright — a network blip, hitting the `--max-time`
timeout, or any condition that produces an **empty string** instead of a JSON response — that empty
string matches *neither* check. It doesn't contain rate-limit text (so the retry loop exits
immediately, no retry attempted), and it doesn't contain `"error"` (so the failure-handling branch
never triggers either). **The script silently treats a totally failed request as a successful,
empty result**, and advances past that block range forever, with no error logged and no result
recorded — a real chunk's real data, permanently lost, with zero trace anywhere in the output.

**Confirmed in practice**: a real transaction hash showed a genuine `Transfer` event (an Aave
variable-debt-token mint) at a specific Polygon block, well inside a range a prior scan had
"completed" with zero errors. Re-querying that exact narrow window directly, immediately, returned
the real data cleanly — proving the chain/RPC data was never the problem; the scan script silently
dropped it.

**Fix**: success/failure detection must positively confirm the response is well-formed (contains
either `"result"` or `"error"` as a JSON key) rather than merely checking for the *absence* of
specific known error substrings. Any response that is empty, malformed, or doesn't match either
expected shape must be treated as a failure requiring retry — never silently advanced past.

**Practical consequence**: any scan run using the "check for absence of known error text" pattern —
notably Base's full-history token discovery (419 tokens found, 0 persistent errors reported) and
Polygon's post-native-genesis token discovery (7 tokens found, 0 persistent errors reported) — must
be considered **potentially incomplete**, not fully trustworthy, until re-run with the corrected
detection logic. This does not necessarily mean those results are wrong (the chunks that succeeded
did produce real, cross-validated results, e.g. Base's `aEthWETH`/`variableDebtBasUSDC` matching the
live cache exactly), but any *absence* of a token from those scans' output can no longer be trusted
as proof that token doesn't exist — exactly the situation that surfaced this bug in the first place.

Corrected pattern for any future scan script:
```bash
# after receiving $result from curl:
if [[ "$result" != *'"result"'* ]] && [[ "$result" != *'"error"'* ]]; then
  # malformed/empty response -- treat as a failure, retry, never advance past it
  ...
fi
```

---

## ⚠️ THIRD CRITICAL FINDING — a scan's "current block" boundary goes stale the moment it's captured

**Discovered via a direct, deliberate cross-check**: the account owner's real live cache showed a
current Ethereum token holding (MORPHO) that Ethereum's "fully re-verified, zero errors" token
discovery scan (see the Ethereum section below) had never found. This is a **third, distinct root
cause** from the other two critical findings above — not a chunking bug, not a silently-swallowed
empty response. The scan itself was genuinely correct for the range it covered; the range was simply
**too old**. It had been bounded at the wallet's first-outgoing-transaction block, a number captured
much earlier in this project's testing — but real time kept passing after that, the wallet kept
transacting, and nothing ever re-extended the scan's end boundary to match. A ~2.1M-block gap between
"what was last scanned" and "what block it actually is now" had silently accumulated, entirely
unnoticed, because "zero errors, fully covered" was true and reported honestly **for the range that
was actually scanned** — it just wasn't the whole real range.

**Confirmed by directly targeting just the gap**: re-scanning from the old boundary to a freshly-
resolved current block found 0 failed chunks and several more real tokens/events that no prior
"complete" scan had ever seen — including MORPHO (exact balance match), USDC, and a fully
opened-and-closed Aave WETH position, all real activity that happened entirely within the
unscanned window.

**Practical consequence — this is a design requirement, not just a testing lesson**: any production
implementation must resolve `"latest"` freshly at the *start* of every scan run, and must never
persist or reuse an old block number as an assumed-current boundary across separate runs without
re-checking it first. A wallet's "fully reconstructed" state has an implicit "as of" timestamp
(whenever the scan last ran) — treating that as permanently current, rather than re-validating or
re-extending it before answering a new request, will silently under-report real, current holdings
exactly the way it did here.

---

## Reproducible test methodology — how to (re-)run everything

This section is written so any of these tests can be re-run from scratch, on this wallet or a
different one, by anyone (including a future Claude session) without re-deriving the approach.

### 0. Tools needed

- A shell with `curl` and `node` available (Git Bash on Windows works; `node` is used for exact
  BigInt-safe hex/decimal conversion — **do not** use plain bash arithmetic for values that might
  exceed ~9.2 × 10^18, e.g. token amounts in wei/18-decimals, since bash integers silently overflow).
- Free RPC endpoints. As of this testing: `https://eth.drpc.org` (Ethereum, keyless), `https://polygon.drpc.org`
  (Polygon, keyless — but see the cap caveats above), `https://base.drpc.org` (Base, keyless, real
  cap much closer to its advertised ~10,000 than Ethereum/Polygon's `drpc` endpoints are), a
  NodeReal free-signup key (covers Ethereum and BSC: `https://{eth,bsc}-mainnet.nodereal.io/v1/<key>`
  — its Base and Polygon hostnames were guessed at and never resolved; not pursued further since
  `drpc`/Ankr already covered those chains), and an Ankr free-signup key for Polygon
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
| Base | USDbC | `0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf` | ⚠️ **Untested against a real position — assumed working.** Address confirmed correct via Compound's own official governance-proposal market list (not a guess). Two separate searches for a real holder address to test against came up empty (explorer holder/event lists aren't accessible via the tools available here, consistent with this project's general avoidance of depending on explorer APIs). Given the identical discovery mechanism has now been independently proven correct on four other real, structurally-different markets (Ethereum Compound, Polygon USDC + USDT0, Base USDS) with zero market-specific code needed each time, this one is assumed to work the same way rather than blocking further progress on it. **If a real position in this market is ever available to test against, do that to fully close this out.** |
| Polygon | USDC (pre-existing) | `0xF25212E676D1F7F89Cd72fFEe66158f541246445` | Live in production before this change; not independently re-verified as part of this work. |
| Polygon | USDT0 | `0xaeB318360f27748Acb200CE616E389A6C9409a07` | ✅ **Fully tested** against a real wallet position: 0.2 WETH + 0.00725985 WBTC collateral, 344.508106 USDT0 borrowed — matches the account owner's own description ("various cryptos... borrow USDT0") exactly. |

**A real, confirmed-legitimate address coincidence worth knowing about**: `0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf` (Base's USDbC market) is the *same address* as Arbitrum's native USDC market, already in this project's pre-existing config. Verified via two independent sources (real Arbiscan transaction logs showing genuine Supply/Withdraw activity, and Compound's own governance-proposal JSON listing the exact per-chain mapping) that these are two real, unrelated, legitimate deployments that happen to share an address across chains — not a bug, not a copy-paste error, but a genuine trap for assuming "same address = same market" when cross-referencing chains.

**Not investigated as part of this work, noticed only in passing**: Compound's official market list (surfaced while researching the above) shows Arbitrum also has an untracked WETH market (`0x6f7D514bbD4aFf3BcD1140B7344b32f063dEe486`) and Ethereum/Optimism weren't re-checked for additional markets beyond their existing single USDC entries. Worth a pass later, out of scope for this round since it wasn't asked for.



### 6. Aave positions (current or historical) — and its own pitfalls

- **Live/current reads**: `getUserAccountData(wallet)` on the chain's Aave V3 Pool address, selector
  `0xbf92857c`. Returns 6 words: `totalCollateralUsd`, `totalDebtUsd`, `availableBorrowsUsd` (all
  8-decimal USD), `currentLiquidationThreshold`/`ltv` (bps), `healthFactor` (18-decimal, or all-`f`s
  = infinite/no debt).
- **Finding the aToken/debt-token addresses needed for the direct-`balanceOf` method below**: call
  `getReserveTokensAddresses(address asset)` on the chain's `AaveProtocolDataProvider`, selector
  `0xd2493b6c`, passing the *underlying* asset's address (e.g. WETH's real address, not a token
  symbol). Returns 3 words: `(aTokenAddress, stableDebtTokenAddress, variableDebtTokenAddress)`.
  This call itself is only reliable from each chain's current DataProvider redeployment date onward
  (see below) — but the returned aToken/debt-token *addresses* themselves are stable and can be
  reused for historical reads arbitrarily far back, since it's the DataProvider *contract* that gets
  redeployed, not the token contracts it points to. In practice: resolve these addresses once, using
  today's DataProvider at `"latest"`, then reuse them for every historical `balanceOf` call
  regardless of date.
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

**Real measured throughput for the post-native-genesis range, using the corrected (bug-fixed)
scanner**: 50,000 blocks in 376 seconds (~133 blocks/second) on Ankr, giving an estimated
**~21.5 hours** for the full ~10.3M-block post-genesis range. Confirms the tiny real cap found via
direct bisection (100 blocks near current blocks) isn't a one-off — it's the genuine, sustained
throughput ceiling for this range on this provider. Much better than the ~20-day pre-genesis
estimate, but still substantial enough that it shouldn't be run interactively/blindly again without
a real decision about the approach (background/overnight run vs. accepting a scoped limitation vs.
finding a faster provider).

---

## Chain-by-chain status

### Ethereum — closed, fully re-verified

| Item | Status |
|---|---|
| Free RPC | ✅ `drpc.org` keyless for point-reads/small ranges; **NodeReal (free signup) for any log scanning**, confirmed reliable at 49,999-block chunks with zero failures across two large re-verification runs (see below). `publicnode.com` is keyless but refuses log queries on old blocks with an archive-token error, regardless of range size — not usable for this without a key. |
| Native genesis | ✅ Block 13,024,431 (Aug 14 2021, 16:43:39 UTC), tx-confirmed: single incoming deposit of ≈0.444 ETH, exact value match to the balance at that block. |
| Token discovery | ✅✅✅ **Re-verified three times.** First two runs (NodeReal, 49,999-block chunks): 210 chunks (native genesis → first outgoing tx) and 261 chunks (block 0 → native genesis), zero failed chunks, found TR3 (genesis block 12,420,246, predates native ETH funding by ~3 months). **A third gap was then found and closed**: those first two runs' end boundary was a stale "current block" captured much earlier in testing — see the third critical finding at the top of this document. Re-scanning just the ~2.1M-block gap between that stale boundary and a freshly-resolved current block (0 failed chunks) found several more real tokens/events: MORPHO (exact balance match to the live cache), USDC (real Compound market activity), a fully opened-and-closed Aave WETH position, and two not-yet-identified token contracts. Ethereum's token discovery is now genuinely current, not just "correct as of an old snapshot." |
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
| Token/event discovery, native genesis (80,406,107) → current | ✅ **Fully closed.** Full-history scan completed both directions (incoming/outgoing), ~40 hours total wall-clock time using the corrected (empty-response-safe) scanner on Ankr — real measured throughput turned out roughly half of the initial short-sample estimate (~40hrs actual vs. ~21.5hrs projected), a useful reminder that short-sample throughput measurements can be optimistic for sustained long runs. **Zero unrecoverable errors.** Found 16 unique token contracts, including both debt tokens (`variableDebtPolUSDCn`, `variableDebtPolUSDT`) that a prior, buggy version of this same scan had silently missed — see the second critical finding at the top of this document for the full story of that bug and its fix. Cross-checked against the account owner's real live cache: every token in the cache's Polygon `tokens` list is present in this discovery result. |
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

**Not yet done on Base**: Compound USDbC market's historical trend — no real position was found to
test against despite two search attempts (see section 5b); assumed to work the same way as every
other Compound market tested so far, given the identical discovery mechanism has held up correctly
four times running. Also not yet done: a repeat of the "does drpc's real cap match the advertised
one" bisection specifically for the dense/early portion of Base's history (not yet found to be a
problem here the way it was on Polygon, but also not explicitly ruled out with the same rigor).

**Compound USDS market historical reads**: ✅ Fully verified. Market deployment block found (Jan 20,
2025, well before this wallet's Base presence began), confirming "not yet deployed" would correctly
read as no-position rather than an error for any date before that. Historical trend across 5 dates
(Oct 2025 - Jun 2026) shows a clean, textbook pattern: no position through Feb 2026, then the
position opens with exactly 402.608089649583865983 sUSDS collateral — which **never changes** across
every subsequent date sampled, including the live cache's current value — while the USDS borrow
smoothly accrues interest with each successive read (40.20 → 40.52 → 40.57), matching a single
deposit-then-hold pattern with no further deposits/withdrawals. This is about as clean a confirmation
as this testing effort has produced anywhere.

**Base token-balance correctness spot-check**: ✅ done, on a genuinely meaningful token rather than
an unidentified airdrop. **8LNDS** (`0x55f9c8992fc4abce5aca585bf8f18284a2379d4c`) showed a real,
unexplained ~367.8 balance increase between two live-cache snapshots six hours apart — initially
looked like a possible second NAFTY-style rebasing case. Investigated directly (a narrow, targeted
log query on the exact 6-hour window, same technique that cracked the NAFTY and Polygon Aave
mysteries earlier): found **7 real `Transfer` events, all in one transaction, all from the same
sender** (the token's distribution/claim contract — the account owner confirmed 8LNDS is a real
investment-platform token distributed to holders on claim/purchase, not an auto-compounding token).
**The sum of those 7 transfers matches the observed balance growth exactly, to the 18th decimal.**
This is the "normal" case (same as TR3 on Ethereum) — every balance change is fully explained by a
real, discoverable event — in direct contrast to NAFTY's case, where balance grew with zero
corresponding Transfer. Confirms the standard discovery+`balanceOf` method will correctly
reconstruct 8LNDS's history.

**cbBTC full-history correctness check**: ✅ done, and this is the strongest validation this project
has produced. Full history scanned (both directions, native-genesis → current, ~13.66M blocks, zero
errors), finding **196 real `Transfer` events** spanning Sept 2025 → Jul 2026. Reconstructed the
complete chronological running balance from these events and it **returns to exactly zero at every
point where it should**, including dozens of real receive-then-immediately-supply-to-DeFi cycles —
not just at the final, current balance. This directly validates the requirement the account owner
specifically raised: reconstruction must get *transient* historical non-zero periods right (e.g. the
wallet briefly holding cbBTC between a swap and a subsequent Compound/Aave supply), not merely
reproduce today's resting balance. Zero discrepancies found anywhere across the full real history.

**Base testing is now substantially complete**: RPC, native genesis, token discovery (full history,
419 tokens — though see the second critical finding at the top of this document; this scan predates
the empty-response-bug fix and should be treated with the same caution as Polygon's original run),
Aave (config, DataProvider redeployment check, historical trend), one of two new Compound markets
(USDS), and now real token-correctness spot-checks (8LNDS and, more thoroughly, cbBTC's full
196-event history) are all independently verified. Native ETH needs no separate correctness check
(not an ERC-20, no delta-vs-balance question applies). USDC only got a light spot-check (a small
recent window, no anomalies found) rather than full-history coverage, a deliberate choice given how
extremely standard/audited USDC is and how disproportionately expensive full coverage would be for
such a high-traffic contract — not treated as fully proven the way 8LNDS/cbBTC are, but low risk.
The only real gap left is the USDbC market (untested against a real position, but assumed working
given the identical mechanism's four-for-four track record on other markets).

Confirmed with the account owner: no meaningful holdings on Avalanche for the test wallet.
Deprioritized; reuse the same proven method later if ever needed, without dedicated testing.

---

## Appendix: known contract addresses and RPC endpoints (quick reference)

Everything below has been independently confirmed correct during this testing (via official
registries, `eth_getCode` sanity checks, and/or real transaction cross-checks) — not guessed. Where
a chain/market isn't listed, it hasn't been looked up as part of this work.

### RPC endpoints used (all free tier)

| Chain | Endpoint | Notes |
|---|---|---|
| Ethereum | `https://eth.drpc.org` | Keyless. Real `eth_getLogs` cap ~101 blocks (not the advertised 10,000) |
| Ethereum | `https://eth-mainnet.nodereal.io/v1/<key>` | Free signup. Real cap ~49,999 blocks, reliable |
| BSC | `https://bsc-mainnet.nodereal.io/v1/<key>` | Free signup. Real cap ~49,999 blocks, reliable. No working keyless option found for BSC archive access |
| BSC | `https://bsc-rpc.publicnode.com` | Keyless, but `"latest"`-only reads (no archive) — fine for current-state tracking, not reconstruction |
| Polygon | `https://polygon.drpc.org` | Keyless. Real cap ~101 blocks near dense eras (both 2020-2021 *and* near-current blocks) |
| Polygon | Ankr (`https://rpc.ankr.com/polygon/<key>` or dashboard URL) | Free signup. Same tiny-cap-near-dense-blocks behavior as drpc — this is a chain-wide Polygon property, not a drpc-specific one |
| Base | `https://base.drpc.org` | Keyless. Real cap much closer to advertised (~10,000) — the one chain where drpc's real cap roughly matches what it claims |

### Aave V3 — Pool and DataProvider addresses, per chain

| Chain | Pool | DataProvider | DataProvider redeployment date |
|---|---|---|---|
| Ethereum | `0x87870Bca3F3fD6335C3F4ce8392D69350B4fA4E2` | `0x0a16f2FCC0D44FaE41cc54e079281D84A363bECD` | June 5, 2025 (block 22,686,778) |
| Polygon | `0x794a61358D6845594F94dc1DB02A252b5b4814aD` | `0x243Aa95cAC2a25651eda86e80bEe66114413c43b` | June 10, 2025 (block 72,592,541) |
| Base | `0xA238Dd80C259a72e81d7e4664a9801593F98d1c5` | `0x0F43731EB8d45A581f4a36DD74F5f358bc90C73A` | June 10, 2025 (block 31,377,575) |
| BSC | `0x6807dc923806fE8Fd134338EABCA509979a7e0cB` | `0xc90Df74A7c16245c5F5C5870327Ceb38Fe5d5328` | June 11, 2025 (block 51,262,445) |

All four addresses independently confirmed against Aave's official `aave-address-book` GitHub repo,
not guessed. Any date before a chain's DataProvider redeployment date needs either the direct
`balanceOf`-on-aToken/debt-token method (works across the whole history on every chain checked) or a
prior DataProvider address (not looked up for any chain, since no wallet tested needed pre-June-2025
Aave history).

### Compound III (Comet) markets tested or added

| Chain | Base asset | Comet address | Deployed |
|---|---|---|---|
| Ethereum | USDC | `0xc3d688B66703497DAA19211EEdff47f25384cdc3` | (pre-existing, not re-checked) |
| Base | USDC | `0xb125E6687d4313864e53df431d5425969c15Eb2F` | (pre-existing, not re-checked) |
| Base | USDS | `0x2c776041CCFe903071AF44aa147368a9c8EEA518` | Jan 20, 2025 |
| Base | USDbC | `0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf` | Aug 8, 2023 (same day as Base's Aave Pool) |
| Polygon | USDC | `0xF25212E676D1F7F89Cd72fFEe66158f541246445` | (pre-existing, not re-checked) |
| Polygon | USDT0 | `0xaeB318360f27748Acb200CE616E389A6C9409a07` | (not checked) |
| Arbitrum | USDC | `0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf` | (pre-existing — **same address as Base's USDbC market, confirmed real coincidence, not a bug**) |
| Optimism | USDC | `0x2e44e174f7D53F0212823acC11C01A11d58c5bCB` | (pre-existing, not re-checked) |

Noticed in passing, not yet added: Arbitrum also has an untracked WETH Comet market at
`0x6f7D514bbD4aFf3BcD1140B7344b32f063dEe486`.

### Function selectors used throughout this testing

| Selector | Function | Used on |
|---|---|---|
| `0x70a08231` | `balanceOf(address)` | Any ERC-20, aToken, debt-token, or Comet market (base-asset supplied) |
| `0x374c49b4` | `borrowBalanceOf(address)` | Comet markets (base-asset borrowed) |
| `0x313ce567` | `decimals()` | Any ERC-20 |
| `0x95d89b41` | `symbol()` | Any ERC-20 (returns dynamic string on most tokens, `bytes32` on some older ones — handle both) |
| `0xa46fe83b` | `numAssets()` | Comet markets |
| `0xc8c7fe6b` | `getAssetInfo(uint8)` | Comet markets — word[1] of the returned tuple is the asset address |
| `0x2b92a07d` | `userCollateral(address,address)` | Comet markets — word[0] of the returned tuple is the balance |
| `0xbf92857c` | `getUserAccountData(address)` | Aave Pool |
| `0x28dd2d01` | `getUserReserveData(address,address)` | Aave DataProvider — NOT safe pre-redeployment, see section 6 |
| `0xd2493b6c` | `getReserveTokensAddresses(address)` | Aave DataProvider — resolves aToken/debt-token addresses, see section 6 |
| `0xb316ff89` | `getAllReservesTokens()` | Aave DataProvider or Compound context — returns the full reserve/asset list |
| `0xddf252ad...` (32 bytes, see section 3) | `Transfer(address,address,uint256)` event topic0 | Any ERC-20 log filtering |

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
  This is the single most load-bearing piece of new code, since nearly every bug found during testing
  was a flaw in this exact logic. It must correctly handle **three distinct failure modes**, found
  through real, repeated production-like testing, not just theorized:
  1. **Rate-limit errors** (e.g. `"Too many requests"`, `"rate limit"` in the message, several
     different exact wordings seen across providers) — back off and retry the *same* block range,
     do not shrink it.
  2. **Density/timeout errors** (e.g. `"Request timeout on the free plan"`) — the range itself may be
     within the provider's nominal cap but still times out because too many logs exist in it (seen on
     Polygon in both its 2020-2021 era *and*, surprisingly, near-current blocks; also seen on Base for
     the wallet's own `Transfer` scans). Shrink the range and retry from the same start block.
  3. **Pure transient flakiness** — the *exact same* query, at the *exact same* size, sometimes fails
     and sometimes succeeds with no pattern tied to range size or density at all (confirmed directly:
     the same narrow block range failed then succeeded on immediate retry with nothing about the
     request changed). For this mode, neither backing off nor shrinking is the fix — a bounded number
     of immediate/short-wait retries at the *same* size is what actually resolves it. A production
     scanner should attempt a handful of quick retries at the current size *before* concluding a
     failure is the rate-limit or density kind and reacting accordingly.
  - **Critical correctness requirement, found only after the above three modes were already handled**:
    success/failure detection must positively confirm the raw response is a well-formed JSON-RPC
    response (contains either a `"result"` or an `"error"` key) before treating it as anything other
    than a failure. A response that is empty or malformed (e.g. from a network-level timeout that
    never reaches the provider, or a connection drop) must never be silently treated as "succeeded
    with an empty result" — this exact bug caused a real, confirmed silent gap in an earlier version
    of this logic during testing (a real token position was missed by a scan that had already reported
    "zero errors, fully covered"). This is not a hypothetical edge case to guard against defensively;
    it is a bug that was shipped, ran, and produced a wrong answer during this project's own testing.
  - **Never run two scans concurrently against the same (chain, provider) pair for the same reason** —
    confirmed directly that doing so roughly halves each scan's effective throughput (provider-side
    rate limiting is shared across concurrent requests from the same key/IP, not per-request). The job
    scheduler (see "Open design question" below) must serialize scans per provider, not just per
    wallet.
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
  **Correctness must be validated across a token's full lifecycle, not just at "now"**: a token can
  correctly show a zero balance today while having held a real, meaningful non-zero balance at
  specific points in its past (e.g. an asset received via a swap, then supplied as DeFi collateral
  minutes later — the wallet's own current holdings genuinely include a real "cbBTC balance of zero
  now" that was non-zero for a real interval during its history). A reconstruction that only gets
  today's zero right without correctly reconstructing the transient non-zero periods in between is
  not actually correct, even though a naive "does today's number match" check would pass. Any test
  suite for this feature needs cases that check point-in-time balances during a token's active window,
  not just its current resting value.
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
take hours — and based on real, repeated measurement during this project's testing, "hours" is an
understatement for the common case, not the worst case. Multiple full-history single-token scans on
Base alone (a chain with one of the *better* real caps found) took **20-58 hours each** in practice,
even after all known scanning bugs were fixed; a full wallet-wide multi-token scan will be
substantially larger than any single-token scan measured so far. Concrete implications for whoever
designs this:
- A simple "cron runs once a minute, checks if the job finished" pattern is necessary but not
  sufficient — the job needs to be **resumable across many, many invocations spanning multiple days**,
  not just tolerant of one interruption. Store enough state (last-scanned block, current adaptive step
  size, discovered-tokens-so-far) that a resume picks up exactly where a prior invocation left off,
  with no wasted re-work and no risk of a gap at the resume boundary.
- **Never schedule two reconstruction jobs against the same (chain, provider) pair concurrently** —
  confirmed this roughly halves throughput for both. If multiple wallets need the same chain
  reconstructed, queue them serially per provider, not in parallel.
- Whoever owns this feature should set expectations accordingly: a "reconstruct my full history"
  request for an active wallet on a chain with real transaction volume is a **multi-day background
  job**, not a "check back in an hour" one, on current free-tier infrastructure. This may itself be
  a reason to consider paid-tier RPC access for this specific feature even though `/holdings-now`
  deliberately avoids it — worth an explicit product decision, not an assumption either way.

This background-execution mechanism hasn't been designed yet and is a real gap to close before
implementation starts, not an afterthought.

---

## What's left to test (in priority order)

1. ~~Polygon, native-genesis → current, log discovery~~ — **done**, see the Polygon section above
   (16 tokens found, zero errors, cross-validated against the real live cache).
2. **Polygon, block 0 → native genesis, log discovery** — the ~20-day-at-current-rate problem. Needs
   a scoping decision (see the three options already laid out below) before it can be called done.
3. ~~Base token-balance correctness spot-check~~ — **done** for 8LNDS (see the Base section above);
   **in progress** for cbBTC specifically (a full-history scan is running as of this writing,
   checking whether transient non-zero balances during supply/withdraw cycles reconstruct correctly,
   not just the current zero balance) — check back on this before considering Base's token
   correctness fully closed.
4. **Re-verify Ethereum's Aave/Compound historical trend checks** with the corrected methodology —
   these were direct point-reads (not chunked scans), so they're likely unaffected by the chunking
   bug, but haven't been explicitly re-confirmed post-discovery the way the log-scan-based findings
   were.
5. **BSC native genesis** — never actually pinned down (low priority, doesn't block anything
   currently understood, but a real gap in an otherwise-closed chain).
6. **The async/background-execution design question** flagged in the architecture section above —
   how a multi-hour scan runs without blocking an HTTP request. Needs an actual design, not just the
   flag that it's unresolved.
7. Only after the above: **build it** — the architecture section above is the blueprint; actual PHP
   implementation (log scanner, cursor repository, endpoint wiring, DB schema) hasn't been started.

No code for the multichain reconstruction feature should be written until this list is empty or its
remaining items are explicitly, consciously scoped out — per this project's "remove every shadow
before building" approach.
