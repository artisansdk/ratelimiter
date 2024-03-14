<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Events;

class Filling extends Event
{
    /**
     * The bucket is filling with drips.
     *
     * @param  string  $key  of the bucket
     * @param  int  $drips  filled
     */
    public function __construct(string $key, int $drips)
    {
        parent::__construct($key, compact('drips'));
    }
}
