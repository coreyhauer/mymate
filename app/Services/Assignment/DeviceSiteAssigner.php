<?php

namespace App\Services\Assignment;

use App\Models\Device;
use App\Models\DeviceSiteChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only thing permitted to write `devices.site_id`.
 *
 * Routing every move through here is what makes the audit log trustworthy: if a
 * controller, job or importer updates site_id directly, history silently gets a
 * hole in it and revertBatch() will restore a device to a site it was never
 * actually at. UI actions, agent-proposed corrections and importer sync all call
 * move()/moveMany().
 *
 * `site_source` travels with the move rather than being stamped separately,
 * because the two are one decision: 'manual' from the editor, 'import' from the
 * authoritative CSV, 'nearest' from the geographic snap. Pass it in $ctx to set
 * it; omit the key entirely to leave whatever is already there.
 *
 * moveMany() assigns one batch_id to the whole set so a bulk correction can be
 * undone in a single call - the operation techs will reach for first when a sweep
 * turns out to have been wrong.
 */
class DeviceSiteAssigner
{
    /** Bulk corrections above this size need review rather than a tech's say-so. */
    public const REVIEW_THRESHOLD = 25;

    /**
     * Move one device to a site (or to null, detaching it).
     *
     * Returns null when the device is already at the target - a no-op must not
     * write an audit row, otherwise re-running a sweep inflates the history and
     * revert has nothing meaningful to undo.
     */
    public function move(int $deviceId, ?int $toSiteId, array $ctx = []): ?DeviceSiteChange
    {
        $source = $ctx['source'] ?? DeviceSiteChange::SOURCE_UI;
        $this->assertValidSource($source);

        return DB::transaction(function () use ($deviceId, $toSiteId, $ctx, $source) {
            /** @var Device|null $device */
            $device = Device::query()->lockForUpdate()->find($deviceId);
            if (!$device) {
                throw new InvalidArgumentException("Device {$deviceId} not found");
            }

            $fromSiteId = $device->site_id;
            $sameSite = (int) $fromSiteId === (int) $toSiteId;

            // A re-run that only re-stamps provenance (same site, same source) is a
            // true no-op. Same site but a *different* source still needs the write.
            $sourceChanges = array_key_exists('site_source', $ctx)
                && $device->site_source !== $ctx['site_source'];

            if ($sameSite && !$sourceChanges) {
                return null;
            }

            $device->site_id = $toSiteId;
            if (array_key_exists('site_source', $ctx)) {
                $device->site_source = $ctx['site_source'];
            }
            $device->save();

            // Provenance-only touches are not moves; do not fabricate audit rows for them.
            if ($sameSite) {
                return null;
            }

            return DeviceSiteChange::create([
                'device_id'    => $deviceId,
                'from_site_id' => $fromSiteId,
                'to_site_id'   => $toSiteId,
                'actor_id'     => $ctx['actor_id'] ?? null,
                'actor_name'   => $ctx['actor_name'] ?? null,
                'actor_email'  => $ctx['actor_email'] ?? null,
                'source'       => $source,
                'reason'       => $ctx['reason'] ?? null,
                'batch_id'     => $ctx['batch_id'] ?? null,
            ]);
        });
    }

    /**
     * Move many devices under one batch_id.
     *
     * @param  array<int,?int>  $moves  device_id => target site_id
     * @return array{batch_id:string,changes:array<int,DeviceSiteChange>,skipped:int}
     */
    public function moveMany(array $moves, array $ctx = []): array
    {
        $batchId = $ctx['batch_id'] ?? (string) Str::uuid();
        $ctx['batch_id'] = $batchId;

        $changes = [];
        $skipped = 0;

        DB::transaction(function () use ($moves, $ctx, &$changes, &$skipped) {
            foreach ($moves as $deviceId => $toSiteId) {
                $change = $this->move((int) $deviceId, $toSiteId, $ctx);
                $change ? $changes[] = $change : $skipped++;
            }
        });

        return ['batch_id' => $batchId, 'changes' => $changes, 'skipped' => $skipped];
    }

    /**
     * Undo every not-yet-reverted change in a batch, newest first.
     *
     * The undo is itself recorded as a new change (source 'console' unless the
     * caller says otherwise) rather than deleting rows - the log stays append-only,
     * so "this was moved and then moved back" remains visible.
     */
    public function revertBatch(string $batchId, array $ctx = []): int
    {
        $ctx['source'] = $ctx['source'] ?? DeviceSiteChange::SOURCE_CONSOLE;
        $ctx['reason'] = $ctx['reason'] ?? "revert of batch {$batchId}";
        unset($ctx['batch_id']);

        return DB::transaction(function () use ($batchId, $ctx) {
            $originals = DeviceSiteChange::query()
                ->where('batch_id', $batchId)
                ->whereNull('reverted_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $undone = 0;
            foreach ($originals as $original) {
                $undo = $this->move($original->device_id, $original->from_site_id, $ctx);
                $original->reverted_at    = now();
                $original->reverted_by_id = $undo?->id;
                $original->save();
                $undone++;
            }

            return $undone;
        });
    }

    private function assertValidSource(string $source): void
    {
        $valid = [
            DeviceSiteChange::SOURCE_UI,
            DeviceSiteChange::SOURCE_AGENT,
            DeviceSiteChange::SOURCE_SYNC,
            DeviceSiteChange::SOURCE_CONSOLE,
        ];

        if (!in_array($source, $valid, true)) {
            throw new InvalidArgumentException("Unknown change source '{$source}'");
        }
    }
}
