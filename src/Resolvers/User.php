<?php

namespace ArtisanSdk\RateLimiter\Resolvers;

use ArtisanSdk\RateLimiter\Contracts\Resolver;
use Closure;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

class User implements Resolver
{
    /**
     * The request available to the resolver.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * The max number of requests allowed by the rate limiter.
     *
     * @var int|string
     */
    protected $max;

    /**
     * The replenish rate in requests per second for the rate limiter.
     *
     * @var int|string
     */
    protected $rate;

    /**
     * The duration in minutes the rate limiter will timeout.
     *
     * @var int|string
     */
    protected $duration;

    /**
     * The user resolver closure.
     *
     * @var \Closure
     */
    protected $userResolver;

    /**
     * Setup the resolver.
     *
     * @param \Illuminate\Http\Request $request
     * @param int|string               $max
     * @param int|float|string         $rate
     * @param int|string               $duration
     */
    public function __construct(Request $request, $max = null, $rate = null, $duration = null)
    {
        $this->request = $request;
        $this->max = $max ?? 60;
        $this->rate = $rate ?? 1;
        $this->duration = $duration ?? 1;
    }

    /**
     * Get the resolver key used by the rate limiter for the unique request.
     *
     * @throws \RuntimeException
     */
    public function key(): string
    {
        if ($user = $this->resolveUser()) {
            return sha1($user->getAuthIdentifier());
        }

        if ($route = $this->request->route()) {
            $domain = method_exists($route, 'getDomain')
                ? $route->getDomain()
                : $route->domain();

            return sha1(ltrim($domain.'|'.$this->request->ip(), '|'));
        }

        throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }

    /**
     * Get the max number of requests allowed by the rate limiter.
     */
    public function max(): int
    {
        return (int) $this->parse($this->max);
    }

    /**
     * Get the replenish rate in requests per second for the rate limiter.
     */
    public function rate(): float
    {
        return (float) $this->parse($this->rate);
    }

    /**
     * Get the duration in minutes the rate limiter will timeout.
     */
    public function duration(): int
    {
        return (int) $this->parse($this->duration);
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
            $parameter = explode('|', $parameter, 2)[$this->resolveUser() ? 1 : 0];
        }

        if ( ! is_numeric($parameter) && $this->resolveUser()) {
            return $this->resolveUser()->{$parameter};
        }

        return $parameter;
    }

    /**
     * Resolve the user from the request.
     *
     * @return mixed
     */
    protected function resolveuser()
    {
        return $this->userResolver
                ? call_user_func($this->userResolver, $this->request)
                : $this->request->user();
    }

    /**
     * Set the user resolver.
     */
    public function setUserResolver(Closure $resolver)
    {
        $this->userResolver = $resolver;
    }
}
