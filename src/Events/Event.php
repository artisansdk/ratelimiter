<?php

namespace ArtisanSdk\RateLimiter\Events;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

abstract class Event implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * The payload for the event.
     *
     * @var array
     */
    protected $payload = [];

    /**
     * Populate the payload of the event.
     *
     * @param string $key of the bucket
     */
    public function __construct(string $key, array $payload = [])
    {
        $this->fill($payload);
        $this->fill(compact('key'));
    }

    /**
     * Get the payload key.
     *
     * @param string $key
     *
     * @return mixed|null
     */
    public function __get($key)
    {
        return $this->payload[$key] ?? null;
    }

    /**
     * Determine if the payload key is set.
     *
     * @param string $key
     *
     * @return bool
     */
    public function __isset($key)
    {
        return isset($this->payload[$key]);
    }

    /**
     * Fill the event with the payload.
     *
     * @return \ArtisanSdk\RateLimiter\Events\Event
     */
    public function fill(array $payload = []): Event
    {
        foreach ($payload as $key => $value) {
            $this->payload[$key] = $value;
        }

        return $this;
    }

    /**
     * Convert the event to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->payload;
    }

    /**
     * Convert the event into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Convert the event to JSON.
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
