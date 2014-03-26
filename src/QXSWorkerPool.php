<?php

// yum install php-process php-pcntl

namespace QXS\WorkerPool;

require_once(__DIR__.'/QXSSemaphore.php');
require_once(__DIR__.'/QXSSimpleSocket.php');

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
	private $created=false;
	private $workerPoolSize=2;
	protected $parentPid=0;
	protected $processes=array();
	protected $worker=null;
	protected $semaphore=null;
	protected $freeProcesses=array();
	protected $results=array();

	public function getWorkerPoolSize() { 
		return $this->workerPoolSize;
	}
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


	public function __construct() {
	}

	public function __destruct() {
		if($this->created) {
			$this->destroy();
		}
	}

	public function create(Worker $worker) {
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
				$this->processes=array();  // we do not have any children
				$this->workerPoolSize=0;   // we do not have any children
				socket_close($sockets[1]); // close the parent socket
				$this->worker->onProcessCreate($this->semaphore);
				$simpleSocket=new SimpleSocket($sockets[0]);
				while(true) {
					$output=array('pid' => getmypid());
					try {
						$cmd=$simpleSocket->receive();
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
				exit(0);
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

	public function signalHandler($signo) {
		switch ($signo) {
			case SIGCHLD:
				$this->reaper();
				break;
			case SIGTERM:
				// handle shutdown tasks
				exit;
				break;
			case SIGHUP:
				// handle restart tasks
				break;
			case SIGUSR1:
				//echo "Caught SIGUSR1...\n";
				break;
			default: // handle all other signals
		}
		// more signals to dispatch?
		pcntl_signal_dispatch();
	}

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

	public function waitForAllWorkers() {
		while($this->getBusyWorkers()>0) {
			$this->collectWorkerResults(90);
		}
	}
	public function getFreeAndBusyWorkers() {
		$this->collectWorkerResults();
		return array(
			'free' => count($this->freeProcesses),
			'busy' => $this->workerPoolSize - count($this->freeProcesses),
			'total' => $this->workerPoolSize
		);;
	}
	public function getFreeWorkers() {
		$this->collectWorkerResults();
		return count($this->freeProcesses);
	}
	public function getBusyWorkers() {
		$this->collectWorkerResults();
		return $this->workerPoolSize - count($this->freeProcesses);
	}

	protected function getNextFreeWorker() {
		$sec=0;
		while(true) {
			$this->collectWorkerResults($sec);
			// get a free child
			while(count($this->freeProcesses)>0) {
				$childpid=array_shift(array_keys($this->freeProcesses)); //array_shift  modifies the keys
				unset($this->freeProcesses[$childpid]);
				if(isset($this->processes[$childpid])) {
					return $childpid;
				}
			}
			$sec=90;
			if($this->workerPoolSize<=0) {
				throw new WorkerPoolException('All workers were gone.');
			}
		}
	}

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

	public function hasResults() {
		$this->collectWorkerResults();
		return !empty($this->results);
	}
	public function countResults() {
		$this->collectWorkerResults();
		return $this->count();
	}
	public function getNextResult() {
		$this->collectWorkerResults();
		return array_shift($this->results);
	}

	// Countable Methods
	public function count() {
		$this->collectWorkerResults();
		return count($this->results);
	}
	// Iterator Methods
	public function current() {
		return reset($this->results);
	}
	public function key() {
		return 1;
	}
	public function next() {
		$this->collectWorkerResults();
		array_shift($this->results);
	}
	public function rewind() {
	}
	public function valid() {
		return !empty($this->results);
	}
}


