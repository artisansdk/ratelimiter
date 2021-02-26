<?php

namespace ArtisanSdk\RateLimiter\Tests\Stubs;

use Closure;
use Illuminate\Contracts\Cache\Repository;

class Cache implements Repository
{
    /**
     * The storage implementation.
     *
     * @var array
     */
    protected $storage = [];

    /**
     * Determine if an item exists in the cache.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has($key)
    {
        return isset($this->storage[$key]);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return $this->storage[$key] ?? $default;
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function pull($key, $default = null)
    {
        $value = $this->get($key, $default);

        $this->forget($key);

        return $value;
    }

    /**
     * Store an item in the cache.
     *
     * @param string                                     $key
     * @param mixed                                      $value
     * @param \DateTimeInterface|\DateInterval|float|int $minutes
     */
    public function put($key, $value, $minutes = null)
    {
        $this->storage[$key] = $value;
    }

    /**
     * Store an item in the cache if the key does not exist.
     *
     * @param string                                     $key
     * @param mixed                                      $value
     * @param \DateTimeInterface|\DateInterval|float|int $minutes
     *
     * @return bool
     */
    public function add($key, $value, $minutes = null)
    {
        if ( ! $this->has($key)) {
            $this->put($key, $value, $minutes);

            return true;
        }

        return false;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        $this->storage[$key] = ! isset($this->storage[$key])
                ? $value : ((int) $this->storage[$key]) + $value;

        return $this->storage[$key];
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function forever($key, $value)
    {
        $this->put($key, $value, 0);
    }

    /**
     * Get an item from the cache, or store the default value.
     *
     * @param string                                     $key
     * @param \DateTimeInterface|\DateInterval|float|int $minutes
     *
     * @return mixed
     */
    public function remember($key, $minutes, Closure $callback)
    {
        $value = $this->get($key);

        if ( ! is_null($value)) {
            return $value;
        }

        $this->put($key, $value = $callback(), $minutes);

        return $value;
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function sear($key, Closure $callback)
    {
        return $this->rememberForever($key, $callback);
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function rememberForever($key, Closure $callback)
    {
        $value = $this->get($key);

        if ( ! is_null($value)) {
            return $value;
        }

        $this->forever($key, $value = $callback());

        return $value;
    }

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     *
     * @return bool
     */
    public function forget($key)
    {
        return $this->delete($key);
    }

    /**
     * Get the cache store implementation.
     *
     * @return \Illuminate\Contracts\Cache\Store
     */
    public function getStore()
    {
        return $this->storage; // this is return type non-compliant
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string                 $key   the key of the item to store
     * @param mixed                  $value the value of the item to store, must be serializable
     * @param int|\DateInterval|null $ttl   Optional. The TTL value of this item. If no value is sent and
     *                                      the driver supports TTL then the library may set a default value
     *                                      for it or let the driver take care of that.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException MUST be thrown if the $key string is not a legal value
     *
     * @return bool true on success and false on failure
     */
    public function set($key, $value, $ttl = null)
    {
        $this->storage[$key] = $value;

        return ture;
    }

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key the unique cache key of the item to delete
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException MUST be thrown if the $key string is not a legal value
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     */
    public function delete($key)
    {
        unset($this->storage[$key]);

        return true;
    }

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool true on success and false on failure
     */
    public function clear()
    {
        $this->storage = [];

        return true;
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable $keys    a list of keys that can obtained in a single operation
     * @param mixed    $default default value to return for keys that do not exist
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException MUST be thrown if $keys is neither an array nor a Traversable,
     *                                                   or if any of the $keys are not a legal value
     *
     * @return iterable A list of key => value pairs. Cache keys that do not exist or are stale will have $default as value.
     */
    public function getMultiple($keys, $default = null)
    {
        $values = [];

        foreach ($keys as $key => $value) {
            if ($this->has($key)) {
                $values[$key] = $this->get($key);
            }
        }

        return $values;
    }

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable               $values a list of key => value pairs for a multiple-set operation
     * @param int|\DateInterval|null $ttl    Optional. The TTL value of this item. If no value is sent and
     *                                       the driver supports TTL then the library may set a default value
     *                                       for it or let the driver take care of that.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *                                                   MUST be thrown if $values is neither an array nor a Traversable,
     *                                                   or if any of the $values are not a legal value
     *
     * @return bool true on success and false on failure
     */
    public function setMultiple($values, $ttl = null)
    {
        foreach ($values as $key => $value) {
            if ( ! $this->set($key, $value, $ttl)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable $keys a list of string-based keys to be deleted
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException MUST be thrown if $keys is neither an array nor a Traversable,
     *                                                   or if any of the $keys are not a legal value
     *
     * @return bool True if the items were successfully removed. False if there was an error.
     */
    public function deleteMultiple($keys)
    {
        foreach ($keys as $key) {
            if ( ! $this->delete($key)) {
                return false;
            }
        }

        return true;
    }
}
