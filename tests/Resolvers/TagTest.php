<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Tests\Resolvers;

use ArtisanSdk\RateLimiter\Resolvers\Tag as Resolver;
use ArtisanSdk\RateLimiter\Tests\Stubs\Request;
use ArtisanSdk\RateLimiter\Tests\Stubs\Route;
use ArtisanSdk\RateLimiter\Tests\TestCase;
use RuntimeException;

class TagTest extends TestCase
{
    /**
     * Test that the default tag resolver can be constructed.
     */
    public function testConstruct()
    {
        $route = new Route();
        $request = Request::createFromGlobals();
        $resolver = new Resolver($request, 'foo');
        $this->assertSame('foo', $resolver->tag(), 'The tag should be the same as what was passed to the constructor.');
        $this->assertStringStartsWith(sha1($route->getDomain().'|'.$request->ip()), $resolver->key(), 'The parent key should be unique to the domain and IP address.');
        $this->assertStringEndsWith(':foo', $resolver->key(), 'The sub key should be same as the tag.');
        $this->assertSame(60, $resolver->max(), 'The default max should be int(60).');
        $this->assertSame(1.0, $resolver->rate(), 'The default rate should be float(1).');
        $this->assertSame(60, $resolver->duration(), 'The default rate should be int(60).');

        $request->setRouteResolver(function () {
            return false;
        });

        try {
            $resolver->key();
        } catch (RuntimeException $exception) {
            return;
        }

        $this->fail('A RuntimeException should have been thrown because no route exists.');
    }

    /**
     * Test that the default configuration for the resolver can be customized.
     */
    public function testConfiguration()
    {
        $resolver = new Resolver(Request::createFromGlobals(), 'foo', 30, 0.1, 300);
        $this->assertSame(30, $resolver->max(), 'The customized max should be int(30).');
        $this->assertSame(0.1, $resolver->rate(), 'The customized rate should be float(0.1).');
        $this->assertSame(300, $resolver->duration(), 'The customized duration should be int(300).');
    }
}
