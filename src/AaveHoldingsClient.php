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
    public function __construct(private array $rpcUrls)
    {
    }

    /**
     * @return array{positions: array<string, array>, errors: array<string, string>}
     *         "positions" covers only chains where this wallet has at least one non-zero
     *         reserve position and every RPC call succeeded. Chains that errored are
     *         reported separately under "errors" so a single bad RPC doesn't silently
     *         drop data.
     */
    public function getHoldings(string $address): array
    {
        $positions = [];
        $errors = [];

        foreach (self::POOLS as $chain => $addrs) {
            if (! isset($this->rpcUrls[$chain])) {
                continue;
            }

            $rpc = new EvmRpcClient($this->rpcUrls[$chain]);
            $result = $this->getChainPosition($rpc, $address, $addrs['dataProvider'], $addrs['pool']);

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
            'reserves' => $reservePositions,
            'summary' => [
                'totalCollateralUsd' => AbiCodec::formatUnits(AbiCodec::hexToDec($aw[0]), 8),
                'totalDebtUsd' => AbiCodec::formatUnits(AbiCodec::hexToDec($aw[1]), 8),
                'ltv' => AbiCodec::formatUnits(AbiCodec::hexToDec($aw[4]), 2),
                'liquidationThreshold' => AbiCodec::formatUnits(AbiCodec::hexToDec($aw[3]), 2),
                'healthFactor' => $healthFactor,
            ],
        ];
    }
}
