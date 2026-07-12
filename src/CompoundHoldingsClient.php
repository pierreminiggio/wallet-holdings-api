<?php

namespace App;

/**
 * Reads a wallet's Compound III (Comet) positions directly from the on-chain contracts
 * via eth_call, across every chain in self::MARKETS. No third-party API/key needed.
 *
 * Compound III has one isolated market contract per (chain, base asset) pair -- e.g.
 * "the USDC market on Base" -- rather than one shared pool like Aave. A wallet's
 * position is scoped per market: it can supply the base asset (earning interest) OR
 * borrow it against collateral, plus post multiple collateral assets.
 *
 * Currently only tracks each chain's USDC market (the dominant one on every chain
 * listed here). Add rows to self::MARKETS the same way to track other base-asset
 * markets (WETH, USDT, ...): pull the "comet" proxy address from
 * https://github.com/compound-finance/comet/tree/main/deployments/<chain>/<asset>/roots.json
 */
class CompoundHoldingsClient
{
    // Function selectors (first 4 bytes of keccak256(signature)), precomputed so no
    // keccak library is needed here.
    private const SEL_BALANCE_OF = '0x70a08231';        // balanceOf(address)
    private const SEL_BORROW_BALANCE_OF = '0x374c49b4'; // borrowBalanceOf(address)
    private const SEL_DECIMALS = '0x313ce567';           // decimals()
    private const SEL_NUM_ASSETS = '0xa46fe83b';         // numAssets()
    private const SEL_GET_ASSET_INFO = '0xc8c7fe6b';     // getAssetInfo(uint8)
    private const SEL_USER_COLLATERAL = '0x2b92a07d';    // userCollateral(address,address)
    private const SEL_SYMBOL = '0x95d89b41';              // symbol()

