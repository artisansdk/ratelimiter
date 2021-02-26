<?php

namespace ArtisanSdk\RateLimiter;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Exception extends HttpException
{
    /**
     * Create a new exception instance.
     *
     * @param string|null $message
     * @param int         $code
     */
    public function __construct($message = null, \Exception $previous = null, array $headers = [], $code = 0)
    {
        parent::__construct(Response::HTTP_TOO_MANY_REQUESTS, $message, $previous, $headers, $code);
    }
}
