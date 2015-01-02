<?php
/**
 * Thisfile requires the jeremeamia/SuperClosure 
 */
require_once(__DIR__.'/vendor/autoload.php');

use QXS\WorkerPool\WorkerPool,
    QXS\WorkerPool\SuperClosureWorker,
    QXS\WorkerPool\SerializableWorkerClosure;


$wp=new WorkerPool();
$wp->setWorkerPoolSize(4)
   ->create(new SuperClosureWorker());


for($i=0; $i<10; $i++) {
	$wp->run(new SerializableWorkerClosure(
		function($input, $semaphore, $storage) use ($i) {
			if($i % 2) {
				echo "CHILD ".getmypid()." CODE using $i - received input $input ...\n";
			}
			else {
				echo "child ".getmypid()." Code using $i - received input $input ...\n";
			}
			return $input / 2;
		},
		$i * 2
	));
}

$wp->waitForAllWorkers(); // wait for all workers

foreach($wp as $val) {
	echo "MASTER RECEIVED ".$val['data']."\n";
}


