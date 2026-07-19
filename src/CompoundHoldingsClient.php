<?php

namespace App;

/**
 * Reads a wallet's Compound III (Comet) positions directly from the on-chain contracts
 * via eth_call, across every market in self::MARKETS. No third-party API/key needed.
 *
 * Compound III has one isolated market contract per (chain, base asset) pair -- e.g.
 * "the USDC market on Base" -- rather than one shared pool like Aave. A chain can (and
 * often does) have several such markets simultaneously; a wallet's position is scoped
 * per market, and a wallet can hold positions in more than one market on the same
 * chain at once. Each market's position: the wallet can supply the base asset (earning
 * interest) OR borrow it against collateral, plus post multiple collateral assets.
 *
 * self::MARKETS is keyed by chain, each holding a *list* of markets for that chain (not
 * a single market -- see MULTICHAIN-HOLDINGS.md for why this matters and which markets
 * have been directly tested vs. added from Compound's official registry but not yet
 * verified against a real position). Pull additional "comet" proxy addresses from
 * https://github.com/compound-finance/comet/tree/main/deployments/<chain>/<asset>/roots.json
 * or the official markets list referenced in Compound's own governance proposals.
 *
 * CAUTION: Comet addresses are not guaranteed unique *across* chains (factory/CREATE2
 * deployments can and do land on the same address on two different chains for two
 * completely unrelated markets -- confirmed true for at least one address in this
 * file). Always resolve an address in the context of the specific chain it's under;
 * never assume seeing the same address elsewhere means the same market.
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
    // Pulled from https://github.com/compound-finance/comet/tree/main/deployments and
    // cross-checked against Compound's official markets list embedded in its own
    // governance proposals where noted below.
    private const MARKETS = [
        'ethereum' => [
            ['base' => 'USDC', 'comet' => '0xc3d688B66703497DAA19211EEdff47f25384cdc3'],
        ],
        'base' => [
            ['base' => 'USDC', 'comet' => '0xb125E6687d4313864e53df431d5425969c15Eb2F'],
            // Tested directly against a real wallet position (sUSDS/cbBTC collateral,
            // USDS borrow) -- see MULTICHAIN-HOLDINGS.md.
            ['base' => 'USDS', 'comet' => '0x2c776041CCFe903071AF44aa147368a9c8EEA518'],
            // NOT tested against a real wallet position -- added from Compound's
            // official registry only. The discovery mechanism (getMarketPosition
            // below) is generic and has been proven correct against two other real,
            // different-shaped markets already, but this specific market's numbers
            // have not been independently cross-checked the way the others have. See
            // MULTICHAIN-HOLDINGS.md before treating this one as fully verified.
            ['base' => 'USDbC', 'comet' => '0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf'],
        ],
        'polygon' => [
            ['base' => 'USDC', 'comet' => '0xF25212E676D1F7F89Cd72fFEe66158f541246445'],
            // Tested directly against a real wallet position (WETH/WBTC collateral,
            // USDT0 borrow) -- see MULTICHAIN-HOLDINGS.md.
            ['base' => 'USDT0', 'comet' => '0xaeB318360f27748Acb200CE616E389A6C9409a07'],
        ],
        'arbitrum' => [
            ['base' => 'USDC', 'comet' => '0x9c4ec768c28520B50860ea7a15bd7213a9fF58bf'],
        ],
        'optimism' => [
            ['base' => 'USDC', 'comet' => '0x2e44e174f7D53F0212823acC11C01A11d58c5bCB'],
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
     * @return array{positions: array<string, list<array>>, errors: array<string, string>}
     *         "positions" maps chain => list of this wallet's non-zero positions on
     *         that chain (one entry per market with an actual position; a chain with
     *         several active markets gets several entries, a chain with none is
     *         omitted entirely rather than present with an empty list).
     *         "errors" is keyed "{chain}/{base}" (not just "{chain}") since a single
     *         chain can now have multiple markets and one market's RPC failure
     *         shouldn't be indistinguishable from another's, or silently block markets
     *         on the same chain that succeeded.
     */
    public function getHoldings(string $address): array
    {
        $positions = [];
        $errors = [];

        foreach (self::MARKETS as $chain => $markets) {
            if (! isset($this->rpcUrls[$chain])) {
                continue;
            }

            $rpc = new EvmRpcClient($this->rpcUrls[$chain]);

            foreach ($markets as $market) {
                $result = $this->getMarketPosition($rpc, $address, $market['comet']);

                if (is_string($result)) {
                    $errors["{$chain}/{$market['base']}"] = $result;

                    continue;
                }

                $hasPosition = ! AbiCodec::isZero($result['supplied'])
                    || ! AbiCodec::isZero($result['borrowed'])
                    || ! empty($result['collateral']);

                if (! $hasPosition) {
                    continue;
                }

                $positions[$chain] ??= [];
                $positions[$chain][] = [
                    'base' => $market['base'],
                    'market' => $market['comet'],
                    'supplied' => $result['suppliedFormatted'],
                    'borrowed' => $result['borrowedFormatted'],
                    'collateral' => $result['collateral'],
                ];
            }
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
