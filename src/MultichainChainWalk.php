<?php

namespace App;

/**
 * The mutable state of ONE chain during one reconstruction request (see
 * MultichainHoldingsReconstructionService). A small object rather than an array on purpose: the log
 * scanner's progress callback must see the walk's CURRENT cursor date and first activity, and PHP
 * objects are shared by handle, not copied.
 *
 * @internal
 */
final class MultichainChainWalk
{
    /** @var list<EvmRpcClient> */
    public array $clients = [];

    /** @var list<EvmHoldingsSnapshotBuilder> one per provider, same order as $clients */
    public array $builders = [];

    public ?EvmBlockResolver $resolver = null;

    public ?EvmLogScanner $scanner = null;

    public EvmLogScanState $state;

    /** The last day fully completed (all its rows written), null until the first day is done. */
    public ?string $cursorDate = null;

    /** The chain's first activity block (null: none found yet). */
    public ?int $firstActivity = null;

    /** end_block of the requested date; null when that date is before the chain's history start. */
    public ?int $endTarget = null;

    /** Unix time of the last mid-scan progress row written for this chain (0: none yet). */
    public int $lastProgressSave = 0;

    /** end_block of the previous day resolved, used as a lower-bound hint for the next one. */
    public ?int $lastEnd = null;

    public function __construct(EvmLogScanState $state)
    {
        $this->state = $state;
    }
}
