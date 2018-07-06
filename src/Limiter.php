<?php

namespace ArtisanSdk\RateLimiter;

use ArtisanSdk\RateLimiter\Contracts\Limiter as Contract;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;

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
     * The buckets implementation.
     *
     * @var array
     */
    protected $buckets = [];

    /**
     * Create a new rate limiter instance.
     *
     * @param \Illuminate\Contracts\Cache\Repository $cache
     * @param \ArtisanSdk\RateLimiter\Bucket         $bucket
     */
    public function __construct(Cache $cache, Bucket $bucket)
    {
        $this->cache = $cache;

        $key = $bucket->key();
        if (false !== stripos($key, ':')) {
            list($user, $route) = explode(':', $key, 2);
            $parent = new $bucket($user, $bucket->max(), $bucket->rate());
            $this->buckets[] = $parent->configure(
                $this->cache->get($user, $bucket->toArray())
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
        if ($this->lastBucket()->key() !== $key) {
            $this->reset();
        }

        $original = array_pop($this->buckets);

        $configured = (new $original($key, $max, $rate))
            ->configure([
                'drips' => $original->drips(),
                'timer' => $original->timer(),
            ]);

        array_push($this->buckets, $configured);

        return $this;
    }

    /**
     * Determine if the limit threshold has been exceeded.
     *
     * @return bool
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
     *
     * @return bool
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
     *
     * @return int
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
     *
     * @return int
     */
    public function limit(): int
    {
        return $this->lastBucket()->max();
    }

    /**
     * Get the number of hits against the rate limiter.
     *
     * @return int
     */
    public function hits(): int
    {
        return $this->lastBucket()->drips();
    }

    /**
     * Reset the number of hits for the rate limiter.
     *
     * @return bool
     */
    public function reset(): bool
    {
        $bucket = $this->lastBucket()->reset();

        return $this->cache->forget($bucket->key());
    }

    /**
     * Get the number of remaining hits allowed by the limiter.
     *
     * @return int
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
     *
     * @return int
     */
    public function backoff(): int
    {
        return max(0, (int) $this->cache->get($this->getTimeoutKey()) - Carbon::now()->getTimestamp());
    }

    /**
     * Get the timeout key.
     *
     * @param string $key
     *
     * @return string
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
