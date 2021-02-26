<?php

namespace ArtisanSdk\RateLimiter\Contracts;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

interface Bucket extends Arrayable, Jsonable, JsonSerializable
{
    /**
     * Get the key for the bucket.
     *
     * @return string
     */
    public function key();

    /**
     * Get or set the timer for the bucket in UNIX seconds.
     *
     * @param float $value
     *
     * @return float|\ArtisanSdk\RateLimiter\Contracts\Bucket
     */
    public function timer($value = null);

    /**
     * Get or set the maximum capacity of the bucket.
     *
     * @param int $value
     *
     * @return int|\ArtisanSdk\RateLimiter\Contracts\Bucket
     */
    public function max(int $value = null);

    /**
     * Get or set the rate per second the bucket leaks.
     *
     * @param int|float $value
     *
     * @return float|\ArtisanSdk\RateLimiter\Contracts\Bucket
     */
    public function rate($value = null);

    /**
     * Is the bucket full?
     */
    public function isFull(): bool;

    /**
     * Is the bucket empty?
     */
    public function isEmpty(): bool;

    /**
     * Reset the bucket to empty.
     *
     * @return \ArtisanSdk\RateLimiter\Contracts\Bucket
     */
    public function reset(): Bucket;

    /**
     * Configure the setting for the bucket.
     *
     * @return \ArtisanSdk\RateLimiter\Contracts\Bucket
     */
    public function configure(array $settings);
}
