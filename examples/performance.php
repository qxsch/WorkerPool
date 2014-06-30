<?php

require_once(__DIR__ . '/../autoload.php');

$worker = new \QXS\WorkerPool\Worker\ClosureWorker(
	function () {
		usleep(rand(1000000, 2000000)); // this is the working load!
		return NULL;
	}
);

$wp = new \QXS\WorkerPool\WorkerPool();
$wp
	->setWorkerPoolSize(100)
	->setWorker($worker)
	->start();

for ($i = 0; $i < 500; $i++) {
	$wp->run($i);
}

$wp->waitForAllWorkers(); // wait for all workers
