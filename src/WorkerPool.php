<?php
/**
 * The WorkerPool Requires the following PHP extensions
 *    * pcntl
 *    * posix
 *    * sysvsem
 *    * sockets
 *    * proctitle (optional, PHP5.5+ comes with a builtin function)
 *
 * Use the following commands to install them on RHEL:
 *    yum install php-process php-pcntl
 *    yum install php-pear php-devel ; pecl install proctitle
 *    echo 'extension=proctitle.so' > /etc/php.d/proctitle.ini
 */

namespace QXS\WorkerPool;

use QXS\WorkerPool\IPC\Message;
use QXS\WorkerPool\Process\Process;
use QXS\WorkerPool\Process\ProcessControl;
use QXS\WorkerPool\Worker\Worker;
use QXS\WorkerPool\Worker\WorkerProcess;
use QXS\WorkerPool\Worker\WorkerResult;

/**
 * The Worker Pool class runs worker processes in parallel
 *
 */
class WorkerPool extends Process {

	/** @var Semaphore the semaphore, that is used to synchronizd tasks across all processes */
	protected $semaphore;

	/** @var int number of minimum running children in the pool */
	private $minimumRunningWorkers = 0;

	/** @var int number of maximum running children in the pool */
	private $maximumRunningWorkers = 1;

	/** @var int Maximum time (in seconds) a process could stay in idle status. */
	private $maximumWorkersIdleTime = 0;

	/** @var \QXS\WorkerPool\Worker\Worker the worker class, that is used to run the tasks */
	protected $worker;

	/** @var WorkerProcess[] Collection of the worker processes */
	protected $workerProcesses = array();

	/** @var Message[] */
	protected $childResultMessages = array();

	/** @var bool */
	protected $handleTerminalSignal = TRUE;

	/** @var WorkerResult[] */
	protected $workerResults = array();

	/**
	 * @inheritdoc
	 */
	protected function onStart() {
		if ($this->context === self::CONTEXT_CHILD) {
			// Create the minimum amount of workers
			while (count($this->workerProcesses) < $this->getMinimumRunningWorkers()) {
				$this->createWorkerProcess();
			}
		} else {
			// no Semaphore attached? -> create one
			if (!($this->semaphore instanceof Semaphore)) {
				$this->semaphore = new Semaphore();
				$this->semaphore->create(Semaphore::SEM_RAND_KEY);
			}

			if (!$this->worker instanceof Worker) {
				throw new WorkerPoolException('A worker must be set to start processing.', 1403611788);
			}
		}
	}

	/**
	 * @inheritdoc
	 */
	protected function onDestroy() {
		$this->waitForAllWorkers();
		$this->terminateWorkers();
	}

	/**
	 * @inheritdoc
	 */
	protected function onChildExit() {
		if ($this->semaphore->isCreated()) {
			$this->semaphore->destroy();
		}
	}

	/**
	 * @param string $procedure
	 * @param mixed  $parameters
	 *
	 * @return mixed|void
	 */
	protected function doProcess($procedure, $parameters) {
		switch ($procedure) {
			case 'run':
			case 'waitForAllWorkers':
			case 'terminateIdleWorkers':
			case 'terminateWorkers':
			case 'getFreeAndBusyWorkers':
			case 'getResults':
			case 'getNextResult':
				return $this->$procedure($parameters);
		}
		return NULL;
	}

	/**
	 * @param mixed $input
	 *
	 * @return int
	 */
	public function run($input) {
		if ($this->context === self::CONTEXT_CHILD) {
			$workerProcess = $this->getNextFreeWorker();
			$workerProcess->process('run', $input, FALSE);
			return $workerProcess->getPid();
		} else {
			return $this->process('run', $input);
		}
	}

	public function waitForAllWorkers() {
		if ($this->context === self::CONTEXT_CHILD) {
			while ($this->getBusyWorkers() > 0) {
				$this->collectWorkerResults(self::PROCESS_TIMEOUT_SEC);
			}
		} else {
			$this->process('waitForAllWorkers');
		}
	}

