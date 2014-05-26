<?php

require_once(__DIR__.'/../vendor/autoload.php');
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
