<?php
namespace QXS\WorkerPool\Process;

class ProcessControl {

	/**
	 * @var Process[]
	 */
	protected $processes = array();

	/**
	 * @var array
	 */
	protected $onProcessReaped = array();

	/**
	 * @var array
	 */
	private $objectRegistry = array();

	/**
	 * @var ProcessControl
	 */
	private static $instance;

	/**
	 * @return ProcessControl
	 */
	public static function instance() {
		if (self::$instance === NULL) {
			self::$instance = new ProcessControl();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->registerObject($this);
		pcntl_signal(SIGCHLD, array($this, 'signalHandler'));
	}

	public function __destruct() {
		if ($this->isObjectRegistered($this)) {
			pcntl_signal(SIGCHLD, SIG_DFL);
		}
	}

	/**
	 * @internal
	 */
	public function signalHandler() {
		$this->reaper(FALSE);
	}

	/**
	 * @param Process $process
	 */
	public function addProcess(Process $process) {
		$this->processes[$process->getPid()] = $process;
	}

	/**
	 * @param bool $wait
	 */
	public function reaper($wait = FALSE) {
		if ($wait) {
			self::sleepAndSignal(500000);
		}

		while (($childpid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
			if (isset($this->onProcessReaped[$childpid]) === FALSE || array_key_exists($childpid, $this->processes) === FALSE) {
				continue;
			}
			$stopSignal = pcntl_wstopsig($status);
			foreach ($this->onProcessReaped[$childpid] as $callable) {
				if (is_callable($callable) === FALSE) {
					continue;
				}
				call_user_func($callable, $this->processes[$childpid], $stopSignal);
			}
		}
	}

	/**
	 * @param int $milliSeconds
	 */
	public static function sleepAndSignal($milliSeconds = 10000) {
		usleep($milliSeconds);
		pcntl_signal_dispatch();
	}

	/**
	 * @param callable $callable
	 * @param int      $pid
	 */
	public function onProcessReaped($callable, $pid) {
		if (isset($this->onProcessReaped[$pid]) === FALSE) {
			$this->onProcessReaped[$pid] = array();
		}
		$this->onProcessReaped[$pid][] = $callable;
	}

	/**
	 * @param mixed $instance
	 */
	public function registerObject($instance) {
		$this->objectRegistry[spl_object_hash($instance)] = getmypid();
	}

	/**
	 * @param mixed $instance
	 *
	 * @return bool
	 */
	public function isObjectRegistered($instance) {
		$objectId = spl_object_hash($instance);
		return array_key_exists($objectId, $this->objectRegistry) === FALSE || $this->objectRegistry[$objectId] === getmypid();
	}
}