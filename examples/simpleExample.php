<?php

use QXS\WorkerPool\Semaphore;

require_once(__DIR__ . '/../autoload.php');

$worker = new \QXS\WorkerPool\Worker\ClosureWorker(
	function ($input) {
		usleep(rand(50000, 100000)); // this is the working load!
		if (rand(1, 3) === 1) {
			echo "[" . getmypid() . "]" . " die $input\n";
			whoops();
		} else {
			echo "[" . getmypid() . "]" . " hi $input\n";
		}
		return $input;
	}, function(){
		echo "Create" . PHP_EOL;
	}, function () {
		echo "Destroy" . PHP_EOL;
	}

);

$wp = new \QXS\WorkerPool\WorkerPool();
$wp
	->setMaximumRunningWorkers(5)
	->setWorker($worker)
	->start();

for ($i = 0; $i < 50; $i++) {
	$result = $wp->run($i);
	echo $i . " runs in " . $result . PHP_EOL;
}

echo "Wait" . PHP_EOL;
$wp->waitForAllWorkers(); // wait for all workers
echo "Wait done" . PHP_EOL;

$counter = 0;
while (($result = $wp->getNextResult()) !== NULL) {
	$counter++;
	$data = $result->getData();
	if ($result->hasError()) {
		echo "Error: " . $data->getMessage() . ' ' . $data->getCode();
	} else {
		echo $data;
	}
	echo " @" . $result->getWorkerPid() . PHP_EOL;
}

$wp->destroy();