<?php
namespace QXS\Tests\WorkerPool\Fixtures;

use QXS\WorkerPool\Semaphore;
use QXS\WorkerPool\Worker;

Class PingWorker implements Worker {

	public function onProcessCreate(Semaphore $semaphore) {
	}

	public function onProcessDestroy() {
	}

	public function run($input) {
		usleep(rand(50000, 70000));
		return $input;
	}
}