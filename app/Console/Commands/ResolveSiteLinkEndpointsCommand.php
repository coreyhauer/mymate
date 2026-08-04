<?php

namespace App\Console\Commands;

use App\Actions\Sites\ResolveSiteLinkEndpoints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill site_links.device_a_id/device_b_id/interface_*_id from device naming convention
 * (see ResolveSiteLinkEndpoints for the algorithm), so the geo map's backhaul lines can
 * eventually be coloured by live link health instead of drawn as inert geometry.
 *
 * Always backs up site_links to a timestamped CSV before writing (storage/app/backhaul_fix,
 * same convention as the prior site_links cleanup - see that directory's restore_site_links.sh
 * for the sibling recovery pattern), and re-verifies after writing that every resolved
 * device's own site_id agrees with the link's site on that side - by construction of the
 * resolver this can't disagree (candidates are only ever drawn from devices already at that
 * site), but this is the guardrail's explicit ask and cheap insurance against a future
 * refactor breaking that invariant silently.
 */
class ResolveSiteLinkEndpointsCommand extends Command
{
    protected $signature = 'mymate:site-links:resolve-endpoints
        {--dry-run : Compute and report, but do not write to the database}
        {--limit= : Only process the first N site_links (ordered by id) - for spot-checking}
        {--backup-dir= : Override the CSV backup directory (default: storage/app/backhaul_fix)}';

    protected $description = 'Resolve device/interface endpoints for site_links from the alpha2bravo naming convention.';

    public function handle(ResolveSiteLinkEndpoints $resolver): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if (! $dryRun) {
            $backupPath = $this->backup((string) ($this->option('backup-dir') ?: storage_path('app/backhaul_fix')));
            if ($backupPath === null) {
                return self::FAILURE;
            }
            $this->info("Backed up site_links -> {$backupPath}");
        } else {
            $this->line('(dry run - skipping backup, no writes will happen)');
        }

        $this->info($dryRun ? 'Resolving (dry run) ...' : 'Resolving and writing ...');
        $summary = $resolver($limit, $dryRun);

        $this->newLine();
        $this->line("Processed: {$summary['processed']}");
        $this->table(
            ['Confidence tier', 'Rows'],
            [
                ['reciprocal_name', $summary['reciprocal_name']],
                ['reciprocal_name_fuzzy', $summary['reciprocal_name_fuzzy']],
                ['name_subnet29', $summary['name_subnet29']],
                ['name_subnet29_fuzzy', $summary['name_subnet29_fuzzy']],
                ['name_only', $summary['name_only']],
                ['name_only_fuzzy', $summary['name_only_fuzzy']],
                ['unresolved', $summary['unresolved']],
            ]
        );
        $this->table(
            ['Endpoint completeness', 'Rows'],
            [
                ['both endpoints', $summary['both_endpoints']],
                ['one endpoint', $summary['one_endpoint']],
                ['neither endpoint', $summary['neither_endpoint']],
                ['interfaces resolved (of resolved ends)', $summary['interfaces_resolved']],
            ]
        );

        if (! $dryRun) {
            $mismatches = $this->verifySiteAgreement();
            if ($mismatches > 0) {
                $this->error("VERIFICATION FAILED: {$mismatches} resolved link(s) have a device whose own site disagrees with the link's site. This should be impossible by construction - investigate before trusting this run.");

                return self::FAILURE;
            }
            $this->info('Verification: every resolved device sits at the site its link side claims. 0 mismatches.');
        }

        return self::SUCCESS;
    }

    /** Timestamped CSV snapshot of the full site_links table, verified readable. Returns the path, or null on failure. */
    private function backup(string $dir): ?string
    {
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Could not create backup directory: {$dir}");

            return null;
        }

        $path = rtrim($dir, '/').'/site_links_backup_'.now()->format('Ymd_His').'.csv';
        $rows = DB::table('site_links')->orderBy('id')->get();

        $fh = fopen($path, 'w');
        if ($fh === false) {
            $this->error("Could not open backup file for writing: {$path}");

            return null;
        }

        $columns = $rows->isEmpty()
            ? DB::getSchemaBuilder()->getColumnListing('site_links')
            : array_keys((array) $rows->first());
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(
                fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v,
                (array) $row
            ));
        }
        fclose($fh);

        // Verify: readable, and row count (minus header) matches the table.
        $verifyFh = fopen($path, 'r');
        if ($verifyFh === false) {
            $this->error("Backup written but not readable back: {$path}");

            return null;
        }
        $lineCount = 0;
        while (fgetcsv($verifyFh) !== false) {
            $lineCount++;
        }
        fclose($verifyFh);

        $expected = $rows->count() + 1; // +1 header
        if ($lineCount !== $expected) {
            $this->error("Backup verification failed: wrote {$rows->count()} rows but read back ".($lineCount - 1)." at {$path}");

            return null;
        }

        return $path;
    }

    /** @return int Count of resolved links where a device's own site_id disagrees with the link's claimed side. */
    private function verifySiteAgreement(): int
    {
        $mismatchA = DB::table('site_links as sl')
            ->join('devices as d', 'd.id', '=', 'sl.device_a_id')
            ->whereColumn('d.site_id', '!=', 'sl.site_a_id')
            ->count();

        $mismatchB = DB::table('site_links as sl')
            ->join('devices as d', 'd.id', '=', 'sl.device_b_id')
            ->whereColumn('d.site_id', '!=', 'sl.site_b_id')
            ->count();

        return $mismatchA + $mismatchB;
    }
}
