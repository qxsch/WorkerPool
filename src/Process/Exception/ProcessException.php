<?php
namespace QXS\WorkerPool\Process\Exception;

class ProcessException extends SerializableException {

	/**
	 * @param \Exception $exception
	 *
	 * @return ProcessException
	 */
	public static function createFromException(\Exception $exception) {
		$result = new ProcessException($exception->getMessage(), intval($exception->getCode()), $exception);
		$result->traceAsString = $exception->getTraceAsString();
		$result->class = get_class($exception);
		return $result;
	}
}