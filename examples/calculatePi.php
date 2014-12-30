<?php
require_once(__DIR__ . '/../autoload.php');


use QXS\WorkerPool\WorkerPool;
use QXS\WorkerPool\WorkerInterface;
use QXS\WorkerPool\Semaphore;



class PiWorker implements WorkerInterface {
	protected $num_steps;
	protected $step;

	public function __construct($num_steps, $step) {
		$this->num_steps = (int) $num_steps;
		$this->step = (float) $step;

	}

	/**
	 * After the worker has been forked into another process
	 *
	 * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to run synchronized tasks
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function onProcessCreate(Semaphore $semaphore) { }

	/**
	 * Before the worker process is getting destroyed
	 *
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function onProcessDestroy() { }

	/**
	 * run the work
	 *
	 * @param \Serializable $input the data, that the worker should process
	 * @return \Serializable Returns the result
	 * @throws \Exception in case of a processing Error an Exception will be thrown
	 */
	public function run($input) {
		list($part_number, $part_step) = $input;
		$x=0.0;
		$sum=0.0;
		for ($i = $part_number; $i < $this->num_steps; $i += $part_step) {
			$x = ($i + 0.5) * $this->step;
			$sum += 4.0 / (1.0 + $x * $x);
		}
		return $sum;
	}

}




$num_steps = 1000000;
$part_step = 8; // set to workerpool size

$step = 1.0 / $num_steps;

$wp=new WorkerPool();
$wp->setWorkerPoolSize($part_step)
   ->create(new PiWorker($num_steps, $step));

for($i = 0; $i < $part_step; $i++) {
	$wp->run(array($i, $part_step));
}


$wp->waitForAllWorkers(); // wait for all workers

$sum=0;
foreach($wp as $result) {
	$sum+=$result['data'];
}

$pi = $step * $sum;

echo "PI is $pi\n";


