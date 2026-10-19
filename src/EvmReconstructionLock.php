<?php

namespace App;

use RuntimeException;

/**
 * One file lock per (chain, provider) (MULTICHAIN-RECONSTRUCTION-PLAN.md, decision 5): the free
 * providers' rate limits are shared by every request, so two walks hammering the same provider at
 * once just throttle each other. A request that cannot take every lock it needs is told to come back
 * (409 with Retry-After in delivery 6): it is contention, not a job status.
 *
 * flock() on a lock file, non-blocking, directory from config ("reconstruction.lock_directory").
 * The OS releases an flock when the process dies, so a crashed or killed walk never leaves a stale
 * lock behind. ASSUMES A SINGLE WEB SERVER (shared-hosting style): flock does not coordinate several
 * instances; revisit if the API is ever deployed on more than one.
 */
class EvmReconstructionLock
{
    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(private string $directory)
    {
    }

    /**
     * @return bool true when the lock is held by this instance (already or newly); false when
     *              another process or instance holds it.
     *
     * @throws RuntimeException when the lock directory or file cannot be created or opened (a
     *                          deployment problem, not contention).
     */
    public function acquire(string $chain, string $provider): bool
    {
        $name = $chain . '--' . $provider;

        if (isset($this->handles[$name])) {
            return true;
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $chain) !== 1 || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $provider) !== 1) {
            throw new RuntimeException("Invalid lock name \"{$name}\"");
        }

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Cannot create the lock directory \"{$this->directory}\"");
        }

        $handle = @fopen($this->directory . DIRECTORY_SEPARATOR . $name . '.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the lock file for \"{$name}\" in \"{$this->directory}\"");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handles[$name] = $handle;

        return true;
    }

    public function releaseAll(): void
    {
        foreach ($this->handles as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->handles = [];
    }

    public function __destruct()
    {
        $this->releaseAll();
    }
}
