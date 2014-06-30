<?php
namespace QXS\WorkerPool\Worker;

use QXS\WorkerPool\Process\Exception\InvalidOperationException;
use QXS\WorkerPool\Process\Process;
use QXS\WorkerPool\Semaphore;

class WorkerProcess extends Process {

	/**
	 * @var \QXS\WorkerPool\Worker\Worker
	 */
	protected $worker;

	/**
	 * @var Semaphore
	 */
	protected $semaphore;

	/**
	 * @param \QXS\WorkerPool\Worker\Worker $worker
	 */
	public function setWorker(Worker $worker) {
		$this->worker = $worker;
	}

	/**
	 * @param \QXS\WorkerPool\Semaphore $semaphore
	 */
	public function setSemaphore(Semaphore $semaphore) {
		$this->semaphore = $semaphore;
	}

	protected function onStart() {
		if ($this->context === self::CONTEXT_CHILD) {
			if ($this->semaphore === NULL) {
				throw new InvalidOperationException('Semaphore is not set', 1403880699);
			}
			if ($this->worker === NULL) {
				throw new InvalidOperationException('Worker is not set', 1403880698);
			}
			$this->worker->onProcessCreate($this->semaphore);
		}
	}

	protected function onChildExit() {
		$this->worker->onProcessDestroy();
	}

	/**
	 * @param string $procedure
	 * @param mixed  $parameters
	 *
	 * @return mixed|void
	 */
	protected function doProcess($procedure, $parameters) {
		if ($procedure === 'run') {
			return $this->worker->run($parameters);
		}
		return NULL;
	}
}