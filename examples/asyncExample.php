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
                                sleep(rand(1,3)); // this is the working load!
                                return $input;
                        }
                )
);

$i=20;
while($i<40) {
	// is there a free worker?
	if($wp->getFreeWorkers()>0) {
        	$wp->run($i);
		$i++;
	}
	else {
		// poll some data - get free workers could be removed to priorize result polling in favor of result submission
		while($wp->hasResults() && $wp->getFreeWorkers()==0) {
			$val=$wp->getNextResult();
			echo "Received: ".$val['data']."    from pid ".$val['pid']."\n";
		}
		$wp->waitForOneFreeWorker();
		//// as an alternative you can manually wait for free workers?
		//if($wp->getFreeWorkers()==0) {
		//	usleep(1000); // and sleep a bit
		//}
	}
}

// bug fix - run get busy workers before has results (worker can finish between the calls and the next call will deliver results)
while($wp->getBusyWorkers()>0 || $wp->hasResults()) {
	// poll some data
	foreach($wp as $val) {
		echo "Received: ".$val['data']."    from pid ".$val['pid']."\n";
	}
	usleep(1000);
}


