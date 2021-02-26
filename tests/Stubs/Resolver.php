<?php

namespace ArtisanSdk\RateLimiter\Tests\Stubs;

use ArtisanSdk\RateLimiter\Contracts\Resolver as Contract;

class Resolver implements Contract
{
    /**
     * Get the resolver key used by the rate limiter for the unique request.
     */
    public function key(): string
    {
        return 'foo';
    }

    /**
     * Get the max number of requests allowed by the rate limiter.
     */
    public function max(): int
    {
        return 2;
    }

    /**
     * Get the replenish rate in requests per second for the rate limiter.
     */
    public function rate(): float
    {
        return 0.1;
    }

    /**
     * Get the duration the rate limiter will lock out for exceeding the limit.
     */
    public function duration(): int
    {
        return 2;
    }
}
