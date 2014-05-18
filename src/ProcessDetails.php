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
	 * @param int $pid
	 * @param SimpleSocket $socket
	 */
	public function __construct($pid, SimpleSocket $socket) {
		$this->pid = $pid;
		$this->socket = $socket;
		$this->socket->annotation['pid'] = $pid;
		$this->isFree = TRUE;
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
	}

	public function killProcess() {
		@socket_close($this->socket->getSocket());
		@posix_kill($this->pid, 9);
	}
}
