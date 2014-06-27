<?php
/**
 * Closure Worker Class
 */

namespace QXS\WorkerPool\Worker;

use QXS\WorkerPool\Semaphore;

/**
 * The Closure Worker Class
 */
class ClosureWorker implements Worker {

	/** @var \Closure Closure that runs the task */
	protected $create;

	/** @var \Closure Closure that will be used when a worker has been forked */
	protected $run;

	/** @var \Closure Closure that will be used before a worker is getting destroyed */
	protected $destroy;

	/** @var \ArrayObject persistent storage container for the working process */
	protected $storage;

	/** @var Semaphore $semaphore the semaphore to run synchronized tasks */
	protected $semaphore;

	/**
	 * The constructor
	 * @param \Closure $run Closure that runs the task
	 * @param \Closure $create Closure that can be used when a worker has been forked
	 * @param \Closure $destroy Closure that can be used before a worker is getting destroyed
	 */
	public function __construct(\Closure $run, \Closure $create = NULL, \Closure $destroy = NULL) {
		$this->storage = new \ArrayObject();
		if (is_null($create)) {
			$create = function ($semaphore, $storage) {
			};
		}
		if (is_null($destroy)) {
			$destroy = function ($semaphore, $storage) {
			};
		}
		$this->create = $create;
		$this->run = $run;
		$this->destroy = $destroy;
	}

	/**
	 * @inheritdoc
	 */
	public function onProcessCreate(Semaphore $semaphore) {
		$this->semaphore = $semaphore;
		$this->create->__invoke($this->semaphore, $this->storage);
	}

	/**
	 * @inheritdoc
	 */
	public function onProcessDestroy() {
		$this->destroy->__invoke($this->semaphore, $this->storage);
	}

	/**
	 * @inheritdoc
	 */
	public function run($input) {
		return $this->run->__invoke($input, $this->semaphore, $this->storage);
	}
}

