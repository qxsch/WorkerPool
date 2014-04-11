<?php

namespace QXS\Tests\WorkerPool;

require_once(__DIR__.'/require_once.php');


/**
 * @requires extension pcntl
 * @requires extension posix
 * @requires extension sysvsem
 * @requires extension sockets
 */
class ClosureWorkerTest extends \PHPUnit_Framework_TestCase {
	public function testClosureMethods() {
		$semaphore=new \QXS\WorkerPool\Semaphore();
		$that=$this;
		$createRun=false;
		$runRun=false;
		$destroyRun=false;
		$worker=new \QXS\WorkerPool\ClosureWorker(
			function($input, $semaphore, $storage) use ($that,  &$runRun) {
				$runRun=true;
				$that->assertInstanceOf('QXS\WorkerPool\Semaphore', $semaphore);
				$that->assertInstanceOf('ArrayObject', $storage);
				return $input;
			},
			function($semaphore, $storage) use ($that, &$createRun) {
				$createRun=true;
				$that->assertInstanceOf('QXS\WorkerPool\Semaphore', $semaphore);
				$that->assertInstanceOf('ArrayObject', $storage);
			},
			function($semaphore, $storage) use ($that, &$destroyRun) {
				$destroyRun=true;
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

