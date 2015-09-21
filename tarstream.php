<?php

namespace DeepDiver1975;

class TarStreamer
{
	const REGTYPE = 1;
	const DIRTYPE = 5;
	const XHDTYPE = 'x';

	// initialize the options array
	public $opt = array();

	protected $use_container_dir = false;

	protected $container_dir_name = '';

	private $errors = array();

	private $error_log_filename = 'archive_errors.log';

	private $error_header_text = 'The following errors were encountered while generating this archive:';

	/**
	 * Process in 1 MB chunks
	 */
	protected $block_size = 1048576;


	/** @var int */
	private $file_size;

	/**
	 * Create a new ArchiveStream object.
	 *
	 * @param string $name name of output file (optional).
	 * @param array $opt hash of archive options (see archive options in readme)
	 * @access public
	 */
	public function __construct($name = null, $opt = array(), $base_path = null)
	{
		// save options
		$this->opt = $opt;

		// if a $base_path was passed set the protected property with that value, otherwise leave it empty
		$this->container_dir_name = isset($base_path) ? $base_path . '/' : '';

		// set large file defaults: size = 20 megabytes, method = store
		if (!isset($this->opt['large_file_size']))
		{
			$this->opt['large_file_size'] = 20 * 1024 * 1024;
		}

		if (!isset($this->opt['large_files_only']))
		{
			$this->opt['large_files_only'] = false;
		}

		$this->output_name = $name;
		if ($name || isset($opt['send_http_headers']))
		{
			$this->need_headers = true;
		}

		// turn off output buffering
		while (ob_get_level() > 0)
		{
			ob_end_flush();
		}

		$this->opt['content_type'] = 'application/x-tar';
	}

	/**
	 * Explicitly adds a directory to the tar (necessary for empty directories)
	 *
	 * @param  string $name Name (path) of the directory
	 * @param  array  $opt  Additional options to set ("type" will be overridden)
	 * @return void
	 */
	function add_directory($name, $opt = array())
	{
		// calculate header attributes
		$opt['type'] = self::DIRTYPE;

		// send header
		$this->init_file_stream_transfer($name, $size = 0, $opt);

		// complete the file stream
		$this->complete_file_stream();
	}

	/**
	 * Initialize a file stream
	 *
	 * @param string $name file path or just name
	 * @param int $size size in bytes of the file
	 * @param array $opt array containing time / type (optional)
	 * @access public
	 */
	public function init_file_stream_transfer($name, $size, $opt = array())
	{
		// try to detect the type if not provided
		$type = self::REGTYPE;
		if (isset($opt['type']))
		{
			$type = $opt['type'];
		}
		elseif (substr($name, -1) == '/')
		{
			$type = self::DIRTYPE;
		}

		$dirName = dirname($name);
		$name = basename($name);

		$dirName = ($dirName == '.') ? '' : $dirName;
		$name = ($type == self::DIRTYPE) ? $name . '/' : $name;

		// if we're using a container directory, prepend it to the filename
		if ($this->use_container_dir)
		{
			// the container directory will end with a '/' so ensure the filename doesn't start with one
			$dirName = $this->container_dir_name . preg_replace('/^\\/+/', '', $dirName);
		}

		// handle long file names via PAX
		if (strlen($name) > 99 || strlen($dirName) > 154)
		{
			$pax = $this->__pax_generate(array(
				'path' => $dirName . '/' . $name
			));

			$this->init_file_stream_transfer('', strlen($pax), array(
				'type' => self::XHDTYPE
			));

			$this->stream_file_part($pax);
			$this->complete_file_stream();
		}

		// stash the file size for later use
		$this->file_size = $size;

		// process optional arguments
		$time = isset($opt['time']) ? $opt['time'] : time();

		// build data descriptor
		$fields = array(
			array('a100', substr($name, 0, 100)),
			array('a8',   str_pad('777', 7,  '0', STR_PAD_LEFT)),
			array('a8',   decoct(str_pad('0',     7,  '0', STR_PAD_LEFT))),
			array('a8',   decoct(str_pad('0',     7,  '0', STR_PAD_LEFT))),
			array('a12',  decoct(str_pad($size,   11, '0', STR_PAD_LEFT))),
			array('a12',  decoct(str_pad($time,   11, '0', STR_PAD_LEFT))),
			array('a8',   ''),
			array('a1',   $type),
			array('a100', ''),
			array('a6',   'ustar'),
			array('a2',   '00'),
			array('a32',  ''),
			array('a32',  ''),
			array('a8',   ''),
			array('a8',   ''),
			array('a155', substr($dirName, 0, 155)),
			array('a12',  ''),
		);

		// pack fields and calculate "total" length
		$header = $this->pack_fields($fields);

		// Compute header checksum
		$checksum = str_pad(decoct($this->__computeUnsignedChecksum($header)),6,"0",STR_PAD_LEFT);
		for($i=0; $i<6; $i++)
		{
			$header[(148 + $i)] = substr($checksum,$i,1);
		}
		$header[154] = chr(0);
		$header[155] = chr(32);

		// print header
		$this->send($header);
	}

