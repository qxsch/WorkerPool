<?php
namespace QXS\WorkerPool\Process;

use QXS\WorkerPool\IPC\Message;
use QXS\WorkerPool\IPC\SimpleSocket;
use QXS\WorkerPool\IPC\SimpleSocketException;
use QXS\WorkerPool\Process\Exception\InvalidOperationException;
use QXS\WorkerPool\Process\Exception\ProcessException;
use QXS\WorkerPool\Process\Exception\SerializableException;
use QXS\WorkerPool\Process\Exception\ProcessTerminatedException;
use QXS\WorkerPool\Worker\WorkerProcess;

abstract class Process {

	// Contexts
	const CONTEXT_PARENT = 0;
	const CONTEXT_CHILD = 1;

	// Status
	const STATUS_INITIALIZING = 0;
	const STATUS_IDLE = 1;
	const STATUS_BUSY = 2;
	const STATUS_EXITING = 10;
	const STATUS_EXITED = 11;
	const STATUS_ABORTED = 20;

	// Timeouts
	const PROCESS_TIMEOUT_SEC = 10;
	const PROCESS_SHUTDOWN_TIMEOUT_SEC = 30;

	/**
	 * @var int
	 */
	protected $logLevel = LOG_DEBUG;

	/**
	 * @var SimpleSocket
	 */
	protected $socket;

	/**
	 * @var string
	 */
	protected $status = self::STATUS_INITIALIZING;

	/**
	 * @var int
	 */
	protected $exitStatus = 0;

	/**
	 * @var int
	 */
	protected $parentPid;

	/**
	 * @var int
	 */
	protected $pid;

	/**
	 * @var int
	 */
	protected $context = self::CONTEXT_PARENT;

	/**
	 * @var int
	 */
	protected $idleSince = 0;

	/**
	 * @var string process title of the parent
	 */
	protected $parentProcessTitleFormat = '%basename%: Parent';

	/**
	 * @var string process title of the children
	 */
	protected $childProcessTitleFormat = '%basename%: Worker %class% [%state%]';

	/**
	 * @var Message[]
	 */
	protected $deferredMessages = array();

	/**
	 * @var bool
	 */
	protected $isDestroying = FALSE;

	public final function __construct() {
		ProcessControl::instance()->registerObject($this);
	}

	public final function __destruct() {
		if ($this->isDestroying === FALSE && $this->isRunning() && ProcessControl::instance()->isObjectRegistered($this)) {
			$this->destroy();
		}
	}

	/**
	 * Starts the process
	 *
	 * This method forks a child process and runs it's process loop. A socket communication between the child and the
	 * parent process is also set up, to communicate via messages.
	 *
	 * @throws \RuntimeException
	 * @throws Exception\InvalidOperationException
	 * @return int The PID of the process
	 */
	public final function start() {
		if ($this->status !== self::STATUS_INITIALIZING) {
			throw new InvalidOperationException('Process is already started.', 1402471085);
		}

		$this->parentPid = getmypid();
		$this->status = self::STATUS_IDLE;

		$sockets = array();
		socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

		$processId = pcntl_fork();

		if ($processId < 0) {
			throw new \RuntimeException('pcntl_fork failed.');
		}

		$childSocket = $sockets[0];
		$parentSocket = $sockets[1];

		if ($processId === 0) {
			// Child
			$this->context = self::CONTEXT_CHILD;
			$this->pid = getmypid();
			$this->setCurrentProcessTitle($this->childProcessTitleFormat);
			socket_close($parentSocket);
			unset($parentSocket);
			$this->socket = new SimpleSocket($childSocket);
			$this->onStart();
			$this->startProcessLoop();
		} else {
			// Parent
			$this->context = self::CONTEXT_PARENT;
			$this->pid = $processId;
			ProcessControl::instance()->addProcess($this);
			ProcessControl::instance()->onProcessReaped(array($this, 'onProcessReaped'), $processId);
			$this->setCurrentProcessTitle($this->parentProcessTitleFormat);
			socket_close($childSocket);
			unset($childSocket);
			$this->socket = new SimpleSocket($parentSocket);
			$this->socket->annotation['pid'] = $this->pid;
			$this->idleSince = time();
			$this->onStart();
		}

		return $this->pid;
	}

