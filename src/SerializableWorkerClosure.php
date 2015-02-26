<?php
/**
 * Super Closure Worker Class
 */

namespace QXS\WorkerPool;

use Jeremeamia\SuperClosure\SerializableClosure;

/**
 * The Serializeable Worker Closure
 */
class SerializableWorkerClosure {
	/** @var SerializableClosure the code that should be executed in the worker */
	protected $run;
	/** @var mixed the input data that should be passed to the worker */
	protected $input;

	/**
	 * The constructor
	 * @param \Closure $run  the closure, that the worker should run
	 * @param \Serializable $input the data, that the worker should process
	 */
	public function __construct(\Closure $run, $input = NULL) {
		$this->run = new SerializableClosure($run);
		$this->input = $input;
	}

	/**
	 * Sets the data, that should be processed
	 * @param \Serializable $input the data, that the worker should process
	 */
	public function setInput($input) {
		$this->input = $input;
		return $this;
	}

	/**
	 * Gets the data, that should be processed
	 * @return \Serializable the data, that the worker should process
	 */
	public function getInput() {
		return $this->input;
	}

	/**
	 * @return SerializableClosure the closure, that the worker should run
	 */
	public function getSerializableClosure() {
		return $this->run;
	}
}

