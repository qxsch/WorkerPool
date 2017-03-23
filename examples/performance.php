<?php

require_once(__DIR__ . '/../autoload.php');

echo "Process 500 Tasks with an avg 1.5secs (min 1sec, max 2secs)\n";
echo "\t- Without the workerpool this would take about 12.5 minutes (avg)\n";

$timeused=microtime(true);

$wp = new \QXS\WorkerPool\WorkerPool();
$wp->setWorkerPoolSize(100)
	->create(new \QXS\WorkerPool\ClosureWorker(
		/**
		 * @param mixed $input the input from the WorkerPool::run() Method
		 * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to synchronize calls accross all workers
		 * @param \ArrayObject $storage a persistent storge for the current child process
		 */
			function ($input, $semaphore, $storage) {
				usleep(rand(1000000,2000000)); // this is the working load!
				return NULL;
			}
		)
	);

for ($i = 0; $i < 500; $i++) {
	$wp->run($i);
}

$wp->waitForAllWorkers(); // wait for all workers


$timeused=microtime(true)-$timeused;
echo "\t- With the workerpool it took: ".number_format($timeused, 2)." seconds\n";
echo "\t- In this example the workerpool is ". number_format(750/$timeused, 2) ." times faster to the avg!\n";
echo "\t- BTW: This is a simulation of a real world example, where we were waiting for remote results. This initiated the development of the workerpool.\n";
