Install the required libs
```bash
yum install php-process php-pcntl
yum install php-pear php-devel
```
Finally install the WorkerPool using composer:
```bash
./composer.phar require "qxsch/worker-pool" '*'
```
