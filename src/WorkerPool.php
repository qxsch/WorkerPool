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

	/**
	 * @var array
	 */
	protected $signals = array(
		SIGCHLD, SIGTERM, SIGHUP, SIGUSR1
	);

	/**
	 * @var bool
	 */
	private $created = FALSE;

	/**
	 * @var int
	 */
	private $workerPoolSize = 2;

	/**
	 * @var int
	 */
	protected $parentPid = 0;

	/**
	 * @var \QXS\WorkerPool\Worker the worker class, that is used to run the tasks
	 */
	protected $worker = NULL;

	/**
	 * @var \QXS\WorkerPool\Semaphore the semaphore, that is used to synchronizd tasks across all processes
	 */
	protected $semaphore = NULL;

	/**
	 * @var ProcessDetailsCollection|ProcessDetails[]
	 */
	protected $processes;

	/**
	 * @var array received results from the workers
	 */
	protected $results = array();

	/**
	 * @var int number of received results
	 */
	protected $resultPosition = 0;

	/**
	 * @var string process title of the parent
	 */
	protected $parentProcessTitleFormat = '%basename%: Parent';

	/**
	 * @var string process title of the children
	 */
	protected $childProcessTitleFormat = '%basename%: Worker %i% of %class% [%state%]';


	/**
	 * Sanitizes the process title format string
	 * @param string $string the process title
	 * @return string the process sanitized title
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	public static function sanitizeProcessTitleFormat($string) {
		$string = preg_replace(
			'/[^a-z0-9-_.:% \\\\\\]\\[]/i',
			'',
			$string
		);
		$string = trim($string);
		return $string;
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
		$this->childProcessTitleFormat = self::sanitizeProcessTitleFormat($string);
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
		$this->parentProcessTitleFormat = self::sanitizeProcessTitleFormat($string);
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
	 * @throws \DomainException in case the $size value is not within the permitted range
	 */
	public function setWorkerPoolSize($size) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Worker Pool Size for a created pool.');
		}
		$size = (int)$size;
		if ($size <= 0) {
			throw new \DomainException('"' . $size . '" is not an integer greater than 0.');
		}
		$this->workerPoolSize = $size;
		return $this;
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
	 * Terminates the current process
	 * @param int $code the exit code
	 */
	public function exitPhp($code) {
		exit($code);
	}

	/**
	 * Sets the proccess title
	 *
	 * This function call requires php5.5+ or the proctitle extension!
	 * Empty title strings won't be set.
	 * @param string $title the new process title
	 * @param array $replacements an associative array of replacment values
	 * @return void
	 */
	protected function setProcessTitle($title, array $replacements = array()) {
		// skip empty title names
		if (trim($title) == '') {
			return;
		}
		// 1. replace the values
		$title = preg_replace_callback(
			'/\%([a-z0-9]+)\%/i',
			function ($match) use ($replacements) {
				if (isset($replacements[$match[1]])) {
					return $replacements[$match[1]];
				}
				return $match[0];
			},
			$title
		);
		// 2. remove forbidden chars
		$title = preg_replace(
			'/[^a-z0-9-_.: \\\\\\]\\[]/i',
			'',
			$title
		);
		// 3. set the title
		if (function_exists('cli_set_process_title')) {
			cli_set_process_title($title); // PHP 5.5+ has a builtin function
		} elseif (function_exists('setproctitle')) {
			setproctitle($title); // pecl proctitle extension
		}
	}

	/**
	 * Creates the worker pool (forks the children)
	 *
	 * Please close all open resources before running this function.
	 * Child processes are going to close all open resources uppon exit,
	 * leaving the parent process behind with invalid resource handles.
	 * @param \QXS\WorkerPool\Worker $worker the worker, that runs future tasks
	 * @throws \RuntimeException
	 * @throws WorkerPoolException
	 * @return WorkerPool
	 */
	public function create(Worker $worker) {
		if ($this->workerPoolSize <= 1) {
			$this->workerPoolSize = 2;
		}
		$this->parentPid = getmypid();
		$this->worker = $worker;
		if ($this->created) {
			throw new WorkerPoolException('The pool has already been created.');
		}
		$this->processes = new ProcessDetailsCollection();

		$this->created = TRUE;
		// when adding signals use pcntl_signal_dispatch(); or declare ticks
		foreach ($this->signals as $signo) {
			pcntl_signal($signo, array($this, 'signalHandler'));
		}

		$this->semaphore = new Semaphore();
		$this->semaphore->create(Semaphore::SEM_RAND_KEY);

		$this->setProcessTitle(
			$this->parentProcessTitleFormat,
			array(
				'basename' => basename($_SERVER['PHP_SELF']),
				'fullname' => $_SERVER['PHP_SELF'],
				'class' => get_class($this)
			)
		);

		for ($i = 1; $i <= $this->workerPoolSize; $i++) {
			$sockets = array();
			if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets) === FALSE) {
				// clean_up using posix_kill & pcntl_wait
				throw new \RuntimeException('socket_create_pair failed.');
				break;
			}
			$processId = pcntl_fork();
			if ($processId < 0) {
				// cleanup using posix_kill & pcntl_wait
				throw new \RuntimeException('pcntl_fork failed.');
				break;
			} elseif ($processId === 0) {
				// WE ARE IN THE CHILD
				$this->processes = new ProcessDetailsCollection(); // we do not have any children
				$this->workerPoolSize = 0; // we do not have any children
				socket_close($sockets[1]); // close the parent socket
				$this->runWorkerProcess($worker, new SimpleSocket($sockets[0]), $i);
			} else {
				// WE ARE IN THE PARENT
				socket_close($sockets[0]); // close child socket
				// create the child
				$this->processes->addFree(new ProcessDetails($processId, new SimpleSocket($sockets[1])));
			}
		}

		return $this;
	}

	/**
	 * Run the worker process
	 * @param \QXS\WorkerPool\Worker $worker the worker, that runs the tasks
	 * @param \QXS\WorkerPool\SimpleSocket $simpleSocket the simpleSocket, that is used for the communication
	 * @param int $i the number of the child
	 */
	protected function runWorkerProcess(Worker $worker, SimpleSocket $simpleSocket, $i) {
		$replacements = array(
			'basename' => basename($_SERVER['PHP_SELF']),
			'fullname' => $_SERVER['PHP_SELF'],
			'class' => get_class($worker),
			'i' => $i,
			'state' => 'free'
		);
		$this->setProcessTitle($this->childProcessTitleFormat, $replacements);
		$this->worker->onProcessCreate($this->semaphore);
		while (TRUE) {
			$output = array('pid' => getmypid());
			try {
				$replacements['state'] = 'free';
				$this->setProcessTitle($this->childProcessTitleFormat, $replacements);
				$cmd = $simpleSocket->receive();
				// invalid response from parent?
				if (!isset($cmd['cmd'])) {
					break;
				}
				$replacements['state'] = 'busy';
				$this->setProcessTitle($this->childProcessTitleFormat, $replacements);
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
			foreach ($this->processes as $processDetails) {
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
			$this->processes->killAllProcesses();

			unset($this->processes);

			usleep(500000); // 0.5 seconds
			// reap the remaining signals
			$this->reaper();
			// destroy the semaphore
			$this->semaphore->destroy();
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
			default: // handle all other signals
		}
		// more signals to dispatch?
		pcntl_signal_dispatch();
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
			$processDetails = $this->processes->getProcessDetails($childpid);
			if ($processDetails !== NULL) {
				$this->workerPoolSize--;
				$this->processes->remove($processDetails);
				unset($processDetails);
			}
			$childpid = pcntl_waitpid($pid, $status, WNOHANG);
		}
	}

	/**
	 * Waits for all children to finish their worka
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
		return $this->processes->getFreeProcessesCount();
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

			$freeProcess = $this->processes->takeFreeProcess();
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
	 */
	protected function collectWorkerResults($sec = 0) {
		// dispatch signals
		pcntl_signal_dispatch();

		$sockets =& $this->processes->getSockets();

		$result = SimpleSocket::select($this->processes->getSockets(), array(), array(), $sec);
		foreach ($result['read'] as $socket) {
			/** @var $socket SimpleSocket */
			$processId = $socket->annotation['pid'];
			$this->processes->registerFreeProcessId($processId);
			$result = $socket->receive();
			$result['pid'] = $processId;
			if (isset($result['data'])) {
				// null values won't be stored
				if (!is_null($result['data'])) {
					array_push($this->results, $result);
				}
			} elseif (isset($result['workerException']) || isset($result['poolException'])) {
				array_push($this->results, $result);
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
	 * @param mixed $input any serializeable value
	 * @throws WorkerPoolException
	 * @return WorkerPool
	 */
	public function run($input) {
		while ($this->workerPoolSize > 0) {
			try {
				$processDetailsOfFreeWorker = $this->getNextFreeWorker();
				$processDetailsOfFreeWorker->getSocket()->send(array('cmd' => 'run', 'data' => $input));
				return $this;
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
		if(!empty($this->results)) {
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


