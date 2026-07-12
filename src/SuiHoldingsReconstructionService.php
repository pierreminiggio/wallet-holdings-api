<?php

namespace App;

use DateTime;
use DateTimeZone;

/**
 * Orchestrates GET /sui-holdings/{address}?date=... cache misses: decides whether a miss
 * means "trigger a reconstruction run" or "this date will never be cached here" (see
 * OUTCOME_BEFORE_GENESIS vs OUTCOME_INCONSISTENT below), triggers reconstruct.js via
 * SuiWalletReconstructionActionClient when appropriate, and backfills every day between the
 * prior cursor and the target date into sui_holdings_cache -- not just the days
 * reconstruct.js actually returned a snapshot for -- so a later request for any date in that
 * range is a pure cache hit, never a second reconstruction run.
 *
 * See the sui-navi-report repo's AGENTS.md for why quiet days don't get their own snapshot
 * from the Action itself (it only writes one when something actually changed), and why
 * that's fine as long as this class backfills them here by carrying the most recent known
 * report forward.
 */
class SuiHoldingsReconstructionService
{
    public const OUTCOME_FOUND = 'found';

    // The requested date is before this wallet's earliest known on-chain activity. Since
    // reconstruction always walks from genesis (or a cursor that itself traces back to
    // genesis), nothing will ever exist here -- this is a client error (400), not a server
    // one, per the reasoning worked out in the conversation this was designed in.
    public const OUTCOME_BEFORE_GENESIS = 'before_genesis';

    // The requested date falls within a range this address's cursor claims is already fully
    // backfilled, yet no cache row exists for it. Given the backfill loop below guarantees no
    // gaps within a walked range, this can only mean something broke in our own process (a
    // crash mid-backfill, a manual edit, etc) -- a genuine bug, not a bad request (500).
    public const OUTCOME_INCONSISTENT = 'inconsistent';

    // The GitHub Action run itself failed, produced no artifact, or produced invalid JSON.
    // See $actionError for which (one of SuiWalletReconstructionActionClient::ERROR_*).
    public const OUTCOME_ACTION_ERROR = 'action_error';

    public function __construct(
        private SuiHoldingsCacheRepository $cacheRepository,
        private SuiHoldingsCursorRepository $cursorRepository,
        private SuiWalletReconstructionActionClient $actionClient
    ) {
    }

    /**
     * @return array{outcome: string, reportJson?: string, actionError?: string}
     */
    public function resolve(string $address, string $targetDate): array
    {
        $cursor = $this->cursorRepository->getCursor($address);

        if ($cursor !== null && $cursor['cursorDate'] >= $targetDate) {
            // Already fully walked at least up to this date -- a forward-only replay can't
            // reach backward to fill in a gap here, so don't trigger anything. Whether this
            // is a legitimate "wallet didn't exist yet" or a genuine data gap is determined
            // by comparing against the earliest thing ever cached for this address.
            return $this->classifyPersistentMiss($address, $targetDate);
        }

        $fromDateExclusive = $cursor !== null ? $cursor['cursorDate'] : null;

        $result = $this->actionClient->reconstruct(
            $address,
            $targetDate,
            $cursor['checkpoint'] ?? null,
            $cursor['walletBalances'] ?? null
        );

        if (is_string($result)) {
            return ['outcome' => self::OUTCOME_ACTION_ERROR, 'actionError' => $result];
        }

        $this->backfillAndAdvanceCursor($address, $fromDateExclusive, $targetDate, $result);

        $cached = $this->cacheRepository->getCacheForDate($address, $targetDate);

        if ($cached === null) {
            // The walk ran and still produced nothing at or before targetDate: a from-cursor
            // walk always crosses through targetDate on its way forward, so the only way to
            // land here is a from-genesis walk (no prior cursor) whose very first transaction
            // was already past targetDate -- this wallet's on-chain history doesn't reach
            // back this far.
            return ['outcome' => self::OUTCOME_BEFORE_GENESIS];
        }

        return ['outcome' => self::OUTCOME_FOUND, 'reportJson' => $cached['reportJson']];
    }

    /**
     * @return array{outcome: string}
     */
    private function classifyPersistentMiss(string $address, string $targetDate): array
    {
        $earliest = $this->cacheRepository->getEarliestCachedDate($address);

        if ($earliest === null || $targetDate < $earliest) {
            return ['outcome' => self::OUTCOME_BEFORE_GENESIS];
        }

        return ['outcome' => self::OUTCOME_INCONSISTENT];
    }

