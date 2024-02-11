<?php

namespace QXS\WorkerPool;

/**
 * The Worker Pool Exception Result class represents an excpetion returned from a child process
 */
class WorkerPoolExceptionResult implements \ArrayAccess {

	/** @var array the worker pool exception result */
	private $result = array();

	/**
	 * The constructor
	 */
	public function __construct(array $result) {
		$this->result = $result;
	}

	/**
	 * Does the offset exist?
	 * @param string $offset can be 'class', 'message', 'trace'
	 * @return bool true, if the offset estists
	 */
	public function offsetExists($offset): bool {
		$offset = (string)$offset;
		return array_key_exists($offset, $this->result);
	}
	/**
	 * Get the offset
	 * @param string $offset can be 'class', 'message', 'trace'
	 * @return string the result
	 */
	public function offsetGet($offset) : string {
		$offset = (string)$offset;
		if($this->offsetExists($offset)) {
			return (string)$this->result[$offset];
		}
		return "";
	}
	/**
	 * Set the offset
	 * this will always throw an exception, because it is read only
	 * @param string $offset can be 'class', 'message', 'trace'
	 * @param string $value  the value 
	 * @throws \LogicException  always throws an exception becasue it is read only
	 */
	public function offsetSet($offset, $value): void {
		throw new \LogicException("Not allowed to add/modify keys");
	}
	/**
	 * Unset the offset
	 * this will always throw an exception, because it is read only
	 * @param string $offset can be 'class', 'message', 'trace'
	 * @throws \LogicException  always throws an exception becasue it is read only
	 */
	public function offsetUnset($offset): void {
		throw new \LogicException("Not allowed to add/modify keys");
	}

	
	/**
	 * Get the class name of the exception
	 * @return string  the class name of the exception
	 */
	public function getClass() : string {
		return (string)$this->offsetGet('class');
	}
	
	/**
	 * Get the message of the exception
	 * @return string  the messsage of the exception
	 */
	public function getMessage() : string {
		return (string)$this->offsetGet('message');
	}

	/**
	 * Get the stack trace of the exception
	 * @return string  the stack trace of the exception
	 */
	public function getTrace() : string {
		return (string)$this->offsetGet('trace');
	}


	/**
	 * Get the object as a human readable string
	 * @return string the object as a human redable string
	 */
	public function __toString() : string {
		return
			'Exception Class:   ' . $this->offsetGet('class') . "\n" .
			'Exception Message: ' . $this->offsetGet('message') . "\n" .
			'Exception Trace:' . "\n" . $this->offsetGet('trace')
		;
	}
}

