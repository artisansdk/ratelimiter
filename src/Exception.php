<?php

namespace ArtisanSDK\RateLimiter;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Response;

class Exception extends HttpException
{
    /**
     * Create a new exception instance.
     *
     * @param  string|null  $message
     * @param  \Exception|null  $previous
     * @param  array  $headers
     * @param  int  $code
     * @return void
     */
    public function __construct($message = null, \Exception $previous = null, array $headers = [], $code = 0)
    {
        parent::__construct(Response::HTTP_TOO_MANY_REQUESTS, $message, $previous, $headers, $code);
    }
}
