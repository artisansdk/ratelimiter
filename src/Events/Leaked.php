<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Events;

class Leaked extends Event
{
    /**
     * The bucket leaked the drips.
     *
     * @param  string  $key  of the bucket
     * @param  int  $drips  leaked
     * @param  int  $remaining  drips in the bucket
     */
    public function __construct(string $key, int $drips, int $remaining)
    {
        parent::__construct($key, compact('drips', 'remaining'));
    }
}