	/**
	 * Destroys the process
	 *
	 * @param int $maxWaitSecs
	 *
	 * @throws Exception\InvalidOperationException
	 */
	public final function destroy($maxWaitSecs = self::PROCESS_TIMEOUT_SEC) {
		if ($this->status === self::STATUS_INITIALIZING) {
			throw new InvalidOperationException('Process not started.', 1403706983);
		}
		if ($this->isDestroying === TRUE) {
			throw new InvalidOperationException('Process is already destroyed.', 1403706984);
		}
		if ($this->processWithThisPidIsRunning() === FALSE) {
			return;
		}

		$this->isDestroying = TRUE;

		// Execute hook
		try {
			$this->onDestroy();
		} catch (\Exception $e) {
			// Ignore exception
		}

		if ($this->processRequest(Message::createExitMessage()) === TRUE) {
			$this->status = self::STATUS_EXITING;
			// Reap before going into wait-loop
			ProcessControl::instance()->reaper();
			while ($this->processWithThisPidIsRunning()) {
				ProcessControl::sleepAndSignal();
			}
		} else {
			// If exit message could not be send to prcess, wait for process to exit itself
			$startWait = time();
			while (time() - $startWait < $maxWaitSecs) {
				ProcessControl::sleepAndSignal();
				if ($this->processWithThisPidIsRunning() === FALSE) {
					break;
				}
			}
		}

		// Last resort
		if ($this->processWithThisPidIsRunning()) {
			posix_kill($this->pid, SIGKILL);
		}

		// Execute hook
		try {
			$this->onDestroyed();
		} catch (\Exception $e) {
			// Ignore exception
		}
	}

	/**
	 * Sends a message to the process loop and returns the result
	 *
	 * @param Message $request
	 * @param bool    $waitForResponse
	 *
	 * @throws Exception\InvalidOperationException
	 * @return mixed
	 */
	protected function processRequest(Message $request, $waitForResponse = TRUE) {
		if ($this->status === self::STATUS_INITIALIZING) {
			throw new InvalidOperationException('Process is not running.', 1403706363);
		}

		$waitForResponse = $request->isExitMessage() ? TRUE : $waitForResponse;

		if ($this->isBusy() && $waitForResponse) {
			$this->addDeferredMessage($this->receiveMessage());
		}

		while ($this->isRunning() && $this->isIdle() === FALSE) {
			ProcessControl::sleepAndSignal();
		}

		if ($this->isRunning() === FALSE && $request->isExitMessage() === FALSE) {
			return $this->waitForProcessExitAndCreateExceptionMessage()->getParameters();
		}

		$request->setAsynchronous($waitForResponse === FALSE);

		$this->status = self::STATUS_BUSY;
		$this->idleSince = 0;

		try {
			$this->socket->send($request);
		} catch (SimpleSocketException $socketException) {
			if ($request->isExitMessage()) {
				return FALSE;
			}
			if ($waitForResponse) {
				return $this->waitForProcessExitAndCreateExceptionMessage()->getParameters();
			}
		}

		// Receive until reponse comes in
		while ($waitForResponse) {
			ProcessControl::sleepAndSignal();
			$response = $this->receiveMessage();
			if ($request->getId() === $response->getId() || $response->isExceptionMessage()) {
				return $response->getParameters();
			} else {
				$this->addDeferredMessage($response);
			}
		}

		return NULL;
	}

	/**
	 * Sends a request to the process loop
	 *
	 * The request consists of a procedure name and its optional parameters. The method blocks while the process is busy
	 * processing a previous message. If $waitForResponse
	 *
	 * @param string $procedure
	 * @param mixed  $parameters
	 * @param bool   $waitForResponse If TRUE wait and return the response. Otherwise just send the request.
	 *
	 * @throws SerializableException
	 * @return mixed Returns the response (if $waitForResponse is TRUE).
	 */
	public final function process($procedure, $parameters = NULL, $waitForResponse = TRUE) {
		return $this->processRequest(new Message($procedure, $parameters), $waitForResponse);
	}

	/**
	 * @return mixed
	 */
	public function getNextResult() {
		return $this->receiveMessage()->getParameters();
	}

	/**
	 * @return bool
	 */
	public function isRunning() {
		pcntl_signal_dispatch();
		return $this->status === self::STATUS_BUSY || $this->status === self::STATUS_IDLE;
	}

	/**
	 * @return bool
	 */
	public function isIdle() {
		return $this->status === self::STATUS_IDLE;
	}

	/**
	 * @return bool
	 */
	public function isBusy() {
		return $this->status === self::STATUS_BUSY;
	}

	/**
	 * @return int
	 */
	public function getIdleTime() {
		if ($this->idleSince === 0) {
			return 0;
		}
		return time() - $this->idleSince;
	}

	/**
	 * @return int
	 */
	public function getPid() {
		return $this->pid;
	}

