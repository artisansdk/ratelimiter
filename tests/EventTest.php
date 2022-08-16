<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Tests;

use ArtisanSdk\RateLimiter\Events\Event;
use ArtisanSdk\RateLimiter\Events\Leaked;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

class EventTest extends TestCase
{
    /**
     * Test that the event implements the abstract.
     */
    public function testImplements()
    {
        $event = new Leaked('foo', 10, 50);

        $this->assertInstanceOf(Event::class, $event, 'The event should extend the '.Event::class.' class.');
        $this->assertInstanceOf(Arrayable::class, $event, 'The event should implement the '.Arrayable::class.' interface.');
        $this->assertInstanceOf(Jsonable::class, $event, 'The event should implement the '.Jsonable::class.' interface.');
        $this->assertInstanceOf(JsonSerializable::class, $event, 'The event should implement the '.JsonSerializable::class.' interface.');
    }

    /**
     * Test the event's magic methods.
     */
    public function testMagic()
    {
        $event = new Leaked('foo', 10, 50);
        $this->assertTrue(isset($event->key), 'The __isset() method should return true for keys set in the payload.');
        $this->assertFalse(isset($event->foo), 'The __isset() method should return false for keys not set in the payload.');

        $this->assertSame('foo', $event->key, 'The __get() method should return the value for keys set in the payload.');
        $this->assertNull($event->foo, 'The __get() method should return null for keys not set in the payload.');
    }

    /**
     * Test that the event can be converted to JSON.
     */
    public function testToJson()
    {
        $event = new Leaked('foo', 10, 50);

        $this->assertJson(
            '{"drips":10,"remaining":50,"key":"foo"}',
            $event->toJson(),
            'Event should be converted to JSON with "key", "drips", and "remaining" keys and values.'
        );
    }
}
