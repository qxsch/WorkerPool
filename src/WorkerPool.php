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

/**
 * The Worker Pool class runs worker processes in parallel
 *
 */
class WorkerPool implements \Iterator, \Countable {

	/** Default child timeout in seconds */
	const CHILD_TIMEOUT_SEC = 10;

	/** @var array signals, that should be watched */
	protected $signals = array(
		SIGCHLD, SIGTERM, SIGHUP, SIGUSR1
	);

	/** @var bool is the pool created? (children forked) */
	private $created = FALSE;

	/** @var int number of children in the pool */
	private $workerPoolSize = 2;

	/** @var int number of children initially in the pool */
	private $initialPoolSize;

	/** @var int Current index for the last worker created in the pool */
	private $currentWorkerIndex = 0;

	/** @var int id of the parent */
	protected $parentPid = 0;

	/** @var \QXS\WorkerPool\WorkerInterface the worker class, that is used to run the tasks */
	protected $worker;

	/** @var \QXS\WorkerPool\Semaphore the semaphore, that is used to synchronizd tasks across all processes */
	protected $semaphore;

	/** @var ProcessDetailsCollection|ProcessDetails[] Collection of the worker processes */
	protected $workerProcesses;

	/** @var array received results from the workers */
	protected $results = array();

	/** @var int number of received results */
	protected $resultPosition = 0;

	/** @var string process title of the parent */
	protected $parentProcessTitleFormat = '%basename%: Parent';

	/** @var string process title of the children */
	protected $childProcessTitleFormat = '%basename%: Worker %i% of %class% [%state%]';

	/** @var boolean Respawn dead workers automatically if set to TRUE */
	private $respawnAutomatically = false;

	/**
	 * The constructor
	 */
	public function __construct() {
		$this->workerProcesses = new ProcessDetailsCollection();
		register_shutdown_function(array($this, 'onShutDown'));
	}

	/**
	 * The destructor
	 */
	public function __destruct() {
		if ($this->created) {
			$this->destroy();
		}
	}

	/**
	 * Returns the process title of the child
	 * @return string the process title of the child
	 */
	public function getChildProcessTitleFormat() {
		return $this->childProcessTitleFormat;
	}

	/**
	 * Sets the process title of the child
	 *
	 * Listing permitted replacments
	 *   %i%         The Child's Number
	 *   %basename%  The base name of PHPSELF
	 *   %fullname%  The value of PHPSELF
	 *   %class%     The Worker's Classname
	 *   %state%     The Worker's State
	 *
	 * @param string $string the process title of the child
	 * @return WorkerPool
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	public function setChildProcessTitleFormat($string) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Parent\'s Process Title Format for a created pool.');
		}
		$this->childProcessTitleFormat = ProcessDetails::sanitizeProcessTitleFormat($string);
		return $this;
	}

	/**
	 * Returns the process title of the parent
	 * @return string the process title of the parent
	 */
	public function getParentProcessTitleFormat() {
		return $this->parentProcessTitleFormat;
	}

	/**
	 * Sets the process title of the parent
	 *
	 * Listing permitted replacments
	 *   %basename%  The base name of PHPSELF
	 *   %fullname%  The value of PHPSELF
	 *   %class%     The WorkerPool's Classname
	 *
	 * @param string $string the process title of the parent
	 * @return WorkerPool
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	public function setParentProcessTitleFormat($string) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Children\'s Process Title Format for a created pool.');
		}
		$this->parentProcessTitleFormat = ProcessDetails::sanitizeProcessTitleFormat($string);
		return $this;
	}

	/**
	 * Returns the current size of the worker pool
	 *
	 * In case the pool hasn't yet been created, this method returns the value of the currently set pool size.
	 * In case of a created pool, this method reports the real pool size (number of alive worker processes).
	 * @return int the number of processes
	 */
	public function getWorkerPoolSize() {
		return $this->workerPoolSize;
	}

