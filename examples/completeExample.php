<?php

require_once(dirname(__DIR__).'/require_once.php');


use QXS\WorkerPool\WorkerPool;
use QXS\WorkerPool\Worker;
use QXS\WorkerPool\Semaphore;


/**
 * Our Worker Class
 */
Class MyWorker implements Worker {
        protected $sem;
        /**
         * after the worker has been forked into another process
         *
         * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to run synchronized tasks
         * @throws \Exception in case of a processing Error an Exception will be thrown
         */
        public function onProcessCreate(Semaphore $semaphore) {
                // semaphore can be used in the run method to synchronize the workers
                $this->sem=$semaphore;
                // write something to the stdout
                echo "\t[".getmypid()."] has been created.\n";
                // initialize mt_rand
                list($usec, $sec) = explode(' ', microtime());
                mt_srand( (float) $sec + ((float) $usec * 100000) );
        }
        /**
         * before the worker process is getting destroyed
         *
         * @throws \Exception in case of a processing Error an Exception will be thrown
         */
        public function onProcessDestroy() {
                // write something to the stdout
                echo "\t[".getmypid()."] will be destroyed.\n";
        }
        /**
         * run the work
         *
         * @param Serializeable $input the data, that the worker should process
         * @return Serializeable Returns the result
         * @throws \Exception in case of a processing Error an Exception will be thrown
         */
        public function run($input) {
                $input=(string)$input;
                echo "\t[".getmypid()."] Hi $input\n";
                sleep(mt_rand(0,10)); // this is the workload!
                // and sometimes exceptions might occur
                if(mt_rand(0,10)==9) {
                        throw new \RuntimeException('We have a problem for '.$input.'.');
                }
                return "Hi $input"; // return null here, in case you do not want to pass any data to the parent
        }
}


$wp=new WorkerPool();
$wp->setWorkerPoolSize(10)
   ->create(new MyWorker());

// produce some tasks
for($i=1; $i<=50; $i++) {
        $wp->run($i);
}

// some statistics
echo "Busy Workers:".$wp->getBusyWorkers()."  Free Workers:".$wp->getFreeWorkers()."\n";

// wait for completion of all tasks
$wp->waitForAllWorkers();

// collect all the results
foreach($wp as $val) {
        if(isset($val['data'])) {
                echo "RESULT: ".$val['data']."\n";
        }
        elseif(isset($val['workerException'])) {
                echo "WORKER EXCEPTION: ".$val['workerException']['class'].": ".$val['workerException']['message']."\n".$val['workerException']['trace']."\n";
        }
        elseif(isset($val['poolException'])) {
                echo "POOL EXCEPTION: ".$val['poolException']['class'].": ".$val['poolException']['message']."\n".$val['poolException']['trace']."\n";
        }
}


// write something, before the parent exits
echo "ByeBye\n";
