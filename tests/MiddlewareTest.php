<?php

namespace ArtisanSdk\RateLimiter\Tests;

use ArtisanSdk\RateLimiter\Buckets\Leaky;
use ArtisanSdk\RateLimiter\Contracts\Resolver as Contract;
use ArtisanSDK\RateLimiter\Exception;
use ArtisanSdk\RateLimiter\Limiter;
use ArtisanSdk\RateLimiter\Middleware;
use ArtisanSdk\RateLimiter\Tests\Stubs\Cache;
use ArtisanSdk\RateLimiter\Tests\Stubs\Request;
use ArtisanSdk\RateLimiter\Tests\Stubs\Resolver;
use Carbon\Carbon;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class MiddlewareTest extends TestCase
{
    /**
     * Test that the middleware calls to next.
     */
    public function testResponseHasXRateHeaders()
    {
        $cache = new Cache();
        $limiter = new Limiter($cache, new Leaky());
        $resolver = new Resolver();
        $middleware = new Middleware($limiter, $resolver);
        $request = Request::createFromGlobals();
        $next = function ($request) { return new Response(); };
        $response = $middleware->handle($request, $next);
        $headers = $response->headers;
        $store = $cache->getStore();
        $bucket = end($store);

        $this->assertSame($resolver->key(), $bucket['key'], 'The bucket should have been configured with the resolver\'s key.');
        $this->assertSame($resolver->rate(), $bucket['rate'], 'The bucket should have been configured with the resolver\'s rate.');
        $this->assertSame($resolver->max(), $limiter->limit(), 'The limiter should have been configured with the resolver\'s max.');
        $this->assertSame(1, $limiter->hits(), 'The limiter should have been hit 1 time by the middleware.');
        $this->assertSame($resolver->max() - 1, $limiter->remaining(), 'The limiter should have 1 hit remaining before exceeding the limits.');
        $this->assertInstanceOf(Response::class, $response, 'The middleware should return a response.');
        $this->assertTrue($headers->has('X-RateLimit-Limit'), 'The X-RateLimit-Limit header should present.');
        $this->assertTrue($headers->has('X-RateLimit-Remaining'), 'The X-RateLimit-Remaining header should be present.');
        $this->assertEquals($resolver->max(), $headers->get('X-RateLimit-Limit'), 'The X-RateLimit-Limit header should be the max limit of the resolver.');
        $this->assertEquals($resolver->max() - 1, $headers->get('X-RateLimit-Remaining'), 'The X-RateLimit-Remaining header should be the max limit of the resolver less the number of hits (1).');

        $response = $middleware->handle($request, $next);
        $headers = $response->headers;

        $this->assertEquals(2, $limiter->hits(), 'The limiter should have been hit 2 times by the middleware.');
        $this->assertEquals(0, $limiter->remaining(), 'The limiter should have 0 hits remaining.');
        $this->assertTrue($limiter->exceeded(), 'The middleware should have exceeded the limits of the limiter.');
        $this->assertTrue($headers->has('X-RateLimit-Limit'), 'The X-RateLimit-Limit header should present.');
        $this->assertTrue($headers->has('X-RateLimit-Remaining'), 'The X-RateLimit-Remaining header should be present.');
        $this->assertEquals($resolver->max(), $headers->get('X-RateLimit-Limit'), 'The X-RateLimit-Limit header should be the max limit of the resolver.');
        $this->assertEquals($resolver->max() - 2, $headers->get('X-RateLimit-Remaining'), 'The X-RateLimit-Remaining header should be the max limit of the resolver less the number of hits (2).');

        try {
            $middleware->handle($request, $next);
        } catch (Exception $exception) {
        }

        if ( ! $exception) {
            $this->fail('An exception should have been thrown when the rate limit was exceeded.');
        }

        $timer = (int) $cache->get('foo:timeout');
        $timeout = Carbon::createFromTimestamp($timer);
        $headers = $exception->getHeaders();

        $this->assertInstanceOf(Exception::class, $exception, 'The exception thrown should be a rate limiter exception.');
        $this->assertEquals(2, $headers['X-RateLimit-Limit'], 'The limit should not increase with additional hits via the middleware.');
        $this->assertEquals(0, $headers['X-RateLimit-Remaining'], 'The remaining hits should still be 0 with additional hits via the middleware.');
        $this->assertSame($resolver->duration() * 60, $limiter->backoff(), 'The backoff should be the duration of the timeout in seconds according to the resolver.');
        $this->assertEquals($limiter->backoff(), $headers['Retry-After'], 'The Retry-After header should be the same as the backoff.');
        $this->assertEquals($timeout->getTimestamp(), $headers['X-RateLimit-Reset'], 'The X-RateLimit-Reset header should be the same as the timestamp of the rate limiter timeout.');

        try {
            $cache->put('foo:timeout', $timer - 10, $resolver->duration());
            $middleware->handle($request, $next);
        } catch (Exception $exception) {
        }

        if ( ! $exception) {
            $this->fail('An exception should have been thrown when the rate limit was exceeded the second time.');
        }

        $timer = (int) $cache->get('foo:timeout');
        $timeout = Carbon::createFromTimestamp($timer);
        $headers = $exception->getHeaders();

        $this->assertInstanceOf(Exception::class, $exception, 'The exception thrown should be a rate limiter exception.');
        $this->assertEquals(2, $headers['X-RateLimit-Limit'], 'The limit should not increase with additional hits via the middleware while in a timeout.');
        $this->assertEquals(0, $headers['X-RateLimit-Remaining'], 'The remaining hits should still be 0 with additional hits via the middleware while in a timeout.');
        $this->assertSame($resolver->duration() * 60 - 10, $limiter->backoff(), 'The backoff should be 10 seconds less now the timeout has elapsed 10 seconds.');
        $this->assertSame($limiter->backoff(), $headers['Retry-After'], 'The Retry-After header should still be the same as the backoff.');
        $this->assertSame($timeout->getTimestamp(), $headers['X-RateLimit-Reset'], 'The X-RateLimit-Reset header should still be the same as the timestamp of the rate limiter timeout.');
    }

    /**
     * Test that an invalid resolver throws an exception.
     */
    public function testInvalidResolver()
    {
        $cache = new Cache();
        $limiter = new Limiter($cache, new Leaky());
        $resolver = new stdClass();
        $middleware = new Middleware($limiter, $resolver);
        $next = function ($request) { return new Response(); };

        try {
            $middleware->handle(Request::createFromGlobals(), $next);
        } catch (InvalidArgumentException $exception) {
        }

        if ( ! $exception) {
            $this->fail('An invalid resolver should throw an InvalidArgumentException.');
        }

        $this->assertSame(
            'stdClass must be an instance of '.Contract::class.'.',
            $exception->getMessage(),
            'The exception message should include the name of the resolver class and the needed interface.'
        );
    }
}
