<?php

namespace ArtisanSdk\RateLimiter\Tests\Buckets;

use ArtisanSdk\RateLimiter\Buckets\Evented;
use ArtisanSdk\RateLimiter\Events\Event;
use ArtisanSdk\RateLimiter\Events\Filled;
use ArtisanSdk\RateLimiter\Events\Filling;
use ArtisanSdk\RateLimiter\Events\Leaked;
use ArtisanSdk\RateLimiter\Events\Leaking;
use ArtisanSdk\RateLimiter\Tests\Stubs\Dispatcher;
use ArtisanSdk\RateLimiter\Tests\TestCase;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

class EventedTest extends TestCase
{
    /**
     * Test that the bucket, when constructed, is reset.
     */
    public function testConstruct()
    {
        $dispatcher = new Dispatcher();

        $bucket = new Evented($dispatcher, 'default', 60, 1);
        $this->assertSame('default', $bucket->key(), 'The default key for the bucket should be default.');
        $this->assertSame(60, $bucket->max(), 'The default max for the bucket should be 60.');
        $this->assertSame(1.0, $bucket->rate(), 'The default rate for the bucket should be 1 drip per second.');
        $this->assertSame(0, $bucket->drips(), 'The bucket should be reset to 0 drips when the bucket is created.');

        $bucket = new Evented($dispatcher, 'fast', 30, 10);
        $this->assertSame('fast', $bucket->key(), 'The passed key should be set on the bucket.');
        $this->assertSame(30, $bucket->max(), 'The passed max should be set on the bucket.');
        $this->assertSame(10.0, $bucket->rate(), 'The passed rate should be set on the bucket.');
        $this->assertSame(0, $bucket->drips(), 'The bucket should be reset to 0 drips when the bucket is created.');

        $bucket = new Evented($dispatcher, 'slow', 30, 0.1);
        $this->assertSame('slow', $bucket->key(), 'The passed key should be set on the bucket.');
        $this->assertSame(30, $bucket->max(), 'The passed max should be set on the bucket.');
        $this->assertSame(0.1, $bucket->rate(), 'The passed rate should be set on the bucket.');
        $this->assertSame(0, $bucket->drips(), 'The bucket should be reset to 0 drips when the bucket is created.');
    }

    /**
     * Test that the bucket emits events when leaking.
     */
    public function testLeak()
    {
        $dispatcher = new Dispatcher();
        $bucket = new Evented($dispatcher, 'default', 60, 1);
        $bucket->leak();
        $events = $dispatcher->getEvents();
        $this->assertCount(2, $events, 'There should have been 2 events dispatched by a call to leak(): Leaking and Leaked.');

        $leaked = array_pop($events);
        $this->assertInstanceOf(Leaked::class, $leaked, 'The last event should have been a '.Leaked::class.' event.');
        $this->assertInstanceOf(Event::class, $leaked, 'The leaked event should extend the '.Event::class.' class.');
        $this->assertInstanceOf(Arrayable::class, $leaked, 'The leaked event should implement the '.Arrayable::class.' interface.');
        $this->assertInstanceOf(Jsonable::class, $leaked, 'The leaked event should implement the '.Jsonable::class.' interface.');
        $this->assertInstanceOf(JsonSerializable::class, $leaked, 'The leaked event should implement the '.JsonSerializable::class.' interface.');
        $this->assertSame('default', $leaked->key, 'The leaked event should have the bucket\'s key in the payload.');
        $this->assertSame(0, $leaked->drips, 'The leaked event should have the number of drips leaked out of the bucket in the payload.');
        $this->assertSame(60, $leaked->remaining, 'The leaked event should have the number of remaining capacity of the bucket in the payload.');

        $leaking = array_pop($events);
        $this->assertInstanceOf(Leaking::class, $leaking, 'The first event should have been a '.Leaking::class.' event.');
        $this->assertInstanceOf(Event::class, $leaking, 'The leaking event should extend the '.Event::class.' class.');
        $this->assertInstanceOf(Arrayable::class, $leaking, 'The leaking event should implement the '.Arrayable::class.' interface.');
        $this->assertInstanceOf(Jsonable::class, $leaking, 'The leaking event should implement the '.Jsonable::class.' interface.');
        $this->assertInstanceOf(JsonSerializable::class, $leaking, 'The leaking event should implement the '.JsonSerializable::class.' interface.');
        $this->assertSame('default', $leaking->key, 'The leaking event should have the bucket\'s key in the payload.');
        $this->assertSame(1.0, $leaking->rate, 'The leaking event should have the bucket\'s leak rate in the payload.');
    }

    /**
     * Test that the bucket emits events when filling.
     */
    public function testFill()
    {
        $dispatcher = new Dispatcher();
        $bucket = new Evented($dispatcher, 'default', 60, 1);
        $bucket->fill(10);
        $events = $dispatcher->getEvents();
        $this->assertCount(2, $events, 'There should have been 2 events dispatched by a call to fill(): Filling and Filled.');

        $filled = array_pop($events);
        $this->assertInstanceOf(Filled::class, $filled, 'The last event should have been a '.Filled::class.' event.');
        $this->assertInstanceOf(Event::class, $filled, 'The filled event should extend the '.Event::class.' class.');
        $this->assertInstanceOf(Arrayable::class, $filled, 'The filled event should implement the '.Arrayable::class.' interface.');
        $this->assertInstanceOf(Jsonable::class, $filled, 'The filled event should implement the '.Jsonable::class.' interface.');
        $this->assertInstanceOf(JsonSerializable::class, $filled, 'The filled event should implement the '.JsonSerializable::class.' interface.');
        $this->assertSame('default', $filled->key, 'The filled event should have the bucket\'s key in the payload.');
        $this->assertSame(10, $filled->drips, 'The filled event should have the number of drips in the bucket in the payload.');
        $this->assertSame(50, $filled->remaining, 'The filled event should have the number of remaining capacity of the bucket in the payload.');

        $filling = array_pop($events);
        $this->assertInstanceOf(Filling::class, $filling, 'The first event should have been a '.Filling::class.' event.');
        $this->assertInstanceOf(Event::class, $filling, 'The filling event should extend the '.Event::class.' class.');
        $this->assertInstanceOf(Arrayable::class, $filling, 'The filling event should implement the '.Arrayable::class.' interface.');
        $this->assertInstanceOf(Jsonable::class, $filling, 'The filling event should implement the '.Jsonable::class.' interface.');
        $this->assertInstanceOf(JsonSerializable::class, $filling, 'The filling event should implement the '.JsonSerializable::class.' interface.');
        $this->assertSame('default', $filling->key, 'The filling event should have the bucket\'s key in the payload.');
        $this->assertSame(10, $filling->drips, 'The filling event should have the bucket\'s drip to be filled in the payload.');
    }
}
