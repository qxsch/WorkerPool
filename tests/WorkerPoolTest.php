<?php

namespace QXS\Tests\WorkerPool;

require_once(__DIR__.'/require_once.php');



Class PingWorker implements \QXS\WorkerPool\Worker {
	public function onProcessCreate(\QXS\WorkerPool\Semaphore $semaphore) {

	}

	public function onProcessDestroy() {

	}

	public function run($input) {
		return $input;
	}
}

Class FatalFailingWorker implements \QXS\WorkerPool\Worker {
	public function onProcessCreate(\QXS\WorkerPool\Semaphore $semaphore) {

	}

	public function onProcessDestroy() {

	}

	public function run($input) {
		@$x->abc();	// fatal error
		return "Hi $input";
	}
}

/**
 * @requires extension pcntl
 * @requires extension posix
 * @requires extension sysvsem
 * @requires extension sockets
 */
class WorkerPoolTest extends \PHPUnit_Framework_TestCase {
	public function setUp() {
		$missingExtensions=array();
		foreach(array('sockets', 'posix', 'sysvsem', 'pcntl') as $extension) {
			if(!extension_loaded($extension)) {
				$missingExtensions[$extension]=$extension;
			}
		}
		if(!empty($missingExtensions)) {
			$this->markTestSkipped('The following extension are missing: '.implode(', ', $missingExtensions));
		}
	}

	public function testFatalFailingWorker() {
		$exceptionMsg=null;
		$exception=null;
		$wp=new \QXS\WorkerPool\WorkerPool();
		$wp->setWorkerPoolSize(10);
		$wp->create(new FatalFailingWorker());
		try {
			for($i=0; $i<20; $i++) {
				$wp->run($i);
			}
		}
		catch(\Exception $e) {
			$exceptionMsg=$e->getMessage();
			$exception=$e;
		}
		$this->assertInstanceOf('QXS\WorkerPool\WorkerPoolException', $exception);
		$this->assertEquals(
			'Unable to run the task.', 
			$exceptionMsg,
			'We have a wrong Exception Message.'
		);
		$wp->destroy();
	} 
 
	public function testGetters() {
		$wp=new \QXS\WorkerPool\WorkerPool();
		$wp->create(new PingWorker());
		$this->assertTrue(
			is_int($wp->getWorkerPoolSize()),
			'getWorkerPoolSize should return an int'
		);
		$this->assertTrue(
			is_string($wp->getChildProcessTitleFormat()),
			'getChildProcessTitleFormat should return a string'
		);
		$this->assertTrue(
			is_string($wp->getParentProcessTitleFormat()),
			'getParentProcessTitleFormat should return a string'
		);
		$wp->destroy();
	}

	public function testSetters() {
		$wp=new \QXS\WorkerPool\WorkerPool();
		try {
			$wp->setWorkerPoolSize(5);
		}
		catch(\Exception $e) {
			$this->assertTrue(
				false,
				'setWorkerPoolSize shouldn\'t throw an exception.'
			);
		}
		try {
			$wp->setChildProcessTitleFormat('X %basename% %class% Child %i% X');
		}
		catch(\Exception $e) {
			$this->assertTrue(
				false,
				'setChildProcessTitleFormat shouldn\'t throw an exception.'
			);
		}
		try {
			$wp->setParentProcessTitleFormat('X %basename% %class% Parent X');
		}
		catch(\Exception $e) {
			$this->assertTrue(
				false,
				'setParentProcessTitleFormat shouldn\'t throw an exception.'
			);
		}
                $this->assertEquals(
			5,
                        $wp->getWorkerPoolSize(),
                        'getWorkerPoolSize should return an int'
                );
                $this->assertEquals(
			'X %basename% %class% Child %i% X',
                        $wp->getChildProcessTitleFormat(),
                        'getChildProcessTitleFormat should return a string'
                );
                $this->assertEquals(
			'X %basename% %class% Parent X',
                        $wp->getParentProcessTitleFormat(),
                        'getParentProcessTitleFormat should return a string'
                );
	
		$wp->create(new PingWorker());

		try {
			$wp->setWorkerPoolSize(5);
			$this->assertTrue(
				false,
				'setWorkerPoolSize should throw an exception for a created pool.'
			);
		}
		catch(\Exception $e) {
		}
		try {
			$wp->setChildProcessTitleFormat('%basename% %class% Child %i%');
			$this->assertTrue(
				false,
				'setChildProcessTitleFormat should throw an exception for a created pool.'
			);
		}
		catch(\Exception $e) {
		}
		try {
			$wp->setParentProcessTitleFormat('%basename% %class% Parent');
			$this->assertTrue(
				false,
				'setParentProcessTitleFormat should throw an exception for a created pool.'
			);
		}
		catch(\Exception $e) {
		}

		$wp->destroy();

	}
 
	public function testDestroyException() {
		$wp=new \QXS\WorkerPool\WorkerPool();
		$wp->setWorkerPoolSize(50);
		$wp->create(new FatalFailingWorker());
		$failCount=0;
		try {
			for($i=0; $i<50; $i++) {
				$wp->run($i);
				$a=$wp->getFreeAndBusyWorkers();
			}
		}
		catch(\Exception $e) {
			$this->assertTrue(
				false, 
				'An unexpected exception was thrown.'
			);
		}
		$result=true;
		try {
			$wp->destroy();
		}
		catch(\Exception $e) {
			$result=false;
		}
		$this->assertTrue(
			$result, 
			'WorkerPool::Destroy shouldn\t throw an exception.'
		);

	} 
 
	public function testPingWorkers() {
		try {
			$wp=new \QXS\WorkerPool\WorkerPool();
			$wp->setWorkerPoolSize(50);
			$wp->create(new PingWorker());
			$failCount=0;
			for($i=0; $i<500; $i++) {
				$wp->run($i);
				$a=$wp->getFreeAndBusyWorkers();
				if($a['free']+$a['busy'] != $wp->getWorkerPoolSize()) {
					$failCount++;
				}
			}
			$wp->waitForAllWorkers();
			$this->assertLessThanOrEqual(
				0, 
				$failCount,
				'Sometimes the sum of free and busy workers does not equal to the pool size.'
			);
			$this->assertEquals(
				500, 
				count($wp),
				'The result count should be 500.'
			);
			$i=0;
			foreach($wp as $val) {
				$i++;
			}
			$this->assertEquals(
				500, 
				$i,
				'We should have 500 results in the pool.'
			);
			$this->assertEquals(
				0, 
				count($wp),
				'The result count should be 0 now.'
			);

		}
		catch(\Exception $e) {
			$this->assertTrue(
				false, 
				'An unexpected exception was thrown.'
			);
		}

		try {
			$wp->destroy();
		}
		catch(\Exception $e) {
			$this->assertTrue(
				false, 
				'WorkerPool::Destroy shouldn\t throw an exception.'
			);
		}
	} 
 
}

