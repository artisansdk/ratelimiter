<?php

namespace ArtisanSdk\RateLimiter\Resolvers;

use ArtisanSdk\RateLimiter\Contracts\Resolver;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

class User implements Resolver
{
    /**
     * Setup the resolver.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $max
     * @param int                      $rate
     * @param int                      $duration
     */
    public function __construct(Request $request, $max = 60, $rate = 1, $duration = 1)
    {
        $this->request = $request;
        $this->max = (int) $this->parse($max);
        $this->rate = (float) $this->parse($rate);
        $this->duration = (int) $this->parse($duration);
    }

    /**
     * Get the resolver key used by the rate limiter for the unique request.
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    public function key(): string
    {
        if ($user = $this->user()) {
            return sha1($user->getAuthIdentifier());
        }

        if ($route = $this->request->route()) {
            return sha1($route->getDomain().'|'.$this->request->ip());
        }

        throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }

    /**
     * Get the max number of requests allowed by the rate limiter.
     *
     * @return int
     */
    public function max(): int
    {
        return $this->max;
    }

    /**
     * Get the replenish rate in requests per second for the rate limiter.
     *
     * @return float
     */
    public function rate(): float
    {
        return $this->rate;
    }

    /**
     * Get the duration the rate limiter will lock out for exceeding the limit.
     *
     * @return int
     */
    public function duration(): int
    {
        return $this->duration;
    }

    /**
     * Parse the parameter value if the user is authenticated or not.
     *
     * @param int|string $parameter
     *
     * @return int|float
     */
    protected function parse($parameter)
    {
        if (false !== stripos($parameter, '|')) {
            $parameter = explode('|', $parameter, 2)[$this->user() ? 1 : 0];
        }

        if ( ! is_numeric($parameter) && $this->user()) {
            $parameter = $this->parse($this->user()->{$parameter});
        }

        return $parameter;
    }

    /**
     * Get the user for the request.
     *
     * @return mixed
     */
    protected function user()
    {
        return $this->request->user();
    }
}
