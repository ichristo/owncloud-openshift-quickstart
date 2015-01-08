<?php
/**
 * @author Jörn Friedrich Dreyer
 * @copyright (c) 2014 Jörn Friedrich Dreyer <jfd@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Files\ObjectStore;

use OC\Files\Filesystem;
use OCP\Files\ObjectStore\IObjectStore;

class ObjectStoreStorage extends \OC\Files\Storage\Common {

	/**
	 * @var array
	 */
	private static $tmpFiles = array();
	/**
	 * @var \OCP\Files\ObjectStore\IObjectStore $objectStore
	 */
	protected $objectStore;
	/**
	 * @var \OC\User\User $user
	 */
	protected $user;

	public function __construct($params) {
		if (isset($params['objectstore']) && $params['objectstore'] instanceof IObjectStore) {
			$this->objectStore = $params['objectstore'];
		} else {
			throw new \Exception('missing IObjectStore instance');
		}
		if (isset($params['storageid'])) {
			$this->id = 'object::store:' . $params['storageid'];
		} else {
			$this->id = 'object::store:' . $this->objectStore->getStorageId();
		}
		//initialize cache with root directory in cache
		if (!$this->is_dir('/')) {
			$this->mkdir('/');
		}
	}

	public function mkdir($path) {
		$path = $this->normalizePath($path);

		if ($this->is_dir($path)) {
			return false;
		}

		$dirName = $this->normalizePath(dirname($path));
		$parentExists = $this->is_dir($dirName);

		$mTime = time();

		$data = array(
			'mimetype' => 'httpd/unix-directory',
			'size' => 0,
			'mtime' => $mTime,
			'storage_mtime' => $mTime,
			'permissions' => \OCP\PERMISSION_ALL,
		);

		if ($dirName === '' && !$parentExists) {
			//create root on the fly
			$data['etag'] = $this->getETag('');
			$this->getCache()->put('', $data);
			$parentExists = true;

			// we are done when the root folder was meant to be created
			if ($dirName === $path) {
				return true;
			}
		}

		if ($parentExists) {
			$data['etag'] = $this->getETag($path);
			$this->getCache()->put($path, $data);
			return true;
		}
		return false;
	}

	/**
	 * @param string $path
	 * @return string
	 */
	private function normalizePath($path) {
		$path = trim($path, '/');
		//FIXME why do we sometimes get a path like 'files//username'?
		$path = str_replace('//', '/', $path);

		// dirname('/folder') returns '.' but internally (in the cache) we store the root as ''
		if (!$path || $path === '.') {
			$path = '';
		}

		return $path;
	}

	/**
	 * Object Stores use a NoopScanner because metadata is directly stored in
	 * the file cache and cannot really scan the filesystem. The storage passed in is not used anywhere.
	 *
	 * @param string $path
	 * @param \OC\Files\Storage\Storage (optional) the storage to pass to the scanner
	 * @return \OC\Files\ObjectStore\NoopScanner
	 */
	public function getScanner($path = '', $storage = null) {
		if (!$storage) {
			$storage = $this;
		}
		if (!isset($this->scanner)) {
			$this->scanner = new NoopScanner($storage);
		}
		return $this->scanner;
	}

	public function getId() {
		return $this->id;
	}

	public function rmdir($path) {
		$path = $this->normalizePath($path);

		if (!$this->is_dir($path)) {
			return false;
		}

		$this->rmObjects($path);

		$this->getCache()->remove($path);

		return true;
	}

	private function rmObjects($path) {
		$children = $this->getCache()->getFolderContents($path);
		foreach ($children as $child) {
			if ($child['mimetype'] === 'httpd/unix-directory') {
				$this->rmObjects($child['path']);
			} else {
				$this->unlink($child['path']);
			}
		}
	}

	public function unlink($path) {
		$path = $this->normalizePath($path);
		$stat = $this->stat($path);

		if ($stat && isset($stat['fileid'])) {
			if ($stat['mimetype'] === 'httpd/unix-directory') {
				return $this->rmdir($path);
			}
			try {
				$this->objectStore->deleteObject($this->getURN($stat['fileid']));
			} catch (\Exception $ex) {
				if ($ex->getCode() !== 404) {
					\OCP\Util::writeLog('objectstore', 'Could not delete object: ' . $ex->getMessage(), \OCP\Util::ERROR);
					return false;
				} else {
					//removing from cache is ok as it does not exist in the objectstore anyway
				}
			}
			$this->getCache()->remove($path);
			return true;
		}
		return false;
	}

	public function stat($path) {
		$path = $this->normalizePath($path);
		return $this->getCache()->get($path);
	}

	/**
	 * Override this method if you need a different unique resource identifier for your object storage implementation.
	 * The default implementations just appends the fileId to 'urn:oid:'. Make sure the URN is unique over all users.
	 * You may need a mapping table to store your URN if it cannot be generated from the fileid.
	 *
	 * @param int $fileId the fileid
	 * @return null|string the unified resource name used to identify the object
	 */
	protected function getURN($fileId) {
		if (is_numeric($fileId)) {
			return 'urn:oid:' . $fileId;
		}
		return null;
	}

	public function opendir($path) {
		$path = $this->normalizePath($path);

		try {
			$files = array();
			$folderContents = $this->getCache()->getFolderContents($path);
			foreach ($folderContents as $file) {
				$files[] = $file['name'];
			}

			\OC\Files\Stream\Dir::register('objectstore' . $path . '/', $files);

			return opendir('fakedir://objectstore' . $path . '/');
		} catch (Exception $e) {
			\OCP\Util::writeLog('objectstore', $e->getMessage(), \OCP\Util::ERROR);
			return false;
		}
	}

	public function filetype($path) {
		$path = $this->normalizePath($path);
		$stat = $this->stat($path);
		if ($stat) {
			if ($stat['mimetype'] === 'httpd/unix-directory') {
				return 'dir';
			}
			return 'file';
		} else {
			return false;
		}
	}

	public function fopen($path, $mode) {
		$path = $this->normalizePath($path);

		switch ($mode) {
			case 'r':
			case 'rb':
				$stat = $this->stat($path);
				if (is_array($stat)) {
					try {
						return $this->objectStore->readObject($this->getURN($stat['fileid']));
					} catch (\Exception $ex) {
						\OCP\Util::writeLog('objectstore', 'Could not get object: ' . $ex->getMessage(), \OCP\Util::ERROR);
						return false;
					}
				} else {
					return false;
				}
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				if (strrpos($path, '.') !== false) {
					$ext = substr($path, strrpos($path, '.'));
				} else {
					$ext = '';
				}
				$tmpFile = \OC_Helper::tmpFile($ext);
				\OC\Files\Stream\Close::registerCallback($tmpFile, array($this, 'writeBack'));
				if ($this->file_exists($path)) {
					$source = $this->fopen($path, 'r');
					file_put_contents($tmpFile, $source);
				}
				self::$tmpFiles[$tmpFile] = $path;

				return fopen('close://' . $tmpFile, $mode);
		}
		return false;
	}

	public function file_exists($path) {
		$path = $this->normalizePath($path);
		return (bool)$this->stat($path);
	}

	public function rename($source, $target) {
		$source = $this->normalizePath($source);
		$target = $this->normalizePath($target);
		$this->remove($target);
		$this->getCache()->move($source, $target);
		$this->touch(dirname($target));
		return true;
	}

	public function getMimeType($path) {
		$path = $this->normalizePath($path);
		$stat = $this->stat($path);
		if (is_array($stat)) {
			return $stat['mimetype'];
		} else {
			return false;
		}
	}

	public function touch($path, $mtime = null) {
		if (is_null($mtime)) {
			$mtime = time();
		}

		$path = $this->normalizePath($path);
		$dirName = dirname($path);
		$parentExists = $this->is_dir($dirName);
		if (!$parentExists) {
			return false;
		}

		$stat = $this->stat($path);
		if (is_array($stat)) {
			// update existing mtime in db
			$stat['mtime'] = $mtime;
			$this->getCache()->update($stat['fileid'], $stat);
		} else {
			$mimeType = \OC_Helper::getFileNameMimeType($path);
			// create new file
			$stat = array(
				'etag' => $this->getETag($path),
				'mimetype' => $mimeType,
				'size' => 0,
				'mtime' => $mtime,
				'storage_mtime' => $mtime,
				'permissions' => \OCP\PERMISSION_ALL,
			);
			$fileId = $this->getCache()->put($path, $stat);
			try {
				//read an empty file from memory
				$this->objectStore->writeObject($this->getURN($fileId), fopen('php://memory', 'r'));
			} catch (\Exception $ex) {
				$this->getCache()->remove($path);
				\OCP\Util::writeLog('objectstore', 'Could not create object: ' . $ex->getMessage(), \OCP\Util::ERROR);
				return false;
			}
		}
		return true;
	}

	public function writeBack($tmpFile) {
		if (!isset(self::$tmpFiles[$tmpFile])) {
			return;
		}

		$path = self::$tmpFiles[$tmpFile];
		$stat = $this->stat($path);
		if (empty($stat)) {
			// create new file
			$stat = array(
				'permissions' => \OCP\PERMISSION_ALL,
			);
		}
		// update stat with new data
		$mTime = time();
		$stat['size'] = filesize($tmpFile);
		$stat['mtime'] = $mTime;
		$stat['storage_mtime'] = $mTime;
		$stat['mimetype'] = \OC_Helper::getMimeType($tmpFile);
		$stat['etag'] = $this->getETag($path);

		$fileId = $this->getCache()->put($path, $stat);
		try {
			//upload to object storage
			$this->objectStore->writeObject($this->getURN($fileId), fopen($tmpFile, 'r'));
		} catch (\Exception $ex) {
			$this->getCache()->remove($path);
			\OCP\Util::writeLog('objectstore', 'Could not create object: ' . $ex->getMessage(), \OCP\Util::ERROR);
			throw $ex; // make this bubble up
		}
	}

	/**
	 * external changes are not supported, exclusive access to the object storage is assumed
	 *
	 * @param string $path
	 * @param int $time
	 * @return false
	 */
	public function hasUpdated($path, $time) {
		return false;
	}
}
