<?php
/**
 * Worker Definition
 */

namespace QXS\WorkerPool;

/**
 * The Interface for worker processes
 */
interface WorkerInterface {

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
	 * @param \Serializable $input the data, that the worker should process
	 * @return \Serializable Returns the result
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function run($input);
}

