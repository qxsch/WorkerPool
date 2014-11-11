<?php
namespace QXS\Tests\WorkerPool\Fixtures;

use QXS\WorkerPool\Semaphore;
use QXS\WorkerPool\WorkerInterface;

Class PingWorker implements WorkerInterface {

	public function onProcessCreate(Semaphore $semaphore) {
	}

	public function onProcessDestroy() {
	}

	public function run($input) {
		usleep(rand(50000, 70000));
		return $input;
	}
}
