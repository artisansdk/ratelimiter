<?php

namespace ArtisanSdk\RateLimiter\Resolvers;

class Route extends User
{
    /**
     * Get the resolver key used by the rate limiter for the unique request.
     */
    public function key(): string
    {
        $prefix = parent::key();

        $route = $this->request->route();

        if ($name = $route->getName()) {
            return $prefix.':'.sha1($name);
        }

        $class = $route->getAction('uses');
        if ( ! is_null($class) && is_string($class)) {
            return $prefix.':'.sha1($class);
        }

        return $prefix.':'.sha1($route->uri());
    }
}
