<?php

namespace App;

use InvalidArgumentException;

/**
 * Builds the holdings of one wallet on one chain at one block, in the same per-chain shape as
 * /holdings-now (MULTICHAIN-RECONSTRUCTION-PLAN.md, delivery 4):
 *
 *   {native: {symbol, amount}|null, tokens: [{symbol, contract, amount}],
 *    defi: {compound: [...], aave: {reserves, summary}|null, other: []}}
 *
 * HOW EACH PART IS READ (every read is pinned to $block, on an archive-capable provider):
 *  - native: eth_getBalance.
 *  - tokens: balanceOf(wallet) on EVERY discovered token, never summed Transfer deltas (a rebasing
 *    token grew 3.84% with no Transfer event: MULTICHAIN-HOLDINGS.md section 4). Logs are for
 *    discovery only. A token whose contract does not exist yet at the block is a verified zero
 *    (eth_getCode); a zero balance is omitted, like the live cache.
 *  - Compound: all markets of the chain through CompoundHoldingsClient (block parameter).
 *  - Aave: direct balanceOf on aTokens / debt tokens plus Pool.getUserAccountData through
 *    AaveHoldingsClient (block parameter): its DataProvider convenience methods are only valid
 *    after each chain's redeployment. Aave "usedAsCollateral" is therefore null here.
 *  - "other" (Zerion-sourced DeFi in the live cache) is always [] in a reconstruction.
 *
 * FAILURE POLICY: a read that cannot be trusted (provider error after the client's bounded
 * retries, a failing Compound/Aave market) fails the whole snapshot (EvmHoldingsSnapshotResult::
 * failed): never a partial one. The single exception is a TOKEN CONTRACT that cannot be read as an
 * ERC-20 at that block (reverts, has no balanceOf/decimals, returns nothing though it has code):
 * spam and broken contracts are routine among discovered tokens, and that is the contract's answer,
 * not an outage, so it is skipped and reported in getSkippedTokens(), not retried forever.
 *
 * One builder holds one EvmRpcClient per chain for its whole life, so the eth_getCode memo, the token
 * metadata and the Aave reserve list are paid once per walk, not once per day.
 */
class EvmHoldingsSnapshotBuilder
{
    /** Symbol of each supported chain's native coin, as the live cache (Zerion) reports it. */
    public const NATIVE_SYMBOLS = [
        EvmReconstructionChains::ETHEREUM => 'ETH',
        EvmReconstructionChains::BASE => 'ETH',
        EvmReconstructionChains::POLYGON => 'POL',
        EvmReconstructionChains::BSC => 'BNB',
    ];

    private const SEL_BALANCE_OF = '0x70a08231';
    private const SEL_DECIMALS = '0x313ce567';
    private const SEL_SYMBOL = '0x95d89b41';

    /** @var array<string, CompoundHoldingsClient> */
    private array $compound = [];

    /** @var array<string, AaveHoldingsClient> */
    private array $aave = [];

    /** @var array<string, array{decimals: int, symbol: string}|false> chain|token => metadata, false = unreadable */
    private array $metadata = [];

    /**
     * @param array<string, EvmRpcClient> $clients one archive-capable client per chain, keyed by chain
     *                                             (configure the retries: EvmRpcClient constructor)
     */
    public function __construct(private array $clients)
    {
    }

    /**
     * The shape of a chain the reconstruction cannot cover (decision 3): empty, plus a marker.
     *
     * @return array{native: null, tokens: list<never>, defi: array, reconstruction_supported: false}
     */
    public static function unsupportedChainShape(): array
    {
        return [
            'native' => null,
            'tokens' => [],
            'defi' => ['compound' => [], 'aave' => null, 'other' => []],
            'reconstruction_supported' => false,
        ];
    }

