<?php
/**
 * The WorkerPool Requires the following PHP extensions
 *	* pcntl
 *	* posix
 *	* sysvsem
 *	* sockets
 *	* proctitle (optional)
 * 
 * Use the following commands to install them on RHEL:
 * 	yum install php-process php-pcntl
 * 	yum install php-pear php-devel ; pecl install proctitle
 * 	echo 'extension=proctitle.so' > /etc/php.d/proctitle.ini
 */


namespace QXS\WorkerPool;

require_once(__DIR__.'/QXSSemaphore.php');
require_once(__DIR__.'/QXSSimpleSocket.php');
/**
 * Exception for the WorkerPool Class
 */
class WorkerPoolException extends \Exception { }

/**
 * The Interface for worker processes
 */
interface Worker {
	/**
	 * After the worker has been forked into another process
	 *
	 * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to run synchronized tasks
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function onProcessCreate(Semaphore $semaphore);
	/**
	 * Before the worker process is getting destroyed
	 *
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function onProcessDestroy();
	/**
	 * run the work
	 *
	 * @param Serializeable $input the data, that the worker should process
	 * @return Serializeable Returns the result
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function run($input);
}


/**
 * The Worker Pool class runs worker processes in parallel
 * 
 */
class WorkerPool implements \Iterator, \Countable {
	/** @var bool has the pool already been created? */
	private $created=false;
	/** @var int the current worker pool size */
	private $workerPoolSize=2;
	/** @var int the id of the parent */
	protected $parentPid=0;
	/** @var array forked processes with their pids and sockets */
	protected $processes=array();
	/** @var \QXS\WorkerPool\Worker the worker class, that is used to run the tasks */
	protected $worker=null;
	/** @var \QXS\WorkerPool\Semaphore the semaphore, that is used to synchronizd tasks across all processes */
	protected $semaphore=null;
	/** @var array queue of free process pids */
	protected $freeProcesses=array();
	/** @var array received results from the workers */
	protected $results=array();
	/** @var int number of received results */
	protected $resultPosition=0;


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
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \DomainException in case the $size value is not within the permitted range
	 * @return int the number of processes
	 */
	public function setWorkerPoolSize($size) {
		if($this->created) {
			throw new WorkerPoolException('Cannot set the Worker Pool Size for a created pool.');
		}
		$size=(int)$size;
		if($size<=0) {
			throw new \DomainException('"'.$size.'" is not an integer greater than 0.');
		}
		$this->workerPoolSize=$size; 
		return $this; 
	}


	/**
	 * The constructor
	 */
	public function __construct() {
	}

