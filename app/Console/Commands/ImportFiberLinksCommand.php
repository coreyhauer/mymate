<?php

namespace App\Console\Commands;

use App\Actions\Sites\ImportFiberLinks;
use Illuminate\Console\Command;

/**
 * Imports fiber backbone links from LibreNMS LLDP into `site_links` (media_type='fiber').
 *
 * `site_links` historically held only the wireless mesh, so the geo map drew radio shots and
 * then stopped dead wherever fiber took over - which is how a single broken span between two
 * fiber hubs could darken four sites and read as four unrelated faults.
 *
 * Safe to re-run: links are keyed on `external_ref` = "lldp:<siteA>-<siteB>" and updated in
 * place, so it never duplicates and never touches a hand-made link.
 */
class ImportFiberLinksCommand extends Command
{
    protected $signature = 'sites:import-fiber-links {--dry-run : Report what would change without writing}';

    protected $description = 'Import fiber site-to-site links from LibreNMS LLDP adjacency';

    public function handle(ImportFiberLinks $action): int
    {
        $dry = (bool) $this->option('dry-run');
        $s = $action->handle($dry);

        $this->info(sprintf(
            '%d adjacencies scanned -> %d created, %d updated. Skipped: %d (endpoint not mapped to a site), %d (both ends same site), %d (radio in an SFP cage).',
            $s['scanned'], $s['created'], $s['updated'], $s['skipped_no_site'], $s['skipped_same_site'], $s['skipped_wireless']
        ));

        if ($dry) {
            $this->comment('Dry run - nothing written.');
        }

        // A high no-site count is the normal state, not a fault: LibreNMS monitors plenty of gear
        // My Mate has no site for. Worth surfacing though, because each one is a fiber hop the
        // Path walk cannot traverse.
        if ($s['skipped_no_site'] > 0) {
            $this->comment('Tip: unmapped endpoints are fiber hops Path cannot cross - "sites:import-fiber-links --dry-run" after adding sites to see the gain.');
        }

        return self::SUCCESS;
    }
}
