WorkerPool 
==========

[![Build Status](https://travis-ci.org/qxsch/WorkerPool.svg?branch=master)](https://travis-ci.org/qxsch/WorkerPool)
[![Project Status](http://www.repostatus.org/badges/latest/active.svg)](http://www.repostatus.org/#active)


[![Latest Stable Version](https://poser.pugx.org/qxsch/worker-pool/v/stable.png)](https://packagist.org/packages/qxsch/worker-pool) [![Total Downloads](https://poser.pugx.org/qxsch/worker-pool/downloads.png)](https://packagist.org/packages/qxsch/worker-pool) [![License](https://poser.pugx.org/qxsch/worker-pool/license.png)](https://packagist.org/packages/qxsch/worker-pool)

**Parallel Processing WorkerPool for PHP**

_10K Downloads within 4 Months, thank you very much! We're adding features as anyone requires them._

## Examples


The WorkerPool class provides a very simple interface to pass data to a worker pool and have it processed.
You can at any time fetch the results from the workers. Each worker child can return any value that can be [serialized][serialize].

### A simple example

```php
<?php

$wp=new \QXS\WorkerPool\WorkerPool();
$wp->setWorkerPoolSize(4)
   ->create(new \QXS\WorkerPool\ClosureWorker(
                        /**
                          * @param mixed $input the input from the WorkerPool::run() Method
                          * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to synchronize calls accross all workers
                          * @param \ArrayObject $storage a persistent storage for the current child process
                          */
                        function($input, $semaphore, $storage) {
                                echo "[".getmypid()."]"." hi $input\n";
                                sleep(rand(1,3)); // this is the working load!
                                return $input; // return null here, in case you do not want to pass any data to the parent 
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

```

### A more sophisticated example

```php
<?php

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

```

### Synchronize your workers
In case you need to access shared ressources, you can synchronize your workers.

```php
<?php

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
					// so here we have just one worker at a time
                                        echo "[A][".getmypid()."]"." hi $input\n";
                                $semaphore->synchronizedEnd();
                                // alternative example
                                $semaphore->synchronize(function() use ($input, $storage) {
                                        // this code is being synchronized accross all workers
					// so here we have just one worker at a time
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
```

### Disable semaphore (ability to synchronize workers)

You can disable the semaphore. Some people complained about opening semaphores, that they do not need at all.

```php
<?php

$wp=new \QXS\WorkerPool\WorkerPool();
$wp->setWorkerPoolSize(4)
   ->disableSemaphore()  // <--- this disables the semaphore support (you can still use it in the worker, but it will have no effect)
   ->create(new \QXS\WorkerPool\ClosureWorker(
                        /**
                          * @param mixed $input the input from the WorkerPool::run() Method
                          * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to synchronize calls accross all workers
                          * @param \ArrayObject $storage a persistent storage for the current child process
                          */
                        function($input, $semaphore, $storage) {
                                echo "[".getmypid()."]"." hi $input\n";
                                sleep(rand(1,3)); // this is the working load!

                                // Simulate unexpected worker death
                                if (rand(1, 10) > 5) exit;

                                return $input; // return null here, in case you do not want to pass any data to the parent 
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

```


### Automatic respawn

You can choose to automatically respawn dead workers.

```php
<?php

$wp=new \QXS\WorkerPool\WorkerPool();
$wp->setWorkerPoolSize(4)
   ->respawnAutomatically()
   ->create(new \QXS\WorkerPool\ClosureWorker(
                        /**
                          * @param mixed $input the input from the WorkerPool::run() Method
                          * @param \QXS\WorkerPool\Semaphore $semaphore the semaphore to synchronize calls accross all workers
                          * @param \ArrayObject $storage a persistent storage for the current child process
                          */
                        function($input, $semaphore, $storage) {
                                echo "[".getmypid()."]"." hi $input\n";
                                sleep(rand(1,3)); // this is the working load!

                                // Simulate unexpected worker death
                                if (rand(1, 10) > 5) exit;

                                return $input; // return null here, in case you do not want to pass any data to the parent 
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

```

Each time a worker dies, a new one will be created with an incremented index.

You should avoid the situation where a worker dies but the respawn capability can be a useful workaround until you fix the situation.


### Transparent output to ps

See what's happening when running a PS:

```
root   2378   \_ simpleExample.php: Parent
root   2379       \_ simpleExample.php: Worker 1 of QXS\WorkerPool\ClosureWorker [busy]
root   2380       \_ simpleExample.php: Worker 2 of QXS\WorkerPool\ClosureWorker [busy]
root   2381       \_ simpleExample.php: Worker 3 of QXS\WorkerPool\ClosureWorker [free]
root   2382       \_ simpleExample.php: Worker 4 of QXS\WorkerPool\ClosureWorker [free]
```

### Documentation

The documentation can be found here http://qxsch.github.io/WorkerPool/doc/

  [serialize]: http://php.net/serialize