	/**
	 * Create a format string and argument list for pack(), then call pack() and return the result.
	 *
	 * @param array $fields key being the format string and value being the data to pack
	 * @return string binary packed data returned from pack()
	 * @access protected
	 */
	protected function pack_fields( $fields )
	{
		list ($fmt, $args) = array('', array());

		// populate format string and argument list
		foreach ($fields as $field) {
			$fmt .= $field[0];
			$args[] = $field[1];
		}

		// prepend format string to argument list
		array_unshift($args, $fmt);

		// build output string from header and compressed data
		return call_user_func_array('pack', $args);
	}


	/**
	 * Stream the next part of the current file stream.
	 *
	 * @param mixed $data raw data to send
	 * @access public
	 */
	function stream_file_part( $data )
	{
		// send data
		$this->send($data);

		// flush the data to the output
		flush();
	}

	/**
	 * If errors were encountered, add an error log file to the end of the archive
	 */
	public function add_error_log()
	{
		if (!empty($this->errors))
		{
			$msg = $this->error_header_text;
			foreach ($this->errors as $err)
			{
				$msg .= "\r\n\r\n" . $err;
			}

			// stash current value so it can be reset later
			$temp = $this->use_container_dir;

			// set to false to put the error log file in the root instead of the container directory, if we're using one
			$this->use_container_dir = false;

			$this->add_file($this->error_log_filename, $msg);

			// reset to original value and dump the temp variable
			$this->use_container_dir = $temp;
			unset($temp);
		}
	}


	/**
	 * Complete the current file stream
	 *
	 * @access private
	 */
	public function complete_file_stream()
	{
		// ensure we pad the last block so that it is 512 bytes
		if (($mod = ($this->file_size % 512)) > 0)
			$this->send( pack('a' . (512 - $mod) , '') );

		// flush the data to the output
		flush();
	}

	/**
	 * Finish an archive
	 *
	 * @access public
	 */
	public function finish()
	{
		// adds an error log file if we've been tracking errors
		$this->add_error_log();

		// tar requires the end of the file have two 512 byte null blocks
		$this->send( pack('a1024', '') );

		// flush the data to the output
		flush();
	}

	/**
	 * Add file to the archive
	 *
	 * Parameters:
	 *
	 * @param string $name path of file in archive (including directory).
	 * @param string $data contents of file
	 * @param array $opt hash of file options (see above for list)
	 * @access public
	 */
	public function add_file($name, $data, $opt = array())
	{
		// calculate header attributes
		// send file header
		$this->init_file_stream_transfer($name, strlen($data), $opt);

		// send data
		$this->stream_file_part($data);

		// complete the file stream
		$this->complete_file_stream();
	}

	/**
	 * Is this file larger than large_file_size?
	 *
	 * @param string $path path to file on disk
	 * @return bool true if large, false if small
	 * @access protected
	 */
	protected function is_large_file($path)
	{
		$st = stat($path);
		return ($this->opt['large_file_size'] > 0) && ($st['size'] > $this->opt['large_file_size']);
	}

	/**
	 * Add file by path
	 *
	 * @param string $name name of file in archive (including directory path).
	 * @param string $path path to file on disk (note: paths should be encoded using
	 *          UNIX-style forward slashes -- e.g '/path/to/some/file').
	 * @param array $opt hash of file options (see above for list)
	 * @access public
	 */
	public function add_file_from_path($name, $path, $opt = array())
	{
		if ($this->opt['large_files_only'] || $this->is_large_file($path))
		{
			// file is too large to be read into memory; add progressively
			$this->add_large_file($name, $path, $opt);
		}
		else
		{
			// file is small enough to read into memory; read file contents and
			// handle with add_file()
			$data = file_get_contents($path);
			$this->add_file($name, $data, $opt);
		}
	}

