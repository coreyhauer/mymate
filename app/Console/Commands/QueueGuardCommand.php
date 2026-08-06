<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Queue-flood tripwire (scheduled every 10 minutes - see routes/console.php).
 *
 * Two production incidents in two days (2026-08-04 OOM via failed_jobs, 2026-08-06 mass
 * false-down via a 463k-deep `poll` queue ballooning redis to 5.8GB and starving the ping
 * workers) shared one property: the backlog built silently for hours and only announced
 * itself as a wedged box. This check announces the BUILD, not the wedge: if any work queue
 * is deeper than the threshold, or redis has grown past its ceiling, post one Google Chat
 * alert (ops WARNING space) and re-alert at most every 6 hours while the condition holds.
 *
 * Silent when healthy, silent without a configured webhook (logs instead) - same contract
 * as the network_analyzer infra-health checks.
 */
class QueueGuardCommand extends Command
{
    protected $signature = 'mymate:ops:queue-guard';

    protected $description = 'Alert to Chat when work queues back up or redis balloons';

    private const QUEUE_DEPTH_LIMIT = 10_000;

    private const REDIS_BYTES_LIMIT = 5 * 1024 * 1024 * 1024; // 5 GB

    public function handle(): int
    {
        $problems = [];

        foreach (['ping', 'poll', 'pppoe', 'discover', 'default'] as $queue) {
            $depth = (int) Redis::llen("queues:{$queue}");
            if ($depth > self::QUEUE_DEPTH_LIMIT) {
                $problems[] = sprintf('queue `%s` is %s jobs deep', $queue, number_format($depth));
            }
        }

        $info = Redis::info('memory');
        $used = (int) ($info['used_memory'] ?? ($info['Memory']['used_memory'] ?? 0));
        if ($used > self::REDIS_BYTES_LIMIT) {
            $problems[] = sprintf('redis is using %.1f GB', $used / 1024 ** 3);
        }

        if ($problems === []) {
            // Healthy: clear the re-alert latch so the NEXT incident alerts immediately.
            Cache::forget('ops:queue-guard:alerted');
            $this->line('healthy');

            return self::SUCCESS;
        }

        $text = "⚠️ My Mate queue guard (10.66.71.210): ".implode('; ', $problems)
            .'. This is how the 2026-08-06 mass false-down started - check mymate-horizon workers '
            .'and the dispatcher before the box wedges.';

        // Cache::add is atomic: first breach wins the right to alert, held for 6h.
        if (! Cache::add('ops:queue-guard:alerted', now()->toIso8601String(), now()->addHours(6))) {
            $this->line('breached, already alerted');

            return self::SUCCESS;
        }

        Log::error('ops: queue guard tripped', ['problems' => $problems]);

        $webhook = (string) config('mymate.ops_chat_webhook');
        if ($webhook !== '') {
            try {
                Http::timeout(10)->post($webhook, ['text' => $text]);
            } catch (\Throwable $e) {
                Log::error('ops: queue guard chat post failed', ['error' => $e->getMessage()]);
            }
        }

        $this->warn($text);

        return self::SUCCESS;
    }
}
