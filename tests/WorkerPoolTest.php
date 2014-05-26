<?php

namespace QXS\Tests\WorkerPool;

use QXS\WorkerPool\WorkerPool;

/**
 * @requires extension pcntl
 * @requires extension posix
 * @requires extension sysvsem
 * @requires extension sockets
 */
class WorkerPoolTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @var WorkerPool
	 */
	protected $sut;

	public function setUp() {
		$missingExtensions = array();
		foreach (array('sockets', 'posix', 'sysvsem', 'pcntl') as $extension) {
			if (!extension_loaded($extension)) {
				$missingExtensions[$extension] = $extension;
			}
		}
		if (!empty($missingExtensions)) {
			$this->markTestSkipped('The following extension are missing: ' . implode(', ', $missingExtensions));
		}
		$this->sut = new WorkerPool();
	}

	public function testFatalFailingWorker() {
		$this->markTestSkipped('Failing workers get respawned now.');
		$exceptionMsg = NULL;
		$exception = NULL;
		$wp = new WorkerPool();
		$wp->setWorkerPoolSize(10);
		$wp->create(new Fixtures\FatalFailingWorker());
		try {
			for ($i = 0; $i < 20; $i++) {
				$wp->run($i);
			}
		} catch (\Exception $e) {
			$exceptionMsg = $e->getMessage();
			$exception = $e;
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
		$wp = new WorkerPool();
		$wp->create(new Fixtures\PingWorker());
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
		$wp = new WorkerPool();
		try {
			$wp->setWorkerPoolSize(5);
		} catch (\Exception $e) {
			$this->assertTrue(
				FALSE,
				'setWorkerPoolSize shouldn\'t throw an exception.'
			);
		}
		try {
			$wp->setChildProcessTitleFormat('X %basename% %class% Child %i% X');
		} catch (\Exception $e) {
			$this->assertTrue(
				FALSE,
				'setChildProcessTitleFormat shouldn\'t throw an exception.'
			);
		}
		try {
			$wp->setParentProcessTitleFormat('X %basename% %class% Parent X');
		} catch (\Exception $e) {
			$this->assertTrue(
				FALSE,
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

		$wp->create(new Fixtures\PingWorker());

		try {
			$wp->setWorkerPoolSize(5);
			$this->assertTrue(
				FALSE,
				'setWorkerPoolSize should throw an exception for a created pool.'
			);
		} catch (\Exception $e) {
		}
		try {
			$wp->setChildProcessTitleFormat('%basename% %class% Child %i%');
			$this->assertTrue(
				FALSE,
				'setChildProcessTitleFormat should throw an exception for a created pool.'
			);
		} catch (\Exception $e) {
		}
		try {
			$wp->setParentProcessTitleFormat('%basename% %class% Parent');
			$this->assertTrue(
				FALSE,
				'setParentProcessTitleFormat should throw an exception for a created pool.'
			);
		} catch (\Exception $e) {
		}

		$wp->destroy();
	}

	public function testDestroyException() {
		$wp = new WorkerPool();
		$wp->setWorkerPoolSize(50);
		$wp->create(new Fixtures\FatalFailingWorker());
		$failCount = 0;
		try {
			for ($i = 0; $i < 50; $i++) {
				$wp->run($i);
				$a = $wp->getFreeAndBusyWorkers();
			}
		} catch (\Exception $e) {
			$this->assertTrue(
				FALSE,
				'An unexpected exception was thrown.'
			);
		}
		$result = TRUE;
		try {
			$wp->destroy();
		} catch (\Exception $e) {
			$result = FALSE;
		}
		$this->assertTrue(
			$result,
			'WorkerPool::Destroy shouldn\t throw an exception.'
		);
	}

	public function testPingWorkers() {
		try {
			$wp = new WorkerPool();
			$wp->setWorkerPoolSize(50);
			$wp->create(new Fixtures\PingWorker());
			$failCount = 0;
			for ($i = 0; $i < 500; $i++) {
				$wp->run($i);
				$a = $wp->getFreeAndBusyWorkers();
				if ($a['free'] + $a['busy'] != $a['total']) {
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
			$i = 0;
			foreach ($wp as $val) {
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
		} catch (\Exception $e) {
			$this->assertTrue(
				FALSE,
				'An unexpected exception was thrown.'
			);
		}

		try {
			$wp->destroy();
		} catch (\Exception $e) {
			$this->assertTrue(
				FALSE,
				'WorkerPool::Destroy shouldn\t throw an exception of type ' . get_class($e) . ' with message:' . $e->getMessage() . "\n" . $e->getTraceAsString()
			);
		}
	}
}

