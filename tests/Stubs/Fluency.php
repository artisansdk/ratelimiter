<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Tests\Stubs;

use ArtisanSdk\RateLimiter\Traits\Fluency as Concern;

#[\AllowDynamicProperties]
class Fluency
{
    use Concern;

    protected $barString = 'foo';

    protected $barArray = ['foo'];

    public function __call($method, $arguments = [])
    {
        return call_user_func_array([$this, 'property'],
            array_merge([$method], $arguments)
        );
    }
}