	/**
	 * Sets the current size of the worker pool
	 * @param int $size the new worker pool size
	 * @return WorkerPool
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \InvalidArgumentException in case the $size value is not within the permitted range
	 */
	public function setWorkerPoolSize($size) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Worker Pool Size for a created pool.');
		}
		$size = (int)$size;
		if ($size <= 0) {
			throw new \InvalidArgumentException('"' . $size . '" is not an integer greater than 0.');
		}
		$this->workerPoolSize = $size;
		return $this;
	}

	/**
	 * Gets the Semaphore, that will be used within the worker processes
	 * @return null|\QXS\WorkerPool\Semaphore $semaphore the Semaphore, that should be used for the workers
	 */
	public function getSemaphore() {
		return $this->semaphore;
	}

	/**
	 * Sets the Semaphore, that will be used within the worker processes
	 * @param \QXS\WorkerPool\Semaphore $semaphore the Semaphore, that should be used for the workers
	 * @return WorkerPool
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \InvalidArgumentException in case the semaphre hasn't been created
	 */
	public function setSemaphore(Semaphore $semaphore) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Worker Pool Size for a created pool.');
		}
		if (!$semaphore->isCreated()) {
			throw new \InvalidArgumentException('The Semaphore hasn\'t yet been created.');
		}
		$this->semaphore = $semaphore;
		return $this;
	}

	/**
	 * Disables the semaphore feature in the workerpool
	 * 
	 * Attention: You will lose the possibility to synchronize worker processes
	 *
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \InvalidArgumentException in case the semaphre hasn't been created
	 */
	public function disableSemaphore() {
		$sem = new NoSemaphore();
		$sem->create();
		$this->setSemaphore($sem);
		return $this;
	}

	/**
	 * Terminates the current process
	 * @param int $code the exit code
	 */
	public function exitPhp($code) {
		exit($code);
	}

	/**
	 * Creates the worker pool (forks the children)
	 *
	 * Please close all open resources before running this function.
	 * Child processes are going to close all open resources uppon exit,
	 * leaving the parent process behind with invalid resource handles.
	 * @param \QXS\WorkerPool\WorkerInterface $worker the worker, that runs future tasks
	 * @throws \RuntimeException
	 * @throws WorkerPoolException
	 * @return WorkerPool
	 */
	public function create(WorkerInterface $worker) {
		if ($this->workerPoolSize <= 1) {
			$this->workerPoolSize = 2;
		}
		$this->initialPoolSize = $this->workerPoolSize;
		$this->parentPid = getmypid();
		$this->worker = $worker;
		if ($this->created) {
			throw new WorkerPoolException('The pool has already been created.');
		}

		$this->created = TRUE;
		// when adding signals use pcntl_signal_dispatch(); or declare ticks
		foreach ($this->signals as $signo) {
			pcntl_signal($signo, array($this, 'signalHandler'));
		}

		// no Semaphore attached? -> create one
		if (!($this->semaphore instanceof Semaphore)) {
			$this->semaphore = new Semaphore();
			$this->semaphore->create(Semaphore::SEM_RAND_KEY);
		}
		elseif(!$this->semaphore->isCreated()) {
			$this->semaphore->create(Semaphore::SEM_RAND_KEY);
		}

		ProcessDetails::setProcessTitle(
			$this->parentProcessTitleFormat,
			array(
				'basename' => basename($_SERVER['PHP_SELF']),
				'fullname' => $_SERVER['PHP_SELF'],
				'class' => get_class($this)
			)
		);

		for ($this->currentWorkerIndex = 1; $this->currentWorkerIndex <= $this->workerPoolSize; $this->currentWorkerIndex++) {
			$this->createWorker($this->currentWorkerIndex);
		}

		return $this;
	}

	/**
	 * Creates the worker
	 * @param int $i
	 * @throws \RuntimeException
	 */
	private function createWorker($i) {
		$sockets = array();
		if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets) === FALSE) {
			// clean_up using posix_kill & pcntl_wait
			throw new \RuntimeException('socket_create_pair failed.');
			return;
		}
		$processId = pcntl_fork();
		if ($processId < 0) {
			// cleanup using posix_kill & pcntl_wait
			throw new \RuntimeException('pcntl_fork failed.');
			return;
		}
		elseif ($processId === 0) {
			// WE ARE IN THE CHILD
			$this->workerProcesses = new ProcessDetailsCollection(); // we do not have any children
			$this->workerPoolSize = 0; // we do not have any children
			socket_close($sockets[1]); // close the parent socket
			$this->runWorkerProcess($this->worker, new SimpleSocket($sockets[0]), $i);
		}
		else {
			// WE ARE IN THE PARENT
			socket_close($sockets[0]); // close child socket
			// create the child
			$this->workerProcesses->addFree(new ProcessDetails($processId, new SimpleSocket($sockets[1])));
		}
	}

	/**
	 * Run the worker process
	 * @param \QXS\WorkerPool\WorkerInterface $worker the worker, that runs the tasks
	 * @param \QXS\WorkerPool\SimpleSocket $simpleSocket the simpleSocket, that is used for the communication
	 * @param int $i the number of the child
	 */
	protected function runWorkerProcess(WorkerInterface $worker, SimpleSocket $simpleSocket, $i) {
		$replacements = array(
			'basename' => basename($_SERVER['PHP_SELF']),
			'fullname' => $_SERVER['PHP_SELF'],
			'class' => get_class($worker),
			'i' => $i,
			'state' => 'free'
		);
		ProcessDetails::setProcessTitle($this->childProcessTitleFormat, $replacements);
		$this->worker->onProcessCreate($this->semaphore);
		while (TRUE) {
			$output = array('pid' => getmypid());
			try {
				$replacements['state'] = 'free';
				ProcessDetails::setProcessTitle($this->childProcessTitleFormat, $replacements);
				$cmd = $simpleSocket->receive();
				// invalid response from parent?
				if (!isset($cmd['cmd'])) {
					break;
				}
				$replacements['state'] = 'busy';
				ProcessDetails::setProcessTitle($this->childProcessTitleFormat, $replacements);
				if ($cmd['cmd'] == 'run') {
					try {
						$output['data'] = $this->worker->run($cmd['data']);
					} catch (\Exception $e) {
						$output['workerException'] = array(
							'class' => get_class($e),
							'message' => $e->getMessage(),
							'trace' => $e->getTraceAsString()
						);
					}
					// send back the output
					$simpleSocket->send($output);
				} elseif ($cmd['cmd'] == 'exit') {
					break;
				}
			} catch (SimpleSocketException $e) {
				break;
			} catch (\Exception $e) {
				// send Back the exception
				$output['poolException'] = array(
					'class' => get_class($e),
					'message' => $e->getMessage(),
					'trace' => $e->getTraceAsString()
				);
				$simpleSocket->send($output);
			}
		}
		$this->worker->onProcessDestroy();
		$this->exitPhp(0);
	}

	/**
	 * This runs on shutdown to prevent the system from semaphore leaks
	 */
	public function onShutDown() {
		// are we in the parent?
		if ($this->parentPid === getmypid()) {
			if($this->created) {
				$this->destroy();
			}
		}
	}

	/**
	 * Destroy the WorkerPool with all its children
	 * @param int $maxWaitSecs a timeout to wait for the children, before killing them
	 * @throws WorkerPoolException
	 * @return WorkerPool
	 */
	public function destroy($maxWaitSecs = self::CHILD_TIMEOUT_SEC) {
		if (!$this->created) {
			throw new WorkerPoolException('The pool hasn\'t yet been created.');
		}
		$this->created = FALSE;

		if ($this->parentPid === getmypid()) {
			$maxWaitSecs = ((int)$maxWaitSecs) * 2;
			if ($maxWaitSecs <= 1) {
				$maxWaitSecs = 2;
			}
			// send the exit instruction
			foreach ($this->workerProcesses as $processDetails) {
				try {
					$processDetails->getSocket()->send(array('cmd' => 'exit'));
				} catch (\Exception $e) {
				}
			}
			// wait up to 10 seconds
			for ($i = 0; $i < $maxWaitSecs; $i++) {
				usleep(500000); // 0.5 seconds
				pcntl_signal_dispatch();
				if ($this->workerPoolSize == 0) {
					break;
				}
			}

			// reset signals
			foreach ($this->signals as $signo) {
				pcntl_signal($signo, SIG_DFL);
			}

			// kill all remaining processes
			$this->workerProcesses->killAllProcesses();

			usleep(500000); // 0.5 seconds
			// reap the remaining signals
			$this->reaper();
			// destroy the semaphore
			$this->semaphore->destroy();

			unset($this->workerProcesses);
		}

		return $this;
	}

	/**
	 * Receives signals
	 *
	 * DO NOT MANUALLY CALL THIS METHOD!
	 * pcntl_signal_dispatch() will be calling this method.
	 * @param int $signo the signal number
	 * @see pcntl_signal_dispatch
	 * @see pcntl_signal
	 */
	public function signalHandler($signo) {
		switch ($signo) {
			case SIGCHLD:
				$this->reaper();
				break;
			case SIGTERM:
				// handle shutdown tasks
				$this->exitPhp(0);
				break;
			case SIGHUP:
				// handle restart tasks
				break;
			case SIGUSR1:
				// handle sigusr
				break;
			case SIGALRM:
				$this->respawnIfRequired();
				break;
			default: // handle all other signals
		}
		// more signals to dispatch?
		pcntl_signal_dispatch();
	}

	/**
	* Respawn workers automatically if they died
	*
	* @param boolean $respawn
	*/
	public function respawnAutomatically($respawn = true) {
		if ($this->respawnAutomatically = $respawn) {
			pcntl_signal(SIGALRM, array($this, 'signalHandler'));
			pcntl_alarm(1);
		}
	}

	private function respawnIfRequired() {
		if (!$this->respawnAutomatically) {
			return;
		}
		while ($this->workerPoolSize < $this->initialPoolSize) {
			$this->createWorker(++$this->currentWorkerIndex);
			$this->workerPoolSize++;
		}
		pcntl_alarm(1);
	}

	/**
	 * Child process reaper
	 * @param int $pid the process id
	 * @see pcntl_waitpid
	 */
	protected function reaper($pid = -1) {
		if (!is_int($pid)) {
			$pid = -1;
		}
		$childpid = pcntl_waitpid($pid, $status, WNOHANG);
		while ($childpid > 0) {
			$stopSignal = pcntl_wstopsig($status);
			if (pcntl_wifexited($stopSignal) === FALSE) {
				array_push($this->results, array(
					'pid' => $childpid,
					'abnormalChildReturnCode' => $stopSignal
				));
			}

			$processDetails = $this->workerProcesses->getProcessDetails($childpid);
			if ($processDetails !== NULL) {
				$this->workerPoolSize--;
				$this->workerProcesses->remove($processDetails);
				unset($processDetails);
			}
			$childpid = pcntl_waitpid($pid, $status, WNOHANG);
		}
	}

	/**
	 * Waits for one free worker
	 *
	 * This function blocks until a worker has finished its work.
	 * You can kill hanging child processes, so that the parent will be unblocked.
	 * Note: the run method already blocks until a free worker is available.
	 */
	public function waitForOneFreeWorker() {
		while ($this->getFreeWorkers() == 0) {
			$this->collectWorkerResults(self::CHILD_TIMEOUT_SEC);
		}
	}
	/**
	 * Waits for all children to finish their worker
	 *
	 * This function blocks until every worker has finished its work.
	 * You can kill hanging child processes, so that the parent will be unblocked.
	 */
	public function waitForAllWorkers() {
		while ($this->getBusyWorkers() > 0) {
			$this->collectWorkerResults(self::CHILD_TIMEOUT_SEC);
		}
	}

	/**
	 * Returns the number of busy and free workers
	 *
	 * This function collects all the information at once.
	 * @return array with the keys 'free', 'busy', 'total'
	 */
	public function getFreeAndBusyWorkers() {
		$free = $this->getFreeWorkers();
		return array(
			'free' => $free,
			'busy' => $this->workerPoolSize - $free,
			'total' => $this->workerPoolSize
		);
	}

	/**
	 * Returns the number of free workers
	 *
	 * PAY ATTENTION WHEN USING THIS FUNCTION WITH A SUBSEQUENT CALL OF getBusyWorkers().
	 * IN THIS CASE THE SUM MIGHT NOT EQUAL TO THE CURRENT POOL SIZE.
	 * USE getFreeAndBusyWorkers() TO GET CONSISTENT RESULTS.
	 * @return int number of free workers
	 */
	public function getFreeWorkers() {
		$this->collectWorkerResults();
		return $this->workerProcesses->getFreeProcessesCount();
	}

	/**
	 * Returns the number of busy workers
	 *
	 * PAY ATTENTION WHEN USING THIS FUNCTION WITH A SUBSEQUENT CALL OF getFreeWorkers().
	 * IN THIS CASE THE SUM MIGHT NOT EQUAL TO THE CURRENT POOL SIZE.
	 * USE getFreeAndBusyWorkers() TO GET CONSISTENT RESULTS.
	 * @return int number of free workers
	 */
	public function getBusyWorkers() {
		return $this->workerPoolSize - $this->getFreeWorkers();
	}

	/**
	 * Get the pid of the next free worker
	 *
	 * This function blocks until a worker has finished its work.
	 * You can kill all child processes, so that the parent will be unblocked.
	 * @throws WorkerPoolException
	 * @return ProcessDetails the pid of the next free child
	 */
	protected function getNextFreeWorker() {
		$sec = 0;
		while (TRUE) {
			$this->collectWorkerResults($sec);

			$freeProcess = $this->workerProcesses->takeFreeProcess();
			if ($freeProcess !== NULL) {
				return $freeProcess;
			}

			$sec = self::CHILD_TIMEOUT_SEC;
			if ($this->workerPoolSize <= 0) {
				throw new WorkerPoolException('All workers were gone.');
			}
		}

		return NULL;
	}

	/**
	 * Collects the results form the workers and processes any pending signals
	 * @param int $sec timeout to wait for new results from the workers
	 * @throws WorkerPoolException
	 */
	protected function collectWorkerResults($sec = 0) {
		// dispatch signals
		pcntl_signal_dispatch();

		if (isset($this->workerProcesses) === FALSE) {
			throw new WorkerPoolException('There is no list of worker processes. Maybe you destroyed the worker pool?', 1401179881);
		}
		$result = SimpleSocket::select($this->workerProcesses->getSockets(), array(), array(), $sec);
		foreach ($result['read'] as $socket) {
			/** @var $socket SimpleSocket */
			$processId = $socket->annotation['pid'];
			$result = $socket->receive();

			$possibleArrayKeys = array('data', 'poolException', 'workerException');
			if (is_array($result) && count(($resultTypes = array_intersect(array_keys($result), $possibleArrayKeys))) === 1) {
				// If the result has the expected format, free the worker and store the result.
				// Otherwise, the worker may be abnormally terminated (fatal error, exit(), ...) and will
				// fall in the reapers arms.
				$this->workerProcesses->registerFreeProcessId($processId);
				$result['pid'] = $processId;
				$resultType = reset($resultTypes);
				// Do not store NULL
				if ($resultType !== 'data' || $result['data'] !== NULL) {
					array_push($this->results, $result);
				}
			}
		}
		// dispatch signals
		pcntl_signal_dispatch();
	}

	/**
	 * Sends the input to the next free worker process
	 *
	 * This function blocks until a worker has finished its work.
	 * You can kill all child processes, so that the parent will be unblocked.
	 * @param mixed $input any serializable value
	 * @throws WorkerPoolException
	 * @return int The PID of the processing worker process
	 */
	public function run($input) {
		while ($this->workerPoolSize > 0) {
			try {
				$processDetailsOfFreeWorker = $this->getNextFreeWorker();
				$processDetailsOfFreeWorker->getSocket()->send(array('cmd' => 'run', 'data' => $input));
				return $processDetailsOfFreeWorker->getPid();
			} catch (\Exception $e) {
				pcntl_signal_dispatch();
			}
		}
		throw new WorkerPoolException('Unable to run the task.');
	}

	/**
	 * Clear all the results
	 */
	public function clearResults() {
		$this->collectWorkerResults();
		$this->results = array();
		return $this;
	}

	/**
	 * Is there any result available?
	 * @return bool true, in case we have received some results
	 */
	public function hasResults() {
		$this->collectWorkerResults();
		return !empty($this->results);
	}

	/**
	 * How many results did we receive?
	 * @return int the number of results
	 */
	public function countResults() {
		$this->collectWorkerResults();
		return $this->count();
	}

	/**
	 * Shifts the next result from the result queue
	 * @return array gets the next result
	 */
	public function getNextResult() {
		$this->collectWorkerResults();
		return array_shift($this->results);
	}

	/**
	 * Countable Method count
	 * @return int the number of results
	 * @see \QXS\WorkerPool\WorkerPool::countResults()
	 */
	public function count() {
		$this->collectWorkerResults();
		return count($this->results);
	}

	/**
	 * Iterator Method current
	 * @return array gets the current result
	 */
	public function current() {
		return reset($this->results);
	}

	/**
	 * Iterator Method key
	 * @return string returns the current key
	 */
	public function key() {
		return $this->resultPosition;
	}

	/**
	 * Iterator Method next()
	 */
	public function next() {
		$this->collectWorkerResults();
		if (!empty($this->results)) {
			$this->resultPosition++;
		}
		array_shift($this->results);
	}

	/**
	 * Iterator Method rewind()
	 */
	public function rewind() {
	}

	/**
	 * Iterator Method valid()
	 * @return bool true = there is a pending result
	 */
	public function valid() {
		return !empty($this->results);
	}
}
