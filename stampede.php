#!/usr/bin/env php
<?php

/**
 *
 * Cache stampede test harness.
 *
 * See README.md for explanation, configuration, and usage notes.
 *
 * Copyright (C) 2017 Internet Archive
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Replace with path to your Predis installation.
 */
require '../predis/src/Autoloader.php';
Predis\Autoloader::register();

/**
 * Update with Predis configuration for your Redis installation.
 *
 * Empty arrays indicate default parameters.  In particular, Predis will connect to a Redis server
 * at 127.0.0.1:6379
 *
 * For more information see:
 *   https://github.com/nrk/predis/wiki/Client-Options
 *   https://github.com/nrk/predis/wiki/Connection-Parameters
 */
$redis_params = [];
$redis_options = [];

/**
 * Harness parameters (parent process).
 */
define('WORKERS', 50);          // number of concurrent child processes
define('REPORT_EVERY_SEC', 5);  // how often to print stats to stdout

/**
 * Worker parameters (child process).
 */
define('EXPIRES', 12);          // expiration time of cached value (in seconds)
define('BETA', 1.0);            // XFetch beta value (default: 1.0, > 1.0 favors earlier recomputation,
                                // < 1.0 favors later)
define('DELTA', 500);           // time to recompute the value to be cached (in milliseconds)

/**
 * Redis keys.
 *
 * WARNING: These are deleted at the start of each run and ruthlessly overwritten throughout
 * execution.
 */
define('REDIS_KEY', 'test:cache');
define('LOCK_KEY',  'test:lock');
define('DELTA_KEY', 'test:delta');
define('SIMUL_KEY', 'test:simul-recomputes');

/**
 * Child process exit codes (indicating cache result).
 *
 * Bottom 3 bits indicate cache result, top 5 bits are modifiers.
 */
define('HIT',     0x00);        // cache hit
define('MISS',    0x01);        // cache miss
define('EARLY',   0x02);        // cache hit but volunteered for early recomputation
define('PROCERR', 0x03);        // general child process error
define('UNKNOWN', 0x04);        // unknown error

define('RECOMP',  0x08);        // worker recomputed cache value
define('RETRY',   0x10);        // worker paused to retry read / recomputation
define('SIMUL',   0x20);        // simultaneous recompute detected
define('CONTEND', 0x40);        // lock contention (failed to acquire lock)
define('DUCKED',  0x80);        // "ducked out" (volunteer for early recompute failed to acquire
                                // lock, so returned good value and called it a day)

/**
 * Parameter name to class and description.
 */
$strategies = [
  'fetch'     => [ 'FetchWorker',       'simple cache strategy' ],
  'locked'    => [ 'LockWorker',        'locked recompute' ],
  'xfetch'    => [ 'XFetchWorker',      'xfetch', ],
  'xlocked'   => [ 'XFetchLockWorker',  'xfetch + locked recompute' ],
];

// run it!
exit(main($argv));

/**
 * int main(int argv, char *argv[])
 */
function main($argv)
{
  global $strategies, $redis_params, $redis_options;

  if (count($argv) != 2)
    return usage($argv);

  $strategy = $argv[1];
  if (!isset($strategies[$strategy]))
    return usage($argv);

  $harness = new Harness($strategies[$strategy][0]);

  // install signal handler to exit on Ctrl+C
  pcntl_signal(SIGINT, function () use (&$harness) {
    echo "Halting...\n";
    $harness->halt();
  });

  // clear the cached values from Redis (to clear any cruft from prior runs)
  $redis = new Predis\Client($redis_params, $redis_options);
  $redis->del(REDIS_KEY, LOCK_KEY, DELTA_KEY, SIMUL_KEY);

  $harness->start();
  $harness->report_stats();

  return 0;
}

function usage($argv)
{
  global $strategies;

  $pgm = basename($argv[0]);

  echo <<<EOS
$pgm <strategy>

Cache strategies:

EOS;
  foreach ($strategies as $param => list(, $desc)) {
    $param = str_pad($param, 12);
    echo "  $param$desc\n";
  }

  return 1;
}

