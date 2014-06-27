<?php
namespace QXS\Tests\WorkerPool;

use QXS\Tests\WorkerPool\Fixtures\TestProcess;
use QXS\WorkerPool\Process\Exception\ProcessTerminatedException;

class ProcessTest extends \PHPUnit_Framework_TestCase {

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

	public function testFatalFailingChildProcessMethodReturnsException() {
		$process = new TestProcess();
		$process->start();
		$result = $process->process('fatal');
		$this->assertInstanceOf('\QXS\WorkerPool\Process\Exception\ProcessTerminatedException', $result);

		if ($process->isRunning()) {
			$process->destroy(0);
		}
	}

	public function testExceptionalFailingChildProcessMethodReturnsException() {
		$process = new TestProcess();
		$process->start();
		$result = $process->process('exception');
		$this->assertInstanceOf('\QXS\WorkerPool\Process\Exception\ProcessException', $result);

		if ($process->isRunning()) {
			$process->destroy(0);
		}
	}

	public function testCorrectExitStatusCanBeFoundInTerminationException() {
		$process = new TestProcess();
		$process->start();
		$result = $process->process('exit', 42);
		$this->assertInstanceOf('\QXS\WorkerPool\Process\Exception\ProcessTerminatedException', $result);
		/** @var ProcessTerminatedException $result */
		$this->assertEquals(42, $result->getExitStatus());

		$process->destroy(0);
	}

	public function testProcessMethodReturnsNullEvenIfChildProcessDoesNotReturnAnything() {
		$process = new TestProcess();
		$process->start();
		$result = $process->process('noReturn');
		$this->assertEquals(NULL, $result);

		$process->destroy(0);
	}

	/**
	 * @return array
	 */
	public function processInputs() {
		$stdClass = new \stdClass();
		$stdClass->foo = 'bar';

		return array(
			array(1),
			array('foo'),
			array(array('foo' => 'bar')),
			array($stdClass)
		);
	}

	/**
	 * @dataProvider processInputs
	 */
	public function testProcessMethodReturnsTheSameValueIfChildProcessReturnsItsInput($input) {
		$process = new TestProcess();
		$process->start();
		$result = $process->process('return', $input);
		$this->assertEquals($input, $result);

		$process->destroy(0);
	}

	public function testProcessMethodReturnsImmediatelyIfWaitForResponseIsTurnedOff() {
		$process = new TestProcess();
		$process->start();
		$startTime = microtime();
		$process->process('waitAndReturn', 'foo', FALSE);
		$this->lessThan(100000, microtime() - $startTime);

		$process->destroy(0);
	}

	public function testGetNextResultWaitsAndReturnsExpectedValueIfWaitForResponseIsTurnedOff() {
		$process = new TestProcess();
		$process->start();
		$process->process('waitAndReturn', 'foo', FALSE);
		$this->assertEquals('foo', $process->getNextResult());

		$process->destroy(0);
	}

	public function testStartReturnsARunningPid() {
		$process = new TestProcess();
		$pid = $process->start();
		$this->assertTrue(is_int(posix_getpgid($pid)), 'Process ' . $pid . ' is not running');

		$process->destroy(0);
	}

	public function testProcessIsNotRunningAfterCallingDestroy() {
		$process = new TestProcess();
		$pid = $process->start();
		$process->destroy();
		$this->assertFalse(is_int(posix_getpgid($pid)), 'Process ' . $pid . ' is still running');
	}

	public function testProcessMethodIsRunningInSubProcess() {
		$process = new TestProcess();
		$pid = $process->start();
		$result = $process->process('getPid');
		$this->assertEquals($pid, $result);

		$process->destroy(0);
	}

	public function testSubProcessIsRunningWithAnotherPid() {
		$thisPid = getmypid();
		$process = new TestProcess();
		$pid = $process->start();
		$this->assertNotEquals($thisPid, $pid);

		$process->destroy(0);
	}

	public function testProcessIsBusyAfterCallingTheProcessMessageIfWaitForResponseIsTurnedOff() {
		$process = new TestProcess();
		$process->start();
		$process->process('waitAndReturn', NULL, FALSE);
		$this->assertTrue($process->isBusy());

		$process->destroy(0);
	}

	public function testProcessIsIdleAfterCallingTheProcessMessage() {
		$process = new TestProcess();
		$process->start();
		$process->process('waitAndReturn');
		$this->assertTrue($process->isIdle());

		$process->destroy(0);
	}

	/**
	 * @expectedException \QXS\WorkerPool\Process\Exception\SerializableException
	 */
	public function testCallingDestroyMethodThrowsExceptionIfProcessIsNotStarted() {
		$process = new TestProcess();
		$process->destroy();
	}

	/**
	 * @expectedException \QXS\WorkerPool\Process\Exception\SerializableException
	 */
	public function testProcessMethodThrowsExceptionIfProcessIsNotStarted() {
		$process = new TestProcess();
		$process->process('return');
	}

	/**
	 * @expectedException \QXS\WorkerPool\Process\Exception\SerializableException
	 */
	public function testStartMethodThrowsExceptionIfCalledTwice() {
		$process = new TestProcess();
		$exception = NULL;
		$process->start();
		$process->start();
	}

	/**
	 * @expectedException \QXS\WorkerPool\Process\Exception\SerializableException
	 */
	public function testCallingDestroyMethodThrowsExceptionIfCalledTwice() {
		$process = new TestProcess();
		$process->start();
		$process->destroy(0);
		$process->destroy(0);
	}
}