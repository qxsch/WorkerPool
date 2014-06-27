<?php
namespace QXS\WorkerPool\Process\Exception;

class ProcessTerminatedException extends SerializableException {

	/**
	 * @var int
	 */
	protected $exitStatus = 0;

	/**
	 * @var int
	 */
	protected $pid = 0;

	/**
	 * @param int $pid
	 * @param int $exitStatus
	 */
	public function __construct($pid, $exitStatus) {
		parent::__construct(sprintf('Process [%d] abnormally termintated.', $pid), 1403619692);
		$this->pid = $pid;
		$this->exitStatus = $exitStatus;
	}

	protected function serializeHook() {
		$result = parent::serializeHook();
		$result['pid'] = $this->pid;
		$result['exitStatus'] = $this->exitStatus;
		return $result;
	}

	protected function unserializeHook(array $unserialized) {
		parent::unserializeHook($unserialized);
		$this->pid = $unserialized['pid'];
		$this->exitStatus = $unserialized['exitStatus'];
	}

	/**
	 * @return int
	 */
	public function getPid() {
		return $this->pid;
	}

	/**
	 * @return int
	 */
	public function getExitStatus() {
		return $this->exitStatus;
	}
}