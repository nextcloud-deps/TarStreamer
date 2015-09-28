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

	public function setUp()
	{
		$this->archive = tempnam('/tmp', 'tar');
		$this->streamer = new TarStreamer(
			['outstream' => fopen($this->archive, 'w')]
		);
	}

	/**
	 * @dataProvider providesNameAndData
	 * @param $fileName
	 * @param $data
	 */
	public function testSimpleFile($fileName, $data)
	{
		$dataStream = fopen('data://text/plain,' . $data, 'r');
		$ret = $this->streamer->addFileFromStream($dataStream, $fileName, 10);
		$this->assertTrue($ret);

		$this->streamer->finalize();

		$this->assertFileInTar($fileName);
	}

	/**
	 * @dataProvider providesNameAndData
	 * @param $fileName
	 * @param $data
	 */
	public function testAddingNoResource($fileName, $data)
	{
		$ret = $this->streamer->addFileFromStream($data, $fileName, 10);
		$this->assertFalse($ret);

		$this->streamer->finalize();

		$this->assertFileNotInTar($fileName);
	}

	public function testDir()
	{
		$folderName = 'foo-folder';
		$this->streamer->addEmptyDir($folderName);

		$this->streamer->finalize();

		$this->assertFolderInTar($folderName);
	}

	public function providesNameAndData()
	{
		return [
			['foo.bar', '1234567890'],
//			['foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234foobar1234.txt', 'abcdefgh']
		];
	}

	private function assertFileInTar($file)
	{
		$elem = $this->getElementFromTar($file);
		$this->assertNotNull($elem);
		$this->assertEquals('0', $elem['typeflag']);
	}

	private function assertFileNotInTar($file)
	{
		$arc = new Archive_Tar($this->archive);
		$content = $arc->extractInString($file);
		$this->assertNull($content);
	}

	private function assertFolderInTar($folderName)
	{
		$elem = $this->getElementFromTar($folderName . '/');
		$this->assertNotNull($elem);
		$this->assertEquals('5', $elem['typeflag']);
	}

	/**
	 * @param $folderName
	 * @param $list
	 * @return array
	 */
	private function getElementFromTar($folderName)
	{
		$arc = new Archive_Tar($this->archive);
		$list = $arc->listContent();
		$elem = array_filter($list, function ($element) use ($folderName) {
			return $element['filename'] == $folderName;
		});
		return isset($elem[0]) ? $elem[0] : null;
	}
}
