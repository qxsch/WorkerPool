<?php
namespace QXS\WorkerPool\IPC;

use QXS\WorkerPool\Process\Exception\SerializableException;

class Message {

	/**
	 * @var string
	 */
	protected $procedure;

	/**
	 * @var mixed
	 */
	protected $parameters;

	/**
	 * @var int
	 */
	protected $pid;

	/**
	 * @var int
	 */
	protected $id;

	/**
	 * @var bool
	 */
	protected $asynchronous = FALSE;

	/**
	 * @var int
	 */
	private static $idCounter = 0;

	/**
	 * @param string $procedure
	 * @param mixed $parameters
	 * @throws \InvalidArgumentException
	 */
	public function __construct($procedure, $parameters = NULL) {
		if (strlen($procedure) > 0 && substr($procedure, 0, 1) === '_') {
			throw new \InvalidArgumentException('Procedures must not start with \'_\'', 1403860352);
		}
		$this->parameters = $parameters;
		$this->procedure = $procedure;
		$this->pid = getmypid();
		$this->id = self::$idCounter;
		self::$idCounter++;
	}

	/**
	 * @param SerializableException $exception
	 *
*@return Message
	 */
	public static function createExceptionMessage(SerializableException $exception) {
		$message = new Message('', $exception);
		$message->procedure = '_exception';
		return $message;
	}

	/**
	 * @return Message
	 */
	public static function createExitMessage() {
		$message = new Message('');
		$message->procedure = '_exit';
		return $message;
	}

	/**
	 * @return int
	 */
	public function getId() {
		return $this->id;
	}

	public function createResponse($data = NULL) {
		$response = new Message('', $data);
		$response->procedure = $this->getProcedure();
		$response->id = $this->getId();
		$response->asynchronous = $this->isAsynchronous();
		return $response;
	}

	/**
	 * @param boolean $asynchronous
	 */
	public function setAsynchronous($asynchronous) {
		$this->asynchronous = $asynchronous;
	}

	/**
	 * @return bool
	 */
	public function isExitMessage() {
		return $this->procedure === '_exit';
	}

	/**
	 * @return bool
	 */
	public function isExceptionMessage() {
		return $this->procedure === '_exception';
	}

	/**
	 * @return boolean
	 */
	public function isAsynchronous() {
		return $this->asynchronous;
	}

	/**
	 * @return int
	 */
	public function getPid() {
		return $this->pid;
	}

	/**
	 * @param int $pid
	 */
	public function overridePid($pid) {
		$this->pid = $pid;
	}

	/**
	 * @return mixed
	 */
	public function getParameters() {
		return $this->parameters;
	}

	/**
	 * @return string
	 */
	public function getProcedure() {
		return $this->procedure;
	}

	function __toString() {
		return sprintf('Message #%d (PID: %d)', $this->id, $this->pid);
	}
}