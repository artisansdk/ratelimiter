<?php

namespace ArtisanSdk\RateLimiter;

use ArtisanSdk\RateLimiter\Buckets\Evented;
use ArtisanSdk\RateLimiter\Contracts\Bucket;
use ArtisanSdk\RateLimiter\Contracts\Limiter as Contract;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Leaky Bucket Rate Limiter.
 */
class Limiter implements Contract
{
    /**
     * The cache store implementation.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * The event dispatcher implementation.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * The buckets implementation.
     *
     * @var array
     */
    protected $buckets = [];

    /**
     * Create a new rate limiter instance.
     *
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(Cache $cache, Bucket $bucket, Dispatcher $events = null)
    {
        $this->cache = $cache;
        $this->events = $events;

        $key = $bucket->key();
        if (false !== stripos($key, ':')) {
            list($parent, $route) = explode(':', $key, 2);
            $parent = $bucket instanceof Evented
                ? (new $bucket($this->events, $parent, $bucket->max(), $bucket->rate()))
                : (new $bucket($parent, $bucket->max(), $bucket->rate()));

            $this->buckets[] = $parent->configure(
                $this->cache->get($parent->key(), $bucket->toArray())
            );
        }

        $this->buckets[] = $bucket->configure(
            $this->cache->get($key, $bucket->toArray())
        );
    }

    /**
     * Configure the limiter.
     *
     * @param string    $key  for the rate
     * @param int       $max  hits against the limiter
     * @param int|float $rate in which limiter decays or leaks
     *
     * @return \ArtisanSdk\RateLimiter\Contracts\Limiter
     */
    public function configure(string $key, int $max, $rate)
    {
        $bucket = $this->lastBucket();

        $settings = [
            'drips' => $bucket->drips(),
            'timer' => $bucket->timer(),
        ];

        if ($bucket->key() !== $key) {
            $this->reset();
        }

        $original = array_pop($this->buckets);

        if ($existing = $this->cache->get($key)) {
            $settings = array_merge($settings, $existing, compact('max', 'rate'));
        }

        $instance = $original instanceof Evented
            ? (new $original($this->events, $key, $max, $rate))
            : (new $original($key, $max, $rate));

        $configured = $instance->configure($settings);

        array_push($this->buckets, $configured);

        return $this;
    }

    /**
     * Determine if the limit threshold has been exceeded.
     */
    public function exceeded(): bool
    {
        if ($this->hasTimeout()) {
            return true;
        }

        foreach ($this->buckets as $bucket) {
            if ($bucket->leak()->isFull()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the rate limiter have a timeout?
     */
    public function hasTimeout(): bool
    {
        foreach ($this->buckets as $bucket) {
            if ($this->cache->has($this->getTimeoutKey($bucket->key()))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Limit additional hits for the duration in minutes.
     *
     * @param int $duration in minutes for the limit to take effect
     */
    public function timeout(int $duration = 1): void
    {
        if ($this->hasTimeout()) {
            return;
        }

        $this->cache->put(
            $this->getTimeoutKey(),
            (int) $this->lastBucket()->timer() + ($duration * 60),
            $duration
        );
    }

    /**
     * Increment the counter for the rate limiter.
     */
    public function hit(): int
    {
        foreach ($this->buckets as $bucket) {
            $bucket->fill();
            $this->cache->put(
                $bucket->key(),
                $bucket->toArray(),
                ceil($bucket->duration() / 60)
            );
        }

        return $bucket->drips();
    }

    /**
     * Get the maximum number of hits allowed by the limiter.
     */
    public function limit(): int
    {
        return $this->lastBucket()->max();
    }

    /**
     * Get the number of hits against the rate limiter.
     */
    public function hits(): int
    {
        return $this->lastBucket()->drips();
    }

    /**
     * Reset the number of hits for the rate limiter.
     */
    public function reset(): bool
    {
        $bucket = $this->lastBucket()->reset();

        return $this->cache->forget($bucket->key());
    }

    /**
     * Get the number of remaining hits allowed by the limiter.
     */
    public function remaining(): int
    {
        return $this->lastBucket()->remaining();
    }

    /**
     * Clear the hits and timeout timer for the rate limiter.
     */
    public function clear(): void
    {
        $this->reset();

        $this->cache->forget($this->getTimeoutKey());
    }

    /**
     * Get the number of seconds until the limiter is available again.
     */
    public function backoff(): int
    {
        return max(0, (int) $this->cache->get($this->getTimeoutKey()) - Carbon::now()->getTimestamp());
    }

    /**
     * Get the timeout key.
     *
     * @param string $key
     */
    protected function getTimeoutKey(string $key = null): string
    {
        $key = $key ?? $this->lastBucket()->key();

        return $key.':timeout';
    }

    /**
     * Get the last bucket in the stack.
     *
     * @return \ArtisanSdk\RateLimiter\Bucket
     */
    protected function lastBucket()
    {
        return end($this->buckets);
    }
}
