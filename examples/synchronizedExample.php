<?php

require_once(__DIR__.'/../vendor/autoload.php');


$wp=new \QXS\WorkerPool\WorkerPool();
$wp->setWorkerPoolSize(4)
   ->create(new \QXS\WorkerPool\ClosureWorker(
                        /**
                          * @param mixed $input the input from the WorkerPool::run() Method
                          * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to synchronize calls accross all workers
                          * @param \ArrayObject $storage a persistent storge for the current child process
                          */
                        function($input, $semaphore, $storage) {
                                $semaphore->synchronizedBegin();
                                        // this code is being synchronized accross all workers
                                        echo "[A][".getmypid()."]"." hi $input\n";
                                $semaphore->synchronizedEnd();
				// alternative example
                                $semaphore->synchronize(function() use ($input, $storage) {
                                        // this code is being synchronized accross all workers
                                        echo "[B][".getmypid()."]"." hi $input\n";
                                });
                                sleep(rand(1,3)); // this is the working load!
                                return $input;
                        }
                )
);


for($i=0; $i<10; $i++) {
        $wp->run($i);
}

$wp->waitForAllWorkers(); // wait for all workers

foreach($wp as $val) {
        var_dump($val);  // dump the returned values
}


