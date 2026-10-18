<?php

namespace App;

/**
 * A classified failure of one JSON-RPC call (see EvmRpcClient).
 *
 * Reconstruction needs to tell failures apart, because each class calls for a different
 * reaction (MULTICHAIN-HOLDINGS.md, "New PHP components needed" -> EvmLogScanner):
 *
 *  - KIND_TRANSIENT: rate limits, "Request timeout on the free plan", dropped
 *    connections, empty or malformed bodies, 5xx. The same input may well succeed on a
 *    bare retry, so back off and retry the SAME input; never shrink a block range for it.
 *  - KIND_RANGE_CAP: a deterministic block-range / result-size cap ("eth_getLogs is
 *    limited to a 2,000 range"). The same range fails the same way every time, so waiting
 *    is pure waste: shrink immediately, with no backoff.
 *  - KIND_AUTH: the provider cannot serve this call at all (bad/missing API key, archive
 *    access refused, pruned state). Neither waiting nor shrinking helps: treat the
 *    provider as unusable for that call.
 *
 * Anything unrecognised is classified KIND_TRANSIENT: an unknown failure is retried a
 * bounded number of times, never silently turned into an empty success.
 */
final class EvmRpcError
{
    public const KIND_TRANSIENT = 'transient';
    public const KIND_RANGE_CAP = 'range_cap';
    public const KIND_AUTH = 'auth';

    // Checked first: these providers' wordings must never fall into the shrink path.
    // "invalid params" comes from MULTICHAIN-HOLDINGS.md ("Auth errors are neither"): a
    // key-gated provider answering a call it will not serve. "missing trie node" /
    // "header not found" are the non-archive nodes' answer to an old-state read.
    private const AUTH_MARKERS = [
        'unauthorized', 'unauthorised', 'api key', 'apikey', 'api-key', 'forbidden',
        'authentication', 'archive requests', 'archive node', 'personal token',
        'invalid params', 'missing trie node', 'header not found', 'state is not available',
        'historical state', 'not allowed', 'access denied', 'upgrade your plan',
    ];

    // Checked second. Includes the wording "Request timeout on the free plan", which was
    // confirmed intermittent and size-independent (it failed on a single-block query and
    // then succeeded on a bare retry), so it is a transient, not a reason to shrink.
    private const TRANSIENT_MARKERS = [
        'too many requests', 'rate limit', 'rate-limit', 'ratelimit', 'request limit',
        'throttl', 'timeout', 'timed out', 'temporarily', 'try again', 'overloaded',
        'service unavailable', 'bad gateway', 'gateway', 'internal error', 'busy',
        'capacity', 'compute units', 'connection',
    ];

    // Checked last, only once the two lists above did not match.
    private const RANGE_CAP_MARKERS = [
        'limited to', 'ranges over', 'block range', 'range', 'exceed', 'more than',
        'too many results', 'too many logs', 'response size', 'result set', 'max results',
        'maximum', 'limit of', 'too large', 'too big', 'query returned',
    ];

    public function __construct(
        private string $kind,
        private string $message,
        private ?int $httpStatus = null,
        private ?int $rpcCode = null,
    ) {
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getRpcCode(): ?int
    {
        return $this->rpcCode;
    }

    public function isTransient(): bool
    {
        return $this->kind === self::KIND_TRANSIENT;
    }

    public function isRangeCap(): bool
    {
        return $this->kind === self::KIND_RANGE_CAP;
    }

    public function isAuth(): bool
    {
        return $this->kind === self::KIND_AUTH;
    }

    public function __toString(): string
    {
        return '[' . $this->kind . '] ' . $this->message;
    }

    /**
     * Builds the error for a JSON-RPC "error" member. The HTTP status, when known, is only
     * a fallback: many providers answer a range cap with HTTP 200 or 400 and put the real
     * information in the JSON body, so the body wins.
     */
    public static function fromJsonRpcError(mixed $error, ?int $httpStatus = null): self
    {
        $code = null;
        $message = '';

        if (is_array($error)) {
            if (isset($error['code']) && is_numeric($error['code'])) {
                $code = (int) $error['code'];
            }

            if (isset($error['message']) && is_string($error['message'])) {
                $message = $error['message'];
            }
        } elseif (is_string($error)) {
            $message = $error;
        }

        if ($message === '') {
            $message = 'JSON-RPC error without a message';
        }

        $kind = self::classifyMessage($message);

        if ($kind === self::KIND_TRANSIENT && ! self::matches(strtolower($message), self::TRANSIENT_MARKERS)) {
            // No marker matched at all: let the HTTP status refine the default, if it can.
            $kind = self::kindFromHttpStatus($httpStatus) ?? self::KIND_TRANSIENT;
        }

        return new self($kind, $message, $httpStatus, $code);
    }

    /**
     * Builds the error for a failure with no usable JSON-RPC body: a network failure
     * (status null or 0), an empty body, a non-JSON body, or a non-200 status.
     */
    public static function fromTransportFailure(?int $httpStatus, string $detail): self
    {
        $kind = self::kindFromHttpStatus($httpStatus) ?? self::KIND_TRANSIENT;

        return new self($kind, $detail, $httpStatus === 0 ? null : $httpStatus);
    }

    public static function classifyMessage(string $message): string
    {
        $lower = strtolower($message);

        if (self::matches($lower, self::AUTH_MARKERS)) {
            return self::KIND_AUTH;
        }

        if (self::matches($lower, self::TRANSIENT_MARKERS)) {
            return self::KIND_TRANSIENT;
        }

        if (self::matches($lower, self::RANGE_CAP_MARKERS)) {
            return self::KIND_RANGE_CAP;
        }

        return self::KIND_TRANSIENT;
    }

    private static function kindFromHttpStatus(?int $status): ?string
    {
        if ($status === null || $status === 0) {
            return self::KIND_TRANSIENT;
        }

        if ($status === 401 || $status === 403) {
            return self::KIND_AUTH;
        }

        if ($status === 413) {
            return self::KIND_RANGE_CAP;
        }

        if ($status === 408 || $status === 429 || $status >= 500) {
            return self::KIND_TRANSIENT;
        }

        return null;
    }

    /**
     * @param list<string> $markers
     */
    private static function matches(string $lowerMessage, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($lowerMessage, $marker)) {
                return true;
            }
        }

        return false;
    }
}
