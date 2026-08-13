<?php

namespace App\Console\Commands;

use App\Actions\Sites\DeriveSiteLinksFromNaming;
use Illuminate\Console\Command;

class DeriveSiteLinksFromNamingCommand extends Command
{
    protected $signature = 'mymate:site-links:derive-from-naming {--dry-run : Report what would be created without writing}';

    protected $description = 'Create missing site_links from reciprocal alpha2bravo device naming (UISP data_link is incomplete).';

    public function handle(DeriveSiteLinksFromNaming $action): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $action->handle($dry);

        if ($r['links'] !== []) {
            $this->table(
                ['site A', 'site B', 'device A', 'device B', 'km'],
                array_map(fn (array $l): array => [
                    mb_substr((string) $l['site_a'], 0, 24),
                    mb_substr((string) $l['site_b'], 0, 24),
                    mb_substr((string) $l['device_a'], 0, 30),
                    mb_substr((string) $l['device_b'], 0, 30),
                    $l['km'] ?? '-',
                ], $r['links'])
            );
        }

        $this->line(sprintf(
            '%s %d link(s); %d ambiguous far-end skipped, %d implausibly-distant skipped, %d reciprocal candidates seen.',
            $dry ? 'WOULD create' : 'Created',
            $r['created'], $r['skipped_ambiguous'], $r['skipped_far'], $r['candidates']
        ));

        // Newly created links have no endpoints yet; the resolver runs right after us on the
        // schedule. Say so, so a hand-run isn't mistaken for a half-finished job.
        if ($r['created'] > 0 && ! $dry) {
            $this->line('Run mymate:site-links:resolve-endpoints to attach devices/interfaces to the new links.');
        }

        return self::SUCCESS;
    }
}
