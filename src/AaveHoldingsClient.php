<?php

namespace App;

/**
 * Reads a wallet's Aave V3 positions directly from the on-chain contracts via eth_call,
 * across every chain in self::POOLS. No third-party API/key needed.
 *
 * Unlike Compound III (one isolated market per base asset), Aave V3 is a single shared
 * Pool per chain listing many reserves at once (WETH, USDC, wstETH, ...); a wallet can
 * supply and/or borrow several of them simultaneously within that one Pool. This uses
 * Aave's AaveProtocolDataProvider helper contract, which exposes:
 *  - getAllReservesTokens()          -> the full, live reserve list for the chain
 *  - getUserReserveData(asset, user) -> that wallet's supplied/borrowed amounts per reserve
 * plus Pool.getUserAccountData(user) for an aggregated summary (collateral/debt in USD,
 * LTV, liquidation threshold, health factor).
 *
 * Pool / DataProvider addresses pulled from the canonical registry:
 * https://github.com/bgd-labs/aave-address-book (src/AaveV3<Chain>.sol)
 */
class AaveHoldingsClient
{
    private const SEL_GET_ALL_RESERVES_TOKENS = '0xb316ff89'; // getAllReservesTokens()
    private const SEL_GET_USER_RESERVE_DATA = '0x28dd2d01';   // getUserReserveData(address,address)
    private const SEL_GET_USER_ACCOUNT_DATA = '0xbf92857c';   // getUserAccountData(address)
    private const SEL_DECIMALS = '0x313ce567';                 // decimals()
    private const SEL_BALANCE_OF = '0x70a08231';               // balanceOf(address)
    private const SEL_GET_RESERVE_TOKENS_ADDRESSES = '0xd2493b6c'; // getReserveTokensAddresses(address)

    private const ZERO_ADDRESS = '0x0000000000000000000000000000000000000000';

    // type(uint256).max, i.e. 2^256 - 1 -- Aave's sentinel for "infinite" health factor.
    // See the comment where this is used, below.
    private const MAX_UINT256 =
        '115792089237316195423570985008687907853269984665640564039457584007913129639935';

    // Keyed the same way as Zerion's chain IDs (see ZerionPosition::$chainId) so
    // results merge cleanly under the same chain keys.
    private const POOLS = [
        'ethereum' => [
            'pool' => '0x87870Bca3F3fD6335C3F4ce8392D69350B4fA4E2',
            'dataProvider' => '0x0a16f2FCC0D44FaE41cc54e079281D84A363bECD',
        ],
        'base' => [
            'pool' => '0xA238Dd80C259a72e81d7e4664a9801593F98d1c5',
            'dataProvider' => '0x0F43731EB8d45A581f4a36DD74F5f358bc90C73A',
        ],
        'polygon' => [
            'pool' => '0x794a61358D6845594F94dc1DB02A252b5b4814aD',
            'dataProvider' => '0x243Aa95cAC2a25651eda86e80bEe66114413c43b',
        ],
        'arbitrum' => [
            'pool' => '0x794a61358D6845594F94dc1DB02A252b5b4814aD',
            'dataProvider' => '0x243Aa95cAC2a25651eda86e80bEe66114413c43b',
        ],
        'optimism' => [
            'pool' => '0x794a61358D6845594F94dc1DB02A252b5b4814aD',
            'dataProvider' => '0x243Aa95cAC2a25651eda86e80bEe66114413c43b',
        ],
        'binance-smart-chain' => [
            'pool' => '0x6807dc923806fE8Fd134338EABCA509979a7e0cB',
            'dataProvider' => '0xc90Df74A7c16245c5F5C5870327Ceb38Fe5d5328',
        ],
    ];

    /**
     * @param array<string, string> $rpcUrls Chain key (e.g. "ethereum") => RPC URL.
     *                                        Chains without an entry here are skipped.
     */
    /**
     * Per chain, the reserve list with its aToken / debt-token addresses and decimals,
     * resolved once at "latest" (see getReserveTokens()). Kept for the life of this
     * instance, so a day-by-day historical walk resolves it once per chain, not once per day.
     *
     * @var array<string, list<array{symbol: string, asset: string, decimals: int,
     *            aToken: string, stableDebtToken: string, variableDebtToken: string}>>
     */
    private array $reserveTokensMemo = [];

    public function __construct(private array $rpcUrls, private ?\Closure $rpcFactory = null)
    {
    }

