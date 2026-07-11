<?php

namespace App;

use PierreMiniggio\GithubActionRunStarterAndArtifactDownloader\GithubActionRunStarterAndArtifactDownloaderFactory;
use Throwable;

/**
 * Triggers the 'pierreminiggio/sui-navi-report' repo's historical reconstruction workflow
 * (wallet-reconstruct.yml) for a given SUI wallet address and target date, waits for the run
 * to finish, and returns the decoded contents of the reconstruction-result.json artifact it
 * produces: { newCursor: {checkpoint, balances}, dailySnapshots: [{date, report}, ...] }. See
 * that project's own README/AGENTS.md for why wallet-coin reconstruction is a resumable,
 * sequential walk (hence resumeCheckpoint/resumeWalletBalances below) while NAVI positions
 * are not.
 */
class SuiWalletReconstructionActionClient
{
    private const OWNER = 'pierreminiggio';
    private const REPO = 'sui-navi-report';
    private const WORKFLOW_FILE = 'wallet-reconstruct.yml';
    private const REF = 'main';

    // A reconstruction run walks real on-chain history (potentially many months of it, one
    // GraphQL round trip per page of transactions plus a couple of direct reads per NAVI
    // asset per day crossed) rather than a single live fetch, so this is polled far less
    // tightly than the ~10-20s live report -- per the project owner, a run spanning a few
    // days' worth of wallet transactions takes on the order of a minute.
    private const REFRESH_TIME_SECONDS = 60;

    public const ERROR_RUN_FAILED = 'run_failed';
    public const ERROR_NO_ARTIFACT = 'no_artifact';
    public const ERROR_INVALID_JSON = 'invalid_json';

    public function __construct(private string $githubToken)
    {
    }

    /**
     * @param array<string, string>|null $resumeWalletBalances coinType -> raw balance, from a
     *                                                          prior run's newCursor.balances.
     *                                                          Null to start from genesis.
     * @return array{newCursor: array{checkpoint: int, balances: array<string, string>},
     *               dailySnapshots: array<int, array{date: string, report: array<string, mixed>}>}|string
     *         The decoded reconstruction-result.json contents on success, or one of the
     *         ERROR_* constants above on failure.
     */
    public function reconstruct(
        string $address,
        string $targetDate,
        ?int $resumeCheckpoint,
        ?array $resumeWalletBalances
    ): array|string {
        $actionRunner = (new GithubActionRunStarterAndArtifactDownloaderFactory())->make();

        $inputs = [
            'wallet_address' => $address,
            'target_date' => $targetDate
        ];

        // Workflow inputs are omitted entirely rather than passed as null/empty when there's
        // no resume state, so the workflow's own "omit to start from genesis" default (see
        // wallet-reconstruct.yml) applies -- matching normal GitHub Actions workflow_dispatch
        // behavior for unset optional inputs.
        if ($resumeCheckpoint !== null) {
            $inputs['resume_checkpoint'] = (string) $resumeCheckpoint;
        }

        if ($resumeWalletBalances !== null) {
            $inputs['resume_wallet_balances'] = json_encode($resumeWalletBalances);
        }

        try {
            // Signature: token, owner, repo, workflowFile, refreshTime, <undocumented,
            // kept at the README example's value of 0, matching SuiWalletReportActionClient>,
            // inputs, ref, deleteAfterDownloading.
            $artifactPaths = $actionRunner->runActionAndGetArtifacts(
                $this->githubToken,
                self::OWNER,
                self::REPO,
                self::WORKFLOW_FILE,
                self::REFRESH_TIME_SECONDS,
                0,
                $inputs,
                self::REF,
                true
            );
        } catch (Throwable $e) {
            error_log(
                'sui-navi-report reconstruction run failed for ' . $address . ' up to '
                    . $targetDate . ': ' . $e->getMessage()
            );

            return self::ERROR_RUN_FAILED;
        }

        if (empty($artifactPaths)) {
            return self::ERROR_NO_ARTIFACT;
        }

        if (count($artifactPaths) > 1) {
            error_log(
                'sui-navi-report reconstruction action returned ' . count($artifactPaths)
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

        if (
            ! is_array($decoded)
            || json_last_error() !== JSON_ERROR_NONE
            || ! isset($decoded['newCursor'], $decoded['dailySnapshots'])
        ) {
            error_log(
                'sui-navi-report reconstruction artifact content was not valid: '
                    . substr($content, 0, 500)
            );

            return self::ERROR_INVALID_JSON;
        }

        return $decoded;
    }
}
