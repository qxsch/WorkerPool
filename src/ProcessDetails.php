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
	 * @param int $pid
	 * @param SimpleSocket $socket
	 */
	public function __construct($pid, SimpleSocket $socket) {
		$this->pid = $pid;
		$this->socket = $socket;
		$this->socket->annotation['pid'] = $pid;
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
}