    /**
     * Builds the RPC client for one chain; see CompoundHoldingsClient::makeRpc().
     */
    private function makeRpc(string $url): EvmRpcClient
    {
        // One client per URL for the life of this instance: its eth_getCode memo
        // (EvmRpcClient::hasCodeAt) must survive from one historical read to the next, or a
        // day-by-day walk would pay for an eth_getCode per contract per day.
        return $this->rpcClients[$url] ??= $this->rpcFactory !== null
            ? ($this->rpcFactory)($url)
            : new EvmRpcClient($url);
    }

    /** @var array<string, EvmRpcClient> */
    private array $rpcClients = [];

    /**
     * @param int|string $block "latest" (default: what /holdings-now reads, via the
     *                          DataProvider's getUserReserveData, unchanged) or a block
     *                          number for a historical read. Historical reads NEVER use the
     *                          DataProvider's convenience methods (silently empty before
     *                          each chain's June 2025 redeployment, MULTICHAIN-HOLDINGS.md
     *                          section 6): they read balanceOf on each reserve's aToken and
     *                          debt tokens (addresses resolved once, at latest, then reused
     *                          for any date) plus the Pool's getUserAccountData. In that
     *                          mode each reserve's "usedAsCollateral" is null (unknown):
     *                          the only source for it is getUserReserveData, which cannot be
     *                          trusted historically.
     *
     * @return array{positions: array<string, array>, errors: array<string, string>}
     *         "positions" covers only chains where this wallet has at least one non-zero
     *         reserve position and every RPC call succeeded. Chains that errored are
     *         reported separately under "errors" so a single bad RPC doesn't silently
     *         drop data.
     */
    public function getHoldings(string $address, int|string $block = 'latest'): array
    {
        $positions = [];
        $errors = [];

        foreach (self::POOLS as $chain => $addrs) {
            if (! isset($this->rpcUrls[$chain])) {
                continue;
            }

            $rpc = $this->makeRpc($this->rpcUrls[$chain]);
            $result = $block === 'latest'
                ? $this->getChainPosition($rpc, $address, $addrs['dataProvider'], $addrs['pool'])
                : $this->getChainPositionAtBlock($chain, $rpc, $address, $addrs['dataProvider'], $addrs['pool'], (int) $block);

            if (is_string($result)) {
                $errors[$chain] = $result;

                continue;
            }

            if (empty($result['reserves'])) {
                continue;
            }

            $positions[$chain] = [
                'reserves' => $result['reserves'],
                'summary' => $result['summary'],
            ];
        }

        return ['positions' => $positions, 'errors' => $errors];
    }

    /**
     * @return array{reserves: list<array>, summary: array}|string Array on success, or
     *         an EvmRpcClient::ERROR_* constant on RPC failure.
     */
    private function getChainPosition(EvmRpcClient $rpc, string $wallet, string $dataProvider, string $pool): array|string
    {
        $walletEnc = AbiCodec::encodeAddress($wallet);

        $reservesRaw = $rpc->ethCall($dataProvider, self::SEL_GET_ALL_RESERVES_TOKENS);

        if ($reservesRaw === EvmRpcClient::ERROR_UPSTREAM) {
            return EvmRpcClient::ERROR_UPSTREAM;
        }

        $reserves = AbiCodec::decodeReservesTokens($reservesRaw);

        $reservePositions = [];

        foreach ($reserves as $reserve) {
            $assetEnc = AbiCodec::encodeAddress($reserve['address']);
            $result = $rpc->ethCall($dataProvider, self::SEL_GET_USER_RESERVE_DATA . $assetEnc . $walletEnc);

            if ($result === EvmRpcClient::ERROR_UPSTREAM) {
                return EvmRpcClient::ERROR_UPSTREAM;
            }

            $w = AbiCodec::words($result);

            // tuple layout (all static uint256/uint40/bool, so plain word slots):
            // [0] currentATokenBalance  [1] currentStableDebt   [2] currentVariableDebt
            // [3] principalStableDebt   [4] scaledVariableDebt  [5] stableBorrowRate
            // [6] liquidityRate         [7] stableRateLastUpdated (uint40) [8] usageAsCollateralEnabled (bool)
            $supplied = AbiCodec::hexToDec($w[0]);
            $stableDebt = AbiCodec::hexToDec($w[1]);
            $variableDebt = AbiCodec::hexToDec($w[2]);
            $usedAsCollateral = hexdec($w[8]) === 1;

            $hasPosition = ! AbiCodec::isZero($supplied)
                || ! AbiCodec::isZero($stableDebt)
                || ! AbiCodec::isZero($variableDebt);

            if (! $hasPosition) {
                continue;
            }

            $decimalsRaw = $rpc->ethCall($reserve['address'], self::SEL_DECIMALS);

            if ($decimalsRaw === EvmRpcClient::ERROR_UPSTREAM) {
                return EvmRpcClient::ERROR_UPSTREAM;
            }

            $decimals = hexdec(AbiCodec::strip0x($decimalsRaw));

            $reservePositions[] = [
                'symbol' => $reserve['symbol'],
                'supplied' => AbiCodec::formatUnits($supplied, $decimals),
                'usedAsCollateral' => $usedAsCollateral,
                'variableDebt' => AbiCodec::formatUnits($variableDebt, $decimals),
                'stableDebt' => AbiCodec::formatUnits($stableDebt, $decimals),
            ];
        }

        $accountRaw = $rpc->ethCall($pool, self::SEL_GET_USER_ACCOUNT_DATA . $walletEnc);

        if ($accountRaw === EvmRpcClient::ERROR_UPSTREAM) {
            return EvmRpcClient::ERROR_UPSTREAM;
        }

        return [
            'reserves' => $reservePositions,
            'summary' => $this->buildSummary($accountRaw),
        ];
    }

