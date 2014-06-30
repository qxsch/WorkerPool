<?php

require_once(__DIR__ . '/../autoload.php');

$worker = new \QXS\WorkerPool\Worker\ClosureWorker(
	function ($input) {
		sleep(rand(1, 3)); // this is the working load!
		return $input;
	}
);

$wp = new \QXS\WorkerPool\WorkerPool();
$wp->setMaximumRunningWorkers(4)
	->setMinimumRunningWorkers(4)
	->setWorker($worker)
	->start();

$i = 20;
while ($i < 40) {
	// is there a free worker?
	if ($wp->getFreeWorkers() > 0) {
		$wp->run($i);
		$i++;
	} else {
		// poll some data
		while ($wp->hasResults() && $wp->getFreeWorkers() == 0) {
			$val = $wp->getNextResult();
			echo "Received: " . $val->getData() . "    from pid " . $val->getWorkerPid() . "\n";
		}
		// still no free workers?
		if ($wp->getFreeWorkers() == 0) {
			usleep(1000); // and sleep a bit
		}
	}
}

while ($wp->hasResults() || $wp->getBusyWorkers() > 0) {
	// poll some data
	foreach ($wp->getResults() as $val) {
		echo "Received: " . $val->getData() . "    from pid " . $val->getWorkerPid() . "\n";
	}
	usleep(1000);
}