	/**
	 * The main process loop
	 *
	 * 1. Waits for a request
	 * 2. Process the request
	 * 3. Send the response
	 */
	protected function startProcessLoop() {
		while (TRUE) {
			try {
				ProcessControl::sleepAndSignal();

				$request = $this->socket->receive();

				if (!$request instanceof Message) {
					break;
				}

				if ($request->isExitMessage()) {
					$this->socket->send($request->createResponse(TRUE));
					break;
				}

				$processResult = NULL;
				try {
					$processResult = $this->doProcess($request->getProcedure(), $request->getParameters());
				} catch (\Exception $exception) {
					$processResult = ProcessException::createFromException($exception);
				}

				$this->socket->send($request->createResponse($processResult));
			} catch (SimpleSocketException $socketException) {
				// End the loop if socket communication is broken
				break;
			}
		}

		$this->onChildExit();
		exit();
	}

	/**
	 * @param string $procedure
	 * @param mixed  $parameters
	 *
	 * @return mixed|void
	 */
	protected abstract function doProcess($procedure, $parameters);

	/**
	 * Receive messages from the given processes
	 *
	 * @param WorkerProcess[] $processes
	 * @param int             $sec
	 *
	 * @return Message[]
	 */
	protected static function receiveMessages($processes, $sec = 0) {
		/** @var SimpleSocket[] $sockets */
		$sockets = array();
		/** @var Message[] $messages */
		$messages = array();

		foreach ($processes as $process) {
			$sockets[$process->getPid()] = $process->getSocket();
			foreach ($process->deferredMessages as $id => $deferredMessage) {
				if ($deferredMessage->isAsynchronous()) {
					array_push($messages, $deferredMessage);
					unset($process->deferredMessages[$id]);
				}
			}
		}

		$selectedSockets = SimpleSocket::select($sockets, array(), array(), $sec);

		foreach ($selectedSockets['read'] as $socket) {
			$pid = $socket->annotation['pid'];
			$process = $processes[$pid];
			$message = $process->receiveMessage();
			// A deferred exception message is created in case of reaped process
			if ($process->isRunning()) {
				array_push($messages, $message);
			}
		}

		return $messages;
	}

	/**
	 * Receive a message from the process
	 *
	 * @return Message
	 */
	protected function receiveMessage() {
		try {
			$message = $this->socket->receive();
			if ($message instanceof Message) {
				$this->status = self::STATUS_IDLE;
				$this->idleSince = time();
				return $message;
			}
		} catch (SimpleSocketException $socketException) {
			// Go on and send exception message
		}
		return $this->waitForProcessExitAndCreateExceptionMessage();
	}

	/**
	 * @internal
	 *
	 * @param Process $process
	 * @param int     $exitStatus
	 */
	public final function onProcessReaped(Process $process, $exitStatus) {
		$process->exitStatus = $exitStatus;
		$process->status = pcntl_wifexited($exitStatus) ? self::STATUS_EXITED : self::STATUS_ABORTED;
		$process->addDeferredMessage($process->waitForProcessExitAndCreateExceptionMessage());
	}

	/**
	 * @param Message $message
	 */
	protected function addDeferredMessage(Message $message) {
		$this->deferredMessages[$message->getId()] = $message;
	}

	/**
	 * @throws Exception\InvalidOperationException
	 * @return \QXS\WorkerPool\IPC\Message[]
	 */
	protected function getRemainingMessages() {
		if ($this->isRunning()) {
			throw new InvalidOperationException('Could not get remaining messages while process is still running.', 1403694631);
		}
		return $this->deferredMessages;
	}

	/**
	 * @return Message
	 */
	protected function waitForProcessExitAndCreateExceptionMessage() {
		while ($this->isRunning()) {
			ProcessControl::sleepAndSignal();
		}

		$message = Message::createExceptionMessage(new ProcessTerminatedException($this->pid, $this->exitStatus));
		$message->overridePid($this->getPid());
		return $message;
	}

	/**
	 * @return bool
	 */
	protected function processWithThisPidIsRunning() {
		$pgid = posix_getpgid($this->pid);
		return $pgid !== FALSE;
	}

	/**
	 * @return SimpleSocket
	 */
	protected function getSocket() {
		return $this->socket;
	}

	protected function onDestroyed() {
	}

	protected function onStart() {
	}

	protected function onDestroy() {
	}

	protected function onChildExit(){

	}

	/**
	 * @param int $logLevel
	 */
	public function setLogLevel($logLevel) {
		$this->logLevel = $logLevel;
	}

	/**
	 * @return int
	 */
	public function getLogLevel() {
		return $this->logLevel;
	}

