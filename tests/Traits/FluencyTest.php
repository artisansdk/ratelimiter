<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Tests\Traits;

use ArtisanSdk\RateLimiter\Tests\Stubs\Fluency;
use ArtisanSdk\RateLimiter\Tests\TestCase;

class FluencyTest extends TestCase
{
    /**
     * Test that a fluent property can be set.
     */
    public function testSet()
    {
        $stub = new Fluency();
        $stub->fooString('bar');
        $stub->fooArray(['bar']);

        $this->assertSame('bar', $stub->fooString, 'Calling property() with a string as the second argument should set the string value as a named property based on the first argument.');
        $this->assertSame(['bar'], $stub->fooArray, 'Calling property() with an array as the second argument should set the array value as a named property based on the first argument.');
    }

    /**
     * Test that a fluent property can be gotten.
     */
    public function testGet()
    {
        $stub = new Fluency();

        $this->assertSame('foo', $stub->barString(), 'Calling property() without a second argument should return the string value under the named property based on the first argument.');
        $this->assertSame(['foo'], $stub->barArray(), 'Calling property() without a second argument should return the array value under the named property based on the first argument.');
    }

    /**
     * Test that a fluent property call can be chained.
     */
    public function testChaining()
    {
        $stub = new Fluency();
        $this->assertSame($stub, $stub->fooString('foo'), 'Calling property() with multiple arguments should be fluent, allowing for chaining: return the class itself after setting a value.');
        $this->assertSame($stub, $stub->fooString('foo')->barString('bar'), 'Calling property() with multiple arguments should be fluent, allowing for chaining: return the class itself after setting a value.');
        $this->assertSame('bar', $stub->fooString('foo')->barString(), 'Calling property() without multiple arguments should return the value: do not chain after returning a value.');
        $this->assertSame('foo', $stub->fooString(), 'Calling property() repeatedly in a chain should allow for multiple values to be set.');
        $this->assertSame('bar', $stub->barString(), 'Calling property() repeatedly in a chain should allow for multiple values to be set.');
    }
}
