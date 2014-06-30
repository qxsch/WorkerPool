<?php

use QXS\WorkerPool\Semaphore;

require_once(__DIR__ . '/../autoload.php');

$worker = new \QXS\WorkerPool\Worker\ClosureWorker(
	function ($input, Semaphore $semaphore, ArrayObject $storage) {
		$semaphore->synchronizedBegin();
		// this code is being synchronized accross all workers
		echo "[A][" . getmypid() . "]" . " hi $input\n";
		$semaphore->synchronizedEnd();
		// alternative example
		$semaphore->synchronize(function () use ($input, $storage) {
			// this code is being synchronized accross all workers
			echo "[B][" . getmypid() . "]" . " hi $input\n";
		});
		sleep(rand(1, 3)); // this is the working load!
		return $input;
	}
);

$wp = new \QXS\WorkerPool\WorkerPool();
$wp
	->setMaximumRunningWorkers(4)
	->setWorker($worker)
	->start();

for ($i = 0; $i < 10; $i++) {
	$wp->run($i);
}

$wp->waitForAllWorkers(); // wait for all workers

foreach ($wp as $val) {
	var_dump($val); // dump the returned values
}


