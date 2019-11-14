<?php

namespace QXS\Tests\WorkerPool;

use QXS\WorkerPool\ClosureWorker;
use QXS\WorkerPool\Semaphore;

/**
 * @requires extension pcntl
 * @requires extension posix
 * @requires extension sysvsem
 * @requires extension sockets
 */
class ClosureWorkerTest extends \PHPUnit\Framework\TestCase {

	public function testClosureMethods() {
		$semaphore = new Semaphore();
		$that = $this;
		$createRun = FALSE;
		$runRun = FALSE;
		$destroyRun = FALSE;
		$worker = new ClosureWorker(
			function ($input, $semaphore, $storage) use ($that, &$runRun) {
				$runRun = TRUE;
				$that->assertInstanceOf('QXS\WorkerPool\Semaphore', $semaphore);
				$that->assertInstanceOf('ArrayObject', $storage);
				return $input;
			},
			function ($semaphore, $storage) use ($that, &$createRun) {
				$createRun = TRUE;
				$that->assertInstanceOf('QXS\WorkerPool\Semaphore', $semaphore);
				$that->assertInstanceOf('ArrayObject', $storage);
			},
			function ($semaphore, $storage) use ($that, &$destroyRun) {
				$destroyRun = TRUE;
				$that->assertInstanceOf('QXS\WorkerPool\Semaphore', $semaphore);
				$that->assertInstanceOf('ArrayObject', $storage);
			}
		);

		$worker->onProcessCreate($semaphore);
		$this->assertTrue(
			$createRun,
			'Worker::onProcessCreate should call the create Closure.'
		);
		$this->assertEquals(
			1,
			$worker->run(1),
			'Worker::run should return the same value.'
		);
		$this->assertTrue(
			$runRun,
			'Worker::run should call the run Closure.'
		);
		$worker->onProcessDestroy();
		$this->assertTrue(
			$destroyRun,
			'Worker::onProcessDestroy should call the destroy Closure.'
		);
	}
}

