<?php

namespace ArtisanSDK\RateLimiter\Contracts;

interface Resolver
{
    /**
     * Get the resolver key used by the rate limiter for the unique request.
     *
     * @return string
     */
    public function key(): string;

    /**
     * Get the max number of requests allowed by the rate limiter.
     *
     * @return int
     */
    public function max(): int;

    /**
     * Get the replenish rate in requests per second for the rate limiter.
     *
     * @return float
     */
    public function rate(): float;

    /**
     * Get the duration the rate limiter will lock out for exceeding the limit.
     *
     * @return int
     */
    public function duration(): int;
}
