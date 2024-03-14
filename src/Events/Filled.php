<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Events;

class Filled extends Event
{
    /**
     * The bucket was filled with drips.
     *
     * @param  string  $key  of the bucket
     * @param  int  $drips  filled
     * @param  int  $remaining  drips till bucket overflows
     */
    public function __construct(string $key, int $drips, int $remaining)
    {
        parent::__construct($key, compact('drips', 'remaining'));
    }
}
