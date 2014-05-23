<?php
/**
 * The Process Details
 */

namespace QXS\WorkerPool;

/**
 * The Process Details Class
 */
class ProcessDetails {

	/** @var int */
	protected $pid;

	/** @var SimpleSocket */
	protected $socket;

	/**
	 * The constructor
	 * @param int $pid
	 * @param SimpleSocket $socket
	 */
	public function __construct($pid, SimpleSocket $socket) {
		$this->pid = $pid;
		$this->socket = $socket;
		$this->socket->annotation['pid'] = $pid;
	}

	/**
	 * Get the pid
	 * @return int
	 */
	public function getPid() {
		return $this->pid;
	}

	/**
	 * Get the socket 
	 * @return \QXS\WorkerPool\SimpleSocket
	 */
	public function getSocket() {
		return $this->socket;
	}
}
