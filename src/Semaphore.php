<?php
/**
 * A simple Object wrapper arround the semaphore functions
 */

namespace QXS\WorkerPool;

/**
 * Semaphore Class
 *
 * <code>
 * $t=new Semaphore();
 * $t->create(Semaphore::SEM_FTOK_KEY);
 * // acquire &&  release
 * $t->acquire();
 * echo "We are in the sem\n";
 * $t->release();
 * // acquire && release (aliases)
 * $t->synchronizedBegin();
 * echo "We are in the sem\n";
 * $t->synchronizedEnd();
 *
 * $t->destroy();
 * </code>
 */
class Semaphore {

	/** generate a random key */
	const SEM_RAND_KEY = 'rand';

	/** generate a key based on ftok */
	const SEM_FTOK_KEY = 'ftok';

	/** minimum semaphore int */
	const SEM_MIN_INT=-2147483647;

	/** maximum semaphore int */
	const SEM_MAX_INT=2147483647;

	/** @var resource|\SysvSemaphore the semaphore resource */
	protected $semaphore = NULL;

	/** @var int the key that is used to access the semaphore */
	protected $semKey = NULL;

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
		if ($this->isCreated()) {
			throw new SemaphoreException('Semaphore has already been created.');
		}

		if (!is_int($maxAcquire)) {
			$maxAcquire = 1;
		}
		$perms=(int)$perms;
		if ($perms < 0 || $perms > 0777) {
			$perms = 0666;
		}

		// randomly generate semaphore, without collision
		if ($semKey == Semaphore::SEM_RAND_KEY) {
			$retries = 5;
			mt_srand((int)(microtime(true)*10000));
		} else {
			$retries = 1;
		}
		// try to generate a semaphore
		while (!$this->isCreated() && $retries > 0) {
			$retries--;
			// generate a semKey
			if (!is_int($semKey)) {
				if ($semKey == Semaphore::SEM_RAND_KEY) {
					$this->semKey = mt_rand(Semaphore::SEM_MIN_INT, Semaphore::SEM_MAX_INT);
				} else {
					$this->semKey = ftok(__FILE__, 's');
				}
			} else {
				$this->semKey = $semKey;
			}
			// check the range
			if($this->semKey < Semaphore::SEM_MIN_INT || $this->semKey > Semaphore::SEM_MAX_INT) {
				$this->semKey = ftok(__FILE__, 's');
			}
			$this->semaphore = sem_get($this->semKey, $maxAcquire, $perms, 0);
		}
		if (!$this->isCreated()) {
			$this->semaphore = NULL;
			$this->semKey = NULL;
			throw new SemaphoreException('Cannot create the semaphore.');
		}

		return $this;
	}

	/**
	 * Acquire the semaphore
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 */
	public function acquire() {
		if (!sem_acquire($this->semaphore)) {
			throw new SemaphoreException('Cannot acquire the semaphore.');
		}
		return $this;
	}

	/**
	 * Releases the semaphore
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 */
	public function release() {
		if (!sem_release($this->semaphore)) {
			throw new SemaphoreException('Cannot release the semaphore.');
		}
		return $this;
	}

	/**
	 * Acquire the semaphore
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 * @see \QXS\WorkerPool\Semaphore::acquire()
	 */
	public function synchronizedBegin() {
		return $this->acquire();
	}

	/**
	 * Releases the semaphore
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 * @see \QXS\WorkerPool\Semaphore::release()
	 */
	public function synchronizedEnd() {
		return $this->release();
	}

	/**
	 * Run something synchronized
	 * @param \Closure $closure the closure, that should be run synchronized
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 */
	public function synchronize(\Closure $closure) {
		$this->acquire();
		try {
			call_user_func($closure);
		}
		finally {
			$this->release();
		}
		return $this;
	}

	/**
	 * Has the semaphore been created?
	 * @return bool true in case the semaphore has been created
	 */
	public function isCreated() {
		return is_resource($this->semaphore) || $this->semaphore instanceof \SysvSemaphore;
	}

	/**
	 * Destroys the semaphore
	 * @throws SemaphoreException in case of an error
	 * @return \QXS\WorkerPool\Semaphore the current object
	 */
	public function destroy() {
		if (!$this->isCreated()) {
			throw new SemaphoreException('Semaphore hasn\'t yet been created.');
		}
		if (!sem_remove($this->semaphore)) {
			throw new SemaphoreException('Cannot remove the semaphore.');
		}

		$this->semaphore = NULL;
		$this->semKey = NULL;
		return $this;
	}
}

