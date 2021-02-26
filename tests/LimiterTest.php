<?php

namespace ArtisanSdk\RateLimiter\Tests;

use ArtisanSdk\RateLimiter\Buckets\Evented;
use ArtisanSdk\RateLimiter\Buckets\Leaky;
use ArtisanSdk\RateLimiter\Limiter;
use ArtisanSdk\RateLimiter\Tests\Stubs\Cache;
use ArtisanSdk\RateLimiter\Tests\Stubs\Dispatcher;
use Carbon\Carbon;

class LimiterTest extends TestCase
{
    /**
     * Test that the rate limiter constructs the bucket and persists it to cache.
     */
    public function testPersistence()
    {
        $cache = new Cache();
        $original = new Limiter($cache, new Leaky('original'));
        $this->assertSame(0, $original->hits(), 'A new bucket limiter should not have any hits when constructed.');
        $this->assertSame(60, $original->remaining(), 'A new bucket limiter should have full remaining capacity when constructed.');

        $original->hit();
        $this->assertSame(1, $original->hits(), 'After the bucket is hit it should increment the hits.');
        $this->assertSame(59, $original->remaining(), 'After the bucket is hit it should decrement the remaining capacity.');

        $new = new Limiter($cache, new Leaky('new'));
        $this->assertNotSame($original->hits(), $new->hits(), 'Different buckets should be stored under different keys.');
        $this->assertNotSame($original->remaining(), $new->remaining(), 'Different buckets should be stored under different keys.');

        $existing = new Limiter($cache, new Leaky('original'));
        $this->assertSame($original->hits(), $existing->hits(), 'Persisted buckets should be stored under the same keys and retrieved on construction.');
        $this->assertSame($original->remaining(), $existing->remaining(), 'Persisted buckets should be stored under the same keys and retrieved on construction.');
    }

    /**
     * Test that the rate limiter can be configured.
     */
    public function testConfigure()
    {
        $cache = new Cache();
        $original = new Leaky('original');
        $limiter = new Limiter($cache, $original);
        $limiter->hit();

        $this->assertSame(60, $limiter->limit(), 'The default limit for the rate limiter should be 60.');
        $this->assertSame($original->toArray(), $cache->get('original'), 'The original bucket should have been persisted to the cache.');

        $limiter->configure('changed', 100, 10)->hit();
        $changed = $cache->get('changed');

        $this->assertNotSame($original->toArray(), $changed, 'The original bucket and the changed bucket should now be different.');
        $this->assertFalse($cache->has('original'), 'The original bucket for the rate limiter should have been removed when the bucket was reconfigured with a new key.');
        $this->assertTrue($cache->has('changed'), 'The key for the rate limiter bucket should have been configured as a string("changed").');
        $this->assertSame(100, $limiter->limit(), 'The limit for the rate limiter should have been configured as a int(100).');
        $this->assertSame(10.0, $changed['rate'], 'The rate for the rate limiter bucket should have been configured as a float(10).');
    }

    /**
     * Test that the rate limiter can be exceeded.
     */
    public function testExceeded()
    {
        $cache = new Cache();
        $limiter = new Limiter($cache, new Leaky('default', 2, 1));

        $limiter->hit();
        $this->assertFalse($limiter->exceeded(), 'The rate limiter should not be exceeded with 1 hit when the limit is 2.');

        $limiter->hit();
        $this->assertTrue($limiter->exceeded(), 'The rate limiter should be exceeded with 2 hits when the limit is 2.');

        $limiter->timeout();
        $limiter->reset();
        $this->assertTrue($limiter->exceeded(), 'The rate limiter should be exceeded because of a timeout even with 0 hits.');
        $this->assertSame(0, $limiter->hits(), 'The rate limiter should have 0 hits since it has been reset.');
    }

    /**
     * Test that the rate limiter timeout is set only once until expired.
     */
    public function testTimeout()
    {
        $cache = new Cache();
        $time = Carbon::now();
        $bucket = (new Leaky('foo'))->timer($time->getTimestamp());
        $limiter = new Limiter($cache, $bucket);
        $limiter->timeout();

        $this->assertSame($time->addMinutes(1)->getTimestamp(), (int) $cache->get('foo:timeout'), 'The timeout should have been set as a timestamp 1 minute in the future.');
        $this->assertSame(60, $limiter->backoff(), 'The timeout should have set the backoff duration to be 60 seconds.');

        $timer = $cache->get('foo:timeout');
        $limiter->timeout(10);
        $this->assertSame($timer, $cache->get('foo:timeout'), 'The timeout should not have changed because calling timeout on the rate limiter more than once should have no effect.');
    }

    /**
     * Test that the rate limiter resets the bucket and timeout when cleared.
     */
    public function testClear()
    {
        $cache = new Cache();
        $bucket = new Leaky('foo');
        (new Limiter($cache, new Leaky('bar')))->hit();
        $limiter = new Limiter($cache, $bucket);
        $limiter->hit();
        $limiter->timeout();
        $store = $cache->getStore();

        $this->assertSame(1, $limiter->hits());
        $this->assertSame(1, $bucket->drips());
        $this->assertTrue($limiter->hasTimeout());
        $this->assertCount(3, $store);
        $this->assertArrayHasKey('foo', $store);
        $this->assertArrayHasKey('foo:timeout', $store);
        $this->assertArrayHasKey('bar', $store);

        $limiter->clear();
        $store = $cache->getStore();

        $this->assertNotEmpty($store);
        $this->assertCount(1, $store);
        $this->assertArrayHasKey('bar', $store);
    }

