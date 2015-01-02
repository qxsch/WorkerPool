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
	 * @param \Closure $run  the closure, that should be run
	 * @param mixed $input any data, that should be processed
	 */
	public function __construct(\Closure $run, $input = NULL) {
		$this->run = new SerializableClosure($run);
		$this->input = $input;
	}


	public function setInput($input) {
		$this->input = $input;
		return $this;
	}
	public function getInput() {
		return $this->input;
	}

	public function getSerializableClosure() {
		return $this->run;
	}
}

