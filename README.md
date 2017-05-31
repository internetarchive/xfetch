# Preventing cache stampede with Redis & XFetch

  * Jim Nelson <jnelson@archive.org>
  * Internet Archive
  * RedisConf 2017

## Introduction

This PHP script accompanies the presentation made at RedisConf 2017, 30 May to 1 June, 2017, in San Francisco.

stampede.php is a test harness for different types of Redis-backed caching strategies.  The harness maintains a configurable number of executing processes all reading from a value from a cache.  The harness and the various strategies are instrumented to collect data on the outcome of each pass.  The raw data is presented in aggregate to the user periodically.

Four strategies are included in stampede.php: fetch, locked, xfetch, and xlocked.

stampede.php takes a fairly naive approach to testing cache stampede across a data cluster.  Use this as a starting point for your own implementations, but do not rely on it as the final word on results in production.  _Your mileage may vary._

## Important!

_*stampede.php deletes and modifies data on your Redis server without warning.*_

_*It may also generate a significant amount of read/write traffic against your Redis server.*_

_*Use ONLY on a test server.  Do NOT use stampede.php on a production Redis server.*_

## Before running

Note: Only one instance of stampede.php may execute against a Redis instance at a time.  If you run multiple instances on the same Redis, you will see invalid statistics and other problems.

### Requirements

stampede.php requires:

  * a modern version of PHP (5.5 or better) - https://php.net
  * a test Redis instance (do *not* use a production server) - https://redis.io
  * Predis 1.1 or better - https://github.com/nrk/predis/

### Configuration

In an ideal world, stampede.php will work out-of-the-box.  Until such an ideal world snaps into existence, you will probably need to edit the following variables in stampede.php to run it on your test environment:

  * The initial `require` line should point to your Predis installation
  * The `$redis_params` and `$redis_options` arrays are Predis' parameters and configuration options respectively.  By default Predis will connect to Redis at `127.0.0.1:6379`.  For more information see https://github.com/nrk/predis/wiki/Client-Options and https://github.com/nrk/predis/wiki/Connection-Parameters

Other constants in the code may be tweaked to change the harness parameters and see how they affect the final results.  **Changing these should not be necessary to run stampede.php:**

  * EXPIRES: The expiration time (in seconds) of the cached value
  * DELTA: The amount of time it takes to recompute the cached value (in milliseconds)
  * REDIS_KEY / LOCK_KEY / DELTA_KEY / SIMUL_KEY: Redis key names used by the harness to store information.
  * WORKERS: Number of simultaneous processes to keep running during the test
  * REPORT_EVERY_SEC: How often to print test data to stdout (in seconds)
  * BETA: The XFetch algorithm's beta value (see below)

## Running the harness

The script's execute bit is set and may be executed directly from a Bourne-type shell or via the PHP interpreter:

```
$ ./stampede
$ php -f stampede.php
```

Each cache strategy has a name, which is printed to stdout if no name is supplied.  Only one strategy may be tested at a time.  For example, to test the XFetch strategy:

```
$ ./stampede xfetch
```

## Basics

stampede.php launches 50 child processes.  The actual number is defined by the WORKER constant.  As each child exits, a new child process is launched to take its place.  This maintains a constant number of "workers" reading the cache throughout the test.

Each child process reads a cached value from a Redis server using a defined strategy (fetch, locked, xfetch, xlocked).  When completed, the child process reports its results via its process exit code.  The code is a bitfield indicating the results of that child process' cache read.

The parent process gathers the child process results in a single table, which it prints every few seconds to stdout.

Under varying conditions, the child process may recompute and store the cache value in Redis.  The recompute function (mimicking a database query or filesystem access) merely pauses DELTA milliseconds and returns a static string.

The results of the first round of workers are not tallied.  This gives the cache a chance to be warmed before gathering data.

If stampede.php is interrupted (i.e. Ctrl+C) it will halt the test and print the final results.

### Code internals

The parent process executes `Harness::start()`.  This event loop launches the child processes, gathers their results, and prints the aggregated data to stdout.

Each cache strategy is implemented as a child class of abstract class `ChildWorker`, which defines an interface for `Harness` to use as well as several helper functions for the concrete classes to use.

## Terminology

Caching is a commonly-understood concept.  Some terminology in this document is particular:

*Recompute*: Expensive operation whose result is cached (database query, filesystem read, HTTP request)

*Expiration*: When a cache value is considered stale or out-of-date

*Evict*: Removing a value from the cache before its expiration

*Delta*: The amount of time it takes to recompute the cached value

## Strategies

Four strategies may be run: fetch, locked, xfetch, and xlocked.

### fetch (FetchWorker)

This is the most basic cache strategy and a common code pattern.

HIT: fetch reads the value from Redis.  If present, the value is returned to the caller.

MISS: Otherwise, fetch recomputes the value, writes it to Redis with an expiration, and returns it to the caller.

### locked (LockWorker)

fetch is susceptible to cache stampede (many workers recomputing the value simultaneously, leading to congestion collapse).  locked attempts to circumvent this via mutual exclusion.

HIT: locked reads the value from Redis.  If present, the value is returned to the caller.

MISS: Otherwise, locked acquires a Redis lock, recomputes the value, writes it to Redis with an expiration, and returns it to the caller.

If locked is unable to acquire the Redis lock, it pauses (to give the other worker a chance to complete) and repeats the above steps.

### xfetch (XFetchWorker)

locked solves the problem of congestion collapse but remains susceptible to workers starved waiting for the recompute to complete.

xfetch's algorithm (see https://archive.org/details/xfetch) uses probabilistic early recomputation to avoid lock contention and congestion collapse.  The BETA constant (default: 1.0) may be used to tweak recomputation time (earlier or later).

xfetch reads the value from Redis.  Even if the value is present, the xfetch() function may signal the caller is to recompute the cache value.  (This is early probabilistic recomputation).

HIT: If the value is present and xfetch() returns false, return the value to the caller.

EARLY: If the value is present and xfetch() returns true, recompute the value, write it to Redis with an expiration, and return it to the caller.

MISS: If the value is not present, recompute, write, and return to caller.

### xlocked (XFetchLockWorker)

While xfetch works well, under heavy load it can lead to simultaneous recomputes (although not in the same scale as fetch).  xlocked is a synthesis of xfetch and locked to avoid simultaneous recomputation.

xlocked has the same mechanics as xfetch (above) with two changes:

  1. A lock is acquired before recomputing the value.
  2. If the lock is not acquired but the value was available in Redis, do not wait to recompute; simply return the value.  (This is called "ducking out" in the code.)

## Conclusions

locked and fetch are susceptible to cache stampede, congestion collapse, and starved workers.  They do not scale well.

xfetch is fine for most situations, as long as simultaneous recomputes are acceptable.

xlocked's results are the best of the four strategies in that it has zero cache misses and no simultaneous recomputes.

The trade-offs: xfetch and xlocked require additional state be stored and are more complicated algorithms.
