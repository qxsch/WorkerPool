<?php
/**
 * A simple object wrapper arround the socket functions
 */

namespace QXS\WorkerPool;

/**
 * Socket Class for IPC
 */
class SimpleSocket {

	/** @var resource the connection socket, that is used for IPC */
	protected $socket = NULL;
	/**
	 * This Variable can be used to attack custom information to the socket
	 * @var array of custom annotations
	 */
	public $annotation = array();

	/**
	 * The constructor
	 * @param resource $socket a valid socket resource
	 */
	public function __construct($socket) {
		if (!is_resource($socket) && strtolower(@get_resource_type($socket) != 'socket')) {
			throw new \InvalidArgumentException('Socket resource is required!');
		}
		$this->socket = $socket;
	}

	/**
	 * The destructor
	 */
	public function __destruct() {
		@socket_close($this->socket);
	}

	/**
	 * Selects active sockets with a timeout
	 * @param array $readSockets Array of \QXS\WorkerPool\SimpleSocket Objects, that should be monitored for read activity
	 * @param array $writeSockets Array of \QXS\WorkerPool\SimpleSocket Objects, that should be monitored for write activity
	 * @param array $exceptSockets Array of \QXS\WorkerPool\SimpleSocket Objects, that should be monitored for except activity
	 * @param int $sec seconds to wait until a timeout is reached
	 * @param int $usec microseconds to wait a timeout is reached
	 * @return array Associative Array of \QXS\WorkerPool\SimpleSocket Objects, that matched the monitoring, with the following keys 'read', 'write', 'except'
	 */
	public static function select(array $readSockets = array(), array $writeSockets = array(), array $exceptSockets = array(), $sec = 0, $usec = 0) {
		$read = array();
		$write = array();
		$except = array();
		$readTbl = array();
		$writeTbl = array();
		$exceptTbl = array();
		foreach ($readSockets as $val) {
			if ($val instanceof \QXS\WorkerPool\SimpleSocket) {
				$read[] = $val->getSocket();
				$readTbl[$val->getResourceId()] = $val;
			}
		}
		foreach ($writeSockets as $val) {
			if ($val instanceof \QXS\WorkerPool\SimpleSocket) {
				$write[] = $val->getSocket();
				$writeTbl[$val->getResourceId()] = $val;
			}
		}
		foreach ($exceptSockets as $val) {
			if ($val instanceof \QXS\WorkerPool\SimpleSocket) {
				$except[] = $val->getSocket();
				$exceptTbl[$val->getResourceId()] = $val;
			}
		}

		$out = array();
		$out['read'] = array();
		$out['write'] = array();
		$out['except'] = array();

		$sockets = socket_select($read, $write, $except, $sec, $usec);
		if ($sockets === FALSE) {
			return $out;
		}

		foreach ($read as $val) {
			$out['read'][] = $readTbl[intval($val)];
		}
		foreach ($write as $val) {
			$out['write'][] = $writeTbl[intval($val)];
		}
		foreach ($except as $val) {
			$out['except'][] = $exceptTbl[intval($val)];
		}

		return $out;
	}

	/**
	 * Get the id of the socket resource
	 * @param int the id of the socket resource
	 */
	public function getResourceId() {
		return intval($this->socket);
	}

	/**
	 * Get the socket resource
	 * @param resource the socket resource
	 */
	public function getSocket() {
		return $this->socket;
	}

	/**
	 * Check if there is any data available
	 * @param int $sec seconds to wait until a timeout is reached
	 * @param int $usec microseconds to wait a timeout is reached
	 * @return bool true, in case there is data, that can be red
	 */
	public function hasData($sec = 0, $usec = 0) {
		$sec = (int)$sec;
		$usec = (int)$usec;
		if ($sec < 0) {
			$sec = 0;
		}
		if ($usec < 0) $usec = 0;

		$read = array($this->socket);
		$write = array();
		$except = array();
		$sockets = socket_select($read, $write, $except, $sec, $usec);

		if ($sockets === FALSE) {
			return FALSE;
		}
		return $sockets > 0;
	}

	/**
	 * Write the data to the socket in a predetermined format
	 * @param mixed $data the data, that should be sent
	 * @throws \QXS\WorkerPool\SimpleSocketException in case of an error
	 */
	public function send($data) {
		$serialized = serialize($data);
		$hdr = pack('N', strlen($serialized)); // 4 byte length
		$buffer = $hdr . $serialized;
		unset($serialized);
		unset($hdr);
		$total = strlen($buffer);
		$sent = 0;
		while ($total > 0) {
			$sent = @socket_write($this->socket, $buffer);
			if ($sent === FALSE) {
				throw new SimpleSocketException('Sending failed with: ' . socket_strerror(socket_last_error()));
				break;
			}
			$total -= $sent;
			$buffer = substr($buffer, $sent);
		}
	}

	/**
	 * Read a data packet from the socket in a predetermined format.
	 * @throws \QXS\WorkerPool\SimpleSocketException in case of an error
	 * @return mixed the data, that has been received
	 */
	public function receive() {
		// read 4 byte length first
		$hdr = '';
		do {
			$read = socket_read($this->socket, 4 - strlen($hdr));
			if ($read === FALSE) {
				throw new SimpleSocketException('Reception failed with: ' . socket_strerror(socket_last_error()));
			} elseif ($read === '' || $read === NULL) {
				return NULL;
			}
			$hdr .= $read;
		} while (strlen($hdr) < 4);

		list($len) = array_values(unpack("N", $hdr));

		// read the full buffer
		$buffer = '';
		do {
			$read = socket_read($this->socket, $len - strlen($buffer));
			if ($read === FALSE || $read == '') {
				return NULL;
			}
			$buffer .= $read;
		} while (strlen($buffer) < $len);

		$data = unserialize($buffer);
		return $data;
	}
}

