<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Tests\Resolvers;

use ArtisanSdk\RateLimiter\Resolvers\Route as Resolver;
use ArtisanSdk\RateLimiter\Tests\Stubs\Request;
use ArtisanSdk\RateLimiter\Tests\Stubs\Route;
use ArtisanSdk\RateLimiter\Tests\TestCase;
use RuntimeException;

class RouteTest extends TestCase
{
    /**
     * Test that the default route resolver can be constructed.
     */
    public function testConstruct()
    {
        $route = new Route();
        $request = Request::createFromGlobals();
        $resolver = new Resolver($request);
        $this->assertStringStartsWith(sha1($route->getDomain().'|'.$request->ip()), $resolver->key(), 'The parent key should be unique to the domain and IP address.');
        $this->assertStringEndsWith(':'.sha1($route->getName()), $resolver->key(), 'The sub key should be same as the route name.');
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
        $resolver = new Resolver(Request::createFromGlobals(), 30, 0.1, 300);
        $this->assertSame(30, $resolver->max(), 'The customized max should be int(30).');
        $this->assertSame(0.1, $resolver->rate(), 'The customized rate should be float(0.1).');
        $this->assertSame(300, $resolver->duration(), 'The customized duration should be int(300).');
    }

    /**
     * Test that the resolver falls back to controller action.
     */
    public function testAction()
    {
        $route = new Route();
        $request = Request::createFromGlobals();
        $request->setRouteResolver(function () {
            $route = new Route();
            $route->name = false;

            return $route;
        });
        $resolver = new Resolver($request);
        $this->assertStringStartsWith(sha1($route->getDomain().'|'.$request->ip()), $resolver->key(), 'The parent key should be unique to the domain and IP address.');
        $this->assertStringEndsWith(':'.sha1($route->getAction()), $resolver->key(), 'The sub key should be same as the route controller action.');
    }

    /**
     * Test that the resolver falls back to URI.
     */
    public function testUri()
    {
        $route = new Route();
        $request = Request::createFromGlobals();
        $request->setRouteResolver(function () {
            $route = new Route();
            $route->name = null;
            $route->action = null;

            return $route;
        });
        $resolver = new Resolver($request);
        $this->assertStringStartsWith(sha1($route->getDomain().'|'.$request->ip()), $resolver->key(), 'The parent key should be unique to the domain and IP address.');
        $this->assertStringEndsWith(':'.sha1($route->uri()), $resolver->key(), 'The sub key should be same as the route URI.');

        $request->setRouteResolver(function () {
            $route = new Route();
            $route->name = null;
            $route->action = function () {
            };

            return $route;
        });

        $this->assertStringEndsWith(':'.sha1($route->uri()), $resolver->key(), 'The sub key should be same as the route URI when the action is a closure.');
    }
}
