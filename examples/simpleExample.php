<?php

require_once(__DIR__ . '/../autoload.php');


$wp=new \QXS\WorkerPool\WorkerPool();
$wp->setWorkerPoolSize(4)
   ->create(new \QXS\WorkerPool\ClosureWorker(
	/**
	 * @param mixed $input the input from the WorkerPool::run() Method
	 * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to synchronize calls accross all workers
	 * @param \ArrayObject $storage a persistent storge for the current child process
	 */
	function($input, $semaphore, $storage) {
		echo "[".getmypid()."]"." hi $input\n";
		sleep(rand(1,3)); // this is the working load!
		if(rand(1, 4) === 2) {
			throw new \Exception("ohje");
		}
		return $input;
	}
  )
);


for($i=0; $i<10; $i++) {
	$wp->run($i);
}

$wp->waitForAllWorkers(); // wait for all workers

foreach($wp as $val) {
	echo $val->dump() . "\n";
        //var_dump($val);  // you can also dump the returned values
}


