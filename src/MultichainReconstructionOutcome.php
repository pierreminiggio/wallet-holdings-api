<?php

namespace App;

/**
 * What MultichainHoldingsReconstructionService::reconstruct() returns. Delivery 6 maps the status to
 * an HTTP response (same family as /sui-holdings):
 *
 *  - FOUND           200: getResponseJson() is the stored row for the requested date, as-is.
 *  - BEFORE_GENESIS  400: no supported chain has any activity by that date.
 *  - INCONSISTENT    500: the cursors say the date is walked but no row exists for it.
 *  - STORAGE_ERROR   500: the database or the lock directory failed (not an RPC problem).
 *  - PROVIDER_ERROR  502: RPC/provider failure after bounded retries. Progress is saved; retrying resumes.
 *  - BUSY            409 with Retry-After: another walk holds a lock this one needs.
 */
final class MultichainReconstructionOutcome
{
    public const STATUS_FOUND = 'found';
    public const STATUS_BEFORE_GENESIS = 'before_genesis';
    public const STATUS_INCONSISTENT = 'inconsistent';
    public const STATUS_STORAGE_ERROR = 'storage_error';
    public const STATUS_PROVIDER_ERROR = 'provider_error';
    public const STATUS_BUSY = 'busy';

    private function __construct(
        private string $status,
        private ?string $responseJson = null,
        private ?string $message = null,
        private ?int $retryAfterSeconds = null,
        private ?string $walkedThrough = null,
    ) {
    }

    public static function found(string $responseJson): self
    {
        return new self(self::STATUS_FOUND, $responseJson);
    }

    public static function beforeGenesis(string $message): self
    {
        return new self(self::STATUS_BEFORE_GENESIS, null, $message);
    }

    public static function inconsistent(string $message): self
    {
        return new self(self::STATUS_INCONSISTENT, null, $message);
    }

    public static function storageError(string $message): self
    {
        return new self(self::STATUS_STORAGE_ERROR, null, $message);
    }

    public static function providerError(string $message, ?string $walkedThrough = null): self
    {
        return new self(self::STATUS_PROVIDER_ERROR, null, $message, null, $walkedThrough);
    }

    public static function busy(string $message, int $retryAfterSeconds): self
    {
        return new self(self::STATUS_BUSY, null, $message, $retryAfterSeconds);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isFound(): bool
    {
        return $this->status === self::STATUS_FOUND;
    }

    public function getResponseJson(): ?string
    {
        return $this->responseJson;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /** For PROVIDER_ERROR: the last day whose rows and cursors were fully written (null: none yet). */
    public function getWalkedThrough(): ?string
    {
        return $this->walkedThrough;
    }
}
