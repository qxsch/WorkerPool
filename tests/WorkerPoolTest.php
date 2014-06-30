<?php

namespace QXS\Tests\WorkerPool;

use QXS\WorkerPool\Worker\ClosureWorker;
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
	 * @param int $min
	 * @param int $max
	 * @param bool $failing
	 * @return WorkerPool
	 */
	protected function createDefaultPool($min = 1, $max = 5, $failing = FALSE) {
		$workerPool = new WorkerPool();
		$workerPool
			->setMaximumWorkersIdleTime(1)
			->setMaximumRunningWorkers($max)
			->setMinimumRunningWorkers($min);
		if ($failing) {
			$workerPool->setWorker(new Fixtures\FatalFailingWorker());
		} else {
			$workerPool->setWorker(new ClosureWorker(function () {
				usleep(500000);
				return TRUE;
			}));
		}

		$workerPool->start();
		return $workerPool;
	}

	/**
	 * @test
	 */
	public function poolHasMinimumAmountOfWorkersAfterCreate() {
		$wp = $this->createDefaultPool();
		$this->assertEquals(0, $wp->getBusyWorkers());
		$this->assertEquals(1, $wp->getFreeWorkers());
		$wp->getBusyWorkers();

		$wp->destroy(0);
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

		for ($i = 0; $i < 5; $i++) {
			$this->assertEquals(0, $wp->getFreeWorkers(), 'Before run: Free workers do not match');
			$this->assertEquals($i, $wp->getBusyWorkers(), 'Before run: Busy workers do not match');
			$wp->run($i);
			$this->assertEquals(0, $wp->getFreeWorkers(), 'After run: Free workers do not match');
			$this->assertEquals($i + 1, $wp->getBusyWorkers(), 'After run: Busy workers do not match');
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

		$pids = array();
		for ($i = 0; $i < 5; $i++) {
			$pids[] = $wp->run($i);
		}
		$wp->waitForAllWorkers();
		sleep(1);
		$wp->terminateIdleWorkers();

		$workersInfo = $wp->getFreeAndBusyWorkers();
		$this->assertEquals(0, $workersInfo['total']);
		foreach ($pids as $pid) {
			$this->assertFalse(posix_getpgid($pid), 'Process ' . $pid . ' is still running');
		}

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
		$this->assertEquals(5, count($wp->getResults()));

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

		$this->assertEquals(5, count($wp->getResults()));
		while (($result = $wp->getNextResult()) !== NULL) {
			// Foo
		}
		$this->assertEquals(0, count($wp->getResults()));

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
			$this->assertEquals(6, count($wp->getResults()));
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

	/**
	 * @test
	 */
	public function getResultsShouldReturnNResultsForNJobs() {
		$wp = $this->createDefaultPool();
		for ($i = 0; $i < 5; $i++) {
			$wp->run('foo bar');
		};
		$wp->waitForAllWorkers();

		$this->assertEquals(5, count($wp->getResults()));

		$wp->destroy(0);
	}
}