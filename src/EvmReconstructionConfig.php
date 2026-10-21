<?php

namespace App;

use InvalidArgumentException;

/**
 * Reads the "reconstruction" section of config.php: the archive- and log-capable RPC
 * providers the historical reconstruction uses, and the lock directory.
 *
 * Kept apart from App::DEFAULT_RPC_URLS and the "rpc" config section on purpose: those are
 * keyless publicnode-style endpoints that only serve "latest" reads for /holdings-now,
 * while the reconstruction needs archive access and eth_getLogs, which is chain-specific
 * (MULTICHAIN-HOLDINGS.md, "Per-chain RPC config for reconstruction").
 *
 * Like App::DEFAULT_RPC_URLS, DEFAULT_PROVIDERS gives a code-level fallback, so an existing
 * config.php created before this section existed still works for the chains that have a
 * keyless option. Configuring a chain REPLACES its defaults (it does not extend them).
 * BSC has no keyless archive provider, so it has no default and needs a keyed one (NodeReal).
 */
final class EvmReconstructionConfig
{
    /**
     * Keyless defaults. Real eth_getLogs caps are far below what these providers claim
     * (measured in Oct 2026 with bin/check-providers.php: under 200 blocks on drpc for Ethereum, Base and
     * Polygon alike, and Ethereum there also times out most requests; NodeReal accepts 50,000 on BNB Smart Chain): the
     * scanner adapts, it does not trust a configured number.
     *
     * @var array<string, list<array{name: string, url: string}>>
     */
    public const DEFAULT_PROVIDERS = [
        EvmReconstructionChains::ETHEREUM => [['name' => 'drpc', 'url' => 'https://eth.drpc.org']],
        EvmReconstructionChains::BASE => [['name' => 'drpc', 'url' => 'https://base.drpc.org']],
        EvmReconstructionChains::POLYGON => [['name' => 'drpc', 'url' => 'https://polygon.drpc.org']],
    ];

    public const DEFAULT_LOCK_DIRECTORY_NAME = 'wallet-holdings-api-locks';

    /** @var array<string, list<array{name: string, url: string}>> */
    private array $providers = [];

    private string $lockDirectory;

    /**
     * @param array<string, mixed> $config The whole config.php array.
     *
     * @throws InvalidArgumentException on a malformed "reconstruction" section.
     */
    public function __construct(array $config)
    {
        $section = $config['reconstruction'] ?? [];

        if (! is_array($section)) {
            throw new InvalidArgumentException('config "reconstruction" must be an array');
        }

        $this->providers = self::DEFAULT_PROVIDERS;

        $rpc = $section['rpc'] ?? [];

        if (! is_array($rpc)) {
            throw new InvalidArgumentException('config "reconstruction.rpc" must be an array');
        }

        foreach ($rpc as $chain => $entries) {
            if (! EvmReconstructionChains::isSupported((string) $chain)) {
                throw new InvalidArgumentException("config reconstruction.rpc: unknown or unsupported chain \"{$chain}\"");
            }

            $this->providers[$chain] = self::parseProviders((string) $chain, $entries);
        }

        $lockDirectory = $section['lock_directory'] ?? '';

        $this->lockDirectory = is_string($lockDirectory) && $lockDirectory !== ''
            ? rtrim($lockDirectory, '/\\')
            : rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_LOCK_DIRECTORY_NAME;
    }

    /**
     * The chain's providers in priority order (the first is the primary, the others are
     * fallbacks). Empty when the chain has none configured (BSC without a NodeReal key).
     *
     * @return list<array{name: string, url: string}>
     */
    public function providers(string $chain): array
    {
        return $this->providers[$chain] ?? [];
    }

    /**
     * Directory holding the per-(chain, provider) lock files (plan, decision 5). Falls back
     * to a directory under the system temp dir when config.php does not set one.
     */
    public function lockDirectory(): string
    {
        return $this->lockDirectory;
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private static function parseProviders(string $chain, mixed $entries): array
    {
        if (is_string($entries)) {
            $entries = [$entries];
        }

        if (! is_array($entries) || $entries === []) {
            throw new InvalidArgumentException("config reconstruction.rpc.{$chain} must be a non-empty list");
        }

        // A single {name, url} map is accepted as a list of one.
        if (isset($entries['url'])) {
            $entries = [$entries];
        }

        $providers = [];
        $names = [];

        foreach ($entries as $entry) {
            $name = null;
            $url = $entry;

            if (is_array($entry)) {
                $url = $entry['url'] ?? null;
                $name = $entry['name'] ?? null;
            }

            if (! is_string($url) || preg_match('#^https?://[^\s]+$#i', $url) !== 1) {
                throw new InvalidArgumentException("config reconstruction.rpc.{$chain}: invalid provider url");
            }

            if ($name === null) {
                $name = self::nameFromUrl($url);
            }

            // The name ends up in a lock file name, so keep it to a safe alphabet.
            if (! is_string($name) || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
                throw new InvalidArgumentException(
                    "config reconstruction.rpc.{$chain}: provider name must match [a-z0-9][a-z0-9_-]*"
                );
            }

            if (isset($names[$name])) {
                throw new InvalidArgumentException("config reconstruction.rpc.{$chain}: duplicate provider name \"{$name}\"");
            }

            $names[$name] = true;
            $providers[] = ['name' => $name, 'url' => $url];
        }

        return $providers;
    }

    /**
     * "https://eth-mainnet.nodereal.io/v1/KEY" -> "nodereal"; an unrecognisable host falls
     * back to the sanitised host. The URL's path (which often holds the API key) is never used.
     */
    private static function nameFromUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $labels = array_values(array_filter(explode('.', $host), static fn (string $l): bool => $l !== ''));

        $candidate = count($labels) >= 2 ? $labels[count($labels) - 2] : ($labels[0] ?? 'provider');
        $candidate = preg_replace('/[^a-z0-9_-]+/', '-', $candidate);
        $candidate = trim((string) $candidate, '-_');

        return $candidate === '' ? 'provider' : $candidate;
    }
}
