<?php

namespace ArtisanSdk\RateLimiter\Buckets;

use ArtisanSdk\RateLimiter\Contracts\Bucket;
use ArtisanSdk\RateLimiter\Traits\Fluency;

/**
 * Leaky Bucket.
 */
class Leaky implements Bucket
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
    public function __construct(string $key = 'default', int $max = 60, $rate = 1)
    {
        $this->key = $key;
        $this->configure(compact('max', 'rate'))->reset();
    }

    /**
     * Get the key for the bucket.
     *
     * @return string
     */
    public function key()
    {
        return $this->key;
    }

    /**
     * Get or set the timer for the bucket in UNIX seconds.
     *
     * @param float $value
     *
     * @return float|\ArtisanSdk\RateLimiter\Contracts\Bucket
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
     * @return int|\ArtisanSdk\RateLimiter\Contracts\Bucket
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
     * @return float|\ArtisanSdk\RateLimiter\Contracts\Bucket
     */
    public function rate($value = null)
    {
        return $this->property(__FUNCTION__, ! is_null($value) ? (float) $value : null);
    }

    /**
     * Get the number of drips in the bucket.
     */
    public function drips(): int
    {
        return max(0, ceil($this->drips));
    }

    /**
     * Get the remaining drips before the bucket overflows.
     */
    public function remaining(): int
    {
        return max(0, $this->max() - $this->drips());
    }

    /**
     * Get the duration in seconds before the bucket is fully drained.
     */
    public function duration(): float
    {
        return (float) max(0,
            microtime(true)
            + ($this->drips() / $this->rate())
            - $this->timer()
        );
    }

    /**
     * Is the bucket full?
     */
    public function isFull(): bool
    {
        return $this->drips() >= $this->max();
    }

    /**
     * Is the bucket empty?
     */
    public function isEmpty(): bool
    {
        return $this->drips() <= 0;
    }

    /**
     * Let the bucket leak at the rate per second.
     *
     * @param int|float $rate
     */
    public function leak($rate = null): Bucket
    {
        $drips = $this->drips();
        $rate = is_null($rate) ? $this->rate() : (float) $rate;
        $timer = $this->timer();
        $now = $this->reset()->timer();
        $elapsed = $now - $timer;
        $drops = floor($elapsed * $rate);

        $this->drips = $this->bounded($drips - $drops);

        return $this;
    }

    /**
     * Fill the bucket with the drips.
     */
    public function fill(int $drips = 1): Bucket
    {
        $this->drips = $this->drips() + $this->bounded($drips);

        return $this;
    }

    /**
     * Reset the bucket to empty.
     */
    public function reset(): Bucket
    {
        $this->drips = 0;
        $this->timer(microtime(true));

        return $this;
    }

    /**
     * Configure the setting for the bucket.
     *
     * @return \ArtisanSdk\RateLimiter\Contracts\Bucket
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

    /**
     * Get the bounded number of drips.
     */
    protected function bounded(int $drips): int
    {
        return max(0, min($this->max(), $drips));
    }
}
