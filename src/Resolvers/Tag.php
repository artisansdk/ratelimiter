<?php

namespace ArtisanSdk\RateLimiter\Resolvers;

use Symfony\Component\HttpFoundation\Request;

class Tag extends User
{
    /**
     * The tag used as the rate limiter key.
     *
     * @var string
     */
    protected $tag;

    /**
     * Setup the resolver.
     *
     * @param \Illuminate\Http\Request $request
     * @param string                   $tag
     * @param int|string               $max
     * @param int|float|string         $rate
     * @param int|string               $duration
     */
    public function __construct(Request $request, string $tag, $max = 60, $rate = 1, $duration = 1)
    {
        parent::__construct($request, $max, $rate, $duration);

        $this->tag = $tag;
    }

    /**
     * Get the resolver key used by the rate limiter for the unique request.
     *
     * @return string
     */
    public function key(): string
    {
        return parent::key().':'.$this->tag();
    }

    /**
     * Get the tag used as the rate limiter key.
     *
     * @return string
     */
    public function tag(): string
    {
        return $this->tag;
    }
}