    // Comet proxy addresses, keyed the same way as Zerion's chain IDs (see
    // ZerionPosition::$chainId) so results merge cleanly under the same chain keys.
    // Pulled from https://github.com/compound-finance/comet/tree/main/deployments
    private const MARKETS = [
        'ethereum' => ['base' => 'USDC', 'comet' => '0xc3d688B66703497DAA19211EEdff47f25384cdc3'],
        'base' => ['base' => 'USDC', 'comet' => '0xb125E6687d4313864e53df431d5425969c15Eb2F'],
        'polygon' => ['base' => 'USDC', 'comet' => '0xF25212E676D1F7F89Cd72fFEe66158f541246445'],
        'arbitrum' => ['base' => 'USDC', 'comet' => '0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf'],
        'optimism' => ['base' => 'USDC', 'comet' => '0x2e44e174f7D53F0212823acC11C01A11d58c5bCB'],
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
     *         "positions" covers only chains where this wallet has a non-zero position
     *         and the RPC call succeeded. Chains that errored are reported separately
     *         under "errors" so a single bad RPC doesn't silently drop data.
     */
    public function getHoldings(string $address): array
    {
        $positions = [];
        $errors = [];

        foreach (self::MARKETS as $chain => $market) {
            if (! isset($this->rpcUrls[$chain])) {
                continue;
            }

            $rpc = new EvmRpcClient($this->rpcUrls[$chain]);
            $result = $this->getMarketPosition($rpc, $address, $market['comet']);

            if (is_string($result)) {
                $errors[$chain] = $result;

                continue;
            }

            $hasPosition = ! AbiCodec::isZero($result['supplied'])
                || ! AbiCodec::isZero($result['borrowed'])
                || ! empty($result['collateral']);

            if (! $hasPosition) {
                continue;
            }

            $positions[$chain] = [
                'base' => $market['base'],
                'market' => $market['comet'],
                'supplied' => $result['suppliedFormatted'],
                'borrowed' => $result['borrowedFormatted'],
                'collateral' => $result['collateral'],
            ];
        }

        return ['positions' => $positions, 'errors' => $errors];
    }

    /**
     * @return array{supplied: string, suppliedFormatted: string, borrowed: string,
     *               borrowedFormatted: string, collateral: list<array>}|string
     *         Array on success (raw + formatted decimal-string amounts), or an
     *         EvmRpcClient::ERROR_* constant on RPC failure.
     */
    private function getMarketPosition(EvmRpcClient $rpc, string $wallet, string $comet): array|string
    {
        $walletEnc = AbiCodec::encodeAddress($wallet);

        $decimalsRaw = $rpc->ethCall($comet, self::SEL_DECIMALS);

        if ($decimalsRaw === EvmRpcClient::ERROR_UPSTREAM) {
            return EvmRpcClient::ERROR_UPSTREAM;
        }

        $baseDecimals = hexdec(AbiCodec::strip0x($decimalsRaw));

        $suppliedRaw = $rpc->ethCall($comet, self::SEL_BALANCE_OF . $walletEnc);
        $borrowedRaw = $rpc->ethCall($comet, self::SEL_BORROW_BALANCE_OF . $walletEnc);
        $numAssetsRaw = $rpc->ethCall($comet, self::SEL_NUM_ASSETS);

        if (
            $suppliedRaw === EvmRpcClient::ERROR_UPSTREAM
            || $borrowedRaw === EvmRpcClient::ERROR_UPSTREAM
            || $numAssetsRaw === EvmRpcClient::ERROR_UPSTREAM
        ) {
            return EvmRpcClient::ERROR_UPSTREAM;
        }

        $supplied = AbiCodec::hexToDec(AbiCodec::strip0x($suppliedRaw));
        $borrowed = AbiCodec::hexToDec(AbiCodec::strip0x($borrowedRaw));
        $numAssets = hexdec(AbiCodec::strip0x($numAssetsRaw));

        $collateral = [];

        for ($i = 0; $i < $numAssets; $i++) {
            $infoResult = $rpc->ethCall($comet, self::SEL_GET_ASSET_INFO . AbiCodec::encodeUint($i));

            if ($infoResult === EvmRpcClient::ERROR_UPSTREAM) {
                return EvmRpcClient::ERROR_UPSTREAM;
            }

            $infoWords = AbiCodec::words($infoResult);
            // tuple layout: [offset, asset, priceFeed, scale, borrowCF, liquidateCF, liquidationFactor, supplyCap]
            $assetAddr = AbiCodec::wordToAddress($infoWords[1]);

            $collResult = $rpc->ethCall(
                $comet,
                self::SEL_USER_COLLATERAL . $walletEnc . AbiCodec::encodeAddress($assetAddr)
            );

            if ($collResult === EvmRpcClient::ERROR_UPSTREAM) {
                return EvmRpcClient::ERROR_UPSTREAM;
            }

            $collWords = AbiCodec::words($collResult);
            $balance = AbiCodec::hexToDec($collWords[0]);

            if (AbiCodec::isZero($balance)) {
                continue;
            }

            $tokenDecimalsRaw = $rpc->ethCall($assetAddr, self::SEL_DECIMALS);
            $symbolRaw = $rpc->ethCall($assetAddr, self::SEL_SYMBOL);

            if ($tokenDecimalsRaw === EvmRpcClient::ERROR_UPSTREAM || $symbolRaw === EvmRpcClient::ERROR_UPSTREAM) {
                return EvmRpcClient::ERROR_UPSTREAM;
            }

            $tokenDecimals = hexdec(AbiCodec::strip0x($tokenDecimalsRaw));
            $symbol = AbiCodec::decodeString($symbolRaw);

            $collateral[] = [
                'symbol' => $symbol,
                'amount' => AbiCodec::formatUnits($balance, $tokenDecimals),
            ];
        }

        return [
            'supplied' => $supplied,
            'suppliedFormatted' => AbiCodec::formatUnits($supplied, $baseDecimals),
            'borrowed' => $borrowed,
            'borrowedFormatted' => AbiCodec::formatUnits($borrowed, $baseDecimals),
            'collateral' => $collateral,
        ];
    }
}
