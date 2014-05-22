<?php
namespace QXS\WorkerPool;

class ProcessDetailsCollection implements \IteratorAggregate {

	/**
	 * @var ProcessDetails[]
	 */
	public $processDetails = array();

	/**
	 * @var array
	 */
	protected $freeProcessIds = array();

	/**
	 * @var SimpleSocket[]
	 */
	protected $sockets = array();

	/**
	 * @param ProcessDetails $processDetails
	 * @return ProcessDetailsCollection
	 */
	public function addFree(ProcessDetails $processDetails) {
		$pid = $processDetails->getPid();
		$this->processDetails[$pid] = $processDetails;
		$this->sockets[$pid] = $processDetails->getSocket();
		$this->registerFreeProcess($processDetails);
		return $this;
	}

	/**
	 * @param ProcessDetails $processDetails
	 * @throws \InvalidArgumentException
	 * @return ProcessDetailsCollection
	 */
	public function remove(ProcessDetails $processDetails) {
		$pid = $processDetails->getPid();

		if ($this->hasProcess($pid) === FALSE) {
			throw new \InvalidArgumentException('Could not remove process. Process (%s) not in list.', 1400761297);
		}

		if (isset($this->freeProcessIds[$pid])) {
			unset($this->freeProcessIds[$pid]);
		}

		if (isset($this->sockets[$pid])) {
			unset($this->sockets[$pid]);
		}

		unset($this->processDetails[$pid]);

		return $this;
	}

	/**
	 * Kills all processes
	 */
	public function killAllProcesses() {
		foreach($this->processDetails as $pid => $processDetails){
			$this->remove($processDetails);
			posix_kill($pid, 9);
		}
	}

	/**
	 * @param ProcessDetails $processDetails
	 * @throws \InvalidArgumentException
	 * @return ProcessDetailsCollection
	 */
	public function registerFreeProcess(ProcessDetails $processDetails) {
		$pid = $processDetails->getPid();
		if ($this->hasProcess($pid) === FALSE) {
			throw new \InvalidArgumentException('Could not register free process. Process (%s) not in list.', 1400761296);
		}
		$this->freeProcessIds[$pid] = $pid;

		return $this;
	}

	/**
	 * @param int $pid
	 * @return ProcessDetailsCollection
	 */
	public function registerFreeProcessId($pid) {
		$processDetails = $this->getProcessDetails($pid);
		if ($processDetails !== NULL) {
			$this->registerFreeProcess($processDetails);
		}

		return $this;
	}

	/**
	 * @return ProcessDetails[]
	 */
	public function &getAllProcesssDetails() {
		return $this->processDetails;
	}

	/**
	 * @return ProcessDetails
	 */
	public function takeFreeProcess() {
		if ($this->getFreeProcessesCount() === 0) {
			return NULL;
		}
		$freePid = array_shift($this->freeProcessIds);
		if ($freePid === NULL) {
			return NULL;
		}
		return $this->getProcessDetails($freePid);
	}

	/**
	 * @param int $pid
	 * @return bool
	 */
	public function hasProcess($pid) {
		return isset($this->processDetails[$pid]);
	}

	public function getFreeProcessesCount() {
		return count($this->freeProcessIds);
	}

	/**
	 * @return \QXS\WorkerPool\SimpleSocket[]
	 */
	public function &getSockets() {
		return $this->sockets;
	}

	/**
	 * @param int $pid
	 * @return ProcessDetails
	 */
	public function getProcessDetails($pid) {
		if ($this->hasProcess($pid) === FALSE) {
			return NULL;
		}

		return $this->processDetails[$pid];
	}

	/**
	 * @inheritdoc
	 */
	public function getIterator() {
		return new \ArrayIterator($this->processDetails);
	}
}
