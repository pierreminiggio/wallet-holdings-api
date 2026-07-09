<?php

namespace App;

use PierreMiniggio\GithubActionRunStarterAndArtifactDownloader\GithubActionRunStarterAndArtifactDownloaderFactory;
use Throwable;

/**
 * Triggers the 'pierreminiggio/sui-navi-report' GitHub Action for a given SUI wallet
 * address, waits for the run to finish, and returns the decoded contents of the
 * wallet-report.json artifact it produces (see that project's own README for the exact
 * report schema). There's no free/keyless API for this data the way there is for the
 * EVM networks (Routescan/Blockscout), so this always means one live Actions run.
 */
class SuiWalletReportActionClient
{
    private const OWNER = 'pierreminiggio';
    private const REPO = 'sui-navi-report';
    private const WORKFLOW_FILE = 'wallet-report.yml';
    private const INPUT_NAME = 'wallet_address';
    private const REF = 'main';

    // How often the underlying package polls the run's status while waiting for it to
    // finish, in seconds. The workflow itself only takes ~10-20s per its own README, so
    // this doesn't need to be tight.
    private const REFRESH_TIME_SECONDS = 30;

    public const ERROR_RUN_FAILED = 'run_failed';
    public const ERROR_NO_ARTIFACT = 'no_artifact';
    public const ERROR_INVALID_JSON = 'invalid_json';

    public function __construct(private string $githubToken)
    {
    }

    /**
     * @return array<string, mixed>|string The decoded wallet-report.json contents on
     *                                       success, or one of the ERROR_* constants
     *                                       above on failure.
     */
    public function fetchReport(string $address): array|string
    {
        $actionRunner = (new GithubActionRunStarterAndArtifactDownloaderFactory())->make();

        try {
            // Signature: token, owner, repo, workflowFile, refreshTime, <undocumented,
            // kept at the README example's value of 0>, inputs, ref, deleteAfterDownloading.
            $artifactPaths = $actionRunner->runActionAndGetArtifacts(
                $this->githubToken,
                self::OWNER,
                self::REPO,
                self::WORKFLOW_FILE,
                self::REFRESH_TIME_SECONDS,
                0,
                [self::INPUT_NAME => $address],
                self::REF,
                true
            );
        } catch (Throwable $e) {
            error_log('sui-navi-report action run failed for ' . $address . ': ' . $e->getMessage());

            return self::ERROR_RUN_FAILED;
        }

        if (empty($artifactPaths)) {
            return self::ERROR_NO_ARTIFACT;
        }

        if (count($artifactPaths) > 1) {
            // The workflow is only supposed to produce one artifact (wallet-report.json).
            // Not fatal on its own -- just use the first one and note it, rather than
            // fail a request over something that doesn't actually block reading the data.
            error_log(
                'sui-navi-report action returned ' . count($artifactPaths)
                    . ' artifact file paths, expected 1. Using the first one.'
            );
        }

        $artifactPath = $artifactPaths[0];

        try {
            $content = is_file($artifactPath) ? file_get_contents($artifactPath) : false;
        } finally {
            if (is_file($artifactPath)) {
                unlink($artifactPath);
            }
        }

        if ($content === false) {
            return self::ERROR_NO_ARTIFACT;
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            error_log('sui-navi-report artifact content was not valid JSON: ' . substr($content, 0, 500));

            return self::ERROR_INVALID_JSON;
        }

        return $decoded;
    }
}
