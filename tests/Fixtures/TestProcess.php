<?php
namespace QXS\Tests\WorkerPool\Fixtures;

use QXS\WorkerPool\Process\Process;

class TestProcess extends Process {

	/**
	 * @param string $procedure
	 * @param mixed  $parameters
	 *
	 * @throws \RuntimeException
	 * @return mixed|void
	 */
	protected function doProcess($procedure, $parameters) {
		switch ($procedure) {
			case 'fatal':
				whoops();
				break;
			case 'exception':
				throw new \RuntimeException('Test', 123);
			case 'null':
				return NULL;
			case 'exit':
				exit($parameters);
			case 'waitAndReturn':
				usleep(100000);
				return $parameters;
			case 'getPid':
				return getmypid();
			case 'noReturn':
				break;
			case 'return':
			default:
				return $parameters;
		}
	}
}