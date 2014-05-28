<?php

namespace QXS\Tests\WorkerPool;

use QXS\WorkerPool\ClosureWorker;
use QXS\WorkerPool\WorkerPool;

/**
 * @requires extension pcntl
 * @requires extension posix
 * @requires extension sysvsem
 * @requires extension sockets
 */
class WorkerPoolTest extends \PHPUnit_Framework_TestCase {

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
	}

	/**
	 * @test
	 */
	public function poolHasMinimumAmountOfWorkersAfterCreate() {
		$wp = $this->createDefaultPool();

		$this->assertEquals(1, $wp->getFreeWorkers());
		$this->assertEquals(0, $wp->getBusyWorkers());
		$wp->destroy(0);
	}

	/**
	 * @param int $min
	 * @param int $max
	 * @param bool $failing
	 * @return WorkerPool
	 */
	protected function createDefaultPool($min = 1, $max = 5, $failing = FALSE) {
		$wp = new WorkerPool();
		$wp
			->setMaximumRunningWorkers($max)
			->setMinimumRunningWorkers($min);
		if ($failing) {
			$wp->create(new Fixtures\FatalFailingWorker());
		} else {
			$wp->create(new ClosureWorker(function ($data) {
				usleep(500000);
				return TRUE;
			}));
		}

		return $wp;
	}

	/**
	 * @test
	 */
	public function poolHasNoWorkersAtAllIfMinimumIsSetToZero() {
		$wp = $this->createDefaultPool(0, 5);

		$this->assertEquals(0, $wp->getFreeWorkers());
		$this->assertEquals(0, $wp->getBusyWorkers());

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function poolCreatesWorkersOnDemand() {
		$wp = $this->createDefaultPool(0);

		$this->assertEquals(0, $wp->getFreeWorkers());
		$this->assertEquals(0, $wp->getBusyWorkers());

		for ($i = 0; $i < 5; $i++) {
			$this->assertEquals(0, $wp->getFreeWorkers());
			$this->assertEquals($i, $wp->getBusyWorkers());
			$wp->run($i);
			$this->assertEquals(0, $wp->getFreeWorkers());
			$this->assertEquals($i + 1, $wp->getBusyWorkers());
		}

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function runMethodReturnsARunningProcessId() {
		$wp = $this->createDefaultPool();

		for ($i = 0; $i < 5; $i++) {
			$pid = $wp->run($i);
			$this->assertTrue(is_int(posix_getpgid($pid)), 'Process ' . $pid . ' is not running');
		}

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function idleWorkerProcessesGetsTerminated() {
		$wp = $this->createDefaultPool(0, 5);
		$wp->setMaximumWorkersIdleTime(1);

		$pids = array();
		for ($i = 0; $i < 5; $i++) {
			$pids[] = $wp->run($i);
		}
		$wp->waitForAllWorkers();
		sleep(2);
		$wp->terminateIdleWorkers();

		$workersInfo = $wp->getFreeAndBusyWorkers();
		$this->assertEquals(0, $workersInfo['total']);
		foreach ($pids as $pid) {
			$this->assertFalse(posix_getpgid($pid), 'Process ' . $pid . ' is still running');
		}

		$wp->destroy(0);
	}

	/**
	 * Data provider for different worker and their pool results
	 *
	 * @return array
	 */
	public function workerResultsOfClosuresProvider() {
		return array(
			array(
				function ($data) {
					exit(42);
				},
				array('abnormalChildReturnCode' => 42)
			),
			array(
				function ($data) {
					iDoNotExist();
				},
				array('abnormalChildReturnCode' => 255)
			),
			array(
				function ($data) {
					// I don't return anything
				},
				array('data' => NULL)
			),
			array(
				function ($data) {
					throw new \Exception('Foo! Nooo!');
				},
				array('workerException' => array(
					'class' => 'Exception',
					'message' => 'Foo! Nooo!'
				))
			),
			array(
				function ($data) {
					return 42;
				},
				array('data' => 42)
			)
		);
	}

	/**
	 * @test
	 * @dataProvider workerResultsOfClosuresProvider
	 */
	public function resultsAreAsExpected($closure, array $expectedResult) {
		$wp = new WorkerPool();
		$wp
			->setMaximumRunningWorkers(5)
			->create(new ClosureWorker($closure));

		$wp->run('foo bar');
		$wp->waitForAllWorkers();

		$this->assertCount(1, $wp);
		$onlyResult = $wp->getNextResult();
		unset($onlyResult['pid']);
		unset($onlyResult['workerException']['trace']);
		$this->assertSame($expectedResult, $onlyResult);

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function failedWorkersAreRecreatedInOnDemandMode() {
		$wp = $this->createDefaultPool(2, 2, TRUE);

		for ($i = 0; $i < 5; $i++) {
			$wp->run('foo bar');
		};

		$wp->waitForAllWorkers();
		$this->assertEquals(5, count($wp));

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function resultCountShouldBeZeroAfterIteratingOverTheResults() {
		$wp = $this->createDefaultPool();
		for ($i = 0; $i < 5; $i++) {
			$wp->run('foo bar');
		};
		$wp->waitForAllWorkers();

		$this->assertEquals(5, count($wp));
		while (($result = $wp->getNextResult()) !== NULL) {
			// Foo
		}
		$this->assertEquals(0, count($wp));

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function thereShouldBeNoBusyWorkersAfterCallingWaitForAllWorkers() {
		$wp = $this->createDefaultPool();
		for ($i = 0; $i < 6; $i++) {
			$wp->run('foo bar');
		};
		$wp->waitForAllWorkers();

		$this->assertEquals(0, $wp->getBusyWorkers());

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function thereShouldBeNFreeWorkersAfterCallingWaitForAllWorkers() {
		$wp = $this->createDefaultPool(5);
		for ($i = 0; $i < 6; $i++) {
			$wp->run('foo bar');
		};
		$wp->waitForAllWorkers();

		$this->assertEquals(5, $wp->getFreeWorkers());

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function waitForAllWorkersShouldBeCallableMultipleTimes() {
		$wp = $this->createDefaultPool();
		for ($j = 1; $j <= 3; $j++) {
			for ($i = 0; $i < 6; $i++) {
				$wp->run($j);
			};
			$wp->waitForAllWorkers();
			$this->assertEquals(5, $wp->getFreeWorkers());
			$this->assertEquals(6 * $j, count($wp));
		}

		$wp->destroy(0);
	}

	/**
	 * @test
	 */
	public function getNextResultShouldReturnNResultsForNJobs() {
		$wp = $this->createDefaultPool();
		for ($i = 0; $i < 5; $i++) {
			$wp->run('foo bar');
		};
		$wp->waitForAllWorkers();

		$counter = 0;
		while (($result = $wp->getNextResult()) !== NULL) {
			$counter++;
		}
		$this->assertEquals(5, $counter);

		$wp->destroy(0);
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
		$wp->destroy(0);
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

		$wp->destroy(0);
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

