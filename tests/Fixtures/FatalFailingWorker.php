<?php
namespace QXS\Tests\WorkerPool\Fixtures;

use QXS\WorkerPool\Semaphore;
use QXS\WorkerPool\Worker\Worker;

Class FatalFailingWorker implements Worker {

	public function onProcessCreate(Semaphore $semaphore) {
	}

	public function onProcessDestroy() {
	}

	public function run($input) {
		whoops();
	}
}