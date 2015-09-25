<?php

namespace ownCloud\TarStreamer\Tests;

use Archive_Tar;
use ownCloud\TarStreamer\TarStreamer;
use PHPUnit_Framework_TestCase;

class Streamer extends PHPUnit_Framework_TestCase
{
	/** @var string */
	private $archive;

	/** @var TarStreamer */
	private $streamer;

	public function setUp() {
		$this->archive = tempnam('/tmp' , 'tar');
		$this->streamer = new TarStreamer(
			['outstream' => fopen($this->archive, 'w')]
		);
	}

	/**
	 * @dataProvider providesNameAndData
	 * @param $fileName
	 * @param $data
	 */
	public function testSimpleFile($fileName, $data) {
		$dataStream = fopen('data://text/plain,'.$data, 'r');
		$this->streamer->addFileFromStream($dataStream, $fileName, 10);
		$this->streamer->finalize();

		$this->assertTar($fileName, $data);
	}

	public function providesNameAndData() {
		return [
			['foo.bar', '1234567890'],
//			['foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234.txt', 'abcdefgh']
		];
	}

	private function assertTar($file, $data)
	{
		$arc = new Archive_Tar($this->archive);
		$content = $arc->extractInString($file);
		$this->assertEquals($data, $content);
	}
}
