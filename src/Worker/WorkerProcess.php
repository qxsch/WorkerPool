<?php
namespace QXS\WorkerPool\Worker;

use QXS\WorkerPool\Process\Process;

class WorkerProcess extends Process {

	/**
	 * @var \QXS\WorkerPool\Worker\Worker
	 */
	protected $worker;

	/**
	 * @param \QXS\WorkerPool\Worker\Worker $worker
	 */
	public function setWorker($worker) {
		$this->worker = $worker;
	}

	/**
	 * @return \QXS\WorkerPool\Worker\Worker
	 */
	public function getWorker() {
		return $this->worker;
	}

	protected function onDestroyed() {
		$this->worker->onProcessDestroy();
	}

	/**
	 * @param string $procedure
	 * @param mixed $parameters
	 * @return mixed|void
	 */
	protected function doProcess($procedure, $parameters) {
		if ($procedure === 'run') {
			return $this->worker->run($parameters);
		}
		return NULL;
	}
}