    /**
     * @param array{newCursor: array{checkpoint: int, balances: array<string, string>},
     *              dailySnapshots: array<int, array{date: string, report: array<string, mixed>}>} $result
     */
    private function backfillAndAdvanceCursor(
        string $address,
        ?string $fromDateExclusive,
        string $targetDate,
        array $result
    ): void {
        $snapshotsByDate = [];

        foreach ($result['dailySnapshots'] as $snapshot) {
            $snapshotsByDate[$snapshot['date']] = $snapshot['report'];
        }

        // reconstruct.js only ever returns a snapshot for a day something actually changed --
        // a quiet stretch produces no entry at all for those days. Every day in
        // [fromDateExclusive+1, targetDate] still needs its own cache row (see the
        // conversation this was designed in: "I don't want the API to reconstruct the
        // holdings of B from date A every time"), so any day without its own snapshot gets a
        // copy of the most recently known report, with asOfDate/generatedAt corrected.
        $startDate = $fromDateExclusive !== null
            ? $this->addDays($fromDateExclusive, 1)
            : ($result['dailySnapshots'][0]['date'] ?? null);

        if ($startDate === null) {
            // No prior cursor AND zero snapshots returned -- this wallet has no activity at
            // all up to targetDate. Nothing to backfill or advance the cursor to (notably,
            // result['newCursor']['checkpoint'] is itself null in this exact case, since
            // reconstruct.js never applied a single transaction -- storing it would violate
            // the cursor table's NOT NULL constraint anyway). resolve() reports
            // OUTCOME_BEFORE_GENESIS off the back of this via the empty getCacheForDate() check.
            return;
        }

        // Seed lastKnownReport from the already-cached report at the resume point, not from
        // nothing. This matters specifically when resuming from an existing cursor and the
        // new walk finds zero new transactions (a genuinely quiet window) -- reconstruct.js
        // then returns an empty dailySnapshots, and without this seed, every day in the loop
        // below would hit the "nothing known yet" branch and write nothing at all, while the
        // cursor still advanced to targetDate as if it had. A real production run hit exactly
        // this: cursor advanced from 2026-06-28 to 2026-07-07 with an unchanged checkpoint
        // (correct -- no transactions happened), but zero cache rows were written for that
        // whole range, and the immediate re-check for 2026-07-07 then wrongly reported
        // OUTCOME_BEFORE_GENESIS -- a wallet nine months into its on-chain history reported as
        // predating its own genesis, because the carry-forward loop had nothing to carry.
        $lastKnownReport = null;

        if ($fromDateExclusive !== null) {
            $priorCached = $this->cacheRepository->getCacheForDate($address, $fromDateExclusive);

            if ($priorCached !== null) {
                $lastKnownReport = json_decode($priorCached['reportJson'], true);
            }
        }

        for ($date = $startDate; $date <= $targetDate; $date = $this->addDays($date, 1)) {
            if (isset($snapshotsByDate[$date])) {
                $lastKnownReport = $snapshotsByDate[$date];
            } elseif ($lastKnownReport === null) {
                // Nothing known yet to carry forward -- this day is before the wallet's first
                // ever activity. Only reachable now when $fromDateExclusive is null (a
                // from-genesis walk) and $startDate already equals the first real snapshot's
                // date, so in practice this branch is never actually taken -- kept as a
                // defensive guard rather than assumed away, same reasoning as before, but no
                // longer silently wrong for the resume case.
                continue;
            }

            $report = $lastKnownReport;
            $report['asOfDate'] = $date;
            // Accurate to the second, not implying sub-second precision we don't have (this
            // row is being synthesized by the API right now, not re-fetched from the chain).
            $report['generatedAt'] = gmdate('Y-m-d\TH:i:s') . '.000Z';

            $this->cacheRepository->store(
                $address,
                json_encode($report, JSON_UNESCAPED_SLASHES),
                $date,
                'reconstructed'
            );
        }

        $this->cursorRepository->storeCursor(
            $address,
            $result['newCursor']['checkpoint'],
            $result['newCursor']['balances'],
            $targetDate
        );
    }

    private function addDays(string $date, int $days): string
    {
        $dt = DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        $dt->modify($days . ' day' . (abs($days) === 1 ? '' : 's'));

        return $dt->format('Y-m-d');
    }
}