/**
 * Cache stampede test harness.
 *
 * The Harness::start() event loop manages all child processes.
 */

class Harness
{
  // no bourgeoisie allowed
  private $worker_class;

  // map of PID => Worker objects
  private $workers = [];

  // stats
  private $total = 0;
  private $tally = [];

  // next time_t to report gathered stats
  private $report_time_t;

  private $halt = false;

  /**
   * @param string $worker_class    The name of a subclass of ChildWorker
   */
  public function __construct($worker_class)
  {
    $this->worker_class = $worker_class;
    $this->report_time_t = time() + REPORT_EVERY_SEC;
  }

  /**
   * Test harness event loop.
   */
  public function start()
  {
    // launch first pass of workers ... these workers' test results are not tallied (to warm the
    // cache)
    while (count($this->workers) < WORKERS)
      $this->add_worker(true);

    // main loop
    while (!$this->halt) {
      // wait for a child process to exit ...
      $worker = $this->wait_for_completion();

      // ... and start one to take its place
      $this->add_worker();

      // gather stats for this worker's results
      $this->tally_stats($worker);

      // allow for signals to be dispatched ... this is used in place of declare(ticks=1)
      pcntl_signal_dispatch();
    }

    // cleanup loop
    while (count($this->workers))
      $this->wait_for_completion();
  }

  public function halt()
  {
    $this->halt = true;
  }

  private function add_worker($first_pass = false)
  {
    $worker = new $this->worker_class(EXPIRES);
    $worker->first_pass = $first_pass;
    $pid = $worker->start();

    $this->workers[$pid] = $worker;
  }

  private function wait_for_completion()
  {
    $pid = pcntl_wait($status);
    if ($pid === -1)
      exit("Could not wait for child process\n");

    // remove from pool and get exit code
    $worker = $this->workers[$pid];
    unset($this->workers[$pid]);
    $worker->exitcode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : UNKNOWN;

    // If process closed due to a signal, print to stdout
    if (pcntl_wifsignaled($status)) {
      $sig = pcntl_wtermsig($status);
      echo "Worker {$worker->pid} terminated: $sig\n";
    }

    return $worker;
  }

  /**
   * Emit a table of gathered statistics to stdout.
   */
  public function report_stats()
  {
    ksort($this->tally);

    echo "{$this->total} samples:\n";
    foreach ($this->tally as $label => $count) {
      $label = str_pad($label, 20);
      echo "  $label$count\n";
    }
    echo "\n";
  }

  /**
   * Gather stats from the completed worker.
   */
  private function tally_stats($worker)
  {
    // ignore initial round of workers
    if ($worker->first_pass)
      return;

    $this->total++;

    // exitcode is an octet bitmask, with the lower 3 bits an enumeration of possible results and
    // the upper 5 bits modifier flags
    switch ($worker->exitcode & 0x07) {
      case HIT:
        $this->incr_tally('hits');
      break;

      case MISS:
        $this->incr_tally('misses');
      break;

      case EARLY:
        $this->incr_tally('earlies');
      break;

      case PROCERR:
      case UNKNOWN:
      default:
        $this->incr_tally("exit $exitcode");
      break;
    }

    // RECOMP modifier (indicating worker recomputed cache value)
    if ($worker->exitcode & RECOMP)
      $this->incr_tally('recomputes');

    // RETRY modifier (indicating worker did not acquire lock on first attempt)
    if ($worker->exitcode & RETRY)
      $this->incr_tally('retries');

    // SIMUL modifier (indicating simultaneous recompute of value)
    if ($worker->exitcode & SIMUL)
      $this->incr_tally('simul. recomputes');

    // CONTEND modifier (indicating lock contention)
    if ($worker->exitcode & CONTEND)
      $this->incr_tally('lock contention');

    // DUCKED modifier (indicates EARLY recompute but lock was held, so used existing value)
    if ($worker->exitcode & DUCKED)
      $this->incr_tally('ducked-out');

    // report stats every 'n' seconds
    if ($this->report_time_t > time())
      return;

    $this->report_stats();
    $this->report_time_t = time() + REPORT_EVERY_SEC;
  }

