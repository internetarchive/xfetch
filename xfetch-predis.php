<?php

/**
 * Replace with path to your Predis installation.
 */
require '../predis/src/Autoloader.php';
Predis\Autoloader::register();

/**
 * Times in seconds.
 */
define('TTL', 10);
define('DELTA', 5);

/**
 * Child process exit codes (indicating cache result).
 */
define('HIT', 0);
define('MISS', 1);
define('EARLY', 2);
define('UNKNOWN', 127);

/**
 * Test parameters.
 */
define('REDIS_KEY', 'test:cache');
define('WORKERS', 50);
define('REPORT_EVERY_SEC', 5);

// run it!
exit(main($argv));

/**
 * int main(int argv, char *argv[])
 */
function main($argv)
{
  // install signal handler to exit on Ctrl+C
  $halt = false;
  pcntl_signal(SIGINT, function () use (&$halt) {
    $halt = true;
  });

  // clear the cached value from Redis
  $redis = new Predis\Client();
  $redis->del(REDIS_KEY);

  $workers = [];

  $total = 0;
  $tally = [ 'hits' => 0, 'misses' => 0, 'earlies' => 0, 'error' => 0 ];

  $report_time_t = time() + REPORT_EVERY_SEC;

  do {
    // keep max. workers running
    $added = 0;
    while (!$halt && count($workers) < WORKERS) {
      $worker = new FetchWorker();
      $pid = $worker->start();

      $workers[$pid] = $worker;
      $added++;
    }

    // if added in bulk, give child processes a chance to start
    if ($added > 1)
      sleep(1);

    // wait for a child process to exit
    $pid = pcntl_wait($status);
    if ($pid === -1)
      exit("Could not wait for child process\n");

    unset($workers[$pid]);
    $exitcode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : UNKNOWN;

    $total++;
    switch ($exitcode) {
      case HIT:
        $tally['hits']++;
      break;

      case MISS:
        $tally['misses']++;
      break;

      case EARLY:
        $tally['earlies']++;
      break;

      case UNKNOWN:
      default:
        $tally['error']++;
      break;
    }

    // report stats every 'n' seconds, but only report when a full round of workers have completed
    if (!$halt && ($total >= WORKERS) && ($report_time_t <= time())) {
      echo "$total samples:\n";
      var_dump($tally);
      echo "\n";

      $report_time_t = time() + REPORT_EVERY_SEC;
    }

    pcntl_signal_dispatch();
  } while (!$halt || count($workers));

  echo "$total samples:\n";
  var_dump($tally);

  return 0;
}

function recompute_fn()
{
  sleep(DELTA);
  return 'gnusto';
}

/**
 *
 */

abstract class ChildWorker
{
  public $pid = -1;

  public function __construct()
  {
  }

  public function start()
  {
    $pid = pcntl_fork();
    if ($pid === -1)
      exit("Could not fork\n");
    else if ($pid)
      return $this->pid = $pid;
    else
      exit($this->run(new Predis\Client()));
  }

  /**
   * @param Predis\Client $redis
   * @return int
   */
  abstract protected function run($redis);
}

/**
 *
 */

class FetchWorker extends ChildWorker
{
  protected function run($redis)
  {
    $result = HIT;

    $value = $redis->get(REDIS_KEY);
    if (!$value) {
      $value = recompute_fn();
      $redis->set(REDIS_KEY, $value, 'EX', TTL);

      $result = MISS;
    }

    return $result;
  }
}
