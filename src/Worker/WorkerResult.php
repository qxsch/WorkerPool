<?php
namespace QXS\WorkerPool\Worker;

class WorkerResult {

	/**
	 * @var mixed
	 */
	protected $data;

	/**
	 * @var int
	 */
	protected $workerPid;

	/**
	 * @param int $workerPid
	 * @param mixed $data
	 */
	public function __construct($workerPid, $data) {
		$this->data = $data;
		$this->workerPid = $workerPid;
	}

	/**
	 * @return int
	 */
	public function getWorkerPid() {
		return $this->workerPid;
	}

	/**
	 * @return mixed
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * @return bool
	 */
	public function hasError() {
		return $this->data instanceof \Exception;
	}
}