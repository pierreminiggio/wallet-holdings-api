<?php

namespace App;

/**
 * A single position from Zerion's portfolio API, covering both wallet token holdings
 * (position_type = "wallet") and DeFi protocol positions (deposit, loan, staked, etc.).
 * Uses plain constructor promotion without readonly (readonly requires PHP 8.1+; this
 * project targets PHP 8.0).
 */
class ZerionPosition
{
    public function __construct(
        /** Zerion's chain identifier, e.g. "ethereum", "base", "polygon", "binance-smart-chain" */
        public string $chainId,
        public string $symbol,
        /** Human-readable decimal amount as a string; Zerion's "numeric" field, avoids float imprecision */
        public string $amount,
        /** Null for native coins (ETH, BNB, POL etc.) */
        public ?string $contractAddress,
        public bool $isNative,
        /** "wallet", "deposit", "loan", "staked", "locked", "reward", "investment" */
        public string $positionType,
        /** Protocol identifier (e.g. "aave-v3", "compound-v3") for DeFi positions, null for plain wallet holdings */
        public ?string $protocolId,
        /** Zerion's own updated_at for this position (ISO 8601), e.g. "2026-07-03T16:33:09Z" */
        public ?string $updatedAt = null,
        /** Zerion's block number when this position was last updated, for debugging */
        public ?int $updatedAtBlock = null
    ) {
    }
}
