<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Tests\Resolvers;

use ArtisanSdk\RateLimiter\Resolvers\User as Resolver;
use ArtisanSdk\RateLimiter\Tests\Stubs\Request;
use ArtisanSdk\RateLimiter\Tests\Stubs\Route;
use ArtisanSdk\RateLimiter\Tests\TestCase;
use RuntimeException;

class UserTest extends TestCase
{
    /**
     * Test that the default user resolver can be constructed.
     */
    public function testConstruct()
    {
        $resolver = new Resolver(Request::createFromGlobals());
        $resolver->setUserResolver(function ($request) {
            return $request->user('johndoe@example.test');
        });

        $this->assertStringStartsWith(sha1('johndoe@example.test'), $resolver->key(), 'The key should be unique to the user.');
        $this->assertSame(60, $resolver->max(), 'The default max should be int(60).');
        $this->assertSame(1.0, $resolver->rate(), 'The default rate should be float(1).');
        $this->assertSame(60, $resolver->duration(), 'The default rate should be int(60).');
    }

    /**
     * Test that the default configuration for the resolver can be customized.
     */
    public function testConfiguration()
    {
        $resolver = new Resolver(Request::createFromGlobals(), 30, 0.1, 300);
        $this->assertSame(30, $resolver->max(), 'The customized max should be int(30).');
        $this->assertSame(0.1, $resolver->rate(), 'The customized rate should be float(0.1).');
        $this->assertSame(300, $resolver->duration(), 'The customized duration should be int(300).');
    }

    /**
     * Test that a runtime exception occurs if there is no route.
     */
    public function testException()
    {
        $request = Request::createFromGlobals();
        $request->setRouteResolver(function () {
            return false;
        });
        $resolver = new Resolver($request);
        try {
            $resolver->key();
        } catch (RuntimeException $exception) {
        }

        if (! $exception) {
            $this->fail('A RuntimeException should have been thrown because no route exists.');
        }

        $this->assertSame(
            'Unable to generate the request signature. Route unavailable.',
            $exception->getMessage(),
            'The RuntimeException should explain that a request signature could not be created because the route is unavailable.'
        );
    }

    /**
     * Test that a guest can have their own limits.
     */
    public function testGuestRates()
    {
        $resolver = new Resolver(Request::createFromGlobals(), '30|60', '0.1|1', '600|60');

        $this->assertSame(30, $resolver->max(), 'The guest max should be int(30).');
        $this->assertSame(0.1, $resolver->rate(), 'The guest rate should be float(0.1).');
        $this->assertSame(600, $resolver->duration(), 'The guest duration should be int(600).');

        $resolver->setUserResolver(function ($request) {
            return $request->user('johndoe@example.test');
        });

        $this->assertSame(60, $resolver->max(), 'The user max should be int(60).');
        $this->assertSame(1.0, $resolver->rate(), 'The user rate should be float(1).');
        $this->assertSame(60, $resolver->duration(), 'The user duration should be int(60).');
    }

    /**
     * Test that a user can have their own limits.
     */
    public function testUserRates()
    {
        $resolver = new Resolver(Request::createFromGlobals(), '60|max', '1|rate', '600|duration');

        $this->assertSame(60, $resolver->max(), 'The guest max should be int(60).');
        $this->assertSame(1.0, $resolver->rate(), 'The guest rate should be float(1).');
        $this->assertSame(600, $resolver->duration(), 'The guest duration should be int(600).');

        $resolver->setUserResolver(function ($request) {
            return $request->user('johndoe@example.test');
        });

        $this->assertSame(100, $resolver->max(), 'The user max should be int(100).');
        $this->assertSame(10.0, $resolver->rate(), 'The user rate should be float(10).');
        $this->assertSame(60, $resolver->duration(), 'The user duration should be int(60).');
    }
}
