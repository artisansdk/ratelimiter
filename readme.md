# Rate Limiter

A leaky bucket rate limiter and middleware controls for route-level granularity.

## Table of Contents

- [Installation](#installation)
- [Usage Guide](#usage-guide)
    - [Overview of the Laravel Rate Limiter](#overview-of-the-laravel-rate-limiter)
    - [Understanding the Leaky Bucket Algorithm](#understanding-the-leaky-bucket-algorithm)
    - [Different Rates for Guests vs. Authenticated Users](#different-rates-for-guests-vs-authenticated-users)
    - [Different Rates for Different Users](#different-rates-for-different-users)
    - [Handling the Rate Limit Exceptions](#handling-the-rate-limit-exceptions)
    - [Setting a Custom Cache for the Rate Limiter](#setting-a-custom-cache-for-the-rate-limiter)
    - [How Request Signature Resolvers Work](#how-request-signature-resolvers-work)
    - [How Multiple Buckets Work](#how-multiple-buckets-work)
    - [Using the Rate Limiter by Itself](#using-the-rate-limiter-by-itself)
    - [Using the Bucket by Itself](#using-the-bucket-by-itself)
- [Licensing](#licensing)

## Installation

The package installs into a PHP application like any other PHP package:

```bash
composer require artisansdk/ratelimiter
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
This means that `throttle:120,2` is effectively the same as 1 r/s but tracked for
`2` minutes and allowing a larger burst limit up to `120` requests. Meanwhile `throttle:120,1`
would be an effective rate of `2 r/s` with the same burst limit.

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
translates to 60 requests by guests and whatever `Auth::user()->rate_limit` returns
(and if you use the documented `throttle:rate_limit` surprise it will be the same as
`throttle:0` for guests!). Still, you want to rate limit the resources the user
accesses differently. The only answer to that problem is to extend the
`Illuminate\Routing\Middleware\ThrottleRequests` middleware and overload the `resolveRequestSignature()` method to return your custom key. Oh, and let's not
forget that the same decay rate is used for both guests and authenticated users
so you have to grok your way through that inadvertent security coupling.

### Understanding the Leaky Bucket Algorithm

The answer to Laravel's rate limiter shortcomings is a better algorithm that includes
a couple of additional configuration options.

#### Leaky Bucket Implementation

The Leaky Bucket Algorithm is the rate limiter this package implements. As its
name suggests, there is a bucket (a cache) that you fill with drips (a requests)
up to the maximum capacity (the limit) at which point if you continue filling it
will overflow (rate limiting). This bucket also leaks at a constant rate of drips
per second (requests per second). This means that if you fill the bucket with drips
at the same rate in which it leaks drips then you can continue hitting it forever
without overflowing. You can also burst up to the maximum capacity which has no
effect on the leak rate. So effectively the Leaky Bucket Algorithm enforces a
constant drip rate determined not by the number of drips but by the leak rate
in constant time.

#### Solution 1: Bursting Limit

As already, explained bursting is an exploit that a hacker can use against the
Laravel rate limiter has and to monitor such exploits makes the attack surface
area even bigger. The bursting limit in a Leaky Bucket implementation is a separate
limit that does not expire in a binary, all or nothing, way but expires one drip
at a time as the bucket leaks. With this implementation you set the bursting limit
and that limit drains slowly over time at the constant leak rate. This means that
so long as the client does not exceed the limits and enters a timeout, the client
can burst up to this limit then wait as little as the leak rate to make one more
request.

For example if the settings were `throttle:60,1` then the user can burst
up in the first second to `60` requests, and only has to wait `1` second to make
a subsequent request but if they make `2` requests then they'll overflow which
introduces the timeout penalty. The more time the client rests the more requests
per second they can make. The two configurations in the settings translate to
the `60` maximum requests allowed when bursting and effectively an average request per second
rate of `1 r/s` (technically it's `1` leak per second or `1 l/s`). Now setting
higher limits represents increased performance, and since they are independent of
each other, configuration is clear with respect to its effect.

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
at all which makes `throttle` and `throttle:\ArtisanSdk\RateLimiter\Resolvers\User::class`
equivalent. The normal bindings of `throttle:60,1` still apply and are just added
on the end like so `throttle:\ArtisanSdk\RateLimiter\Resolvers\User::class,60,1`. To use
a different resolver (which all default back to the `User` resolver) just make it
the first parameter like so `throttle:\ArtisanSdk\RateLimiter\Resolvers\Route:class,60,1`.

You can define your own resolvers and call them the same way like
`throttle:\App\Http\FooBarResolver::class` and just implement the
`ArtisanSdk\RateLimiter\Contracts\Resolver` interface on your custom resolver.
Extensibility built right in. Resolvers can therefore also be used to share and
reuse typical throttling settings so no more magic numbers in your route bindings.
For example `throttle:\App\Http\UserResourceLimits::class`, `throttle:\App\Http\HighLimits::class`,
or `throttle:\App\Http\SlowLimits::class`

#### Bonus: Overflow Penalties

This package's implementation also puts a customizable penalty on the filler (the client)
if they overflow (exceed the bursting limits). This is done in the form of a third
configuration setting such as `throttle:60,1,1`. The default value is `1` minute which
is close to the default behavior of Laravel's rate limiter. This value however is
independent of all the others whereas Laravel's is coupled to the decay time. This
third configuration sets a penalty in minutes the client must rest before making
another request. This is enforced even if the bursting rates would normally reset
according to the leak rate. For example, a `throttle:60,1,10` would enact a `10` minute
timeout for exceeding the `60` requests burst limit. This would slow down a hacker
`10x` more than with Laravel's built in rate limiter.

Furthermore the timeout is customizable differently for guests vs. authenticated
users by using the split configuration such as `throttle:60|100,1|10,1440|60`.
This would translate to a guest being able to make up to `60` requests at once or
at a constant rate of `1 r/s` and if they violate these rules then they will have
exceeded their daily limit and be rate limited for `24` hours (`1440` minutes).
Meanwhile, an authenticated user can make up to `100` requests at once or at a
constant rate of `10 r/s` all day long (`846K` requests per day) and if they
violate the rules then they go into only a `1` hour (`60` minute) timeout. Using
the route-based rate limiter for a login route you could do something like
`throttle:Resolvers\Route::class,3,0.1,10` which would limit the login to `1` request
every `10` seconds (`1r/10s` --> `0.1 r/s`) and up to `3` in `10` seconds with any
violators being banned for `10` minutes.

### Different Rates for Guests vs. Authenticated Users

Chances are you don't trust your guests as much as you do your authenticated users.
In reality you should trust no one but this packages makes it easier to segment
rates that should apply to guests and the rates that apply to authenticated users.
Laravel uses the pipe (`|`) separate convention to accomplish this with the configuration
settings so this package extends that behavior. The guest's value will be on the
left of the pipe while the user's value will be on the right of the pipe. An example
would be `throttle:60|120` which would apply a limit of 60 requests for guests and
`120` requests for users. You can alternatively provide a string on the user side
of the pipe to dynamically set the rate from the authenticated user's profile such
as `throttle:60,rate_limit` or just `throttle:rate_limit` if you're OK with `0`
being inferred for the guest side.

All of the configuration settings for `max`, `rate`, and `duration` are configurable
this way (something Laravel's rate limiter doesn't do) so you can pass pipe-separated
limits for guests and users for any of these settings. The signature format is of
the form `throttle:max|rate|duration`. For example you could do:
`throttle:60|120,1|2,5|1` to set the following limits:

|  | Burst Limit | Leak Rate | Timeout Duration |
| --- | --- | --- | --- |
| Guest | 60 requests | 1 r/s | 5 minutes |
| User | 120 requests | 2 r/s | 1 minute |

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

While Laravel is smart enough to resolve your default cache driver, you may specifically
want to use specialized Redis or Memcached cache for the hits and timers for the
Leaky Buckets. In that case you'll need to register that configuration in your `App\Providers\AppServiceProvider` class. Just add to your `register()` method the
following (or better, abstract it to it's own method):

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
services available to the class. The only resolved vlaue that cannot be overwritten
is the key which is used as the signature that identifies the unique request. This
signature is the key used to cache the Leaky Bucket. No two users would have the
same bucket and no two routes would likewise use the same bucket because the keys
would resolve to something different.

### How Multiple Buckets Work

The keys are hashed but in raw form they look like `example.com|127.0.0.1` for guests
or `johndoe[at]example.com` for authenticated users. More granular keys such as
`example.com|127.0.01::/api/foo/bar` as used by the `Tag` and `Route` resolvers
nest using the `::` separator. Both sides of the separator are hashed separately
such that you could think of the sides as `client::bucket` where hits against the
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

The package has several built in resolvers with the default being to uniquely identify
the user and apply a global rate limit. All other resolvers should fall back to this resolver or create a sub bucket. This ensures that the resolver's more granular rate
limits count towards the global rate limit for the user. All of the built in resolvers
use the same default settings for the rates including for both guests and authenticated
users:

| Max Requests | Leak Rate | Timeout Duration |
| --- | --- | --- |
| 60 total | 1 per second | 1 minute |

```php
// Use the current user as the resolver (default)
// The following lines are all the same binding
use ArtisanSdk\RateLimiter\Resolvers\User;
Route::middleware('throttle');
Route::middleware('throttle:60,1,1');
Route::middleware('throttle:'.User::class.',60,1,1');

// Add the route to the bucket key to add more granularity
use ArtisanSdk\RateLimiter\Resolvers\Route;
Route::middleware('throttle:'.Route::class.',60,1,1');

// Add a tag to the bucket key to group related resources
use ArtisanSdk\RateLimiter\Resolvers\Tag;
Route::middleware('throttle:'.Tag::class.',foo,60,1,1');
```

#### Creating Custom Resolvers

> **Note:** The docs are still being written, check back later or submit a PR.

#### Setting a Custom Resolver as the Default

### Using the Rate Limiter by Itself

#### Creating a Custom Rate Limiter
### Using the Bucket by Itself
#### Logging the Drips in the Bucket

## Licensing

Copyright (c) 2018 [Artisans Collaborative](https://artisanscollaborative.com)

This package is released under the MIT license. Please see the LICENSE file
distributed with every copy of the code for commercial licensing terms.
