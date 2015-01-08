<?php
/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Files\Cache;

/**
 * Class Storage
 *
 * cache storage specific data
 *
 * @package OC\Files\Cache
 */
class Storage {
	private $storageId;
	private $numericId;

	/**
	 * @param \OC\Files\Storage\Storage|string $storage
	 */
	public function __construct($storage) {
		if ($storage instanceof \OC\Files\Storage\Storage) {
			$this->storageId = $storage->getId();
		} else {
			$this->storageId = $storage;
		}
		if (strlen($this->storageId) > 64) {
			$this->storageId = md5($this->storageId);
		}

		$sql = 'SELECT `numeric_id` FROM `*PREFIX*storages` WHERE `id` = ?';
		$result = \OC_DB::executeAudited($sql, array($this->storageId));
		if ($row = $result->fetchRow()) {
			$this->numericId = $row['numeric_id'];
		} else {
			$sql = 'INSERT INTO `*PREFIX*storages` (`id`) VALUES(?)';
			\OC_DB::executeAudited($sql, array($this->storageId));
			$this->numericId = \OC_DB::insertid('*PREFIX*storages');
		}
	}

	/**
	 * @return string
	 */
	public function getNumericId() {
		return $this->numericId;
	}

	/**
	 * @return string|null
	 */
	public static function getStorageId($numericId) {

		$sql = 'SELECT `id` FROM `*PREFIX*storages` WHERE `numeric_id` = ?';
		$result = \OC_DB::executeAudited($sql, array($numericId));
		if ($row = $result->fetchRow()) {
			return $row['id'];
		} else {
			return null;
		}
	}

	/**
	 * @return string|null
	 */
	public static function getNumericStorageId($storageId) {
		if (strlen($storageId) > 64) {
			$storageId = md5($storageId);
		}

		$sql = 'SELECT `numeric_id` FROM `*PREFIX*storages` WHERE `id` = ?';
		$result = \OC_DB::executeAudited($sql, array($storageId));
		if ($row = $result->fetchRow()) {
			return $row['numeric_id'];
		} else {
			return null;
		}
	}

	/**
	 * @param string $storageId
	 * @return bool
	 */
	public static function exists($storageId) {
		return !is_null(self::getNumericStorageId($storageId));
	}

	/**
	 * remove the entry for the storage
	 *
	 * @param string $storageId
	 */
	public static function remove($storageId) {
		if (strlen($storageId) > 64) {
			$storageId = md5($storageId);
		}
		$numericId = self::getNumericStorageId($storageId);
		$sql = 'DELETE FROM `*PREFIX*storages` WHERE `id` = ?';
		\OC_DB::executeAudited($sql, array($storageId));

		if (!is_null($numericId)) {
			$sql = 'DELETE FROM `*PREFIX*filecache` WHERE `storage` = ?';
			\OC_DB::executeAudited($sql, array($numericId));
		}
	}
}
