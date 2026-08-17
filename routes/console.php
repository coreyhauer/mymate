<?php

use App\Enums\AgentStatus;
use App\Jobs\ManageHistoryPartitionsJob;
use App\Models\Agent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recent history: keep the interface_samples daily partitions rolling +
// drop expired ones. The mymate:loop daemon also does this on its own cadence; this
// is the standard scheduler path for deployments running `schedule:work`.
Schedule::job(new ManageHistoryPartitionsJob)->daily()->name('history-partitions')->withoutOverlapping();

// Device config backups: nightly, fan out one backup job per
// backup-enabled device onto the isolated `backup` queue (the SSH capture runs in the
// Rusted sidecar). Off-peak so a slow fleet-wide sweep doesn't compete with the day.
// Runs hourly; the command fires only when the operator-configured cadence
// (App\Support\BackupSchedule, editable on the Backups page) is due - so changing the
// schedule takes effect without restarting the scheduler.
Schedule::command('mymate:backup:run --scheduled')->hourly()->name('device-backups')->withoutOverlapping();

// Sweep cached RouterOS upgrade packages past the retention window (default 90 days).
Schedule::command('mymate:routeros:prune-packages')->daily()->name('routeros-package-prune')->withoutOverlapping();

// Queue-flood tripwire: alert to Chat while a work queue is >10k deep or redis >5GB -
// the silent-buildup signature of both August 2026 resource incidents on this box.
Schedule::command('mymate:ops:queue-guard')->everyTenMinutes()->name('queue-guard')->withoutOverlapping();

// Cap the failed-jobs table at a week. Chronic poll-batch timeouts produce ~1k rows/day;
// left unpruned it reached 179k rows / multi-GB and `queue:failed` became a 12GB memory
// bomb that OOM-wedged this box (2026-08-04). A week is plenty to diagnose anything real.
Schedule::command('queue:prune-failed --hours=168')->daily()->name('failed-jobs-prune')->withoutOverlapping();

// RF/link-health: pull the latest wireless sensor readings from LibreNMS (~16k devices,
// one MySQL round-trip - see PullLibreNmsRfMetrics) into rf_link_samples + rf_link_state.
// No-ops when mymate.librenms_rf isn't configured.
Schedule::job(new \App\Jobs\PullLibreNmsRfMetricsJob)->everyFiveMinutes()->name('rf-librenms-pull')->withoutOverlapping();

// RF/link-health: roll yesterday's raw 5-min samples up into rf_link_daily_stats (the
// long-retention history the baseline/alerting stage will read). Idempotent upsert.
Schedule::job(new \App\Jobs\RollupRfLinkDailyStatsJob)->dailyAt('00:25')->name('rf-daily-rollup')->withoutOverlapping();

// DISCOVER site_links that UISP's data_link table never had, from reciprocal alpha2bravo naming.
// Must run BEFORE resolve-endpoints so links created here get their devices attached in the same
// nightly pass. Dennis Loucks read "No fiber drain reachable" purely because one such link was
// missing; 26 more were, and creating them took the fleet's unreachable sites from 132 to 120.
Schedule::command('mymate:site-links:derive-from-naming')->dailyAt('01:05')->name('site-link-derive')->withoutOverlapping();

// Re-resolve site_links endpoint devices from the alpha2bravo naming convention nightly -
// newly imported/renamed radios get matched without anyone running the command by hand.
Schedule::command('mymate:site-links:resolve-endpoints')->dailyAt('01:10')->name('site-link-endpoints')->withoutOverlapping();

// Reap silent agents. A connected agent heartbeats via the hub keepalive every ~30s; if an
// "online" one hasn't been heard from in 90s its socket is dead (e.g. a blackholed link that
// never sent a TCP close, which the raised /agent proxy timeout would otherwise mask for up to
// an hour) - flip it offline so the UI and job dispatch don't treat a gone agent as connected.
Schedule::call(function () {
    Agent::query()
        ->where('status', AgentStatus::Online)
        ->where('last_seen_at', '<', now()->subSeconds(90))
        ->update(['status' => AgentStatus::Offline]);
})->everyMinute()->name('agent-reap-stale')->withoutOverlapping();

// Recompute the RF health overlays, so a REPAIRED LINK CLEARS ITSELF from the map. Jake asked
// "what happens after we fix a link - do we need to wait for the db to update for the lines to go
// away?" and the answer was no: rf_link_state refreshes every 5 min but the DEFICIT derived from it
// was only ever computed by hand, so a fixed link would have stayed red forever.
// Hourly: cheap arithmetic over data already stored, and well inside the time it takes a tech to
// climb down. LOS is NOT re-run here - terrain does not change hourly and each call builds a
// profile; that runs once a day below.
Schedule::command('mymate:rf:refresh')->hourly()->name('rf-deficit-refresh')->withoutOverlapping();

// Line of sight for links that currently look bad. Daily is plenty - what changes is the link, not
// the hillside. Runs after the nightly link derivation so newly discovered links get a verdict.
Schedule::command('mymate:rf:refresh --los')->dailyAt('01:20')->name('rf-los-refresh')->withoutOverlapping();

// PPPoE active sessions: sweep every concentrator's /ppp/active into `pppoe_sessions` so the
// customer data plane ("who is online, on which concentrator, since when") is answerable
// without touching a router. The command only queues sharded jobs onto the isolated `pppoe`
// queue, staggered across mymate.pppoe.stagger_seconds (default 240s) - so this scheduler tick
// returns immediately and the fleet is swept as a trickle, never a thundering herd.
// withoutOverlapping is belt-and-braces on top of the per-shard queue locks.
Schedule::command('mymate:pppoe:sweep')->everyFiveMinutes()->name('pppoe-session-sweep')->withoutOverlapping();

// OSPF adjacencies: delete rows for devices that stopped producing a successful read entirely
// (unmonitored, credential pulled, OSPF removed). Dropped adjacencies are already pruned inline
// by every poll, so this only catches devices that report nothing at all - hourly is ample.
Schedule::command('mymate:ospf:reap')->hourly()->name('ospf-neighbor-reap')->withoutOverlapping();

// Wireless registration-table clients: delete rows for radios that stopped producing a
// non-empty successful read entirely (unmonitored, credential pulled, powered off). A departed
// client is already pruned inline by every non-empty poll of its radio - this only catches
// radios that never report again at all. The cutoff is RETENTION
// (mymate.wireless.retention_days, default 90d), not staleness: the metrics lane can leave a
// healthy AP unvisited for hours, and a freshness-derived cutoff deleted those radios' clients
// for being polled late. Hourly is ample for a horizon measured in days.
Schedule::command('mymate:wireless:reap')->hourly()->name('wireless-registration-reap')->withoutOverlapping();

// Sonar ticket links: keep OPEN/PENDING tickets' cached subject/status/etc fresh without
// anyone opening the device/site/link (closed tickets don't change, so they're skipped). A
// no-op when Sonar isn't configured (mymate.sonar.enabled derives from the API token).
Schedule::command('mymate:sonar:refresh-tickets')->everyFifteenMinutes()->name('sonar-refresh-tickets')->withoutOverlapping();
