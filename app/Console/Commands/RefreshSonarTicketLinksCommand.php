<?php

namespace App\Console\Commands;

use App\Models\SonarTicketLink;
use App\Services\Sonar\SonarClient;
use App\Services\Sonar\SonarUnavailableException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic refresh (every 15 min - see routes/console.php) of open Sonar ticket links, so the
 * NOC's ticket panel stays current without anyone opening the device/site/link to trigger a
 * read-time refresh (SonarTicketLinkController::index). Links whose cached status is already
 * `CLOSED` are skipped - a closed ticket doesn't change. Capped per run (mymate.sonar.
 * refresh_batch) to bound how many rows one run touches; the fetch itself is always exactly
 * one HTTP request regardless of batch size (GraphQL aliasing - see SonarClient), so the cap
 * is about keeping a single run's DB writes bounded, not the Sonar rate budget.
 */
class RefreshSonarTicketLinksCommand extends Command
{
    protected $signature = 'mymate:sonar:refresh-tickets';

    protected $description = 'Refresh cached Sonar ticket data for links whose status is not yet CLOSED';

    public function handle(SonarClient $sonar): int
    {
        if (! config('mymate.sonar.enabled')) {
            $this->line('Sonar integration is not configured - skipping.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) config('mymate.sonar.refresh_batch', 200));

        $links = SonarTicketLink::query()
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'CLOSED');
            })
            ->orderBy('cached_at')
            ->limit($limit)
            ->get();

        if ($links->isEmpty()) {
            $this->line('No open ticket links due for refresh.');

            return self::SUCCESS;
        }

        try {
            $fresh = $sonar->fetchTickets($links->pluck('ticket_id')->map(fn ($id): int => (int) $id)->all());
        } catch (SonarUnavailableException $e) {
            Log::warning('sonar: scheduled refresh failed', ['error' => $e->getMessage()]);
            $this->warn("Sonar was unreachable - left {$links->count()} link(s) cached as-is.");

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($links as $link) {
            if (isset($fresh[(int) $link->ticket_id])) {
                $link->forceFill(SonarClient::toCacheAttributes($fresh[(int) $link->ticket_id]))->save();
                $updated++;
            }
        }

        $this->info("Refreshed {$updated} of {$links->count()} open Sonar ticket link(s).");

        return self::SUCCESS;
    }
}
