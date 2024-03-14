<?php

declare(strict_types=1);

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
     */
    public function __construct(Cache $cache, Bucket $bucket, ?Dispatcher $events = null)
    {
        $this->cache = $cache;
        $this->events = $events;

        $key = $bucket->key();
        if (stripos($key, ':') !== false) {
            [$parent, $route] = explode(':', $key, 2);
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
     * @param  string  $key  for the rate
     * @param  int  $max  hits against the limiter
     * @param  int|float  $rate  in which limiter decays or leaks per second
     * @return \ArtisanSdk\RateLimiter\Contracts\Limiter
     */
    public function configure(string $key, int $max, $rate)
    {
        $bucket = $this->lastBucket();

        if ($bucket->key() !== $key) {
            $bucket->reset();
            $this->cache->forget($bucket->key());
        }

        $settings = [
            'drips' => $bucket->drips(),
            'timer' => $bucket->timer(),
        ];

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
     * Limit additional hits for the duration in seconds.
     *
     * @param  int  $duration  in seconds for the limit to take effect
     */
    public function timeout(int $duration = 60): void
    {
        if ($this->hasTimeout()) {
            return;
        }

        $this->cache->put(
            $this->getTimeoutKey(),
            ((int) $this->lastBucket()->timer()) + $duration,
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
                (int) max(1, ceil($bucket->duration())) // $ttl to $seconds conversion requires minimally 1s
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
     *
     * @return \ArtisanSdk\RateLimiter\Contracts\Limiter
     */
    public function clear()
    {
        $this->reset();

        $this->cache->forget($this->getTimeoutKey());

        return $this;
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
     */
    protected function getTimeoutKey(?string $key = null): string
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
