#!/usr/bin/env php
<?php

/**
 * Replace with path to your Predis installation.
 */
require '../predis/src/Autoloader.php';
Predis\Autoloader::register();

/**
 * Times in seconds.
 */
define('TTL', 12);
define('DELTA_MS', 500);
define('LOCK_TTL', 10);

/**
 * Child process exit codes (indicating cache result).
 *
 * Bottom 3 bits are cache result, top 5 bits are modifier, UNKNOWN and PROCERR are general errors.
 */
define('HIT',     0x00);
define('MISS',    0x01);
define('EARLY',   0x02);
define('PROCERR', 0x03);
define('UNKNOWN', 0x04);

define('RECOMP',  0x08);
define('RETRY',   0x10);
define('SIMUL',   0x20);
define('CONTEND', 0x40);
define('DUCKED',  0x80);

/**
 * Redis keys.
 */
define('REDIS_KEY', 'test:cache');
define('LOCK_KEY',  'test:lock');
define('DELTA_KEY', 'test:delta');
define('SIMUL_KEY', 'test:simul-recomputes');

/**
 * Test parameters.
 */
define('WORKERS', 50);
define('REPORT_EVERY_SEC', 5);
define('BETA', 1.0);

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
  global $strategies;

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
  $redis = new Predis\Client();
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
 *
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

  public function __construct($worker_class)
  {
    $this->worker_class = $worker_class;
    $this->report_time_t = time() + REPORT_EVERY_SEC;
  }

  public function start()
  {
    // launch first pass of workers
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
    $worker = new $this->worker_class(TTL);
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

    if (pcntl_wifsignaled($status)) {
      $sig = pcntl_wtermsig($status);
      echo "Worker {$worker->pid} terminated: $sig\n";
    }

    return $worker;
  }

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

  private function tally_stats($worker)
  {
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
 *
 */

abstract class ChildWorker
{
  public $result = UNKNOWN;
  public $pid = -1;
  public $redis;
  public $expires;

  public function __construct($expires)
  {
    $this->redis = new Predis\Client();
    $this->expires = $expires;
  }

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
   *
   */
  abstract protected function run();

  /**
   * Dummy recompute function.
   */
  protected function recompute_fn()
  {
    usleep(DELTA_MS * 1000);
    $this->result |= RECOMP;

    return 'gnusto';
  }

  /**
   * Track simultaneous recomputes.
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

  protected function lock_acquire()
  {
    $acquired = $this->redis->set(LOCK_KEY, 'acquired', 'EX', LOCK_TTL, 'NX');
    if (!$acquired)
      $this->result |= CONTEND;

    return $acquired;
  }

  protected function lock_release()
  {
    $this->redis->del(LOCK_KEY);
  }

  protected function retry()
  {
    usleep(10 * 1000);
    $this->result |= RETRY;
  }
}

/**
 *
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
 *
 */

class LockWorker extends ChildWorker
{
  protected function run()
  {
    $value = $this->redis->get(REDIS_KEY);
    $this->result = $value ? HIT : MISS;

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

      $this->retry();
      $value = $this->redis->get(REDIS_KEY);
    }

    if ($locked)
      $this->lock_release();
  }
}

/**
 *
 */

class XFetchWorker extends ChildWorker
{
  protected function run()
  {
    list($ttl, $value, $delta) = $this->read();

    if (!$value)
      $this->result = MISS;
    else if (self::xfetch($delta, $ttl))
      $this->result = EARLY;
    else
      return $this->result = HIT;

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

  protected function full_recompute()
  {
    $this->recompute_start();

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
 *
 */

class XFetchLockWorker extends XFetchWorker
{
  protected function run()
  {
    list($ttl, $value, $delta) = $this->read();

    if (!$value)
      $this->result = MISS;
    else if (self::xfetch($delta, $ttl))
      $this->result = EARLY;
    else
      return $this->result = HIT;

    $locked = false;
    do {
      $locked = $this->lock_acquire();
      if (!$locked && $value) {
        $this->result |= DUCKED;

        break;
      }

      if ($locked) {
        $this->full_recompute();

        break;
      }

      $this->retry();
      list(, $value, ) = $this->read();
    } while (!$value);

    if ($locked)
      $this->lock_release();
  }
}
