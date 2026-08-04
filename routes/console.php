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

// RF/link-health: pull the latest wireless sensor readings from LibreNMS (~16k devices,
// one MySQL round-trip - see PullLibreNmsRfMetrics) into rf_link_samples + rf_link_state.
// No-ops when mymate.librenms_rf isn't configured.
Schedule::job(new \App\Jobs\PullLibreNmsRfMetricsJob)->everyFiveMinutes()->name('rf-librenms-pull')->withoutOverlapping();

// RF/link-health: roll yesterday's raw 5-min samples up into rf_link_daily_stats (the
// long-retention history the baseline/alerting stage will read). Idempotent upsert.
Schedule::job(new \App\Jobs\RollupRfLinkDailyStatsJob)->dailyAt('00:25')->name('rf-daily-rollup')->withoutOverlapping();

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

// Sonar ticket links: keep OPEN/PENDING tickets' cached subject/status/etc fresh without
// anyone opening the device/site/link (closed tickets don't change, so they're skipped). A
// no-op when Sonar isn't configured (mymate.sonar.enabled derives from the API token).
Schedule::command('mymate:sonar:refresh-tickets')->everyFifteenMinutes()->name('sonar-refresh-tickets')->withoutOverlapping();
