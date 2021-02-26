<?php

namespace ArtisanSdk\RateLimiter\Buckets;

use ArtisanSdk\RateLimiter\Contracts\Bucket;
use ArtisanSdk\RateLimiter\Events\Filled;
use ArtisanSdk\RateLimiter\Events\Filling;
use ArtisanSdk\RateLimiter\Events\Leaked;
use ArtisanSdk\RateLimiter\Events\Leaking;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Evented Leaky Bucket.
 */
class Evented extends Leaky
{
    /**
     * The events dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * {@inheritdoc}
     */
    public function __construct(Dispatcher $events, string $key = 'default', int $max = 60, $rate = 1)
    {
        parent::__construct($key, $max, $rate);

        $this->events = $events;
    }

    /**
     * {@inheritdoc}
     */
    public function leak($rate = null): Bucket
    {
        $rate = is_null($rate) ? $this->rate() : (float) $rate;

        $this->until(new Leaking(
            $this->key(),
            $rate
        ));

        $drips = $this->drips();

        parent::leak($rate);

        $this->fire(new Leaked(
            $this->key(),
            $drips - $this->drips(),
            $this->remaining()
        ));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function fill(int $drips = 1): Bucket
    {
        $drips = max(0, min($this->max(), $drips)); // out of bounds handling

        $this->until(new Filling(
            $this->key(),
            $drips
        ));

        $this->drips = $this->drips() + $drips;

        $this->fire(new Filled(
            $this->key(),
            $this->drips(),
            $this->remaining()
        ));

        return $this;
    }

    /**
     * Dispatch an event until the first non-null response is returned.
     *
     * @param string|object $event
     *
     * @return array|null
     */
    protected function until($event)
    {
        return $this->events->until($event);
    }

    /**
     * Dispatch an event and call the listeners.
     *
     * @param string|object $event
     *
     * @return array|null
     */
    protected function fire($event)
    {
        return method_exists($this->events, 'dispatch')
            ? $this->events->dispatch($event)
            : $this->events->fire($event);
    }
}
