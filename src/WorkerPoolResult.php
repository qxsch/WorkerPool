<?php

namespace QXS\WorkerPool;

/**
 * The Worker Pool Result class represents a result of the workerpool
 */
class WorkerPoolResult implements \ArrayAccess {

	/** @var array the worker pool result */
	private $result = array();
	/** @var string the worker pool result type */
	private $resultType = 'data';

	/**
	 * The constructor
	 */
	public function __construct(array $result) {
		// create exception result objects and set result type
		foreach(array('workerException', 'poolException') as $k) {
			if(array_key_exists($k, $result) && is_array($result[$k])) {
				$result[$k] = new WorkerPoolExceptionResult($result[$k]);
				$this->resultType = $k;
			}
		}
		if(array_key_exists('abnormalChildReturnCode', $result)) {
			if(!array_key_exists('poolException', $result)) {
				$result['poolException'] = new WorkerPoolException(array(
					'class' => 'RuntimeException',
					'message' => 'The WorkerPool process reaper discovered an abnormal child return code: ' . $result['abnormalChildReturnCode'],
					'trace' => ''
				));
			}
		}
		$this->result = $result;
	}

	/**
	 * Does the offset exist?
	 * @param string $offset can be 'pid', 'data', 'poolException', 'workerException'
	 * @return bool true, if the offset estists
	 */
	public function offsetExists($offset): bool {
		$offset = (string)$offset;
		return array_key_exists($offset, $this->result);
	}
	/**
	 * Get the offset
	 * @param string $offset can be 'pid', 'data', 'poolException', 'workerException'
	 * @return mixed any value depending on the result and offset
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet($offset) {
		$offset = (string)$offset;
		if($this->offsetExists($offset)) {
			return $this->result[$offset];
		}
		return NULL;
	}
	/**
	 * Set the offset
	 * this will always throw an exception, because it is read only
	 * @param string $offset can be 'pid', 'data', 'poolException', 'workerException'
	 * @param mixed $value any value depending on the result and offset
	 * @throws \LogicException  always throws an exception becasue it is read only
	 */
	public function offsetSet($offset,$value): void {
		throw new \LogicException("Not allowed to add/modify keys");
	}
	/**
	 * Unset the offset
	 * this will always throw an exception, because it is read only
	 * @param string $offset can be 'pid', 'data', 'poolException', 'workerException'
	 * @throws \LogicException  always throws an exception becasue it is read only
	 */
	public function offsetUnset($offset): void {
		throw new \LogicException("Not allowed to add/modify keys");
	}

	/**
	 * Get the result type
	 * @return string one of the values 'pid', 'data', 'poolException', 'workerException'
	 */
	public function getPid() : int {
		return (int)$this->offsetGet('pid');
	}

	/**
	 * Get the result type
	 * @return string one of the values 'pid', 'data', 'poolException', 'workerException'
	 */
	public function getResultType() : bool {
		return $this->resultType;
	}
	
	/**
	 * Does the result have a workerpool exception?
	 * @return bool true, if the result has a workerpool exception
	 */
	public function hasPoolException() : bool {
		return $this->offsetExists('poolException');
	}

	/**
	 * Get the workerpool exception
	 * @return null|WorkerPoolExceptionResult  the exception returned from the workerpool
	 */
	public function getPoolException() : ?WorkerPoolExceptionResult {
		return $this->offsetGet('poolException');
	}

	/**
	 * Does the result have a worker exception?
	 * @return bool true, if the result has a worker exception
	 */
	public function hasWorkerException() : bool {
		return $this->offsetExists('workerException');
	}

	/**
	 * Get the worker exception
	 * @return null|WorkerPoolExceptionResult  the exception returned from the worker
	 */
	public function getWorkerException() : ?WorkerPoolExceptionResult {
		return $this->offsetGet('workerException');
	}

	/**
	 * Does the result have data?
	 * @return bool true, if the result has data
	 */
	public function hasData() : bool {
		return $this->offsetExists('data');
	}

	/**
	 * Get the data
	 * @return mixed  the data returned from the worker
	 */
	public function getData() {
		return $this->offsetGet('data');
	}

	/**
	 * Uses var_dump to dump data
	 * @return string the dumped output
	 */
	public function dump() : string {
		if($this->hasPoolException()) {
			// return pool exception
			return
				'PID: ' . $this->offsetGet('pid') . "\n" .
				'Pool Exception:' . "\n" . WorkerPoolResult::indentExceptionString($this->getPoolException()) . "\n"
			;
		}
		elseif($this->hasWorkerException()) {
			// return worker exception
			return
				'PID: ' . $this->offsetGet('pid') . "\n" .
				'Worker Exception:' . "\n" . WorkerPoolResult::indentExceptionString($this->getWorkerException()) . "\n"
			;
		}

		ob_start();
		var_dump($this->offsetGet('data'));
		$content = trim(ob_get_contents());
		ob_end_clean();
		
		// return data
		return
			'PID: ' . $this->offsetGet('pid') . "\n" .
			'Data:' . "\n$content\n"
		;
		
	}

	/**
	 * Indents the exception string
	 * @param string $string  the exception string
	 * @return string the indented exception string
	 */
	protected static function indentExceptionString(string $str) : string {
		$indent = "    ";
		$str =  $indent . str_replace("\n", "\n$indent", str_replace("\r", "", $str));
		if(substr($str, -strlen($indent)) == $indent) {
			$str = substr($str, 0, -strlen($indent));
		}
		return $str;
	}

	/**
	 * Get the object as a human readable string
	 * @return string the object as a human redable string
	 */
	public function __toString() : string {
		if($this->hasPoolException()) {
			// return pool exception
			return
				'PID: ' . $this->offsetGet('pid') . "\n" .
				'Pool Exception:' . "\n" . WorkerPoolResult::indentExceptionString($this->getPoolException()) . "\n"
			;
		}
		elseif($this->hasWorkerException()) {
			// return worker exception
			return
				'PID: ' . $this->offsetGet('pid') . "\n" .
				'Worker Exception:' . "\n" . WorkerPoolResult::indentExceptionString($this->getWorkerException()) . "\n"
			;
		}
		// return data
		return
			'PID: ' . $this->offsetGet('pid') . "\n" .
			'Data:' . "\n" . print_r($this->offsetGet('data'), TRUE) . "\n"
		;
	}
}

