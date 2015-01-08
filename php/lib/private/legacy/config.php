<?php
/**
 * ownCloud
 *
 * @author Frank Karlitschek
 * @author Jakob Sack
 * @copyright 2012 Frank Karlitschek frank@owncloud.org
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

/**
 * This class is responsible for reading and writing config.php, the very basic
 * configuration file of ownCloud.
 */
class OC_Config {

	/** @var \OC\Config */
	public static $object;

	/**
	 * Returns the config instance
	 * @return \OC\Config
	 */
	public static function getObject() {
		return self::$object;
	}

	/**
	 * Lists all available config keys
	 * @return array an array of key names
	 *
	 * This function returns all keys saved in config.php. Please note that it
	 * does not return the values.
	 */
	public static function getKeys() {
		return self::$object->getKeys();
	}

	/**
	 * Gets a value from config.php
	 * @param string $key key
	 * @param mixed $default = null default value
	 * @return mixed the value or $default
	 *
	 * This function gets the value from config.php. If it does not exist,
	 * $default will be returned.
	 */
	public static function getValue($key, $default = null) {
		return self::$object->getValue($key, $default);
	}

	/**
	 * Sets a value
	 * @param string $key key
	 * @param mixed $value value
	 *
	 * This function sets the value and writes the config.php.
	 *
	 */
	public static function setValue($key, $value) {
		self::$object->setValue($key, $value);
	}

	/**
	 * Removes a key from the config
	 * @param string $key key
	 *
	 * This function removes a key from the config.php.
	 */
	public static function deleteKey($key) {
		self::$object->deleteKey($key);
	}
}
