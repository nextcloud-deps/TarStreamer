<?php

namespace Testnamespace;

use \DeepDiver1975\TarStreamer\TarStreamer;

include '../src/tarstreamer.php';

// We will use this directory tree for streaming
$basePath = realpath(__DIR__ . '/testdata/');
$objects = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS), 
			\RecursiveIteratorIterator::SELF_FIRST
	);


// Usecase: stream to stdout
$tarStdoutStreamer = new TarStreamer();

// Send headers for browser to be aware that it needs to download the content
$tarStdoutStreamer->sendHeaders('testdir.tar');

// Iterate though the directory tree
foreach($objects as $path => $object){

	// Find a relative path inside the package
	$internalPath = substr($path, strlen($basePath));
	
	if (is_file($path)) {
		// Path a file descriptor, relative path and file size
		$fh = fopen($path, 'r');
		$tarStdoutStreamer->addFileFromStream($fh, $internalPath, filesize($path));
		fclose($fh);
	} elseif(is_dir($path)) {
		// Just a path
		$tarStdoutStreamer->addEmptyDir($internalPath);
	}
}

// Send the end marker
$tarStdoutStreamer->finalize();
// And that's it 


// Another Usecase: Stream into file
$newTarFileDescriptor = fopen('test.tar', 'w+');

// Passing a descriptor to a brand new object
$tarFileStreamer = new TarStreamer(['outstream' => $newTarFileDescriptor]);

// Iterate though the directory tree
foreach($objects as $path => $object){
	// Just for the sake of debugging
	echo "adding $path\n";
	
	// Find a relative path inside the package
	$internalPath = substr($path, strlen($basePath));
	
	if (is_file($path)) {
		$fh = fopen($path, 'r');
		$tarFileStreamer->addFileFromStream($fh, $internalPath, filesize($path));
		fclose($fh);
	} elseif(is_dir($path)) {
		$tarFileStreamer->addEmptyDir($internalPath);
	}
}

// Send the end marker
$tarFileStreamer->finalize();

// Close output file
fclose($newTarFileDescriptor);