	/**
	 * Log an error to be output at the end of the archive
	 *
	 * @param string $message error text to display in log file
	 */
	public function push_error($message)
	{
		$this->errors[] = (string) $message;
	}

	/**
	 * Set whether or not all elements in the archive will be placed within one container directory
	 *
	 * @param bool $bool true to use container directory, false to prevent using one. Defaults to false
	 */
	public function set_use_container_dir($bool = false)
	{
		$this->use_container_dir = (bool) $bool;
	}

	/**
	 * Set the name filename for the error log file when it's added to the archive
	 *
	 * @param string $name the filename for the error log
	 */
	public function set_error_log_filename($name)
	{
		if (isset($name))
		{
			$this->error_log_filename = (string) $name;
		}
	}

	/**
	 * Set the first line of text in the error log file
	 *
	 * @param string $msg the text to display on the first line of the error log file
	 */
	public function set_error_header_text($msg)
	{
		if (isset($msg))
		{
			$this->error_header_text = (string) $msg;
		}
	}

	/**
	 * Send HTTP headers for this stream.
	 *
	 * @access private
	 */
	private function send_http_headers()
	{
		// grab options
		$opt = $this->opt;

		// grab content type from options
		if ( isset($opt['content_type']) )
			$content_type = $opt['content_type'];
		else
			$content_type = 'application/x-zip';

		// grab content type encoding from options and append to the content type option
		if ( isset($opt['content_type_encoding']) )
			$content_type .= '; charset=' . $opt['content_type_encoding'];

		// grab content disposition
		$disposition = 'attachment';
		if ( isset($opt['content_disposition']) )
			$disposition = $opt['content_disposition'];

		if ( $this->output_name )
			$disposition .= "; filename=\"{$this->output_name}\"";

		$headers = array(
			'Content-Type'              => $content_type,
			'Content-Disposition'       => $disposition,
			'Pragma'                    => 'public',
			'Cache-Control'             => 'public, must-revalidate',
			'Content-Transfer-Encoding' => 'binary',
		);

		foreach ( $headers as $key => $val )
			header("$key: $val");
	}

	/**
	 * Send string, sending HTTP headers if necessary.
	 *
	 * @param string $data data to send
	 * @access protected
	 */
	protected function send( $data )
	{
		if ($this->need_headers)
			$this->send_http_headers();
		$this->need_headers = false;

		echo $data;
	}

	/**
	 * Add a large file from the given path
	 *
	 * @param string $name name of file in archive (including directory path).
	 * @param string $path path to file on disk (note: paths should be encoded using
	 *          UNIX-style forward slashes -- e.g '/path/to/some/file').
	 * @param array $opt hash of file options (see above for list)
	 * @access protected
	 */
	protected function add_large_file($name, $path, $opt = array())
	{
		// send file header
		$this->init_file_stream_transfer($name, filesize($path), $opt);

		// open input file
		$fh = fopen($path, 'rb');

		// send file blocks
		while ($data = fread($fh, $this->block_size))
		{
			// send data
			$this->stream_file_part($data);
		}

		// close input file
		fclose($fh);

		// complete the file stream
		$this->complete_file_stream();
	}

	/**
	 * Generate unsigned checksum of header
	 *
	 * @param string $header
	 * @return string unsigned checksum
	 * @access private
	 */
	private function __computeUnsignedChecksum($header)
	{
		$unsigned_checksum = 0;
		for($i=0; $i<512; $i++)
			$unsigned_checksum += ord($header[$i]);
		for($i=0; $i<8; $i++)
			$unsigned_checksum -= ord($header[148 + $i]);
		$unsigned_checksum += ord(" ") * 8;

		return $unsigned_checksum;
	}

	/**
	 * Generate a PAX string
	 *
	 * @param array $fields key value mapping
	 * @return string PAX formated string
	 * @link http://www.freebsd.org/cgi/man.cgi?query=tar&sektion=5&manpath=FreeBSD+8-current tar / PAX spec
	 * @access private
	 */
	private function __pax_generate($fields)
	{
		$lines = '';
		foreach ($fields as $name => $value)
		{
			// build the line and the size
			$line = ' ' . $name . '=' . $value . "\n";
			$size = strlen(strlen($line)) + strlen($line);

			// add the line
			$lines .= $size . $line;
		}

		return $lines;
	}
}
