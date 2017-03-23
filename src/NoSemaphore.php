<?php
/**
 * A simple Object wrapper arround the semaphore functions
 */

namespace QXS\WorkerPool;

/**
 * No Semaphore Class
 *
 * Disables the semaphore! Just pass it to the Workerpool setSemaphore method before creating the pool.
 * Attention: You will lose the possibility to synchronize worker processes
 */
class NoSemaphore extends Semaphore {

	/**
	 * Returns the key, that can be used to access the semaphore
	 * @return int the key of the semaphore
	 */
	public function getSemaphoreKey() {
		return $this->semKey;
	}

	/**
	 * Create a semaphore
	 * @param string $semKey the key of the semaphore - use a specific number or Semaphore::SEM_RAND_KEY or Semaphore::SEM_FTOK_KEY
	 * @param int $maxAcquire the maximum number of processes, that can acquire the semaphore
	 * @param int $perms the unix permissions for (user,group,others) - valid range from 0 to 0777
	 * @throws SemaphoreException
	 * @return \QXS\WorkerPool\Semaphore the current object
	 */
	public function create($semKey = Semaphore::SEM_FTOK_KEY, $maxAcquire = 1, $perms=0666) {
		$this->semaphore = NULL;
		$this->semKey = 1;

		return $this;
	}

	/**
	 * Acquire the semaphore
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 */
	public function acquire() {
		return $this;
	}

	/**
	 * Releases the semaphore
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 */
	public function release() {
		return $this;
	}

	/**
	 * Has the semaphore been created?
	 * @return bool true in case the semaphore has been created
	 */
	public function isCreated() {
		return $this->semKey!==NULL;
	}

	/**
	 * Destroys the semaphore
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 */
	public function destroy() {
		$this->semaphore = NULL;
		$this->semKey = NULL;
		return $this;
	}
}

