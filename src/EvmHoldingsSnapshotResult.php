<?php

namespace App;

/**
 * The outcome of EvmHoldingsSnapshotBuilder::snapshot(): either a complete per-chain
 * structure (shaped exactly like one chain of a /holdings-now response) or a failure. There is
 * deliberately no "partial" success: a snapshot with a missing token or a missing DeFi read looks
 * complete and is wrong, so any read that cannot be trusted fails the whole snapshot.
 */
final class EvmHoldingsSnapshotResult
{
    /**
     * @param array{native: ?array, tokens: list<array>, defi: array}|null $holdings
     * @param list<array{contract: string, reason: string}> $skippedTokens contracts that cannot be read as
     *        ERC-20s at that block (reverting, no balanceOf/decimals): visible, but not a failure
     */
    private function __construct(
        private ?array $holdings,
        private array $skippedTokens,
        private ?string $error,
    ) {
    }

    /**
     * @param array{native: ?array, tokens: list<array>, defi: array} $holdings
     * @param list<array{contract: string, reason: string}> $skippedTokens
     */
    public static function ok(array $holdings, array $skippedTokens = []): self
    {
        return new self($holdings, $skippedTokens, null);
    }

    public static function failed(string $error): self
    {
        return new self(null, [], $error);
    }

    public function isOk(): bool
    {
        return $this->holdings !== null;
    }

    /**
     * @return array{native: ?array, tokens: list<array>, defi: array}|null
     */
    public function getHoldings(): ?array
    {
        return $this->holdings;
    }

    /** @return list<array{contract: string, reason: string}> */
    public function getSkippedTokens(): array
    {
        return $this->skippedTokens;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * True when the chain holds nothing at all at that block: no native balance, no token, no
     * Compound position, no Aave position. The live cache omits such chains.
     */
    public function isEmpty(): bool
    {
        return $this->holdings !== null
            && $this->holdings['native'] === null
            && $this->holdings['tokens'] === []
            && $this->holdings['defi']['compound'] === []
            && $this->holdings['defi']['aave'] === null;
    }
}
