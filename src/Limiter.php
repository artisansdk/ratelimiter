<?php

namespace ArtisanSDK\RateLimiter;

use Illuminate\Contracts\Cache\Repository as Cache;
use Carbon\Carbon;
use ArtisanSDK\RateLimiter\Contracts\Limiter as Contract;

/**
 * Leaky Bucket Rate Limiter
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
     * @param \Illuminate\Contracts\Cache\Repository  $cache
     * @param \ArtisanSDK\RateLimiter\Bucket          $bucket
     */
    public function __construct(Cache $cache, Bucket $bucket)
    {
        $this->cache = $cache;

        $key = $bucket->key();
        if( stripos($key, ':') !== false ) {
            list($user, $route) = explode(':', $key, 2);
            $this->buckets[] = (clone $bucket)->configure(
                $this->cache->get($user, $bucket->toArray())
            );
        }

        $this->buckets[] = $bucket->configure(
            $this->cache->get($key, $bucket->toArray())
        );
    }

    /**
     * Determine if the limit threshold has been exceeded.
     *
     * @param string $key for the rate
     * @param int $max hits against the limiter
     * @param int|float $rate in which limiter decays or leaks
     *
     * @return \ArtisanSDK\RateLimiter\Contracts\Limiter
     */
    public function config(string $key, int $max, $rate)
    {
        (end($this->buckets))
            ->key($key)
            ->max($max)
            ->rate($rate);

        return $this;
    }

    /**
     * Determine if the limit threshold has been exceeded.
     *
     * @return bool
     */
    public function exceeded() : bool
    {
        if( $this->hasTimeout() ) {
            return true;
        }

        foreach($this->buckets as $bucket) {
            if( $this->buckets->leak()->isFull() ) {
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
    public function hasTimeout() : bool
    {
        foreach($this->buckets as $bucket){
            if( $this->cache->has($this->getTimerKey($bucket->key())) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Limit additional hits for the duration in minutes.
     *
     * @param int $duration in minutes for the limit to take effect
     *
     * @return void
     */
    public function timeout(int $duration = 1) : void
    {
        if( $this->hasTimeout() ) {
            return;
        }

        $bucket = end($this->buckets);

        $this->cache->put($this->getTimerKey($bucket->key()), ceil($bucket->timer() + ($duration * 60)), $duration);
    }

    /**
     * Increment the counter for the rate limiter.
     *
     * @return int
     */
    public function hit() : int
    {
        foreach($this->buckets as $bucket) {
            $bucket->fill();
            $this->cache->put($bucket->key(), $bucket->toArray());
        }

        return $bucket->drips();
    }

    /**
     * Get the maximum number of hits allowed by the limiter.
     *
     * @return int
     */
    public function limit() : int
    {
        return (end($this->buckets))->max();
    }

    /**
     * Get the number of hits against the rate limiter.
     *
     * @return int
     */
    public function hits() : int
    {
        return (end($this->buckets))->drips();
    }

    /**
     * Reset the number of hits for the rate limiter.
     *
     * @return bool
     */
    public function reset() : bool
    {
        $bucket = end($this->bucket);
        $bucket->reset();

        return $this->cache->forget($bucket->key());
    }

    /**
     * Get the number of remaining hits allowed by the limiter.
     *
     * @return int
     */
    public function remaining() : int
    {
        return (end($this->bucket))->remaining();
    }

    /**
     * Clear the hits and timeout timer for the rate limiter.
     *
     * @return void
     */
    public function clear() : void
    {
        $this->resetAttempts();

        $bucket = end($this->buckets);

        $this->cache->forget($this->getTimerKey($bucket->key()));
    }

    /**
     * Get the number of seconds until the limiter is available again.
     *
     * @return int
     */
    public function backoff() : int
    {
        $bucket = end($this->buckets);

        return max(0, $this->cache->get($this->getTimerKey($bucket->key())) - Carbon::now()->getTimestamp());
    }

    /**
     * Get the lock out timer key.
     *
     * @param string $key
     * @return string
     */
    protected function getTimerKey(string $key) : string
    {
        return $key.':timer';
    }
}
