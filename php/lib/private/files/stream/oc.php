<?php
/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Files\Stream;

/**
 * a stream wrappers for ownCloud's virtual filesystem
 */
class OC {
	/**
	 * @var \OC\Files\View
	 */
	static private $rootView;

	private $path;

	/**
	 * @var resource
	 */
	private $dirSource;

	/**
	 * @var resource
	 */
	private $fileSource;
	private $meta;

	private function setup(){
		if (!self::$rootView) {
			self::$rootView = new \OC\Files\View('');
		}
	}

	public function stream_open($path, $mode, $options, &$opened_path) {
		$this->setup();
		$path = substr($path, strlen('oc://'));
		$this->path = $path;
		$this->fileSource = self::$rootView->fopen($path, $mode);
		if (is_resource($this->fileSource)) {
			$this->meta = stream_get_meta_data($this->fileSource);
		}
		return is_resource($this->fileSource);
	}

	public function stream_seek($offset, $whence = SEEK_SET) {
		return fseek($this->fileSource, $offset, $whence) === 0;
	}

	public function stream_tell() {
		return ftell($this->fileSource);
	}

	public function stream_read($count) {
		return fread($this->fileSource, $count);
	}

	public function stream_write($data) {
		return fwrite($this->fileSource, $data);
	}

	public function stream_set_option($option, $arg1, $arg2) {
		switch ($option) {
			case STREAM_OPTION_BLOCKING:
				stream_set_blocking($this->fileSource, $arg1);
				break;
			case STREAM_OPTION_READ_TIMEOUT:
				stream_set_timeout($this->fileSource, $arg1, $arg2);
				break;
			case STREAM_OPTION_WRITE_BUFFER:
				stream_set_write_buffer($this->fileSource, $arg1, $arg2);
		}
	}

	public function stream_stat() {
		return fstat($this->fileSource);
	}

	public function stream_lock($mode) {
		flock($this->fileSource, $mode);
	}

	public function stream_flush() {
		return fflush($this->fileSource);
	}

	public function stream_eof() {
		return feof($this->fileSource);
	}

	public function url_stat($path) {
		$this->setup();
		$path = substr($path, strlen('oc://'));
		if (self::$rootView->file_exists($path)) {
			return self::$rootView->stat($path);
		} else {
			return false;
		}
	}

	public function stream_close() {
		fclose($this->fileSource);
	}

	public function unlink($path) {
		$this->setup();
		$path = substr($path, strlen('oc://'));
		return self::$rootView->unlink($path);
	}

	public function dir_opendir($path, $options) {
		$this->setup();
		$path = substr($path, strlen('oc://'));
		$this->path = $path;
		$this->dirSource = self::$rootView->opendir($path);
		if (is_resource($this->dirSource)) {
			$this->meta = stream_get_meta_data($this->dirSource);
		}
		return is_resource($this->dirSource);
	}

	public function dir_readdir() {
		return readdir($this->dirSource);
	}

	public function dir_closedir() {
		closedir($this->dirSource);
	}

	public function dir_rewinddir() {
		rewinddir($this->dirSource);
	}
}