  private function incr_tally($label)
  {
    isset($this->tally[$label]) ? $this->tally[$label]++ : ($this->tally[$label] = 1);
  }
}

/**
 * Abstract base class for all cache strategies.
 *
 * ChildWorker::start() will fork a child process and execute the ChildWorker::run() method in the
 * child's context.  It also manages all the signal stuff and traps fatal exceptions so they're
 * reported properly to Harness (in the parent process).
 */

abstract class ChildWorker
{
  public $result = UNKNOWN;
  public $pid = -1;
  public $redis;
  public $expires;

  /**
   * @param int $expires      Expiration time for cached value (in seconds)
   */
  public function __construct($expires)
  {
    global $redis_params, $redis_options;

    $this->redis = new Predis\Client($redis_params, $redis_options);
    $this->expires = $expires;
  }

  /**
   * Fork the child process.
   *
   * @return int PID of child process
   */
  public function start()
  {
    $pid = pcntl_fork();
    if ($pid === -1) {
      exit("Could not fork\n");
    } else if ($pid) {
      // still in parent process
      return $this->pid = $pid;
    }

    //
    // in child process
    //

    // block SIGINT (allow parent process to control shutdown)
    pcntl_sigprocmask(SIG_BLOCK, [ SIGINT ]);

    // to keep the worker processes from executing in lockstep, introduce some stochasticity with a
    // random pause before starting execution
    usleep(mt_rand(10, 1000) * 1000);

    // go
    try {
      $this->run();
    } catch (Exception $e) {
      $this->result = PROCERR;
    }

    exit($this->result);
  }

  /**
   * Execute cache strategy.
   *
   * Code should modify the $result variable to indicate cache results.  Helper functions will do
   * so automatically.
   */
  abstract protected function run();

  /**
   * Dummy recompute function.
   *
   * Adds the RECOMP modifier to $result.
   */
  protected function recompute_fn()
  {
    usleep(DELTA * 1000);
    $this->result |= RECOMP;

    return 'gnusto';
  }

  /**
   * Report recomputation is starting.
   *
   * Adds SIMUL modifier if simultaneous recomputation is detected.
   *
   * @return boolean Indicating if other processes are also recomputing.
   * @see recompute_finish
   */
  protected function recompute_start()
  {
    $simul = ($this->redis->incr(SIMUL_KEY) > 1);
    if ($simul)
      $this->result |= SIMUL;

    return $simul;
  }

  /**
   * @see recompute_start
   */
  protected function recompute_finish()
  {
    $this->redis->decr(SIMUL_KEY);
  }

  /**
   * Acquire an advisory lock to prevent simultaneous recomputation.
   *
   * Adds CONTEND modifier if unable to acquire lock.
   *
   * @param boolean If lock acquired
   */
  protected function lock_acquire()
  {
    // 30 second expiration (for abandonment -- not a normal issue in this limited environment)
    $acquired = $this->redis->set(LOCK_KEY, 'acquired', 'EX', 30, 'NX');
    if (!$acquired)
      $this->result |= CONTEND;

    return $acquired;
  }

  /**
   * Release the advisory lock.
   *
   * Does not check if lock was previously acquired.
   */
  protected function lock_release()
  {
    $this->redis->del(LOCK_KEY);
  }

  /**
   * Pause to wait for retry (due to lock contention).
   *
   * Adds RETRY modifier.
   */
  protected function retry()
  {
    usleep(10 * 1000);
    $this->result |= RETRY;
  }
}

/**
 * Implementation of fetch strategy.
 */

class FetchWorker extends ChildWorker
{
  protected function run()
  {
    $value = $this->redis->get(REDIS_KEY);
    $this->result = $value ? HIT : MISS;

    if ($value)
      return;

    $this->recompute_start();

    $value = $this->recompute_fn();
    $this->redis->set(REDIS_KEY, $value, 'EX', $this->expires);

    $this->recompute_finish();
  }
}

