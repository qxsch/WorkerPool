<?php
/**
 * This is a php implementation of http://msdn.microsoft.com/de-de/library/dd460705%28v=vs.110%29.aspx
 */

namespace ParallelTasks;

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



function GetCountForWord(array $words, $term) {
	$count=0;
	foreach($words as $word) {
		if($word==$term) {
			$count++;
		}
	}
	echo sprintf("Task Count For Word -- The word %s occurs %d times.\n", $term, $count);
}


function GetMostCommonWords(array $words) {
	$frequencyOrder=array();
	foreach($words as $word) {
		if(strlen($word) > 6) {
			@$frequencyOrder[$word]++;
		}
	}

	asort($frequencyOrder);

	$commonWords=array();
	$num=0;
	foreach(array_reverse($frequencyOrder) as $word => $frequency) {
		if($num >= 10) {
			break;
		}
		$commonWords[]=$word;
		$num++;
	}
	
	echo sprintf("Task Most Common Words -- The most common words are:\n\t%s\n", implode("\n\t", $commonWords));
}


function GetLongestWord(array $words) {
	$longestWord = '';
	foreach($words as $word) {
		if(strlen($word) > strlen($longestWord)) {
			$longestWord=$word;
		}
	}

	echo sprintf("Task Longest Word -- The longest word is %s\n", $longestWord);
	return $longestWord;
}

// An http request performed synchronously for simplicity.
function CreateWordArray($uri) {
	echo sprintf("Retrieving from %s\n", $uri);
	// Download a web page the easy way.
	$s = file_get_contents($uri);
	//Separate string into an array of words, removing some common punctuation.
	//return preg_split('@(\s+|[,.;:-_/])@', $s, PREG_SPLIT_NO_EMPTY);
	$words=array();
	foreach(preg_split('@(\s+|[,.;:_/)(-])@', $s) as $word) {
		if($word!='') {
			$words[]=$word;
		}
	}
	return $words;
}



    /* Output (May vary on each execution):
        Retrieving from http://www.gutenberg.org/dirs/etext99/otoos610.txt
        Response stream received.
        Begin first task...
        Begin second task...
        Task 2 -- The most common words are:
          species
          selection
          varieties
          natural
          animals
          between
          different
          distinct
          several
          conditions

        Begin third task...
        Task 1 -- The longest word is characteristically
        Task 3 -- The word "species" occurs 1927 times.
        Returned from Parallel.Invoke
        Press any key to exit  
     */

$wp=new WorkerPool();
$wp->setWorkerPoolSize(4)
   ->create(new SuperClosureWorker());


// Retrieve Darwin's "Origin of the Species" from Gutenberg.org.
$words = CreateWordArray('darwin.txt');
//$words = CreateWordArray('http://www.gutenberg.org/files/2009/2009.txt');

$wp->run(new SerializableWorkerClosure(
	function($words, $semaphore, $storage) {
		echo "Begin Task Count Words\n";
		echo sprintf("Task Count Words -- We have %d words\n", count($words));
	},
	$words
));

$wp->run(new SerializableWorkerClosure(
	function($words, $semaphore, $storage) {
		echo "Begin Task Longest Word\n";
		\ParallelTasks\GetLongestWord($words);
	},
	$words
));

$wp->run(new SerializableWorkerClosure(
	function($words, $semaphore, $storage) {
		echo "Begin Task Most Common Words\n";
                \ParallelTasks\GetMostCommonWords($words);
	},
	$words
));

$wp->run(new SerializableWorkerClosure(
	function($words, $semaphore, $storage) {
		echo "Begin Task Count For Word\n";
		\ParallelTasks\GetCountForWord($words, "species");
	},
	$words
));