	/**
	 * @return WorkerResult
	 */
	public function getNextResult() {
		if ($this->context === self::CONTEXT_CHILD) {
			$this->collectWorkerResults();
			return array_shift($this->workerResults);
		} else {
			return $this->process('getNextResult');
		}
	}

	/**
	 * @return WorkerResult[]
	 */
	public function getResults() {
		if ($this->context === self::CONTEXT_CHILD) {
			return $this->workerResults;
		} else {
			return $this->process('getResults');
		}
	}

	public function terminateWorkers() {
		if ($this->context === self::CONTEXT_CHILD) {
			foreach ($this->workerProcesses as $workerProcess) {
				$this->removeWorkerProcess($workerProcess);
			}
		} else {
			$this->process('terminateWorkers');
		}
	}

	/**
	 * Terminates all worker processes which are idle for longer than the defined maximum idle time. You usually do not
	 * have to call this method explicitly.
	 */
	public function terminateIdleWorkers() {
		if ($this->context === self::CONTEXT_CHILD) {
			if ($this->getMaximumWorkersIdleTime() === 0) {
				return;
			}

			foreach ($this->workerProcesses as $pid => $workerProcess) {
				if (
					$workerProcess->getIdleTime() >= $this->getMaximumWorkersIdleTime() &&
					count($this->workerProcesses) > $this->getMinimumRunningWorkers()
				) {
					$workerProcess->destroy();
					unset($this->workerProcesses[$pid]);
				}
			}
		} else {
			$this->process('terminateIdleWorkers');
		}
	}

	/**
	 * Returns the number of busy and free workers
	 *
	 * This function collects all the information at once.
	 *
	 * @return array with the keys 'free', 'busy', 'total'
	 */
	public function getFreeAndBusyWorkers() {
		$result = array(
			'free' => 0,
			'busy' => 0,
			'total' => 0
		);

		if ($this->context === self::CONTEXT_CHILD) {
			$result['total'] = count($this->workerProcesses);
			foreach ($this->workerProcesses as $workerProcess) {
				if ($workerProcess->isIdle()) {
					$result['free']++;
				} else if ($workerProcess->isBusy()) {
					$result['busy']++;
				}
			}
			return $result;
		} else {
			$processResult = $this->process('getFreeAndBusyWorkers');
			if (is_array($processResult)) {
				return $processResult;
			} else {
				return $result;
			}
		}
	}

	/**
	 * Returns the number of free workers
	 *
	 * PAY ATTENTION WHEN USING THIS FUNCTION WITH A SUBSEQUENT CALL OF getBusyWorkers().
	 * IN THIS CASE THE SUM MIGHT NOT EQUAL TO THE CURRENT POOL SIZE.
	 * USE getFreeAndBusyWorkers() TO GET CONSISTENT RESULTS.
	 *
	 * @return int number of free workers
	 */
	public function getFreeWorkers() {
		$workers = $this->getFreeAndBusyWorkers();
		return $workers['free'];
	}

	/**
	 * Returns the number of busy workers
	 *
	 * PAY ATTENTION WHEN USING THIS FUNCTION WITH A SUBSEQUENT CALL OF getFreeWorkers().
	 * IN THIS CASE THE SUM MIGHT NOT EQUAL TO THE CURRENT POOL SIZE.
	 * USE getFreeAndBusyWorkers() TO GET CONSISTENT RESULTS.
	 *
	 * @return int number of free workers
	 */
	public function getBusyWorkers() {
		$workers = $this->getFreeAndBusyWorkers();
		return $workers['busy'];
	}

	/**
	 * @param Worker $worker
	 *
	 * @throws WorkerPoolException
	 * @return WorkerPool
	 */
	public function setWorker(Worker $worker) {
		$this->checkThatProcessIsInitializing();
		$this->worker = $worker;
		return $this;
	}

	/**
	 * @return \QXS\WorkerPool\Worker\Worker
	 */
	public function getWorker() {
		return $this->worker;
	}

