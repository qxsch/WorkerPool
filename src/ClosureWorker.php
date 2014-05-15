<?php
/**
 * Closure Worker Class
 */

namespace QXS\WorkerPool;

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

	/** @var \QXS\WorkerPool\Semaphore $semaphore the semaphore to run synchronized tasks */
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
	 * After the worker has been forked into another process
	 *
	 * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to run synchronized tasks
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function onProcessCreate(Semaphore $semaphore) {
		$this->semaphore = $semaphore;
		$this->create->__invoke($this->semaphore, $this->storage);
	}

	/**
	 * Before the worker process is getting destroyed
	 *
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function onProcessDestroy() {
		$this->destroy->__invoke($this->semaphore, $this->storage);
	}

	/**
	 * run the work
	 *
	 * @param \Serializable $input the data, that the worker should process
	 * @return \Serializable Returns the result
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function run($input) {
		return $this->run->__invoke($input, $this->semaphore, $this->storage);
	}
}

