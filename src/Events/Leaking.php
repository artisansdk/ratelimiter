<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Events;

class Leaking extends Event
{
    /**
     * The bucket is leaking drips at a rate.
     *
     * @param  string  $key  of the bucket
     * @param  float  $rate  of leak in drips per second
     */
    public function __construct(string $key, float $rate)
    {
        parent::__construct($key, compact('rate'));
    }
}