	/**
	 * Returns the current size of the worker pool
	 *
	 * In case the pool hasn't yet been created, this method returns the value of the currently set pool size.
	 * In case of a created pool, this method reports the real pool size (number of alive worker processes).
	 *
	 * @return int the number of processes
	 *
	 * @deprecated
	 */
	public function getWorkerPoolSize() {
		return $this->getMaximumRunningWorkers();
	}

	/**
	 * Sets the current size of the worker pool
	 *
	 * @param int $size the new worker pool size
	 *
	 * @return WorkerPool
	 * @throws \InvalidArgumentException in case the $size value is not within the permitted range
	 *
	 * @deprecated
	 */
	public function setWorkerPoolSize($size) {
		$this->checkThatProcessIsInitializing();
		$this->setMaximumRunningWorkers($size);
		$this->setMinimumRunningWorkers($size);
		return $this;
	}

	/**
	 * Sets the minimum number of running worker processes
	 *
	 * @param int $minimumRunningWorkers
	 *
	 * @return WorkerPool
	 * @throws \InvalidArgumentException
	 */
	public function setMinimumRunningWorkers($minimumRunningWorkers) {
		$this->checkThatProcessIsInitializing();
		$minimumRunningWorkers = (int)$minimumRunningWorkers;
		if ($minimumRunningWorkers < 0) {
			throw new \InvalidArgumentException('$minimumRunningWorkers must not be negative.', 1401261542);
		}
		$this->minimumRunningWorkers = $minimumRunningWorkers;
		$this->checkWorkerCountBoundaries();
		return $this;
	}

	/**
	 * Gets the minimum number of running worker processes
	 *
	 * @return int
	 */
	public function getMinimumRunningWorkers() {
		return $this->minimumRunningWorkers;
	}

	/**
	 * Sets the maximum number of running worker processes
	 *
	 * @param int $maximumRunningWorkers
	 *
	 * @throws \InvalidArgumentException
	 * @return WorkerPool
	 */
	public function setMaximumRunningWorkers($maximumRunningWorkers) {
		$this->checkThatProcessIsInitializing();
		$maximumRunningWorkers = (int)$maximumRunningWorkers;
		if ($maximumRunningWorkers <= 0) {
			throw new \InvalidArgumentException('$maximumRunningWorkers must be greater than 0.', 1401261541);
		}
		$this->maximumRunningWorkers = $maximumRunningWorkers;
		$this->checkWorkerCountBoundaries();
		return $this;
	}

	/**
	 * Gets the maximum number of running worker processes
	 *
	 * @return int
	 */
	public function getMaximumRunningWorkers() {
		return $this->maximumRunningWorkers;
	}

	/**
	 * Sets the maximum time (in seconds) a process could stay in idle status. Set to 0 if no idle processes should be
	 * automatically terminated.
	 *
	 * @param int $maximumWorkersIdleTime
	 *
	 * @throws \InvalidArgumentException
	 * @return WorkerPool
	 */
	public function setMaximumWorkersIdleTime($maximumWorkersIdleTime) {
		$this->checkThatProcessIsInitializing();
		$maximumWorkersIdleTime = (int)$maximumWorkersIdleTime;
		if ($maximumWorkersIdleTime < 0) {
			throw new \InvalidArgumentException('$maximumWorkersIdleTime must be not negative.', 1401261540);
		}
		$this->maximumWorkersIdleTime = $maximumWorkersIdleTime;
		return $this;
	}

	/**
	 * Gets the maximum time (in seconds) a process could stay in idle status.
	 *
	 * @return int
	 */
	public function getMaximumWorkersIdleTime() {
		return $this->maximumWorkersIdleTime;
	}

