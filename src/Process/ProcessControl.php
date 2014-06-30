<?php
namespace QXS\WorkerPool\Process;

class ProcessControl {

	/**
	 * @var array
	 */
	protected $signals = array(
		SIGCHLD, SIGTERM, SIGHUP, SIGUSR1, SIGINT
	);

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
	protected $onSignal = array();

	/**
	 * @var array
	 */
	protected $objectRegistry = array();

	/**
	 * @var ProcessControl
	 */
	protected static $instance;

	/**
	 * @return ProcessControl
	 */
	public static function instance() {
		if (self::$instance === NULL) {
			self::$instance = new ProcessControl();
		}
		return self::$instance;
	}

	protected function __construct() {
		$this->registerObject($this);
		foreach ($this->signals as $signal) {
			pcntl_signal($signal, array($this, 'signalHandler'));
		}
	}

	public function __destruct() {
		if ($this->isObjectRegistered($this)) {
			foreach ($this->signals as $signal) {
				pcntl_signal($signal, SIG_DFL);
			}
		}
	}

	/**
	 * @internal
	 */
	public function signalHandler($signo) {
		switch ($signo) {
			case SIGCHLD:
				$this->reaper(FALSE);
				break;
			default:
				$myPid = getmypid();
				if (array_key_exists($myPid, $this->onSignal)) {
					foreach ($this->onSignal[$myPid] as $callable) {
						if (is_callable($callable) === FALSE) {
							continue;
						}
						call_user_func($callable, $signo);
					}
				}
		}
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
	public function reaper($wait = TRUE) {
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
	 * @param callable $callable
	 * @param int      $pid
	 */
	public function onSignal($callable, $pid = 0) {
		$pid = $pid === 0 ? getmypid() : $pid;
		if (isset($this->onSignal[$pid]) === FALSE) {
			$this->onSignal[$pid] = array();
		}
		$this->onSignal[$pid][] = $callable;
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
