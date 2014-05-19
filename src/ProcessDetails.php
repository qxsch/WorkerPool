<?php
namespace QXS\WorkerPool;

class ProcessDetails {

	/**
	 * @var int
	 */
	protected $pid;

	/**
	 * @var SimpleSocket
	 */
	protected $socket;

	/**
	 * @var bool
	 */
	protected $isFree;

	/**
	 * @var array
	 */
	protected static $freeProcessIds = array();

	/**
	 * @param int $pid
	 * @param SimpleSocket $socket
	 */
	public function __construct($pid, SimpleSocket $socket) {
		$this->pid = $pid;
		$this->socket = $socket;
		$this->socket->annotation['pid'] = $pid;
		$this->setIsFree(TRUE);
	}

	/**
	 * @return boolean
	 */
	public function isFree() {
		return $this->isFree;
	}

	/**
	 * @return int
	 */
	public function getPid() {
		return $this->pid;
	}

	/**
	 * @return \QXS\WorkerPool\SimpleSocket
	 */
	public function getSocket() {
		return $this->socket;
	}

	/**
	 * @param boolean $isFree
	 */
	public function setIsFree($isFree) {
		$this->isFree = $isFree;
		if ($isFree) {
			self::$freeProcessIds[$this->pid] = TRUE;
		} else {
			unset(self::$freeProcessIds[$this->pid]);
		}
	}

	/**
	 * @return int|NULL
	 */
	public static function getFreeProcessId() {
		$freeProcessIds = array_keys(self::$freeProcessIds);
		return count($freeProcessIds) > 0 ? $freeProcessIds[0] : NULL;
	}

	public function killProcess() {
		$this->setIsFree(FALSE);
		@socket_close($this->socket->getSocket());
		@posix_kill($this->pid, 9);
	}
}