    /**
     * Formats Pool.getUserAccountData(user) into the response's "summary" shape. Shared by
     * the latest and the historical reads, so both produce identical summaries.
     *
     * @return array{totalCollateralUsd: string, totalDebtUsd: string, ltv: string,
     *               liquidationThreshold: string, healthFactor: ?string}
     */
    private function buildSummary(string $accountRaw): array
    {
        $aw = AbiCodec::words($accountRaw);
        $healthFactorRaw = AbiCodec::hexToDec($aw[5]);
        // Aave returns type(uint256).max for health factor when there's no debt ("infinite" --
        // can't be liquidated). Represented as null here rather than an astronomically large
        // number, so API consumers don't need to special-case a magic sentinel value themselves.
        // Hardcoded rather than computed (2^256 - 1) since it's a fixed constant -- avoids
        // needing bcmath/GMP just to derive a value that never changes. Plain string equality
        // is exact here because AbiCodec::hexToDec never produces leading zeros.
        $healthFactor = $healthFactorRaw === self::MAX_UINT256
            ? null
            : AbiCodec::formatUnits($healthFactorRaw, 18);

        return [
            'totalCollateralUsd' => AbiCodec::formatUnits(AbiCodec::hexToDec($aw[0]), 8),
            'totalDebtUsd' => AbiCodec::formatUnits(AbiCodec::hexToDec($aw[1]), 8),
            'ltv' => AbiCodec::formatUnits(AbiCodec::hexToDec($aw[4]), 2),
            'liquidationThreshold' => AbiCodec::formatUnits(AbiCodec::hexToDec($aw[3]), 2),
            'healthFactor' => $healthFactor,
        ];
    }

    /**
     * Historical read of one chain: direct balanceOf on every reserve's aToken / stable
     * debt token / variable debt token at $block, plus Pool.getUserAccountData at $block.
     * (The Pool is a stable proxy, so its getUserAccountData is valid at any block; the
     * DataProvider is not, which is why it is only used at "latest", to resolve addresses.)
     *
     * A pool or token contract that does not exist yet at $block is a verified zero
     * (eth_getCode, via EvmRpcClient::hasCodeAt()), never an inference from an empty
     * eth_call. Any other failure fails the whole chain: a partial position would look
     * complete and be wrong.
     *
     * @return array{reserves: list<array>, summary: ?array}|string
     */
    private function getChainPositionAtBlock(
        string $chain,
        EvmRpcClient $rpc,
        string $wallet,
        string $dataProvider,
        string $pool,
        int $block
    ): array|string {
        $poolDeployed = $rpc->hasCodeAt($pool, $block);

        if ($poolDeployed instanceof EvmRpcError) {
            return EvmRpcClient::ERROR_UPSTREAM;
        }

        if (! $poolDeployed) {
            return ['reserves' => [], 'summary' => null];
        }

        $reserves = $this->getReserveTokens($chain, $rpc, $dataProvider);

        if (is_string($reserves)) {
            return $reserves;
        }

        $walletEnc = AbiCodec::encodeAddress($wallet);
        $reservePositions = [];

        foreach ($reserves as $reserve) {
            $supplied = $this->tokenBalanceAt($rpc, $reserve['aToken'], $walletEnc, $block);
            $stableDebt = $this->tokenBalanceAt($rpc, $reserve['stableDebtToken'], $walletEnc, $block);
            $variableDebt = $this->tokenBalanceAt($rpc, $reserve['variableDebtToken'], $walletEnc, $block);

            if ($supplied === null || $stableDebt === null || $variableDebt === null) {
                return EvmRpcClient::ERROR_UPSTREAM;
            }

            if (AbiCodec::isZero($supplied) && AbiCodec::isZero($stableDebt) && AbiCodec::isZero($variableDebt)) {
                continue;
            }

            $reservePositions[] = [
                'symbol' => $reserve['symbol'],
                'supplied' => AbiCodec::formatUnits($supplied, $reserve['decimals']),
                // Unknown historically: see the getHoldings() docblock.
                'usedAsCollateral' => null,
                'variableDebt' => AbiCodec::formatUnits($variableDebt, $reserve['decimals']),
                'stableDebt' => AbiCodec::formatUnits($stableDebt, $reserve['decimals']),
            ];
        }

        $accountRaw = $rpc->ethCall($pool, self::SEL_GET_USER_ACCOUNT_DATA . $walletEnc, $block);

        if ($accountRaw === EvmRpcClient::ERROR_UPSTREAM) {
            return EvmRpcClient::ERROR_UPSTREAM;
        }

        return [
            'reserves' => $reservePositions,
            'summary' => $this->buildSummary($accountRaw),
        ];
    }