	/**
	 * Returns the process title of the child
	 *
	 * @return string the process title of the child
	 */
	public function getChildProcessTitleFormat() {
		return $this->childProcessTitleFormat;
	}

	/**
	 * Sets the process title of the child
	 *
	 * Listing permitted replacments
	 *   %i%         The Child's Number
	 *   %basename%  The base name of PHPSELF
	 *   %fullname%  The value of PHPSELF
	 *   %class%     The Worker's Classname
	 *   %state%     The Worker's State
	 *
	 * @param string $string the process title of the child
	 *
	 * @return \QXS\WorkerPool\WorkerPool
	 * @throws InvalidOperationException in case the WorkerPool has already been created
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	public function setChildProcessTitleFormat($string) {
		$this->checkThatProcessIsInitializing();
		$this->childProcessTitleFormat = self::sanitizeProcessTitleFormat($string);
		return $this;
	}

	/**
	 * Returns the process title of the parent
	 *
	 * @return string the process title of the parent
	 */
	public function getParentProcessTitleFormat() {
		return $this->parentProcessTitleFormat;
	}

	/**
	 * Sets the process title of the parent
	 *
	 * Listing permitted replacments
	 *   %basename%  The base name of PHPSELF
	 *   %fullname%  The value of PHPSELF
	 *   %class%     The WorkerPool's Classname
	 *
	 * @param string $string the process title of the parent
	 *
	 * @return \QXS\WorkerPool\WorkerPool
	 * @throws InvalidOperationException in case the WorkerPool has already been created
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	public function setParentProcessTitleFormat($string) {
		$this->checkThatProcessIsInitializing();
		$this->parentProcessTitleFormat = self::sanitizeProcessTitleFormat($string);
		return $this;
	}

	/**
	 * @throws Exception\InvalidOperationException
	 */
	protected function checkThatProcessIsInitializing() {
		if ($this->status !== self::STATUS_INITIALIZING) {
			throw new InvalidOperationException('Operation is not allowed for a running process.', 1403864147);
		}
	}

	/**
	 * @param string $format
	 */
	protected function setCurrentProcessTitle($format) {
		$status='Initializing';
		switch($this->status) {
			case self::STATUS_IDLE: $status='Idle'; break;
			case self::STATUS_BUSY : $status='Busy'; break;
			case self::STATUS_INITIALIZING: $status='Initializing'; break;
			case self::STATUS_EXITING : $status='Exiting'; break;
			case self::STATUS_EXITED : $status='Exited'; break;
			case self::STATUS_ABORTED : $status='Aborted'; break;
		}
		self::setProcessTitle(
			$format,
			array(
				'basename' => basename($_SERVER['PHP_SELF']),
				'fullname' => $_SERVER['PHP_SELF'],
				'class' => get_class($this),
				'state' => $status
			)
		);
	}

	/**
	 * Sanitizes the process title format string
	 *
	 * @param string $string the process title
	 *
	 * @return string the process sanitized title
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	protected static function sanitizeProcessTitleFormat($string) {
		$string = preg_replace(
			'/[^a-z0-9-_.:% \\\\\\]\\[]/i',
			'',
			$string
		);
		$string = trim($string);
		return $string;
	}

	/**
	 * Sets the proccess title
	 *
	 * This function call requires php5.5+ or the proctitle extension!
	 * Empty title strings won't be set.
	 *
	 * @param string $title        the new process title
	 * @param array  $replacements an associative array of replacment values
	 *
	 * @return void
	 */
	protected static function setProcessTitle($title, array $replacements = array()) {
		// skip empty title names
		if (trim($title) == '') {
			return;
		}
		// 1. replace the values
		$title = preg_replace_callback(
			'/\%([a-z0-9]+)\%/i',
			function ($match) use ($replacements) {
				if (isset($replacements[$match[1]])) {
					return $replacements[$match[1]];
				}
				return $match[0];
			},
			$title
		);
		// 2. remove forbidden chars
		$title = preg_replace(
			'/[^a-z0-9-_.: \\\\\\]\\[]/i',
			'',
			$title
		);
		// 3. set the title
		if (function_exists('cli_set_process_title')) {
			cli_set_process_title($title); // PHP 5.5+ has a builtin function
		} elseif (function_exists('setproctitle')) {
			setproctitle($title); // pecl proctitle extension
		}
	}

	/**
	 * @param string $message
	 * @param int    $level
	 */
	protected function log($message, $level = LOG_INFO) {
		if ($this->logLevel >= $level) {
			echo sprintf("%s\t%s\t%d\t%d\t%s\t%s", date('c'), $this->status, $this->pid, $this->parentPid, $this->context, $message) . PHP_EOL;
		}
	}
}
