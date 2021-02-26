<?php

namespace ArtisanSdk\RateLimiter\Tests\Stubs;

use Closure;
use Symfony\Component\HttpFoundation\Request as HttpRequest;

class Request extends HttpRequest
{
    /**
     * The route resolver closure.
     *
     * @var \Closure
     */
    protected $routeResolver;

    /**
     * Get the user making the request.
     *
     * @param string $identifier
     *
     * @return mixed
     */
    public function user($identifier = null)
    {
        return $identifier ? new User($identifier) : false;
    }

    /**
     * Get the route handling the request.
     *
     * @param string|null $param
     * @param mixed       $default
     *
     * @return \Illuminate\Routing\Route|object|string
     */
    public function route($param = null, $default = null)
    {
        return $this->routeResolver
                ? call_user_func($this->routeResolver)
                : new Route();
    }

    /**
     * Get the client IP address.
     *
     * @return string
     */
    public function ip()
    {
        return '0.0.0.0';
    }

    /**
     * Set the reoute resolver.
     */
    public function setRouteResolver(Closure $resolver)
    {
        $this->routeResolver = $resolver;
    }
}
