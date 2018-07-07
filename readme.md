# Rate Limiter

A leaky bucket rate limiter and middleware controls for route-level granularity.

## Table of Contents

- [Installation](#installation)
- [Usage Guide](#usage-guide)
    - [Comparison to the Laravel Rate Limiter](#comparison-to-the-laravel-rate-limiter)
    - [Understanding the Leaky Bucket Algorithm](#understanding-the-leaky-bucket-algorithm)
    - [Different Rates for Guests vs. Authenticated Users](#different-rates-for-guests-vs-authenticated-users)
    - [Different Rates for Different Users](#different-rates-for-different-users)
    - [Handling the Rate Limit Exceptions](#handling-the-rate-limit-exceptions)
    - [Setting a Cache for the Middleware](#setting-a-cache-for-the-middleware)
    - [How Multiple Buckets Work](#how-multiple-buckets-work)
    - [How Request Signature Resolvers Work](#how-request-signature-resolvers-work)
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
> **Important:** This documentation is a work in progress. Check back later.

### Comparison to the Laravel Rate Limiter
### Understanding the Leaky Bucket Algorithm
### Different Rates for Guests vs. Authenticated Users
### Different Rates for Different Users
### Handling the Rate Limit Exceptions
### Setting a Cache for the Middleware
### How Multiple Buckets Work
### How Request Signature Resolvers Work
#### Using the Built In Resolvers

The package has several built in resolvers with the default being to uniquely identify the user and apply a global rate limit. All other resolvers should fall back to this resolver or create a sub bucket. This ensures that the resolver's more granular rate limits count towards the global rate limit for the user. All of the built in resolvers use the same default settings for the rates including for both guests and authenticated users:

| Max Requests | Leak Rate | Timeout Duration |
| --- | --- | --- |
| 60 total | 1 per second | 1 minute |

```
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
### Using the Rate Limiter by Itself
#### Creating a Custom Rate Limiter
### Using the Bucket by Itself
#### Logging the Drips in the Bucket

## Licensing

Copyright (c) 2018 [Artisans Collaborative](https://artisanscollaborative.com)

This package is released under the MIT license. Please see the LICENSE file
distributed with every copy of the code for commercial licensing terms.
