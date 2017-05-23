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
define('INITIAL_TTL', 12);
define('DELTA_MS', 500);
define('LOCK_TTL', 10);

/**
 * Child process exit codes (indicating cache result).
 */
define('HIT',     0x00);
define('MISS',    0x01);
define('EARLY',   0x02);
define('RETRY',   0x10);
define('SIMUL',   0x20);
define('UNKNOWN', 0x7F);

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

// run it!
exit(main($argv));

/**
 * int main(int argv, char *argv[])
 */
function main($argv)
{
  $harness = new Harness();

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

/**
 *
 */

class Harness
{
  // map of PID => Worker objects
  private $workers = [];
  // indicates created Workers are in the first-pass and should be ignored, as the cache is cold
  private $first_pass = true;

  // stats
  private $total = 0;
  private $tally = [];

  // next time_t to report gathered stats
  private $report_time_t;

  private $halt = false;

  public function __construct()
  {
    $this->report_time_t = time() + REPORT_EVERY_SEC;
  }

  public function start()
  {
    // main loop
    while (!$this->halt) {
      // keep max. workers running
      $this->maintain_worker_count();

      // reset to indicate additional workers are post-first-pass (which plays into stats gathering)
      $this->first_pass = false;

      // wait for a child process to exit
      $worker = $this->wait_for_completion();

      // don't gather stats for the first pass; this allows for stats to be gathered only once the
      // cache is warm
      if (!$worker->first_pass)
        $this->tally_stats($worker->exitcode);

      // report stats every 'n' seconds, but only report when a full round of workers have completed
      if (($this->total >= WORKERS) && ($this->report_time_t <= time())) {
        $this->report_stats();

        // calc next report time
        $this->report_time_t = time() + REPORT_EVERY_SEC;
      }

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

  private function maintain_worker_count()
  {
    while (count($this->workers) < WORKERS) {
      $worker = new XFetchWorker($this->first_pass ? INITIAL_TTL : TTL);
      $worker->first_pass = $this->first_pass;

      $pid = $worker->start();

      $this->workers[$pid] = $worker;
    }
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

    return $worker;
  }

  public function report_stats()
  {
    ksort($this->tally);

    echo "{$this->total} samples:\n";
    foreach ($this->tally as $label => $count)
      echo "  $label\t$count\n";
    echo "\n";
  }

  private function tally_stats($exitcode)
  {
    $this->total++;

    // exitcode is an octet bitmask, with the lower nibble an enumeration of possible results and
    // the upper nibble modifier flags
    switch ($exitcode & 0x0F) {
      case HIT:
        $this->incr_tally('hits');
      break;

      case MISS:
        $this->incr_tally('misses');
      break;

      case EARLY:
        $this->incr_tally('earlies');
      break;

      default:
        // stash in default bucket and exit (don't check for modifiers)
        $this->incr_tally("exit $exitcode");
        return;
    }

    if (($exitcode & RETRY) != 0)
      $this->incr_tally('retries');

    if (($exitcode & SIMUL) != 0)
      $this->incr_tally('simul. recomputes');
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

    // in child process

    // replace parent signal handler w/ nop
    pcntl_signal(SIGINT, function () {
    });

    // to keep all the processes from executing in lockstep, introduce some stochasticity with a
    // random pause before starting execution
    usleep(mt_rand(10, 500) * 1000);

    // go
    try {
      exit($this->run());
    } catch (Exception $e) {
      exit(-1);
    }
  }

  /**
   * @return int
   */
  abstract protected function run();

  /**
   * Dummy recompute function.
   */
  protected function recompute_fn()
  {
    usleep(DELTA_MS * 1000);
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
    return $this->redis->incr(SIMUL_KEY) > 1;
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
    return $this->redis->set(LOCK_KEY, 'acquired', 'EX', LOCK_TTL, 'NX');
  }

  protected function lock_release()
  {
    $this->redis->del(LOCK_KEY);
  }
}

/**
 *
 */

class FetchWorker extends ChildWorker
{
  protected function run()
  {
    $result = HIT;

    $value = $this->redis->get(REDIS_KEY);
    if (!$value) {
      $result = MISS;

      if ($this->recompute_start())
        $result |= SIMUL;

      $value = $this->recompute_fn();
      $this->redis->set(REDIS_KEY, $value, 'EX', $this->expires);

      $this->recompute_finish();
    }

    return $result;
  }
}

/**
 *
 */

class LockWorker extends ChildWorker
{
  protected function run()
  {
    $result = HIT;
    for (;;) {
      $value = $this->redis->get(REDIS_KEY);
      if ($value)
        break;

      $result = MISS;

      if ($this->lock_acquire()) {
        // simultaneous recomputes should never happen with locking, but check anyway
        if ($r = $this->recompute_start())
          $result |= SIMUL;

        $value = $this->recompute_fn();
        $this->redis->set(REDIS_KEY, $value, 'EX', $this->expires);

        $this->recompute_finish();
        $this->lock_release();

        break;
      }

      $result |= RETRY;
      usleep(100 * 1000);
    }

    return $result;
  }
}

/**
 *
 */

class XFetchWorker extends ChildWorker
{
  protected function run()
  {
    $result = HIT;

    $pipe = $this->redis->pipeline(['atomic' => true]);
    $pipe->ttl(REDIS_KEY);
    $pipe->get(REDIS_KEY);
    $pipe->get(DELTA_KEY);
    list($ttl, $value, $delta) = $pipe->execute();

  if (!$value || self::xfetch($delta, $ttl)) {
      $result = !$value ? MISS : EARLY;

      if ($this->recompute_start())
        $result |= SIMUL;

      $start = microtime(true);
      $value = $this->recompute_fn();
      $delta = microtime(true) - $start;

      $pipe = $this->redis->pipeline(['atomic' => true]);
      $pipe->setex(REDIS_KEY, $this->expires, $value);
      $pipe->setex(DELTA_KEY, $this->expires, $delta);
      $pipe->execute();

      $this->recompute_finish();
    }

    return $result;
  }

  private static function xfetch($delta, $ttl)
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
