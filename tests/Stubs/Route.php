<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Tests\Stubs;

class Route
{
    /**
     * The name for the route.
     *
     * @var string
     */
    public $name = 'foo.bar';

    /**
     * The controller action for the route.
     *
     * @var string
     */
    public $action = 'App\Http\Controllers\Foo@bar';

    /**
     * Get the domain defined for the route.
     *
     * @return string|null
     */
    public function getDomain()
    {
        return 'example.test';
    }

    /**
     * Get the name of the route instance.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the action array or one of its properties for the route.
     *
     * @param  string|null  $key
     * @return mixed
     */
    public function getAction($key = null)
    {
        return $this->action;
    }

    /**
     * Get the URI associated with the route.
     *
     * @return string
     */
    public function uri()
    {
        return '/foo/bar';
    }
}
