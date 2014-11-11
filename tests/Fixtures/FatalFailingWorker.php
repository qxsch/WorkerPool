<?php
namespace QXS\Tests\WorkerPool\Fixtures;

use QXS\WorkerPool\Semaphore;
use QXS\WorkerPool\WorkerInterface;

Class FatalFailingWorker implements WorkerInterface {

	public function onProcessCreate(Semaphore $semaphore) {
	}

	public function onProcessDestroy() {
	}

	public function run($input) {
		@$x->abc(); // fatal error
		return "Hi $input";
	}
}
