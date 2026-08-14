<?php

namespace App\Jobs\Middleware;

use App\Support\EngineLog;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * WithoutOverlapping that says so when it throws a job away.
 *
 * The stock middleware's `dontRelease()` path is completely silent: if the lock cannot be
 * acquired it simply does not call `$next`, and the job disappears with no log line, no failed
 * entry and no Horizon record. For a periodic sweep that is *mostly* the right behaviour - a
 * slow shard skipping its next tick is the backpressure working - but "mostly right and always
 * invisible" is how a permanently-locked shard ends up looking like a concentrator that has no
 * sessions. A stale lock (worker killed between acquiring and releasing) would silently exclude
 * those devices until `expireAfter` elapsed, with nothing anywhere to explain it.
 *
 * Behaviour is otherwise identical to the parent, including honouring `releaseAfter` when
 * `dontRelease()` has not been called.
 */
class LoggedWithoutOverlapping extends WithoutOverlapping
{
    /** @var array<string, mixed> extra fields to log when a job is dropped */
    protected array $context = [];

    /** @param  array<string, mixed>  $context */
    public function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @param  mixed  $job
     * @param  callable  $next
     * @return mixed
     */
    public function handle($job, $next)
    {
        $lock = Container::getInstance()->make(Cache::class)->lock(
            $this->getLockKey($job), $this->expiresAfter
        );

        if ($lock->get()) {
            try {
                return $next($job);
            } finally {
                $lock->release();
            }
        }

        if (! is_null($this->releaseAfter)) {
            $job->release($this->releaseAfter);

            return null;
        }

        EngineLog::warning('queue: job dropped by overlap lock', array_merge([
            'job' => get_class($job),
            'lock' => $this->key,
        ], $this->context));

        return null;
    }
}
