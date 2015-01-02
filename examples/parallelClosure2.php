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


$wp->run(new SerializableWorkerClosure(
	function($input, $semaphore, $storage) {
		sleep(2);
		echo "$input: Hi $input\n";
	},
	1
));

$z="hello";
$wp->run(new SerializableWorkerClosure(
	function($input, $semaphore, $storage) use ($z) {
		sleep(2);
		echo "$input: $z $input\n";
	},
	2
));

$wp->run(new SerializableWorkerClosure(
	function($input, $semaphore, $storage) use ($z) {
		sleep(2);
		echo "$input: $z ";
		$input*=2;
		echo $input."\n";
	},
	3
));

$wp->run(new SerializableWorkerClosure(
	function($input, $semaphore, $storage) use ($z) {
		sleep(2);
		echo "$input: $z ";
		$input+=10;
		echo $input."\n";
	},
	4
));

$wp->waitForAllWorkers(); // wait for all workers

foreach($wp as $val) {
	echo "MASTER RECEIVED ".$val['data']."\n";
}


