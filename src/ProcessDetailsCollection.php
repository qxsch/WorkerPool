<?php
/**
 * The Process Details Collection
 */

namespace QXS\WorkerPool;

/**
 * The Process Details Collection Class
 */
class ProcessDetailsCollection implements \IteratorAggregate {

	/** @var ProcessDetails[] the details */
	public $processDetails = array();

	/** @var array the free process ids */
	protected $freeProcessIds = array();

	/** @var SimpleSocket[] all sockets */
	protected $sockets = array();

	/**
	 * Adds the ProcessDetails to the list and registers it as a free one.
	 *
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
	 * Removes the ProcessDetails from the list.
	 *
	 * @param ProcessDetails $processDetails
	 * @throws \InvalidArgumentException
	 * @return ProcessDetailsCollection
	 */
	public function remove(ProcessDetails $processDetails) {
		$pid = $processDetails->getPid();

		if ($this->hasProcess($pid) === FALSE) {
			throw new \InvalidArgumentException(sprintf('Could not remove process. Process (%d) not in list.', $processDetails->getPid()), 1400761297);
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
	 * Sends the kill signal to all processes and removes them from the list.
	 *
	 * @return void
	 */
	public function killAllProcesses() {
		foreach ($this->processDetails as $pid => $processDetails) {
			$this->remove($processDetails);
			posix_kill($pid, 9);
		}
	}

	/**
	 * Register a ProcessDetails as free
	 *
	 * @param ProcessDetails $processDetails
	 * @throws \InvalidArgumentException
	 * @return ProcessDetailsCollection
	 */
	public function registerFreeProcess(ProcessDetails $processDetails) {
		$pid = $processDetails->getPid();
		if ($this->hasProcess($pid) === FALSE) {
			throw new \InvalidArgumentException(sprintf('Could not register free process. Process (%d) not in list.', $processDetails->getPid()), 1400761296);
		}
		$this->freeProcessIds[$pid] = $pid;

		return $this;
	}

	/**
	 * Register the ProcessDetails with the given PID as free.
	 *
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
	 * Get all ProcessDetails by reference
	 *
	 * @return ProcessDetails[]
	 */
	public function &getAllProcesssDetails() {
		return $this->processDetails;
	}

	/**
	 * Takes one ProcessDetails from the list of free ProcessDetails. Returns NULL if no free process is available.
	 *
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
	 * Checks if the ProcessDetails with given PID is in the list.
	 *
	 * @param int $pid
	 * @return bool
	 */
	public function hasProcess($pid) {
		return isset($this->processDetails[$pid]);
	}

	/**
	 * Get the count of free processes
	 *
	 * @return int
	 */
	public function getFreeProcessesCount() {
		return count($this->freeProcessIds);
	}

	/**
	 * Get the count of processes
	 *
	 * @return int
	 */
	public function getProcessesCount() {
		return count($this->processDetails);
	}

	/**
	 * Returns the associated sockets of all processes in the list.
	 *
	 * @return \QXS\WorkerPool\SimpleSocket[]
	 */
	public function &getSockets() {
		return $this->sockets;
	}

	/**
	 * Get a ProcessDetails of the given PID
	 *
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