	/**
	 * Sets the Semaphore, that will be used within the worker processes
	 *
	 * @param Semaphore $semaphore the Semaphore, that should be used for the workers
	 *
	 * @return WorkerPool
	 * @throws \InvalidArgumentException in case the semaphre hasn't been created
	 */
	public function setSemaphore(Semaphore $semaphore) {
		$this->checkThatProcessIsInitializing();
		if (!$semaphore->isCreated()) {
			throw new \InvalidArgumentException('The Semaphore hasn\'t yet been created.');
		}
		$this->semaphore = $semaphore;
		return $this;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	protected function checkWorkerCountBoundaries() {
		if ($this->minimumRunningWorkers > $this->maximumRunningWorkers) {
			throw new \InvalidArgumentException('The boundary for the minimum running workers must be less or equal than the maximum.', 1401261539);
		}
	}

	/**
	 * @throws \RuntimeException
	 */
	private function createWorkerProcess() {
		$workerProcess = new WorkerProcess();
		$workerProcess->setWorker($this->worker);
		$workerProcess->start();
		ProcessControl::instance()->onProcessReaped(array($this, 'onWorkerProcessReaped'), $workerProcess->getPid());
		$this->workerProcesses[$workerProcess->getPid()] = $workerProcess;
		return $workerProcess;
	}

	/**
	 * @internal
	 *
	 * @param Process $process
	 */
	public function onWorkerProcessReaped(Process $process) {
		if (array_key_exists($process->getPid(), $this->workerProcesses) === FALSE) {
			return;
		}

		foreach ($process->getRemainingMessages() as $remainingMessage) {
			$this->addWorkerResult($remainingMessage);
		}

		$this->removeWorkerProcess($process);
	}

	/**
	 * @param Process $workerProcess
	 */
	protected function removeWorkerProcess(Process $workerProcess) {
		$pid = $workerProcess->getPid();
		if (array_key_exists($pid, $this->workerProcesses) === FALSE) {
			return;
		}
		if ($workerProcess->isRunning()) {
			$workerProcess->destroy();
		}
		unset($this->workerProcesses[$pid]);
	}

	/**
	 * @param Message $message
	 */
	protected function addWorkerResult(Message $message) {
		// Accept only this message types as worker result
		if (in_array($message->getProcedure(), array('run', '_exception')) === FALSE) {
			return;
		}
		$this->workerResults[] = new WorkerResult($message->getPid(), $message->getParameters());
	}

	/**
	 * @return WorkerProcess[]
	 */
	protected function getFreeWorkerProcesses() {
		$freeWorkerProcesses = array();
		foreach ($this->workerProcesses as $pid => $workerProcess) {
			if ($workerProcess->isIdle()) {
				$freeWorkerProcesses[$pid] = $workerProcess;
			}
		}
		return $freeWorkerProcesses;
	}

	/**
	 * Get the pid of the next free worker
	 *
	 * This function blocks until a worker has finished its work.
	 * You can kill all child processes, so that the parent will be unblocked.
	 *
	 * @return WorkerProcess
	 */
	protected function getNextFreeWorker() {
		$sec = 0;
		while (TRUE) {
			while (count($this->workerProcesses) < $this->getMinimumRunningWorkers()) {
				$this->createWorkerProcess();
			}

			$this->collectWorkerResults($sec);

			$freeWorkerProcesses = $this->getFreeWorkerProcesses();

			if (count($freeWorkerProcesses) === 0 && count($this->workerProcesses) < $this->getMaximumRunningWorkers()) {
				return $this->createWorkerProcess();
			}

			$freeWorkerProcesses = $this->getFreeWorkerProcesses();
			if (count($freeWorkerProcesses) > 0) {
				return reset($freeWorkerProcesses);
			}

			$sec = self::PROCESS_TIMEOUT_SEC;
			ProcessControl::sleepAndSignal();
		}

		return NULL;
	}

	/**
	 * Collects the results form the workers and processes any pending signals
	 *
	 * @param int $sec timeout to wait for new results from the workers
	 *
	 * @throws WorkerPoolException
	 */
	protected function collectWorkerResults($sec = 0) {
		foreach (WorkerProcess::receiveMessages($this->workerProcesses, $sec) as $message) {
			$this->addWorkerResult($message);
		}
	}
}