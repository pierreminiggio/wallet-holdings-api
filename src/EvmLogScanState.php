<?php

namespace App;

use InvalidArgumentException;

/**
 * The resumable state of one (wallet, chain) log scan: exactly what the cursor table keeps
 * (MULTICHAIN-RECONSTRUCTION-PLAN.md, Table 2: last_scanned_block, step_size, tokens_json,
 * first_activity_block), so an interrupted scan resumes where it stopped.
 *
 * INVARIANT (the "no hole at a resume boundary" rule): lastScannedBlock is the highest block
 * whose logs were fully and successfully fetched, or deliberately covered (a hardcoded
 * skipped block, or a recorded unresolved block). EvmLogScanner moves it only AFTER a chunk's
 * calls all succeeded, and updates the token sets in the same step, so a state is never ahead
 * of what it has actually seen.
 *
 * Discovery only: addresses, never balances. Balances always come from balanceOf at the
 * snapshot block, never from summed Transfer deltas (MULTICHAIN-HOLDINGS.md, section 4).
 */
final class EvmLogScanState
{
    public const DEFAULT_STEP_SIZE = 40000;

    /** @var array<string, true> contracts that emitted ERC-20-shaped Transfers (3 topics) touching the wallet; NFT-shaped ones (4 topics) are ignored by design */
    private array $tokens = [];

    /**
     * @param list<string> $tokens
     */
    public function __construct(
        private int $lastScannedBlock,
        private int $stepSize = self::DEFAULT_STEP_SIZE,
        array $tokens = [],
        private ?int $firstLogBlock = null,
    ) {
        if ($stepSize < 1) {
            throw new InvalidArgumentException('Step size must be at least 1');
        }

        foreach ($tokens as $token) {
            $this->tokens[strtolower($token)] = true;
        }

    }

    /**
     * A fresh scan whose first block to fetch is $firstBlock (the chain's history start).
     */
    public static function startingAt(int $firstBlock, int $stepSize = self::DEFAULT_STEP_SIZE): self
    {
        if ($firstBlock < 0) {
            throw new InvalidArgumentException('First block cannot be negative');
        }

        return new self($firstBlock - 1, $stepSize);
    }

    /**
     * @param array<string, mixed> $data As produced by toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['last_scanned_block'],
            (int) ($data['step_size'] ?? self::DEFAULT_STEP_SIZE),
            array_values((array) ($data['tokens'] ?? [])),
            isset($data['first_log_block']) ? (int) $data['first_log_block'] : null,
        );
    }

    /**
     * @return array{last_scanned_block: int, step_size: int, tokens: list<string>, first_log_block: ?int}
     */
    public function toArray(): array
    {
        return [
            'last_scanned_block' => $this->lastScannedBlock,
            'step_size' => $this->stepSize,
            'tokens' => $this->tokens(),
            'first_log_block' => $this->firstLogBlock,
        ];
    }

    public function getLastScannedBlock(): int
    {
        return $this->lastScannedBlock;
    }

    public function getStepSize(): int
    {
        return $this->stepSize;
    }

    /** Lowest block where any matching Transfer log was seen so far (null: none yet). */
    public function getFirstLogBlock(): ?int
    {
        return $this->firstLogBlock;
    }

    /**
     * @return list<string> lower-cased, sorted
     */
    public function tokens(): array
    {
        $tokens = array_keys($this->tokens);
        sort($tokens);

        return $tokens;
    }

    public function addToken(string $address): void
    {
        $this->tokens[strtolower($address)] = true;
    }

    public function noteLogBlock(int $block): void
    {
        if ($this->firstLogBlock === null || $block < $this->firstLogBlock) {
            $this->firstLogBlock = $block;
        }
    }

    public function advanceTo(int $block): void
    {
        if ($block <= $this->lastScannedBlock) {
            throw new InvalidArgumentException("Cannot move last scanned block from {$this->lastScannedBlock} to {$block}");
        }

        $this->lastScannedBlock = $block;
    }

    public function setStepSize(int $stepSize): void
    {
        if ($stepSize < 1) {
            throw new InvalidArgumentException('Step size must be at least 1');
        }

        $this->stepSize = $stepSize;
    }
}
