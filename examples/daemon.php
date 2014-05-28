<?php

require_once(__DIR__ . '/../autoload.php');

use QXS\WorkerPool\WorkerPool;

class Daemon {

	/**
	 * @var WorkerPool
	 */
	protected $workerPool;

	/**
	 * @var bool
	 */
	protected $terminating = FALSE;

	/**
	 * @var array
	 */
	protected $jobs = array();

	/**
	 * @var array
	 */
	protected $runningJobs = array();

	public function __construct() {
		$this->initializeSignals();
		$this->workerPool = new WorkerPool();
		$this->workerPool
			->setMaximumRunningWorkers(10)
			->setMinimumRunningWorkers(2)
			->setMaximumWorkersIdleTime(10)
			->create(new \QXS\WorkerPool\ClosureWorker(
				function ($input, $semaphore, $storage) {
					$load = rand(2, 7);
					$time = time();
					while (time() - $time < $load) {
						sqrt(9999999);
					}
					if (rand(0, 500) === 0) {
						whoops();
					}
					return 42;
				}
			));
	}

	public function run() {
		$this->createDummyJobs();

		while ($this->terminating === FALSE) {
			$busy = $this->workerPool->getBusyWorkers();
			$free = $this->workerPool->getFreeWorkers();
			echo "Stats\t\t$free/$busy (free/busy)\n";

			$newRunnedJobs = 0;
			$runningJobs = 0;
			foreach ($this->loadJobs() as $jobId => $job) {
				if ($this->isJobRunning($jobId)) {
					$runningJobs++;
					continue;
				}
				$this->runJob($job, $jobId);
				$newRunnedJobs++;
			}
			echo "Run\t\t$newRunnedJobs/$runningJobs (new/running)\n";
			$this->collectResults();

			sleep(1);
		}
	}

	/**
	 * @return array
	 */
	protected function createDummyJobs() {
		for ($i = 0; $i < 500; $i++) {
			$this->jobs[rand(50000, 70000)] = array(
				'data' => rand(10000, 100000),
				'result' => NULL,
				'state' => 'initial'
			);
		}
	}

	protected function collectResults() {
		$erroneous = 0;
		$done = 0;
		while (($nextResult = $this->workerPool->getNextResult()) !== NULL) {
			$jobId = NULL;
			$pid = $nextResult['pid'];
			// Find job id
			foreach ($this->runningJobs as $runningJobId => $runningPid) {
				if ($pid === $runningPid) {
					$jobId = $runningJobId;
					break;
				}
			}

			$job =& $this->jobs[$jobId];

			if (array_key_exists('data', $nextResult) && $nextResult['data'] !== NULL) {
				$job['result'] = $nextResult['data'];
				$job['state'] = 'done';
				$done++;
			} else {
				$job['state'] = 'error';
				$erroneous++;
			}

			unset($this->runningJobs[$jobId]);
		}

		echo "Collect\t\t$done/$erroneous (done/erroneous)\n";
	}

	/**
	 * @param array $job
	 * @param int $jobId
	 */
	protected function runJob(array $job, $jobId) {
		$pid = $this->workerPool->run($job['data']);
		$this->runningJobs[$jobId] = $pid;
	}

	/**
	 * @param $jobId
	 * @return bool
	 */
	protected function isJobRunning($jobId) {
		return array_key_exists($jobId, $this->runningJobs);
	}

	/**
	 * Loads all undone jobs
	 *
	 * @return array
	 */
	protected function loadJobs() {
		$jobs = array();
		$maxJobs = rand(1, 7);
		if (rand(0, 6) < 6) {
			return array();
		}
		foreach ($this->jobs as $jobId => $job) {
			if (count($jobs) >= $maxJobs) {
				break;
			}
			if ($job['state'] !== 'done') {
				$jobs[$jobId] = $job;
			}
		}
		sleep(rand(1, 2));
		echo "Load\t" . count($jobs) . " jobs\n";
		return $jobs;
	}

	protected function initializeSignals() {
		$signals = array(SIGTERM, SIGINT);

		foreach ($signals as $signal) {
			pcntl_signal($signal, array($this, 'signal'));
		}
	}

	public function signal($signo) {
		switch ($signo) {
			case SIGINT:
			case SIGTERM:
				echo "Shutting down...\n";
				$this->terminating = TRUE;
				$this->workerPool->destroy();
				$this->workerPool->exitPhp(0);
				break;
		}
	}
}

$daemon = new Daemon();
$daemon->run();