/**
 * Implementation of lock strategy.
 */

class LockWorker extends ChildWorker
{
  protected function run()
  {
    $value = $this->redis->get(REDIS_KEY);
    $this->result = $value ? HIT : MISS;

    // if value was not available, acquire lock and recompute value
    $locked = false;
    while (!$value) {
      $locked = $this->lock_acquire();
      if ($locked) {
        $this->recompute_start();

        $value = $this->recompute_fn();
        $this->redis->set(REDIS_KEY, $value, 'EX', $this->expires);

        $this->recompute_finish();

        break;
      }

      // unable to acquire lock, pause and retry
      $this->retry();
      $value = $this->redis->get(REDIS_KEY);
    }

    if ($locked)
      $this->lock_release();
  }
}

/**
 * Implementation of XFetch strategy.
 */

class XFetchWorker extends ChildWorker
{
  protected function run()
  {
    // XFetch requires value, its time-to-live, and the value's recompute time
    list($ttl, $value, $delta) = $this->read();

    // if no value or xfetch() is true
    if (!$value) {
      $this->result = MISS;
    } else if (self::xfetch($delta, $ttl)) {
      $this->result = EARLY;
    } else {
      $this->result = HIT;

      return;
    }

    $this->full_recompute();
  }

  /**
   * @return array [ $ttl, $value, $delta ]
   */
  protected function read()
  {
    $pipe = $this->redis->pipeline(['atomic' => true]);
    $pipe->ttl(REDIS_KEY);
    $pipe->get(REDIS_KEY);
    $pipe->get(DELTA_KEY);

    return $pipe->execute();
  }

  /**
   * Performs all the steps for recomputing and writing cache value.
   */
  protected function full_recompute()
  {
    $this->recompute_start();

    // note that microtime() returns time in *seconds* as a float with microsecond precision
    $start = microtime(true);
    $value = $this->recompute_fn();
    $delta = microtime(true) - $start;

    $this->write($value, $delta);

    $this->recompute_finish();
  }

  protected function write($value, $delta)
  {
    $pipe = $this->redis->pipeline(['atomic' => true]);
    $pipe->setex(REDIS_KEY, $this->expires, $value);
    $pipe->setex(DELTA_KEY, $this->expires, $delta);
    $pipe->execute();
  }

  /**
   * The XFetch algorithm.
   *
   * @param float $delta  Recompute time (in seconds)
   * @param float $ttl    Cache value's time-to-live (in seconds)
   * @return boolean      TRUE if caller should recompute
   */
  public static function xfetch($delta, $ttl)
  {
    $now = time();
    $expiry = $now + $ttl;
    $rnd = log(mt_rand() / mt_getrandmax());

    $xfetch = $delta * BETA * $rnd;

    $recompute = ($now - $xfetch) >= $expiry;
    if ($recompute)
      echo "* early recompute! delta:$delta ttl:$ttl rnd:$rnd xfetch:$xfetch\n";

    return $recompute;
  }
}

/**
 * Implementation of locked XFetch strategy.
 */

class XFetchLockWorker extends XFetchWorker
{
  protected function run()
  {
    list($ttl, $value, $delta) = $this->read();

    if (!$value) {
      $this->result = MISS;
    } else if (self::xfetch($delta, $ttl)) {
      $this->result = EARLY;
    } else {
      $this->result = HIT;

      return;
    }

    // acquire lock, recompute, write value
    $locked = false;
    do {
      $locked = $this->lock_acquire();
      if (!$locked && $value) {
        // special case: Could not acquire lock (meaning another worker was recomputing the value)
        // *but* this worker read a valid value.  Don't sweat it and use the valid value.  This is
        // called "ducking out."
        $this->result |= DUCKED;

        break;
      }

      if ($locked) {
        $this->full_recompute();

        break;
      }

      // did not get value and did not acquire lock; pause and retry
      $this->retry();
      list(, $value, ) = $this->read();
    } while (!$value);

    if ($locked)
      $this->lock_release();
  }
}
