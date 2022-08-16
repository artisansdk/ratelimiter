<?php

declare(strict_types=1);

namespace ArtisanSdk\RateLimiter\Tests;

use ArtisanSdk\RateLimiter\Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExceptionTest extends TestCase
{
    /**
     * Test that a too many requests exception can be constructed.
     */
    public function testConstruct()
    {
        $exception = new Exception(
            'Too Many Requests',
            new \Exception('Previous'),
            ['X-Foo' => 'Bar'],
            $code = 4
        );

        $this->assertInstanceOf(HttpException::class, $exception, 'Rate limiter exception must extend Symfony\'s HttpException.');
        $this->assertSame('Too Many Requests', $exception->getMessage(), 'Message passed to rate limiter exception constructor should be the message for the exception.');
        $this->assertSame(4, $exception->getCode(), 'Code passed to rate limiter exception constructor should be the code for the exception.');
        $this->assertSame(429, $exception->getStatusCode(), 'Status code for rate limiter exception should be 429.');
        $this->assertSame('Bar', $exception->getHeaders()['X-Foo'], 'Headers passed to rate limiter constructor should be applied to HTTP exception.');
    }
}