	/**
	 * The destructor
	 */
	public function __destruct() {
		if($this->created) {
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
	 * This function call requires the proctitle extension!
	 * @param string $title the new process title
	 */
	protected function setProcessTitle($title) {
		if(function_exists('setproctitle')) {
			setproctitle(preg_replace(
				// allowed characters
				'/[^a-z0-9-_.: \\\\\\]\\[]/i', 
				'',
				// commandline
				basename($_SERVER['PHP_SELF']).': '.$title
			));
		}
	}

	/**
	 * Creates the worker pool (forks the children)
	 *
	 * Please close all open resources before running this function.
	 * Child processes are going to close all open resources uppon exit,
	 * leaving the parent process behind with invalid resource handles.
	 * @param \QXS\WorkerPool\Worker $worker the worker, that runs future tasks
	 */
	public function create(Worker $worker) {
		if($this->workerPoolSize<=1) {
			$this->workerPoolSize=2;
		}
		$this->parentPid=getmypid();
		$this->worker=$worker;
		if($this->created) {
			throw new WorkerPoolException('The pool has already been created.');
		}
		$this->created=true;
		// when adding signals use pcntl_signal_dispatch(); or declare ticks 
		pcntl_signal(SIGCHLD, array($this, 'signalHandler'));
		pcntl_signal(SIGTERM, array($this, 'signalHandler'));
		pcntl_signal(SIGHUP, array($this, 'signalHandler'));
		pcntl_signal(SIGUSR1, array($this, 'signalHandler'));

		$this->semaphore=new Semaphore();
		$this->semaphore->create(Semaphore::SEM_RAND_KEY);

		$this->setProcessTitle('Parent');

		for($i=1; $i<=$this->workerPoolSize; $i++) {
			$sockets=array();
			if(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets) === false) {
				// clean_up using posix_kill & pcntl_wait
				throw new \RuntimeException('socket_create_pair failed.');
				break;
			}
			$processId=pcntl_fork();  
			if($processId < 0){
				// cleanup using posix_kill & pcntl_wait
				throw new \RuntimeException('pcntl_fork failed.');
				break;
			} 
			elseif($processId == 0) {
				// WE ARE IN THE CHILD
				$this->setProcessTitle('Worker '.$i.' of '.get_class($worker).' [free]');
				$this->processes=array();  // we do not have any children
				$this->workerPoolSize=0;   // we do not have any children
				socket_close($sockets[1]); // close the parent socket
				$this->worker->onProcessCreate($this->semaphore);
				$simpleSocket=new SimpleSocket($sockets[0]);
				while(true) {
					$output=array('pid' => getmypid());
					try {
						$this->setProcessTitle('Worker '.$i.' of '.get_class($worker).' [free]');
						$cmd=$simpleSocket->receive();
						$this->setProcessTitle('Worker '.$i.' of '.get_class($worker).' [busy]');
						if($cmd['cmd']=='run') {
							try {
								$output['data']=$this->worker->run($cmd['data']);
							}
							catch(\Exception $e) {
								$output['workerException']=array(
									'class' => get_class($e),
									'message' => $e->getMessage(),
									'trace' => $e->getTraceAsString()
								);
							}
							// send back the output
							$simpleSocket->send($output);
						}
						elseif($cmd['cmd']=='exit') {
							break;
						}
					}
					catch(\Exception $e) {
						// send Back the exception
						$output['poolException']=array(
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
			else {
				// WE ARE IN THE PARENT
				socket_close($sockets[0]); // close child socket
				// create the child
				$this->processes[$processId]=array(
					'pid' => $processId,
					'socket' => new SimpleSocket($sockets[1])
				);
				$this->processes[$processId]['socket']->annotation['pid']=$processId;
				// mark it as a free child
				$this->freeProcesses[$processId]=$processId;
			}
		}


		return $this;
	}

	/**
	 * Destroy the WorkerPool with all its children
	 * @param int $maxWaitSecs a timeout to wait for the children, before killing them
	 */
	public function destroy($maxWaitSecs=10) {
		if(!$this->created) {
			throw new WorkerPoolException('The pool hasn\'t yet been created.');
		}
		$this->created=false;

		if($this->parentPid==getmypid()) {
			$maxWaitSecs=((int)$maxWaitSecs)*2;
			if($maxWaitSecs<=1) {
				$maxWaitSecs=2;
			}
			// send the exit instruction
			foreach($this->processes as $process) {
				try {
					$process['socket']->send(array('cmd' => 'exit'));
				}
				catch(\Exception $e) {
				}
			}
			// wait up to 10 seconds
			for($i=0; $i<$maxWaitSecs; $i++) {
				usleep(500000); // 0.5 seconds
				pcntl_signal_dispatch();
				if($this->workerPoolSize==0) {
					break;
				}
			}
			// reset the handlers
			pcntl_signal(SIGCHLD, SIG_DFL);
			pcntl_signal(SIGTERM, SIG_DFL);
			pcntl_signal(SIGHUP, SIG_DFL);
			pcntl_signal(SIGUSR1, SIG_DFL);
			// kill all remaining processes
			foreach($this->processes as $process) {
				@socket_close($process['socket']->getSocket());
				posix_kill($process['pid'], 9);
			}
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
	protected function reaper($pid=-1) {
		if (!is_int($pid)) {
			$pid=-1;
		}
		$childpid=pcntl_waitpid($pid, $status, WNOHANG);
		while($childpid>0) {
			if(isset($this->processes[$childpid])) {
				$this->workerPoolSize--;
				@socket_close($this->processes[$childpid]['socket']->getSocket());
				unset($this->processes[$childpid]);
				unset($this->freeProcesses[$childpid]);
			}
			$childpid=pcntl_waitpid($pid, $status, WNOHANG);
		}
		// remove freeProcesses
		foreach($this->freeProcesses as $key => $pid) {
			if(!isset($this->processes[$pid])) {
				unset($this->freeProcesses[$key]);
			}
		}
	}

	/**
	 * Waits for all children to finish their worka
	 *
	 * This function blocks until every worker has finished its work.
	 * You can kill hanging child processes, so that the parent will be unblocked.
	 */
	public function waitForAllWorkers() {
		while($this->getBusyWorkers()>0) {
			$this->collectWorkerResults(10);
		}
	}
	/**
	 * Returns the number of busy and free workers
	 *
	 * This function collects all the information at once.
	 * @param array with the keys 'free', 'busy', 'total'
	 */
	public function getFreeAndBusyWorkers() {
		$this->collectWorkerResults();
		return array(
			'free' => count($this->freeProcesses),
			'busy' => $this->workerPoolSize - count($this->freeProcesses),
			'total' => $this->workerPoolSize
		);;
	}
	/**
	 * Returns the number of free workers
	 *
	 * PAY ATTENTION WHEN USING THIS FUNCTION WITH A SUBSEQUENT CALL OF getBusyWorkers().
	 * IN THIS CASE THE SUM MIGHT NOT EQUAL TO THE CURRENT POOL SIZE.
	 * USE getFreeAndBusyWorkers() TO GET CONSISTENT RESULTS.
	 * @param int number of free workers
	 */
	public function getFreeWorkers() {
		$this->collectWorkerResults();
		return count($this->freeProcesses);
	}
	/**
	 * Returns the number of busy workers
	 *
	 * PAY ATTENTION WHEN USING THIS FUNCTION WITH A SUBSEQUENT CALL OF getFreeWorkers().
	 * IN THIS CASE THE SUM MIGHT NOT EQUAL TO THE CURRENT POOL SIZE.
	 * USE getFreeAndBusyWorkers() TO GET CONSISTENT RESULTS.
	 * @param int number of free workers
	 */
	public function getBusyWorkers() {
		$this->collectWorkerResults();
		return $this->workerPoolSize - count($this->freeProcesses);
	}

	/**
	 * Get the pid of the next free worker
	 *
	 * This function blocks until a worker has finished its work.
	 * You can kill all child processes, so that the parent will be unblocked.
	 * @return int the pid of the next free child
	 */
	protected function getNextFreeWorker() {
		$sec=0;
		while(true) {
			$this->collectWorkerResults($sec);
			// get a free child
			while(count($this->freeProcesses)>0) {
				$arr=array_keys($this->freeProcesses); // combining array_keys and array_shift returns an error: Strict standards: Only variables should be passed by reference
				$childpid=array_shift($arr); //array_shift  modifies the keys
				unset($this->freeProcesses[$childpid]);
				if(isset($this->processes[$childpid])) {
					return $childpid;
				}
			}
			$sec=10;
			if($this->workerPoolSize<=0) {
				throw new WorkerPoolException('All workers were gone.');
			}
		}
	}

	/**
	 * Collects the resluts form the workers and processes any pending signals
	 * @param int $sec timeout to wait for new results from the workers
	 */
	protected function collectWorkerResults($sec=0) {
		// dispatch signals
		pcntl_signal_dispatch();
		// let's collect the information
		$read=array();
		foreach($this->processes as $process) {
			$read[]=$process['socket'];
		}
		if(!empty($read)) {
			$result=SimpleSocket::select($read, array(), array(), $sec);
			foreach($result['read'] as $socket) {
				$processId=$socket->annotation['pid'];
				$this->freeProcesses[$processId]=$processId;
				$result=$socket->receive();
				$result['pid']=$processId;
				if(isset($result['data'])) {
					// null values won't be stored
					if(!is_null($result['data'])) {
						array_push($this->results, $result);
					}
				}
				elseif(isset($result['workerException']) || isset($result['poolException'])) {
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
	 * @param mixed $input any serializeable value
	 */
	public function run($input) {
		while($this->workerPoolSize>0) {
			try {
				$childpid=$this->getNextFreeWorker();
				$this->processes[$childpid]['socket']->send(array('cmd' => 'run', 'data' => $input));
				return $this;
			}
			catch(\Exception $e) {
				pcntl_signal_dispatch();
			}
		}
		throw new WorkerPoolException('Unable to run the task.');
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
		$this->resultPosition++;
		return $this->resultPosition;
	}
	/**
	 * Iterator Method next()
	 */
	public function next() {
		$this->collectWorkerResults();
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


