<?php

namespace ArtisanSDK\RateLimiter\Contracts;

interface Limiter
{
    /**
     * Determine if the limit threshold has been exceeded.
     *
     * @param string    $key  for the rate
     * @param int       $max  hits against the limiter
     * @param int|float $rate in which limiter decays or leaks
     *
     * @return \ArtisanSDK\RateLimiter\Contracts\Limiter
     */
    public function config(string $key, int $max, $rate);

    /**
     * Determine if the limit threshold has been exceeded.
     *
     * @return bool
     */
    public function exceeded(): bool;

    /**
     * Limit additional hits for the duration in minutes.
     *
     * @param int $duration in minutes for the limit to take effect
     */
    public function timeout(int $duration = 1): void;

    /**
     * Increment the counter for the rate limiter.
     *
     * @return int
     */
    public function hit(): int;

    /**
     * Get the maximum number of hits allowed by the limiter.
     *
     * @return int
     */
    public function limit(): int;

    /**
     * Get the number of remaining hits allowed by the limiter.
     *
     * @return int
     */
    public function remaining(): int;

    /**
     * Get the number of seconds until the limiter is available again.
     *
     * @return int
     */
    public function backoff(): int;
}
