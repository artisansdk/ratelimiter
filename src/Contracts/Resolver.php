<?php

namespace ArtisanSdk\RateLimiter\Contracts;

interface Resolver
{
    /**
     * Get the resolver key used by the rate limiter for the unique request.
     */
    public function key(): string;

    /**
     * Get the max number of requests allowed by the rate limiter.
     */
    public function max(): int;

    /**
     * Get the replenish rate in requests per second for the rate limiter.
     */
    public function rate(): float;

    /**
     * Get the duration in minutes the rate limiter will timeout.
     */
    public function duration(): int;
}
