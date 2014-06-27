<?php
namespace QXS\WorkerPool\Process;

class ProxyProcess extends Process {

	/**
	 * @var Process
	 */
	protected $subject;

	public function setSubject(Process $process) {
		$this->subject = $process;
	}

	protected function onStart($inChild, $inParent) {
		if ($inChild) {
			$this->process('startSubject');
		}
	}

	function __call($name, $arguments) {
		if (method_exists($this->subject, $name)) {
			return call_user_func_array(array($this->subject, $name), $arguments);
		}
		return $this->subject->process($name, $arguments);
	}

	/**
	 * @param string $procedure
	 * @param mixed $parameters
	 * @return mixed|void
	 */
	protected function doProcess($procedure, $parameters) {
		if ($procedure === 'startSubject') {
			return $this->subject->start();
		}
		return $this->subject->doProcess($procedure, $parameters);
	}
}