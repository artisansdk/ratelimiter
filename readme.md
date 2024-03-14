# Rate Limiter

A leaky bucket rate limiter and corresponding middleware with route-level granularity compatible with Laravel.

## Table of Contents

- [Table of Contents](#table-of-contents)
- [Installation](#installation)
- [Usage Guide](#usage-guide)
  - [Overview of the Laravel Rate Limiter](#overview-of-the-laravel-rate-limiter)
    - [Laravel's Implementation](#laravels-implementation)
    - [Problem 1: Bursting Exploit](#problem-1-bursting-exploit)
    - [Problem 2: No Granularity](#problem-2-no-granularity)
    - [Problem 3: Not Extensible](#problem-3-not-extensible)
  - [Understanding the Leaky Bucket Algorithm](#understanding-the-leaky-bucket-algorithm)
    - [Leaky Bucket Implementation](#leaky-bucket-implementation)
    - [Solution 1: Bursting Limit](#solution-1-bursting-limit)
    - [Solution 2: Route-Level Granularity](#solution-2-route-level-granularity)
    - [Solution 3: Extensible Key Resolvers](#solution-3-extensible-key-resolvers)
    - [Bonus: Overflow Penalties](#bonus-overflow-penalties)
  - [Different Rates for Guests vs. Authenticated Users](#different-rates-for-guests-vs-authenticated-users)
  - [Different Rates for Different Users](#different-rates-for-different-users)
  - [Handling the Rate Limit Exceptions](#handling-the-rate-limit-exceptions)
  - [Setting a Custom Cache for the Rate Limiter](#setting-a-custom-cache-for-the-rate-limiter)
  - [How Request Signature Resolvers Work](#how-request-signature-resolvers-work)
  - [How Multiple Buckets Work](#how-multiple-buckets-work)
    - [Using the Built In Resolvers](#using-the-built-in-resolvers)
    - [Creating Custom Resolvers](#creating-custom-resolvers)
    - [Setting a Custom Resolver as the Default](#setting-a-custom-resolver-as-the-default)
  - [Using the Rate Limiter by Itself](#using-the-rate-limiter-by-itself)
    - [Creating a Custom Rate Limiter](#creating-a-custom-rate-limiter)
  - [Using the Bucket by Itself](#using-the-bucket-by-itself)
    - [Using the Evented Bucket](#using-the-evented-bucket)
    - [Logging the Drips in the Bucket](#logging-the-drips-in-the-bucket)
- [Running the Tests](#running-the-tests)
- [Licensing](#licensing)

## Installation

The package installs into a PHP application like any other PHP package:

```bash
composer require artisansdk/ratelimiter
```

Once installed, you will need to bind your choice of `Bucket` implementations for
the rate `Limiter` class. Choose either the `Leaky` or the Leaky `Evented` bucket
if you need additional event dispatching. Add the following lines to your
`App\Providers\AppServiceProvider`:

```php
use ArtisanSdk\RateLimiter\Buckets\Leaky;
use ArtisanSdk\RateLimiter\Contracts\Bucket;

public function register()
{
    $this->app->bind(Bucket::class, Leaky::class);
}
```

If you do plan on using the `Evented` leaky bucket then you'll also want to change
to the following binding to your `register()` method. The event dispatcher is
injected automatically by Laravel:

```php
use ArtisanSdk\RateLimiter\Buckets\Evented;
use ArtisanSdk\RateLimiter\Contracts\Bucket;

public function register()
{
    $this->app->bind(Bucket::class, Evented::class);
}
```

The package includes middleware for the rate limiter which is compatible with
Laravel's built in `Illuminate\Routing\Middleware\ThrottleRequests`. Simply update
the `App\Http\Kernel::$routeMiddleware` array so that the `throttle` key points
to `ArtisanSdk\RateLimiter\Middleware` like so:

```php
protected $routeMiddleware = [
    // ...
    'throttle' => \ArtisanSdk\RateLimiter\Middleware::class,
];
```

Now requests will go through the leaky bucket rate limiter. The requests are throttled
according to the algorithm which leaks at rate of 1 request per second (r\s) with a
maximum capacity of 60 requests with a 1 minute timeout when the limit is exceeded.
This is based on the default Laravel signature of `throttle:60,1` which is found in
`App\Http\Kernel::$middlewareGroups` under the `api` group:

```php
protected $middlewareGroups = [
    // ...
    'api' => [
        'throttle:60,1',
        'bindings',
    ],
];
```

Change the rates or add `throttle:60,1` to the `web` group as well to rate limit
even regular page requests. See the [Usage Guide](#usage-guide) for more options
including using the rate limiter and bucket without the middleware.

## Usage Guide

### Overview of the Laravel Rate Limiter

Laravel shipped without rate limiting for years and so it is a welcomed addition.
To be fair, some rate limiting is better than none at all. From a security
perspective though, at best Laravel's rate limiter only slows down a hacker,
typically trips up legitimate usage, and presents a false sense of security.

#### Laravel's Implementation

Laravel has a fixed decay rate limiter. With default settings of `throttle:60,1`
this means that a client could make `60` requests with `1` minute of decay before hitting
a `1` minute forced decay timeout. The client could make `60` requests in `1` second
or distributed over `60` seconds at a rate of 1 request per second (`1 r/s`). If the
requests are evenly spaced at about `1 r/s` then the client will not be rate limited.
This means that `throttle:120,2` is effectively the same as `1 r/s` but tracked for
`2` minutes and allowing a larger burst limit up to `120` requests. Meanwhile
`throttle:120,1` would be an effective rate of `2 r/s` with the same burst limit.

#### Problem 1: Bursting Exploit

Generally you want both of these numbers to be large because that provides
more tracking of abuse while allowing for sufficient legitimate requests. For
example if the goal is to get `1 r/s` average over `24` hours up to `100K` requests
that would translate to `throttle:100000,1440`. Every day a client could dump
`100K` requests in `1` second! So much for `1 r/s` load balancing. Furthermore, there's
no penalty for abuse – just wait the `1440` minutes and you can do it again as if
you made `1 r/s` non stop for ever. So you lower it to `throttle:3600,60` so bursting
is limited to `3600` requests but the rates are reset every hour. There might be
a sweet spot but it is hard to get just right.

#### Problem 2: No Granularity

Also the signature for the client is determined by the domain and IP address of
the requester. While most hackers will randomize their IP and almost all rate limiters
suffer from this (and the shared IP address issue common on public networks), all
requests made by a user dump into the same cache of hits for that client. So you
set your throttler differently for different routes like `throttle:10,10`
for a user login screen vs. `throttle:60,1` for other routes because you hear you
are suppose to rate limit resources according to their typical usage. Instead of
working you find your users hit rate limits because they made a lot of requested
against one route that tripped the rate limiter on another. So you raise the limits
because that sounds like the simple fix but it turns out you just increased your
attack surface area.

#### Problem 3: Not Extensible

If the user is logged in, Laravel does use the unique identifier for the user as
the key which is better than the IP address. Even better, different rates for
different users using a string based key like `throttle:60|rate_limit` which
translates to `60` requests by guests and whatever `Auth::user()->rate_limit` returns
for users (or if you use the Laravel suggested `throttle:rate_limit` value, it will
actually be the same as `throttle:0` for guests). That's all fine but you want to rate
limit the resources the user accesses differently. The only answer to that problem
is to hack the `Illuminate\Routing\Middleware\ThrottleRequests` middleware and overload
the `resolveRequestSignature()` method to return your custom key. Oh, and let's not
forget that the same decay rate is used for both guests and authenticated users
so you have to grok your way through that inadvertent security coupling.

### Understanding the Leaky Bucket Algorithm

The answer to Laravel's rate limiter is a better algorithm that includes
a couple of additional configuration settings.

#### Leaky Bucket Implementation

The Leaky Bucket Algorithm is the rate limiter this package implements. As its
name suggests, there is a bucket (a cache) that you fill with drips (a request)
up to the maximum capacity (the limit) at which point if you continue filling it
will overflow (rate limiting). This bucket also leaks at a constant rate of drips
per second (requests per second). This means that if you fill the bucket with drips
at the same rate in which it leaks then you can continue hitting it forever
without overflowing. You can also burst up to the maximum capacity which has no
effect on the leak rate. So effectively the Leaky Bucket Algorithm enforces a
constant drip rate determined not by the number of drips added to the bucket but
by the leak rate in constant time. Since the algorithm tracks leaks and buckets
and not just drips, buckets can be persisted for a longer time to track malicious
activity longer and rate limit a more balanced request load.

#### Solution 1: Bursting Limit

As already, explained bursting is an exploit that a hacker can use against the
Laravel rate limiter and to monitor (increase the limits) the exploit makes the
attack surface area even bigger. The bursting limit in a Leaky Bucket implementation
is a separate limit that does not expire in a binary, all or nothing, way but
expires one drip at a time as the bucket leaks. With this implementation you set the
bursting limit and that limit drains slowly over time at the constant leak rate. This
means that so long as the client does not exceed the limits and enters a timeout, the
client can burst up to this limit then wait as little as the leak rate to make one more
request. They can trickle in requests constantly so long as they don't overflow the
bucket's maximum capacity.

For example if the settings were `throttle:60,1` then the user can burst
up in the first second to `60` requests, and only has to wait `1` second to make
a subsequent request but if they make `2` requests then they'll overflow which
introduces the timeout penalty. The more time the client rests the more requests
per second they can make. The two configurations in the settings translate to
the `60` maximum requests allowed when bursting and effectively an average request
per second rate of `1 r/s` (technically it's `1` leak per second or `1 l/s`). Now
setting higher limits represents increased performance, and since they are independent
of each other, configuration is clear with respect to its effect.

#### Solution 2: Route-Level Granularity

As mentioned, Laravel's built in key resolver for determining what a unique client
is simply based on the guest's IP address or the authenticated user's identifier.
There's no more granularity than this and any attempt to introduce granularity
feels like a hack or is riddled with complexity. Instead, this rate limiter ships
with a couple of different resolvers including `ArtisanSdk\RateLimiter\Resolver\Route`
class which attempts to match the client against rates specific to the URI they
are requesting. This is done firstly by the route's name, falling back to the
route's controller method, before finally just using the URI. If none of these
fallbacks can be resolved, it'll just revert back to default behavior of resolving
to the guest's IP address or authenticated user's identifier. To use this resolver,
you simply set it in the route binding like so `throttle:ArtisanSdk\RateLimiter\Resolver\Route,60,1`.

The rate limiter implements a Leaky Bucket and as such granularity for one bucket
needs to apply to the greater limits applied to the user's more global limits. This
implementation uses a multi-bucket solution. The bucket's rates cascade
from outside in from more global to more specific. You can imagine the more granular
route-level rates as being a subset of the more user-level rate limits so a hit
against the route-level rates counts as a hit against the user-level rates. If
either one of them is tripped then that bucket's limits are in effect. Different
routes share the same parent user-level buckets but have separate bucket limits
themselves so tripping only a route-level bucket will prevent further requests to
that route, while other routes may be still active. This use case is good for
when you need to limit a specific resource to a lower threshold of requests while
simultaneously limiting the user to daily maximum requests.

#### Solution 3: Extensible Key Resolvers

Both Laravel's rate limiter and this Leaky Bucket rate limiter use cache keys to
save the hits against the rate limiter by a given client. The default resolver for
this key is the same in both rate limiters. The way in which these resolvers are
defined is different: this package makes use of a separate resolver class that
you can customize and mixin when configuring the routes themselves. The package
ships with three built-in resolvers and you can create your own very easily:

- Limit by IP/User (default): `ArtisanSdk\RateLimiter\Resolvers\User`
- Limit by Route: `ArtisanSdk\RateLimiter\Resolvers\Route`
- Limit by Tag: `ArtisanSdk\RateLimiter\Resolvers\Tag`

Because the `User` resolver is the default, you do not have to specify the resolver
at all which makes `throttle` and `throttle:\ArtisanSdk\RateLimiter\Resolvers\User`
equivalent. The normal bindings of `throttle:60,1` still apply and are just added
on the end like so `throttle:\ArtisanSdk\RateLimiter\Resolvers\User,60,1`. To use
a different resolver (which all default back to the `User` resolver) just make it
the first parameter like so `throttle:\ArtisanSdk\RateLimiter\Resolvers\Route,60,1`.

You can define your own resolvers and call them the same way like
`throttle:\App\Http\FooBarResolver` and just implement the
`ArtisanSdk\RateLimiter\Contracts\Resolver` interface on your custom resolver.
Extensibility built right in. Resolvers can therefore also be used to share and
reuse typical throttling settings so no more magic numbers in your route bindings.
For example `throttle:\App\Http\UserResourceLimits`, `throttle:\App\Http\HighLimits`,
or `throttle:\App\Http\SlowLimits`.

#### Bonus: Overflow Penalties

This package's implementation also puts a customizable penalty on the filler
(the client) if they overflow (exceed the bursting limits). This is done in the
form of a third configuration setting such as `throttle:60,1,60`. The default
value is `60` minute which is close to the default behavior of Laravel's rate
limiter. This value however is independent of all the others whereas Laravel's
is coupled to the decay time. This third configuration sets a penalty in seconds
the client must rest before making another request. This is enforced even if the
bursting rates would normally reset according to the leak rate. For example, a
`throttle:60,1,600` would enact a `600` second (`10` minute) timeout for
exceeding the `60` requests burst limit. This would slow down a hacker `10x`
more than with Laravel's built in rate limiter.

Furthermore the timeout is customizable differently for guests vs. authenticated
users by using the split configuration such as `throttle:60|100,1|10,86400|3600`.
This would translate to a guest being able to make up to `60` requests at once or
at a constant rate of `1 r/s` and if they violate these rules then they will have
exceeded their daily limit and be rate limited for `24` hours (`86400` seconds).
Meanwhile, an authenticated user can make up to `100` requests at once or at a
constant rate of `10 r/s` all day long (`846K` requests per day) and if they
violate the rules then they go into only a `60` minute (`3600` second) timeout.
Using the route-based rate limiter for a login route you could do something like
`throttle:Resolvers\Route::class,3,0.1,600` which would limit the login to `1`
request every `10` seconds (`1r/10s` --> `0.1 r/s`) and up to `3` in `10`
seconds with any violators being banned for `10` minutes (`600` minutes).

### Different Rates for Guests vs. Authenticated Users

Chances are you don't trust your guests as much as you do your authenticated users.
In reality you should trust no one but this packages makes it easier to segment
rates that should apply to guests and the rates that apply to authenticated users.
Laravel uses the pipe (`|`) separate convention to accomplish this with the configuration
settings so this package extends that behavior. The guest's value will be on the
left of the pipe while the user's value will be on the right of the pipe. An example
would be `throttle:60|120` which would apply a limit of `60` requests for guests and
`120` requests for users. You can alternatively provide a string on the user side
of the pipe to dynamically set the rate from the authenticated user such
as `throttle:60,rate_limit` or just `throttle:rate_limit` if you're OK with `0`
being inferred for the guest side.

All of the configuration settings for `max`, `rate`, and `duration` are configurable
this way (something Laravel's rate limiter doesn't do) so you can pass pipe-separated
limits for guests and users for any of these settings. The signature format is of
the form `throttle:max|rate|duration`. For example you could do:
`throttle:60|120,1|2,300|60` to set the following limits:

|       | Burst Limit  | Leak Rate | Timeout Duration |
| ----- | ------------ | --------- | ---------------- |
| Guest | 60 requests  | 1 r/s     | 300 seconds      |
| User  | 120 requests | 2 r/s     | 60 seconds       |

Additionally all the user limits accept a string which may be used to fetch the value
dynamically from the authenticated user. Even if you do not support a database
driven value, you could create an attribute getter on the `App\User` model to
get the value as a constant or based on some value.

### Different Rates for Different Users

Chances are you have a combination of tiered SaaS plans, admin and regular users,
a mobile app that makes heavier use of your API than your web app does, or just
that one user who seems to thinks they need to hammer that page. You'll want to
have different rates for different users in other words. You can of course segment
guests from users but that is a broad stroke. You want more fine grain control and
this package makes that easier. You can define a custom rate per user for every
rate setting including the `max`, `rate`, and `duration`. Simply provide a string
instead of a number for the configuration value such as
`throttle:max_limit,rate_limit,duration_limit` which map to a call such as
`Auth::user()->max_limit`, `Auth::user()->rate_limit`, etc. Now Bob and Suzy
can have different rates pulled back from their user profile.

Anything more sophisticated than that and you'll want to use a custom resolver.
For example if you use this with the `Tag` or `Route` resolver then you'll likely
be wanting to set different user rates for different resources or routes. For that
you'll implement a rates table to setup the fine grain controls per user. You'll
have to implement a custom resolver to fetch the limits in that case. You can checkout
the `ArtisanSdk\RateLimiter\Resolvers\User::parse()` method for some inspiration
on how to query the authenticated user for the limits.

### Handling the Rate Limit Exceptions

Whenever a client is rate limited there is an exception thrown. The exception is
the `ArtisanSdk\RateLimiter\Exception` which roughly maps to the
`Illuminate\Caching\Exceptions\TooManyAttempts` exception which likewise extends a
Symfony HTTP exception with appropriate `429 Too Many Requests` code and message.
The built in Laravel exception handler (`App\Exceptions\Handler`) catches these and
renders the appropriate response. Simply custom the `Handler::report()` and
`Handler::render()` methods to handle the exception differently. For example, you
could report the rate limiting as an audit log event on the user's profile for
further investigating or reporting of suspicious user activity. Using these logs as
a feedback loop you could even increase subsequent penalty rates for that user so
that they have to ease back in or suffer increasingly severe backoff penalties to
the point of blocking their user permanently.

### Setting a Custom Cache for the Rate Limiter

While Laravel is smart enough to resolve your default cache driver, you may
specifically want to use specialized Redis or Memcached cache for the hits and
timers for the Leaky Buckets. In that case you'll need to register that
configuration in your `App\Providers\AppServiceProvider` class. Just add to your
`register()` method the following (or better, abstract it to its own method):

```php
use ArtisanSdk\RateLimiter\Contracts\Limiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

$this->app->when(Limiter::class)
    ->needs(Repository::class)
    ->give(function(){
        return Cache::driver('redis');
    });
```

This binding ensures that when the `Limiter` is injected into the middleware that
it is resolved out of the container using the `redis` driver instead of the
default `file` driver for the cache `Repository` needed by the `Limiter`. You
could do something similar if you needed to use a completely different `Limiter`
or set a different default resolver within the middleware.

### How Request Signature Resolvers Work

The key resolvers are technically only used by the `ArtisanSdk\RateLimiter\Middleware`
class and their values passed off as request limits to the rate limiter. You can
use the resolvers to get the request key and rate limits for other things other
than requests, but generally you would only be using them with request throttling
via the middleware. A resolver is any class that implements the
`ArtisandSdk\RateLimiter\Contracts\Resolver` interface. The returned values could
be anything statically returned or dynamically resolved from the request and other
services available to the class. The only resolved value that cannot be overwritten
is the key which is used as the signature that identifies the unique request. This
signature is the key used to cache the Leaky Bucket. No two users would have the
same bucket and no two routes would likewise use the same bucket because the keys
would resolve to something different.

### How Multiple Buckets Work

The keys are hashed but in raw form they look like `example.com|127.0.0.1` for guests
or `johndoe[at]example.com` for authenticated users. More granular keys such as
`example.com|127.0.01:/api/foo/bar` as used by the `Tag` and `Route` resolvers
nest using the colon (`:`) separator. Both sides of the separator are hashed separately
such that you could think of the sides as `client:bucket` where hits against the
bucket key count as a hit against the client key as well. Timeout durations resolve
from outside in so if the client key is in a timeout, all bucket keys are rate limited
as well. Conversely if a bucket key is in a timeout, other buckets may still be
available and the parent client key may also still be available. Since multiple
buckets for the same client share the same client key, the sum of bucket hits will
overflow the client limits resulting in a client timeout and not just a bucket timeout.

Because of the timeouts, clients should attempt to remain at all times within their limits.
While every request returns the `X-RateLimit` headers, the application developer
may wish to expose an API endpoint such as `/api/rates` or deploy some other
automated way of informing the client about _all_ the available resources and their
respective limits. Included in this response would be the remaining drips available
by both the client and the specific resources including the appropriate retry
timestamp and back off durations for any resources imposing rate limited timeouts.
This would require some sort of global rate store which can be queried and resolved
and is outside the scope fo this package. Use could probably reuse the middleware but
may need to implement a custom implementation of `ArtisandSdk\RateLimiter\Contracts\Limiter`.

#### Using the Built In Resolvers

The package has several built in resolvers with the default being to uniquely
identify the user and apply a global rate limit. All other resolvers should fall
back to this resolver or create a sub bucket. This ensures that the resolver's
more granular rate limits count towards the global rate limit for the user. All
of the built in resolvers use the same default settings for the rates including
for both guests and authenticated users:

| Max Requests | Leak Rate    | Timeout Duration |
| ------------ | ------------ | ---------------- |
| 60 total     | 1 per second | 60 seconds       |

```php
// Use the current user as the resolver (default)
// The following lines are all the same binding
use ArtisanSdk\RateLimiter\Resolvers\User;
Route::middleware('throttle');
Route::middleware('throttle:60,1,1');
Route::middleware('throttle:'.User::class.',60,1,60');

// Add the route to the bucket key to add more granularity
use ArtisanSdk\RateLimiter\Resolvers\Route;
Route::middleware('throttle:'.Route::class.',60,1,60');

// Add a tag to the bucket key to group related resources
use ArtisanSdk\RateLimiter\Resolvers\Tag;
Route::middleware('throttle:'.Tag::class.',foo,60,1,60');
```

#### Creating Custom Resolvers

One of the simplest custom resolvers would be a hard-coded version of the `Tag`
resolver which can be used to create settings objects for throttling related resources.
Something like this would do the trick:

```php
use ArtisanSdk\RateLimiter\Resolvers\User as Resolver;
use Symfony\Component\HttpFoundation\Request;

class UserResourceLimits extends Resolver
{
    protected $max = '50|100'; // 50 drips for guests, 100 drips for users
    protected $rate = '1|10'; // 1 drip per second for guests, 10 drips per second for users
    protected $duration = 3600; // 3600 second (60 minute) timeout
    protected $resource = 'user'; // resource key

    public function __construct(Request $request)
    {
        parent::__construct($request, $this->max, $this->rate, $this->duration);
    }

    public function key(): string
    {
        return parent::key().':'.$this->resource();
    }

    public function resource(): string
    {
        return $this->resource;
    }
}
```

Then to use this limiter you would simply bind it on routes like this:

```php
use App\Http\UserResourceLimits;

Route::middleware('throttle:'.UserResourceLimits::class)
    ->prefix('api/user')
    ->group(function($router){
        $router->get('/', 'UserApi@index');
        $router->get('{id}', 'UserApi@show');
    });

Route::get('/dashboard', 'Dashboard@index');
```

Each of the `/api/user` prefixed routes would then log a hit against the `user`
resource bucket while the `/dashboard` would use the default global limits. A
visit to the dashboard would increment the global bucket, while a visit to a
user resource endpoint would increment both the `user` resource bucket and the
global bucket. The `UserResourceLimits` resolver uses the hard-coded values so
that there is only one configurable place to customize the settings. This is
purposefully closed and if a more extensible solution is needed then the
built-in `Tag` resolver would be a better option.

#### Setting a Custom Resolver as the Default

Similar to how a custom cache `Repository` can be injected into the rate
`Limiter` class, a secondary argument allows for the injection of a custom
`ArtisanSdk\RateLimiter\Contracts\Resolver` implementation. The default resolver
is `ArtisanSdk\RateLimiter\Resolvers\User` and to override this, you to bind the
custom resolver as default by registering it in your
`App\Providers\AppServiceProvider` class. Just add to your `register()` method
the following (or better, abstract it to it's own method):

```php
use ArtisanSdk\RateLimiter\Middleware;
use App\Http\FooBarResolver;

$this->app->when(Middleware::class)
    ->needs('$resolver')
    ->give(function(){
        return FooBarResolver::class;
    });
```

This will give the fully-qualified resolver class name to the `$resolver` variable
in the `Middleware`'s constructor which will then be used as the default anytime
a more specific route binding is not provided. In this case it is providing the
custom `App\Http\FooBarResolver` as the default.

### Using the Rate Limiter by Itself

The `Limiter` class can be used by itself to persist the leaky `Bucket` implementation.
Essentially the `Limiter` class is just an abstraction of the `Bucket` to be more
aligned with the concepts of "hits", "limits", and "backoffs" as are often used
in rate limiting requests or login attempts. These are not the only things that
need be rate limited. You can rate limit the number of reads and writes for models
defering to queuing when a certain limit is exceeded. You can rate limit the number
of parallel processed jobs. Essentially anything that needs to be limited using
the Leaky Bucket Algorithm can use the `Limiter` class as a standalone rate limiter.

All you need to use the limiter is a persistence layer that implements the
`Illuminate\Contracts\Cache\Repository` interface and an instance of the
`ArtisanSdk\RateLimiter\Bucket`. The `Bucket` which contains the drips (hits)
against the `Limiter` and is configured with the needed rates and limits, is
also instantiated with `$key` which the `Repository` service uses to persist the
`Bucket`. For long-running daemons, the `Bucket` might not even be persisted in
which case the `Illuminate\Cache\ArrayStore` repository may be used.

```php
use ArtisanSdk\RateLimiter\Limiter;
use ArtisanSdk\RateLimiter\Bucket;
use Illuminate\Support\Facades\Cache;

// Configure the limiter to use the default cache driver
// and persist the bucket under the key 'foo' and limit to
// 1 hit per minute or up to the maximum of 10 hits while bursting
$bucket = new Bucket($key = 'foo', $max = 10, $rate = 0.016667);
$limiter = new Limiter(Cache::store(), $bucket);

// Keep popping or queuing jobs until empty or the limit is hit
while(/* some function that gets a job */) {

    // Check that we can proceed with processing
    // This is an abstraction for checking if there's an existing timeout
    // or if the leaky bucket is now overflowing
    if( $limiter->exceeded() ) {

        // Put the bucket in a timeout until it drains
        // or you could use any arbitrary duration (or even allow for overflow)
        $seconds = $bucket->duration();
        $limiter->timeout($seconds);
        break;
    }

    // Execute the job and when the work is done, log a hit
    // Unlike the bucket which allows for multiples drips at a time,
    // a rate limiter usually only allows for a single hit at a time.
    $limiter->hit();
}

// Let the caller know when in seconds to try again
return $limiter->backoff();
```

If you need to use multiple buckets then simply instantiate a bucket with a compound
key such as `foo:bar`. The rate limiter would then apply rates for hits against `foo:bar`
and `foo` simultaneously. Just change out the lines to be:

```php
$limiter = new Limiter(Cache::store(), new Bucket('foo:bar'));

// or let Laravel handle the cache driver dependencies with
$limiter = app(Limiter::class, ['bucket' => new Bucket('foo:bar')]);
```

Take a look at `ArtisanSdk\RateLimiter\Contracts\Limiter` for additional methods
you can call or at the concrete implementation `ArtisanSdk\RateLimiter\Limiter`
for non-contract, convenience methods such as `reset()`, `clear()`, `hasTimeout()`, etc.
that are unique to the implementation.

#### Creating a Custom Rate Limiter

If the logic of the rate limiter is not to your liking, you can use the `Middleware`
and the leaky `Bucket` but implement your own instance of
`ArtisanSdk\RateLimiter\Contracts\Limiter`. Alternatively you could re-implement
Laravel's fixed Decay Rate Limiter by modifying the calls that refer to the Leaky
Bucket Algorithm with more generic methods on a custom bucket. So long as the
`Limiter` contract is implemented, then the `Middleware` can be configured to
inject your custom `Limiter`.

Similar to how a custom cache `Repository` can be injected into the rate
`Limiter` class, the `Middleware` can receive your custom `Limiter` as an
injected dependency. You bind the custom `Limiter` by registering it in your
`App\Providers\AppServiceProvider` class. Just add to your `register()` method
the following (or better, abstract it to it's own method):

```php
use App\Http\RateLimiter;
use ArtisanSdk\RateLimiter\Contracts\Limiter;

$this->app->bind(Limiter::class, RateLimiter::class);
```

Now wherever the type hinted `Limiter` contract is resolved out of the container,
your custom `RateLimiter` class will be provided instead.

### Using the Bucket by Itself

The `Bucket` class can be used by itself wherever a Leak Bucket Algorithm is needed.
All of the algorithm is implemented against an internal in-memory store which can
be converted to an array with `toArray()` or as JSON with `toJson()`. This allows
persistence layers such as a rate `Limiter` implementation to be just about anything.

For example, the `Bucket` could be implemented as a new connection pooling and
flood controls for a long-running WebSocket server. Whenever a new connection
is established, the `Bucket` is `fill()` with a drip and when it `isFull()` then
connections could be rejected. Since the `Bucket` is constantly leaking, new connection
can be made at a constant rate, once full. This is especially useful for logic where
establishing say up to `100` connections can be done relatively quickly, but once you have
that many connections established, adding more involves more coordination and more
consideration of resource prioritization for the already established connections.
Having a Leaky Bucket lets you control the rate of addition.

The `Bucket` has a fluent builder interface for configuring itself and as the
critical part of the code-base it's worth taking a look under the hood at the raw
code. Here's a quick overview of it's public API though:

```php
use ArtisanSdk\RateLimiter\Buckets\Leaky;

$bucket = new Leaky('foo');              // bucket named 'foo' with default capacity and leakage
$bucket = new Leaky('foo', 100, 10);     // bucket holding 100 drips that leaks 10 drips per second
$bucket = new Leaky('foo', 1, 0.016667); // bucket that overflows at more than 1 drip per minute

(new Leaky('foo'))
    ->configure([
        'max' => 100,            // 100 drips capacity
        'rate' => 10,            // leaks 10 drips per second
        'drips' => 50,           // already half full
        'timer' => time() - 10,  // created 10 seconds ago
    ])
    ->fill(10)                   // add 10 more drips
    ->leak()                     // recalculate the bucket's state
    ->toArray();                 // get array representation for persistence

$bucket = (new Leaky('foo'))     // instantiate the same bucket as above
    ->max(100)                   // $bucket->max() would return 100
    ->rate(10)                    // $bucket->rate() would return 10
    ->drips(50)                  // $bucket->drips() would return 50
    ->timer(time() - 10)         // $bucket->timer() would get the time
    ->fill(10)                   // $bucket->remaining() would return 40
    ->leak();                    // $bucket->drips() would return 30

$bucket->isEmpty();              // false
$bucket->isFull();               // false
$bucket->duration();             // 10 seconds till empty again
$bucket->key();                  // string('foo')
$bucket->reset();                // keeps configuration but reset drips and timer
```

#### Using the Evented Bucket

If you consider it, a drip in the bucket represents some sort of event that occurred
within the application. At some point you routed your call to log the drip into
the bucket. Chances are you could listen for the original event, but if you are
dispatching through a command bus, then you might need to log calls to the bucket
as events the rest of your application can listen for.

> **Note:** The `Evented` bucket is an extension of the `Leaky` bucket that only
> wraps the parent class with events. All the same builder logic and behavior is the
> same otherwise.

You can switch from the basic `Leaky` bucket to the `Evented` bucket by binding
the interface to the concrete the `register()` method of your
`App\Providers\AppServiceProvider`:

```php
use ArtisanSdk\RateLimiter\Buckets\Evented;
use ArtisanSdk\RateLimiter\Contracts\Bucket;

$this->app->bind(Bucket::class, Evented::class);
```

And then you can listen for the following events:

- `ArtisanSdk\RateLimiter\Events\Filling`
- `ArtisanSdk\RateLimiter\Events\Filled`
- `ArtisanSdk\RateLimiter\Events\Leaking`
- `ArtisanSdk\RateLimiter\Events\Leaked`

If you want to fire events whenever the limiter is exceeded, you'll need to do
that in your own code or modify the `Limiter` itself to also fire events. You could
do that by injecting into the constructor the optional implementation of
`Illuminate\Contracts\Event\Dispatcher` and when present the `Limiter` would
fire events for `Limiter::hit()` and `Limiter::timeout()` and optionally
`Limiter::clear()` methods.

#### Logging the Drips in the Bucket

Another way would be to do the decorating of the `Bucket` at the `Limiter` level
or simply do the eventing directly there. If you notice, the `Limiter` is the persistence
manager for the `Bucket` anyway and the `Bucket` simply holds the state while in
memory. So with that in mind, you could also modify the `Limiter` such that a `hit()`
passes an event object to the `Bucket` which is pushed on to an internal stack of events
instead of incrementing an internal counter. Then when the `Bucket` is persisted
instead of simply returning a count of `$drips` it can return an array of event
objects. This opens up the ability to log hits only if they are unique, or to
further limit the bucket based on the types of hits received. You could go as far
as resolving the right rate bucket to store the hit against based on the event object
being passed to `hit()` method. With a little modification, you could convert
the `Bucket` to an event store.

## Running the Tests

The package is unit tested with 100% line coverage and path coverage. You can
run the tests by simply cloning the source, installing the dependencies, and then
running `./vendor/bin/phpunit`. Additionally included in the developer dependencies
are some Composer scripts which can assist with Code Styling and coverage reporting:

```bash
composer test
composer fix
composer report
```

See the `composer.json` for more details on their execution and reporting output.

## Licensing

Copyright (c) 2018-2024 [Artisan Made, Co.](http://artisanmade.io)

This package is released under the MIT license. Please see the LICENSE file
distributed with every copy of the code for commercial licensing terms.
