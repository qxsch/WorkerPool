Install the required libs
```bash
yum install php-process php-pcntl
yum install php-pear php-devel
```
**Optionally** install proctitle **JUST IN CASE YOUR PHP VERSION IS BELOW 5.5**
```bash
# in case your PHP Version is below 5.5, you can optionally install proctitle
pecl install proctitle
echo 'extension=proctitle.so' > /etc/php.d/proctitle.ini
```
Finally install the WorkerPool using composer:
```bash
./composer.phar require "qxsch/worker-pool" '*'
```