    /**
     * Test that the rate limiter can handle multiple buckets.
     */
    public function testMultipleBuckets()
    {
        $cache = new Cache();
        $bucket = new Leaky('foo:bar:baz'); // we provide 3 keys to ensure only 2 are used
        $limiter = new Limiter($cache, $bucket);
        $limiter->hit();
        $store = $cache->getStore();
        $this->assertCount(2, $store, 'The rate limiter cache should have 2 items stored: the parent bucket and the actual bucket.');
        $this->assertArrayHasKey('foo', $store, 'The parent bucket should be present in the rate limiter cache.');
        $this->assertArrayHasKey('foo:bar:baz', $store, 'The actual bucket should be present in the rate limiter cache.');
        $this->assertNotSame($store['foo'], $store['foo:bar:baz'], 'The parent bucket and actual bucket should be different.');
        $this->assertSame(1, $store['foo']['drips'], 'The parent bucket should have 1 hit against it.');
        $this->assertSame(1, $store['foo:bar:baz']['drips'], 'The actual bucket should have 1 hit against it.');

        $limiter->reset();
        $store = $cache->getStore();
        $this->assertCount(1, $store, 'The rate limiter cache should have 1 item stored: the actual bucket should be removed when reset.');
        $this->assertArrayHasKey('foo', $store, 'The parent bucket should be present in the rate limiter cache.');
        $this->assertSame(1, $store['foo']['drips'], 'The parent bucket should still have 1 hit against it: resetting the actual bucket should not affect the parent bucket.');

        $limiter->hit();
        $store = $cache->getStore();
        $this->assertCount(2, $store, 'The rate limiter cache should have 2 items stored.');
        $this->assertArrayHasKey('foo', $store, 'The parent bucket should be present in the rate limiter cache.');
        $this->assertArrayHasKey('foo:bar:baz', $store, 'The actual bucket should be present in the rate limiter cache.');
        $this->assertNotSame($store['foo'], $store['foo:bar:baz'], 'The parent bucket and actual bucket should be different.');
        $this->assertSame(2, $store['foo']['drips'], 'The parent bucket should have 2 hits in it: reset did not reset the parent bucket.');
        $this->assertSame(1, $store['foo:bar:baz']['drips'], 'The actual bucket should have only 1 hit against it since it has been reset.');

        $limiter->timeout();
        $store = $cache->getStore();
        $this->assertCount(3, $store, 'The rate limiter cache should have 3 items stored: the timeout should be added.');
        $this->assertArrayHasKey('foo', $store, 'The parent bucket should be present in the rate limiter cache.');
        $this->assertArrayHasKey('foo:bar:baz', $store, 'The actual bucket should be present in the rate limiter cache.');
        $this->assertArrayHasKey('foo:bar:baz:timeout', $store, 'The timeout key should be added to the rate limiter cache.');

        $limiter->clear();
        $store = $cache->getStore();
        $this->assertCount(1, $store, 'The rate limiter cache should have 1 item stored: only the parent bucket should remain after the rate limiter is cleared.');
        $this->assertArrayHasKey('foo', $store, 'The parent bucket you should remain cache after the rate limiter is cleared.');
        $this->assertSame(2, $store['foo']['drips'], 'The parent bucket\'s capacity should remain unaffected after the rate limiter is cleared.');

        $bucket = new Leaky('foo:bar'); // we reuse the foo parent bucket key
        $limiter = new Limiter($cache, $bucket);
        $limiter->hit();
        $store = $cache->getStore();
        $this->assertCount(2, $store, 'The rate limiter cache should have 2 items stored: the parent bucket should be shared between actual buckets.');
        $this->assertArrayHasKey('foo', $store, 'The parent bucket should be present in the rate limiter cache.');
        $this->assertArrayHasKey('foo:bar', $store, 'The actual bucket should be present in the rate limiter cache.');
        $this->assertNotSame($store['foo'], $store['foo:bar'], 'The parent bucket and actual bucket should be different.');
        $this->assertSame(3, $store['foo']['drips'], 'The parent bucket should have 3 hits in it: parent bucket is shared across multiple actual buckets.');
        $this->assertSame(1, $store['foo:bar']['drips'], 'The actual bucket should have only 1 hit against it.');
    }

    /**
     * Test that the rate limiter passes the dispatcher to the evented buckets.
     */
    public function testDispatcher()
    {
        $cache = new Cache();
        $dispatcher = new Dispatcher();
        $bucket = new Evented($dispatcher, 'foo:bar', 60, 1);

        $limiter = new Limiter($cache, $bucket, $dispatcher);
        $this->assertCount(4, $dispatcher->getEvents(), 'There should have been 4 events dispatched because both the "foo" and "foo:bar" buckets should have been filled.');

        $limiter->configure('foo:bar:baz', 10, 1);
        $this->assertCount(6, $dispatcher->getEvents(), 'There should have been 2 more events dispatched because the "foo:bar" bucket was configured which caused it to be refilled.');
    }
}