    /**
     * balanceOf($walletEnc) on a token as of $block, as a decimal string; "0" when the
     * token is the zero address (a reserve with no stable debt token) or does not exist yet
     * at $block; null on any RPC failure.
     */
    private function tokenBalanceAt(EvmRpcClient $rpc, string $token, string $walletEnc, int $block): ?string
    {
        if (strtolower($token) === self::ZERO_ADDRESS) {
            return '0';
        }

        $deployed = $rpc->hasCodeAt($token, $block);

        if ($deployed instanceof EvmRpcError) {
            return null;
        }

        if (! $deployed) {
            return '0';
        }

        $raw = $rpc->ethCall($token, self::SEL_BALANCE_OF . $walletEnc, $block);

        if ($raw === EvmRpcClient::ERROR_UPSTREAM) {
            return null;
        }

        $words = AbiCodec::words($raw);

        if (count($words) !== 1 || strlen($words[0]) !== 64) {
            return null;
        }

        return AbiCodec::hexToDec($words[0]);
    }

    /**
     * The chain's reserves with their token addresses and decimals, resolved at "latest"
     * through the DataProvider (getAllReservesTokens, then getReserveTokensAddresses per
     * reserve, then decimals). The returned aToken / debt-token addresses are stable and
     * valid for any historical block (it is the DataProvider contract that gets
     * redeployed, not the tokens it points to), so this runs once per chain per instance.
     *
     * @return list<array{symbol: string, asset: string, decimals: int, aToken: string,
     *               stableDebtToken: string, variableDebtToken: string}>|string
     */
    private function getReserveTokens(string $chain, EvmRpcClient $rpc, string $dataProvider): array|string
    {
        if (isset($this->reserveTokensMemo[$chain])) {
            return $this->reserveTokensMemo[$chain];
        }

        $reservesRaw = $rpc->ethCall($dataProvider, self::SEL_GET_ALL_RESERVES_TOKENS);

        if ($reservesRaw === EvmRpcClient::ERROR_UPSTREAM) {
            return EvmRpcClient::ERROR_UPSTREAM;
        }

        $resolved = [];

        foreach (AbiCodec::decodeReservesTokens($reservesRaw) as $reserve) {
            $tokensRaw = $rpc->ethCall(
                $dataProvider,
                self::SEL_GET_RESERVE_TOKENS_ADDRESSES . AbiCodec::encodeAddress($reserve['address'])
            );
            $decimalsRaw = $rpc->ethCall($reserve['address'], self::SEL_DECIMALS);

            if ($tokensRaw === EvmRpcClient::ERROR_UPSTREAM || $decimalsRaw === EvmRpcClient::ERROR_UPSTREAM) {
                return EvmRpcClient::ERROR_UPSTREAM;
            }

            $words = AbiCodec::words($tokensRaw);

            if (count($words) < 3) {
                return EvmRpcClient::ERROR_UPSTREAM;
            }

            $resolved[] = [
                'symbol' => $reserve['symbol'],
                'asset' => $reserve['address'],
                'decimals' => (int) hexdec(AbiCodec::strip0x($decimalsRaw)),
                // getReserveTokensAddresses -> (aToken, stableDebtToken, variableDebtToken)
                'aToken' => AbiCodec::wordToAddress($words[0]),
                'stableDebtToken' => AbiCodec::wordToAddress($words[1]),
                'variableDebtToken' => AbiCodec::wordToAddress($words[2]),
            ];
        }

        $this->reserveTokensMemo[$chain] = $resolved;

        return $resolved;
    }
}