    /**
     * @param list<string> $tokens token contracts discovered by EvmLogScanner (any case)
     */
    public function snapshot(string $address, string $chain, int $block, array $tokens): EvmHoldingsSnapshotResult
    {
        if (! EvmReconstructionChains::isSupported($chain)) {
            throw new InvalidArgumentException("Chain \"{$chain}\" is not supported by the reconstruction");
        }

        if (! isset($this->clients[$chain])) {
            throw new InvalidArgumentException("No RPC client for chain \"{$chain}\"");
        }

        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $address) !== 1) {
            throw new InvalidArgumentException("Invalid wallet address \"{$address}\"");
        }

        $rpc = $this->clients[$chain];

        $native = $this->readNative($rpc, $chain, $address, $block);

        if (is_string($native)) {
            return EvmHoldingsSnapshotResult::failed($native);
        }

        $tokenList = $this->readTokens($rpc, $chain, $address, $block, $tokens);

        if (is_string($tokenList)) {
            return EvmHoldingsSnapshotResult::failed($tokenList);
        }

        [$tokenEntries, $skipped] = $tokenList;

        $this->compound[$chain] ??= new CompoundHoldingsClient([$chain => $rpc->getRpcUrl()], static fn (string $url): EvmRpcClient => $rpc);
        $this->aave[$chain] ??= new AaveHoldingsClient([$chain => $rpc->getRpcUrl()], static fn (string $url): EvmRpcClient => $rpc);

        $compound = $this->compound[$chain]->getHoldings($address, $block);

        if ($compound['errors'] !== []) {
            return EvmHoldingsSnapshotResult::failed('Compound read failed: ' . json_encode($compound['errors']) . $this->lastErrorSuffix($rpc));
        }

        $aave = $this->aave[$chain]->getHoldings($address, $block);

        if ($aave['errors'] !== []) {
            return EvmHoldingsSnapshotResult::failed('Aave read failed: ' . json_encode($aave['errors']) . $this->lastErrorSuffix($rpc));
        }

        return EvmHoldingsSnapshotResult::ok([
            'native' => $native,
            'tokens' => $tokenEntries,
            'defi' => [
                'compound' => $compound['positions'][$chain] ?? [],
                'aave' => $aave['positions'][$chain] ?? null,
                'other' => [],
            ],
        ], $skipped);
    }

    /**
     * @return array{symbol: string, amount: string}|null|string null for a zero balance; a string is an error
     */
    private function readNative(EvmRpcClient $rpc, string $chain, string $address, int $block): array|string|null
    {
        $balance = $rpc->getBalance($address, $block);

        if ($balance instanceof EvmRpcError) {
            return "native balance read failed at block {$block}: {$balance}";
        }

        $wei = AbiCodec::hexToDec(AbiCodec::strip0x($balance));

        if (AbiCodec::isZero($wei)) {
            return null;
        }

        return ['symbol' => self::NATIVE_SYMBOLS[$chain], 'amount' => AbiCodec::formatUnits($wei, 18)];
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{0: list<array{symbol: string, contract: string, amount: string}>, 1: list<array{contract: string, reason: string}>}|string
     */
    private function readTokens(EvmRpcClient $rpc, string $chain, string $address, int $block, array $tokens): array|string
    {
        $walletEnc = AbiCodec::encodeAddress($address);
        $entries = [];
        $skipped = [];

        $tokens = array_values(array_unique(array_map('strtolower', $tokens)));
        sort($tokens);

        foreach ($tokens as $token) {
            $deployed = $rpc->hasCodeAt($token, $block);

            if ($deployed instanceof EvmRpcError) {
                return "eth_getCode failed for {$token} at block {$block}: {$deployed}";
            }

            if (! $deployed) {
                continue; // not created yet at that block: a verified zero
            }

            $balance = $this->call($rpc, $token, self::SEL_BALANCE_OF . $walletEnc, $block);

            if ($balance['status'] === 'error') {
                return "balanceOf failed for {$token} at block {$block}: {$balance['error']}";
            }

            if ($balance['status'] === 'empty' && $this->confirmedCodeAt($rpc, $token, $block) === null) {
                return "balanceOf returned nothing for {$token} at block {$block} and eth_getCode could not confirm it: {$rpc->getLastError()}";
            }

            if ($balance['status'] !== 'ok' || strlen(AbiCodec::strip0x($balance['value'])) !== 64) {
                $skipped[] = ['contract' => $token, 'reason' => 'balanceOf is not readable on this contract (' . $balance['status'] . ')'];

                continue;
            }

            $raw = AbiCodec::hexToDec(AbiCodec::strip0x($balance['value']));

            if (AbiCodec::isZero($raw)) {
                continue;
            }

            $metadata = $this->tokenMetadata($rpc, $chain, $token, $block);

            if (is_string($metadata)) {
                return $metadata;
            }

            if ($metadata === false) {
                $skipped[] = ['contract' => $token, 'reason' => 'decimals() is not readable on this contract'];

                continue;
            }

            $entries[] = [
                'symbol' => $metadata['symbol'],
                'contract' => $token,
                'amount' => AbiCodec::formatUnits($raw, $metadata['decimals']),
            ];
        }

        return [$entries, $skipped];
    }

    /**
     * @return array{decimals: int, symbol: string}|false|string false: not an ERC-20; a string is an error
     */
    private function tokenMetadata(EvmRpcClient $rpc, string $chain, string $token, int $block): array|false|string
    {
        $key = $chain . '|' . $token;

        if (isset($this->metadata[$key])) {
            return $this->metadata[$key];
        }

        $decimals = $this->call($rpc, $token, self::SEL_DECIMALS, $block);

        if ($decimals['status'] === 'error') {
            return "decimals failed for {$token} at block {$block}: {$decimals['error']}";
        }

        if ($decimals['status'] !== 'ok' || strlen(AbiCodec::strip0x($decimals['value'])) !== 64) {
            return $this->metadata[$key] = false;
        }

        $decimalsValue = hexdec(AbiCodec::strip0x($decimals['value']));

        if ($decimalsValue > 77) {
            return $this->metadata[$key] = false; // beyond a uint256's digits: not a sane ERC-20
        }

        $symbol = 'UNKNOWN';
        $symbolCall = $this->call($rpc, $token, self::SEL_SYMBOL, $block);

        if ($symbolCall['status'] === 'error') {
            return "symbol failed for {$token} at block {$block}: {$symbolCall['error']}";
        }

        if ($symbolCall['status'] === 'ok') {
            $decoded = self::decodeSymbol($symbolCall['value']);
            $symbol = $decoded !== '' ? $decoded : $symbol;
        }

        return $this->metadata[$key] = ['decimals' => (int) $decimalsValue, 'symbol' => $symbol];
    }

    /**
     * One eth_call with the outcomes a token probe needs to tell apart.
     *
     * @return array{status: 'ok', value: string}|array{status: 'revert'|'empty'}|array{status: 'error', error: EvmRpcError|string}
     */
    private function call(EvmRpcClient $rpc, string $to, string $data, int $block): array
    {
        $result = $rpc->request('eth_call', [['to' => $to, 'data' => $data], EvmRpcClient::blockParam($block)]);

        if ($result instanceof EvmRpcError) {
            return $result->isRevert() ? ['status' => 'revert'] : ['status' => 'error', 'error' => $result];
        }

        if (! is_string($result) || preg_match('/^0x[0-9a-fA-F]*$/', $result) !== 1) {
            return ['status' => 'error', 'error' => 'eth_call result is not hex'];
        }

        return $result === '0x' ? ['status' => 'empty'] : ['status' => 'ok', 'value' => $result];
    }

    /**
     * Not memoized: an empty answer from a contract the memo says exists is exactly the case where
     * a stale "deployed" memo or a flaky provider could hide a real failure, so ask for this block.
     *
     * @return true|null null when the provider failed or the contract has no code at that block
     */
    private function confirmedCodeAt(EvmRpcClient $rpc, string $token, int $block): ?bool
    {
        $code = $rpc->getCode($token, $block);

        return (! ($code instanceof EvmRpcError) && $code !== '0x') ? true : null;
    }

    /**
     * ERC-20 symbol(): a dynamic string, or (older tokens such as MKR) a bytes32.
     */
    public static function decodeSymbol(string $hex): string
    {
        $raw = AbiCodec::strip0x($hex);

        if (strlen($raw) === 64) {
            $bytes = hex2bin($raw);

            return $bytes === false ? '' : self::printable(rtrim($bytes, "\0"));
        }

        if (strlen($raw) < 128) {
            return '';
        }

        $length = hexdec(substr($raw, 64, 64));

        if ($length < 1 || $length > 64 || strlen($raw) < 128 + $length * 2) {
            return '';
        }

        $bytes = hex2bin(substr($raw, 128, $length * 2));

        return $bytes === false ? '' : self::printable($bytes);
    }

    private static function printable(string $text): string
    {
        // Symbols come from arbitrary contracts: keep only printable ASCII.
        return trim((string) preg_replace('/[^\x20-\x7E]/', '', $text));
    }

    private function lastErrorSuffix(EvmRpcClient $rpc): string
    {
        $error = $rpc->getLastError();

        return $error === null ? '' : ' (last RPC error: ' . $error . ')';
    }
}
