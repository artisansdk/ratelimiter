<?php

namespace ArtisanSDK\RateLimiter;

use ArtisanSDK\RateLimiter\Traits\Fluency;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

/**
 * Leak Bucket.
 */
class Bucket implements ArrayAccess, Arrayable, Jsonable, JsonSerializable
{
    use Fluency;

    /**
     * The unique key for the bucket.
     *
     * @var string
     */
    protected $key;

    /**
     * The maximum capacity of the bucket before overflowing.
     *
     * @var int
     */
    protected $max;

    /**
     * The rate per second that the bucket leaks drips.
     *
     * @var float
     */
    protected $rate;

    /**
     * The drips in the bucket.
     *
     * @var int
     */
    protected $drips = 0;

    /**
     * The timer for the bucket.
     *
     * @var float
     */
    protected $timer;

    /**
     * Setup a new bucket.
     *
     * @example new Bucket('foo') max capacity of 60 leaking at 1 r/s
     *          new Bucket('foo', 100, 10) max capacity of 100 leaking at 10 r/s
     *          new Bucket('foo', 100, 0.1) max capacity of 100 leaking at rate of 1 every 10 seconds
     *
     * @param string    $key  for the bucket cache
     * @param int       $max  capacity
     * @param int|float $rate of leak per second
     */
    public function __construct(string $key, int $max = 60, $rate = 1)
    {
        $this->key($key)
            ->max($max)
            ->rate($rate)
            ->reset();
    }

    /**
     * Get or set the key for the bucket.
     *
     * @param string $value
     *
     * @return string|self
     */
    public function key(string $value = null)
    {
        return $this->property(__FUNCTION__, $value);
    }

    /**
     * Get or set the timer for the bucket in UNIX seconds.
     *
     * @param float $value
     *
     * @return float|self
     */
    public function timer($value = null)
    {
        return $this->property(__FUNCTION__, ! is_null($value) ? (float) $value : null);
    }

    /**
     * Get or set the maximum capacity of the bucket.
     *
     * @param int $value
     *
     * @return int|self
     */
    public function max(int $value = null)
    {
        return $this->property(__FUNCTION__, $value);
    }

    /**
     * Get or set the rate per second the bucket leaks.
     *
     * @param int|float $value
     *
     * @return float|self
     */
    public function rate($value = null)
    {
        return $this->property(__FUNCTION__, ! is_null($value) ? (float) $value : null);
    }

    /**
     * Get the number of drips in the bucket.
     *
     * @return int
     */
    public function drips(): int
    {
        return max(0, ceil($this->drips));
    }

    /**
     * Get the remaining drips before the bucket overflows.
     *
     * @return int
     */
    public function remaining(): int
    {
        return max(0, $this->max() - $this->drips());
    }

    /**
     * Is the bucket full?
     *
     * @return bool
     */
    public function isFull(): bool
    {
        return $this->drips() >= $this->max();
    }

    /**
     * Is the bucket empty?
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->drips() <= 0;
    }

    /**
     * Let the bucket leak at the rate per second.
     *
     * @param int|float $rate
     *
     * @return self
     */
    public function leak($rate = null): self
    {
        $drips = $this->drips();
        $rate = is_null($rate) ? $this->rate() : (float) $rate;
        $timer = $this->timer();
        $now = $this->reset()->timer();
        $elapsed = $now - $timer;
        $this->fill($drips - floor($elapsed * $rate));

        return $this;
    }

    /**
     * Fill the bucket with the drips.
     *
     * @param int $drips
     *
     * @return self
     */
    public function fill(int $drips = 1): self
    {
        $drips = max(0, min($this->max(), $drips)); // out of bounds handling

        $this->drips = $this->drips() + $drips;

        return $this;
    }

    /**
     * Reset the bucket to empty.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->drips = 0;
        $this->timer(microtime(true));

        return $this;
    }

    /**
     * Configure the setting for the bucket.
     *
     * @param array $settings
     *
     * @return self
     */
    public function configure(array $settings)
    {
        foreach (['timer', 'max', 'rate'] as $config) {
            if (isset($settings[$config])) {
                $this->$config($settings[$config]);
            }
        }

        if (isset($settings['drips'])) {
            $this->drips = 0;
            $this->fill($settings['drips']);
        }

        return $this;
    }

    /**
     * Convert the bucket to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'key'       => $this->key(),
            'timer'     => $this->timer(),
            'max'       => $this->max(),
            'rate'      => $this->rate(),
            'drips'     => $this->drips(),
            'remaining' => $this->remaining(),
        ];
    }

    /**
     * Convert the bucket into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Convert the bucket to JSON.
     *
     * @param int $options
     *
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }
}
