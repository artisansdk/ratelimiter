<?php

namespace ArtisanSdk\RateLimiter;

use ArtisanSdk\RateLimiter\Contracts\Resolver;
use ArtisanSdk\RateLimiter\Resolvers\User;
use Carbon\Carbon;
use Closure;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leaky Bucket Rate Limiting Middleware.
 *
 * @example Route::middleware('throttle:Resolver\Route') --> default settings but using unique route key
 *          Route::middleware('throttle:60,1,10') --> 60 requests max, 1 r/s leak, 10 min lockout
 *          Route::middleware('throttle:60|300') --> 60 requests max as guest or 300 request max as user
 *          Route::middleware('throttle:60|rate_limit') --> accesses User::$rate_limit attribute for the max
 */
class Middleware
{
    /**
     * The rate limiter implementation.
     *
     * @var \ArtisanSdk\RateLimiter\Contracts\Limiter
     */
    protected $limiter;

    /**
     * The default request resolver.
     *
     * @var \ArtisanSdk\RateLimiter\Contracts\Resolver
     */
    protected $resolver;

    /**
     * Inject the rate limiter dependencies.
     *
     * @param \ArtisanSdk\RateLimiter\Contracts\Limiter         $limiter
     * @param string|\ArtisanSdk\RateLimiter\Contracts\Resolver $resolver
     */
    public function __construct(Limiter $limiter, $resolver = null)
    {
        $this->limiter = $limiter;
        $this->resolver = $resolver ?? User::class;
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @throws \ArtisanSdk\RateLimiter\Exception
     *
     * @return mixed
     */
    public function handle($request, Closure $next, ...$args)
    {
        $resolver = $this->makeResolver($request, $args);

        $limiter = $this->configureLimiter($resolver);

        if ($limiter->exceeded()) {
            $limiter->timeout($resolver->duration());
            throw $this->buildException($limiter->limit(), $limiter->remaining(), $limiter->backoff());
        }

        $limiter->hit();

        $response = $next($request);

        return $this->addHeaders($response,
            $limiter->limit(),
            $limiter->remaining()
        );
    }

    /**
     * Make an instance of the request resolver.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @throws \InvalidArgumentException for an invalid resolver class
     */
    protected function makeResolver(Request $request, array $args = []): Resolver
    {
        $class = array_shift($args);
        if (is_null($class) || ! class_exists($class)) {
            array_unshift($args, $class);
            $class = $this->resolver;
        }

        $resolver = new $class($request, ...$args);
        if ( ! $resolver instanceof Resolver) {
            throw new InvalidArgumentException(get_class($resolver).' must be an instance of '.Resolver::class.'.');
        }

        return $resolver;
    }

    /**
     * Configure the limiter from the resolver.
     *
     * @return \ArtisanSdk\RateLimiter\Contracts\Limiter
     */
    protected function configureLimiter(Resolver $resolver): Limiter
    {
        $this->limiter->configure(
            $resolver->key(),
            $resolver->max(),
            $resolver->rate()
        );

        return $this->limiter;
    }

    /**
     * Create a Too Many Requests exception.
     *
     * @param int $limit     of hits allowed
     * @param int $remaining hits allowed
     * @param int $backoff   before next hit should be attempted
     *
     * @return \ArtisanSdk\RateLimiter\Exception
     */
    protected function buildException(int $limit, int $remaining, int $backoff): Exception
    {
        $headers = $this->getHeaders($limit, $remaining, $backoff);

        return new Exception('Too Many Requests', null, $headers);
    }

    /**
     * Add the limit header information to the given response.
     *
     * @param int $limit     of hits allowed
     * @param int $remaining hits allowed
     * @param int $backoff   before next hit should be attempted
     */
    protected function addHeaders(Response $response, int $limit, int $remaining, int $backoff = null): Response
    {
        $response->headers->add(
            $this->getHeaders($limit, $remaining, $backoff)
        );

        return $response;
    }

    /**
     * Get the limit headers information.
     *
     * @param int $limit     of hits allowed
     * @param int $remaining hits allowed
     * @param int $backoff   before next hit should be attempted
     */
    protected function getHeaders(int $limit, int $remaining, int $backoff = null): array
    {
        $headers = [
            'X-RateLimit-Limit'     => $limit,
            'X-RateLimit-Remaining' => $remaining,
        ];

        if ( ! is_null($backoff)) {
            $headers['Retry-After'] = $backoff;
            $headers['X-RateLimit-Reset'] = Carbon::now()->addSeconds($backoff)->getTimestamp();
        }

        return $headers;
    }
}
