<?php
/**
 * This file requires the jeremeamia/SuperClosure 
 *
 * Serialization of closures comes with some overhead...
 */

require_once(__DIR__ . '/../autoload.php');
if(file_exists(__DIR__.'/../../../autoload.php')) {
	require_once(__DIR__.'/../../../autoload.php');
}
else {
	die("Cannot find a classloader for jeremeamia/SuperClosure!\n");
}

use QXS\WorkerPool\WorkerPool,
    QXS\WorkerPool\SuperClosureWorker,
    QXS\WorkerPool\SerializableWorkerClosure;


$wp=new WorkerPool();
$wp->setWorkerPoolSize(4)
   ->create(new SuperClosureWorker());

echo "starting closure 1..\n";
$wp->run(new SerializableWorkerClosure(
	function($input, $semaphore, $storage) {
		sleep(2);
		echo "$input: Hi $input\n";
	},
	1
));

echo "starting closure 2..\n";
$z="hello";
$wp->run(new SerializableWorkerClosure(
	function($input, $semaphore, $storage) use ($z) {
		sleep(2);
		echo "$input: $z $input\n";
	},
	2
));

echo "starting closure 3..\n";
$wp->run(new SerializableWorkerClosure(
	function($input, $semaphore, $storage) use ($z) {
		sleep(2);
		echo "$input: $z ";
		$input*=2;
		echo $input."\n";
	},
	3
));

echo "starting closure 4..\n";
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


