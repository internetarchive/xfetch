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
define('DELTA', 3);
define('REPORT_EVERY_SEC', 5);

/**
 * Child process exit codes (indicating cache result).
 */
define('HIT', 0);
define('MISS', 1);
define('EARLY', 2);
define('UNKNOWN', 127);

exit(main($argv));

/**
 * int main(int argv, char *argv[])
 */
function main($argv)
{
  // install signal handler to exit on SIGINT
  $halt = false;
  pcntl_signal(SIGINT, function () use (&$halt) {
    $halt = true;
  });

  $workers = [];

  $total = 0;
  $tally = [ 'hits' => 0, 'misses' => 0, 'earlies' => 0, 'error' => 0 ];

  $dummy = new FetchWorker();
  $dummy->clear();

  $report_time_t = time() + REPORT_EVERY_SEC;

  for (;;) {
    while (!$halt && count($workers) < 50) {
      $worker = new FetchWorker();
      $pid = $worker->start();

      $workers[$pid] = $worker;
    }

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

    if (!$halt && ($total > 50) && ($report_time_t <= time())) {
      echo "$total samples:\n";
      var_dump($tally);
      echo "\n";

      $report_time_t = time() + REPORT_EVERY_SEC;
    }

    if ($halt && !count($workers))
      break;

    pcntl_signal_dispatch();
  }

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
  public $redis;
  public $pid = -1;

  public function __construct()
  {
    $this->redis = new Predis\Client();
  }

  public function start()
  {
    $pid = pcntl_fork();
    if ($pid === -1)
      exit("Could not fork\n");
    else if ($pid)
      return $this->pid = $pid;
    else
      exit($this->run());
  }

  public function join()
  {
    pcntl_waitpid($this->pid, $status);

    return pcntl_wifexited($status) ? pcntl_wexitstatus($status) : UNKNOWN;
  }

  /**
   * @return int
   */
  abstract protected function run();

  abstract public function clear();
}

/**
 *
 */

class FetchWorker extends ChildWorker
{
  const REDIS_KEY = 'test:cache:fetch';

  protected function run()
  {
    $result = HIT;

    $value = $this->redis->get(self::REDIS_KEY);
    if (!$value) {
      $value = recompute_fn();
      $this->redis->set(self::REDIS_KEY, $value, 'EX', TTL);

      $result = MISS;
    }

    return $result;
  }

  public function clear()
  {
    $this->redis->del(self::REDIS_KEY);
  }
}
