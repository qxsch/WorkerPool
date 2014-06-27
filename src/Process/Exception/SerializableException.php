<?php
namespace QXS\WorkerPool\Process\Exception;

abstract class SerializableException extends \Exception implements \Serializable {

	/**
	 * @var string
	 */
	protected $class;

	/**
	 * @var string
	 */
	protected $traceAsString;

	/**
	 * @param string     $message
	 * @param int        $code
	 * @param \Exception $previous
	 */
	public function __construct($message = "", $code = 0, \Exception $previous = NULL) {
		\Exception::__construct($message, $code, $previous);
		$this->traceAsString = $this->getTraceAsString();
		$this->class = get_class($this);
	}

	/**
	 * @inheritdoc
	 */
	public function serialize() {
		return serialize($this->serializeHook());
	}

	protected function serializeHook() {
		return array(
			'code' => $this->getCode(),
			'class' => $this->class,
			'trace' => $this->traceAsString,
			'message' => $this->getMessage(),
			'file' => $this->getFile(),
			'line' => $this->getLine()
		);
	}

	/**
	 * @return string
	 */
	public function getExceptionClass() {
		return $this->class;
	}

	/**
	 * @return string
	 */
	public function getExceptionTrace() {
		return $this->traceAsString;
	}

	/**
	 * @inheritdoc
	 */
	public function unserialize($serialized) {
		$unserialized = unserialize($serialized);
		$this->code = $unserialized['code'];
		$this->class = $unserialized['class'];
		$this->traceAsString = $unserialized['trace'];
		$this->message = $unserialized['message'];
		$this->file = $unserialized['file'];
		$this->line = $unserialized['line'];
		$this->unserializeHook($unserialized);
	}

	/**
	 * @param array $unserialized
	 */
	protected function unserializeHook(array $unserialized) {
	}
}