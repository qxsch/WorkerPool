<?php

use QXS\WorkerPool\Semaphore;

require_once(__DIR__ . '/../autoload.php');

$worker = new \QXS\WorkerPool\Worker\ClosureWorker(
	function ($input, Semaphore $semaphore, ArrayObject $storage) {
		$storage->append($input);
		echo "[" . getmypid() . "]" . " hi $input\n";
		sleep(rand(1, 3)); // this is the working load!
		return $input;
	},
	function () {
		echo "[" . getmypid() . "]" . " child has been created\n";
	},
	function (Semaphore $semaphore, ArrayObject $storage) {
		$semaphore->synchronizedBegin();
		echo "[" . getmypid() . "]" . " child will be destroyed, see its history\n";
		foreach ($storage as $val) {
			echo "\t$val\n";
		}
		$semaphore->synchronizedEnd();
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
	echo "Parent has retrieved: " . $val['data'] . "\n";
}