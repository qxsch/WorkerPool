<?php
/**
 * A simple object wrapper arround the socket functions
 */

namespace QXS\WorkerPool;

/**
 * Socket Class for IPC
 */
class SimpleSocket {

	/** @var resource|\Socket the connection socket, that is used for IPC */
	protected $socket = NULL;
	/**
	 * This Variable can be used to attack custom information to the socket
	 * @var array of custom annotations
	 */
	public $annotation = array();

	/**
	 * The constructor
	 * @param resource $socket a valid socket resource
	 * @throws \InvalidArgumentException
	 */
	public function __construct($socket) {
	    $hasSocket = (is_resource($socket) && strtolower(@get_resource_type($socket)) === 'socket')
            || $socket instanceof \Socket;
		if (!$hasSocket) {
			throw new \InvalidArgumentException('Socket resource is required!');
		}
		$this->socket = $socket;
	}

	/**
	 * The destructor
	 */
	public function __destruct() {
		socket_close($this->socket);
	}

	/**
	 * Selects active sockets with a timeout
	 * @param SimpleSocket[] $readSockets Array of \QXS\WorkerPool\SimpleSocket Objects, that should be monitored for read activity
	 * @param SimpleSocket[] $writeSockets Array of \QXS\WorkerPool\SimpleSocket Objects, that should be monitored for write activity
	 * @param SimpleSocket[] $exceptSockets Array of \QXS\WorkerPool\SimpleSocket Objects, that should be monitored for except activity
	 * @param int $sec seconds to wait until a timeout is reached
	 * @param int $usec microseconds to wait a timeout is reached
	 * @return array Associative Array of \QXS\WorkerPool\SimpleSocket Objects, that matched the monitoring, with the following keys 'read', 'write', 'except'
	 */
	public static function select(array $readSockets = array(), array $writeSockets = array(), array $exceptSockets = array(), $sec = 0, $usec = 0) {
		$out = array();
		$out['read'] = array();
		$out['write'] = array();
		$out['except'] = array();

		if(count($readSockets) === 0){
			return $out;
		}

		$readSocketsResources = array();
		$writeSocketsResources = array();
		$exceptSocketsResources = array();
		$readSockets = self::createSocketsIndex($readSockets, $readSocketsResources);
		$writeSockets = self::createSocketsIndex($writeSockets, $writeSocketsResources);
		$exceptSockets = self::createSocketsIndex($exceptSockets, $exceptSocketsResources);

		$socketsSelected = @socket_select($readSocketsResources, $writeSocketsResources, $exceptSocketsResources, $sec, $usec);
		if ($socketsSelected === FALSE) {
			$socketError = socket_last_error();
			// 1 more retry https://stackoverflow.com/questions/2933343/php-can-pcntl-alarm-and-socket-select-peacefully-exist-in-the-same-thread/2938156#2938156
			if ($socketError === SOCKET_EINTR) {
				socket_clear_error();

				$socketsSelected = socket_select($readSocketsResources, $writeSocketsResources, $exceptSocketsResources, $sec, $usec);
				if ($socketsSelected === FALSE) {
					return $out;
				}
			} else {
				trigger_error(
					sprintf('socket_select(): unable to select [%d]: %s', $socketError, socket_strerror($socketError)),
					E_USER_WARNING
				);
				return $out;
			}
		}

		foreach ($readSocketsResources as $socketResource) {
			$out['read'][] = $readSockets[self::getSocketId($socketResource)];
		}
		foreach ($writeSocketsResources as $socketResource) {
			$out['write'][] = $writeSockets[self::getSocketId($socketResource)];
		}
		foreach ($exceptSocketsResources as $socketResource) {
			$out['except'][] = $exceptSockets[self::getSocketId($socketResource)];
		}

		return $out;
	}

	/**
	 * @param SimpleSocket[] $sockets
	 * @param array $socketsResources
	 * @return SimpleSocket[]
	 */
	protected static function createSocketsIndex($sockets, &$socketsResources) {
		$socketsIndex = array();
		foreach ($sockets as $socket) {
			if (!$socket instanceof SimpleSocket) {
				continue;
			}
			$resourceId = $socket->getResourceId();
			$socketsIndex[$resourceId] = $socket;
			$socketsResources[$resourceId] = $socket->getSocket();
		}

		return $socketsIndex;
	}

	/**
	 * Get the id of the socket resource
	 * @return int the id of the socket resource
	 */
	public function getResourceId() {
		return self::getSocketId($this->socket);
	}

    /**
     * Get the id of the socket
     * @param $socket
     * @return int the id of the socket
     */
	protected static function getSocketId($socket) {
	    if ($socket instanceof \Socket) {
	        return spl_object_id($socket);
        }

        return intval($socket);
    }

	/**
	 * Get the socket resource
	 * @return resource the socket resource
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
		if ($usec < 0) {
			$usec = 0;
		}

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
		while ($total > 0) {
			$sent = socket_write($this->socket, $buffer);
			if ($sent === FALSE) {
				throw new SimpleSocketException('Sending failed with: ' . socket_strerror(socket_last_error($this->socket)));
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
				throw new SimpleSocketException('Reception failed with: ' . socket_strerror(socket_last_error($this->socket)));
			}
			elseif ($read === '' || $read === NULL) {
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
				throw new SimpleSocketException('Reception failed with: ' . socket_strerror(socket_last_error($this->socket)));
			}
			elseif ($read == '') {
				return NULL;
			}
			$buffer .= $read;
		} while (strlen($buffer) < $len);

		$data = unserialize($buffer);
		return $data;
	}
}

