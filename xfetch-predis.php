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
define('INITIAL_TTL', 3);
define('DELTA', 1);
define('LOCK_TTL', 10);

/**
 * Child process exit codes (indicating cache result).
 */
define('HIT',     0);
define('MISS',    1);
define('EARLY',   2);
define('RETRY',   3);
define('SIMUL',   4);
define('UNKNOWN', 127);

$EXITCODE_TO_LABEL = [
  HIT     => 'hits',
  MISS    => 'misses',
  EARLY   => 'earlies',
  RETRY   => 'retries',
  SIMUL   => 'simul recomputes',
];

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
  global $EXITCODE_TO_LABEL;

  // install signal handler to exit on Ctrl+C
  $halt = false;
  pcntl_signal(SIGINT, function () use (&$halt) {
    echo "Halting...\n";
    $halt = true;
  });

  // clear the cached value from Redis
  $redis = new Predis\Client();
  $redis->del(REDIS_KEY, LOCK_KEY, DELTA_KEY, SIMUL_KEY);

  $workers = [];
  $first_pass = true;

  $total = 0;
  $tally = [];

  $report_time_t = time() + REPORT_EVERY_SEC;

  do {
    // keep max. workers running
    $added = 0;
    while (!$halt && count($workers) < WORKERS) {
      $worker = new XFetchWorker($first_pass ? INITIAL_TTL : TTL);
      $worker->first_pass = $first_pass;

      $pid = $worker->start();

      $workers[$pid] = $worker;
      $added++;
    }

    // reset to indicate new workers are post-first-pass (which plays into stats gathering)
    $first_pass = false;

    // wait for a child process to exit
    $pid = pcntl_wait($status);
    if ($pid === -1)
      exit("Could not wait for child process\n");

    // remove from pool and get exit code
    $worker = $workers[$pid];
    unset($workers[$pid]);
    $exitcode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : UNKNOWN;

    // don't gather stats for the first pass; this allows for stats to be gathered only once the
    // cache is warm
    if (!$worker->first_pass) {
      $total++;
      $label = isset($EXITCODE_TO_LABEL[$exitcode]) ? $EXITCODE_TO_LABEL[$exitcode] : "exit $exitcode";
      isset($tally[$label]) ? $tally[$label]++ : ($tally[$label] = 1);
    }

    // report stats every 'n' seconds, but only report when a full round of workers have completed
    if (!$halt && ($total >= WORKERS) && ($report_time_t <= time())) {
      report_stats($total, $tally);

      // calc next report time
      $report_time_t = time() + REPORT_EVERY_SEC;
    }

    // allow for signals to be dispatched ... this is used in place of declare(ticks=1)
    if (!$halt)
      pcntl_signal_dispatch();
  } while (!$halt || count($workers));

  // final report
  report_stats($total, $tally);

  return 0;
}

/**
 * Dummy recompute function.
 */
function recompute_fn()
{
  sleep(DELTA);
  return 'gnusto';
}

function report_stats($total, $tally)
{
  ksort($tally);

  echo "$total samples:\n";
  foreach ($tally as $label => $count)
    echo "  $label\t$count\n";
  echo "\n";
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
      $result = $this->recompute_start() ? SIMUL : MISS;

      $value = recompute_fn();
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

      if ($result == HIT)
        $result = MISS;

      if ($this->lock_acquire()) {
        // simultaneous recomputes should never happen with locking, but check anyway
        if ($this->recompute_start())
          $result = SIMUL;

        $value = recompute_fn();
        $this->redis->set(REDIS_KEY, $value, 'EX', $this->expires);

        $this->lock_release();
        $this->recompute_finish();

        break;
      }

      $result = RETRY;
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
        $result = SIMUL;

      $start = microtime(true);
      $value = recompute_fn();
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

    $recompute = (time() - $xfetch) >= $expiry;
    if ($recompute)
      echo "* early recompute! delta:$delta ttl:$ttl rnd:$rnd xfetch:$xfetch\n";

    return $recompute;
  }
}
