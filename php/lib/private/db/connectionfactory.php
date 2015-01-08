<?php
/**
 * Copyright (c) 2014 Andreas Fischer <bantu@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\DB;

/**
* Takes care of creating and configuring Doctrine connections.
*/
class ConnectionFactory {
	/**
	* @var array
	*
	* Array mapping DBMS type to default connection parameters passed to
	* \Doctrine\DBAL\DriverManager::getConnection().
	*/
	protected $defaultConnectionParams = array(
		'mssql' => array(
			'adapter' => '\OC\DB\AdapterSQLSrv',
			'charset' => 'UTF8',
			'driver' => 'pdo_sqlsrv',
			'wrapperClass' => 'OC\DB\Connection',
		),
		'mysql' => array(
			'adapter' => '\OC\DB\AdapterMySQL',
			'charset' => 'UTF8',
			'driver' => 'pdo_mysql',
			'wrapperClass' => 'OC\DB\Connection',
		),
		'oci' => array(
			'adapter' => '\OC\DB\AdapterOCI8',
			'charset' => 'AL32UTF8',
			'driver' => 'oci8',
			'wrapperClass' => 'OC\DB\OracleConnection',
		),
		'pgsql' => array(
			'adapter' => '\OC\DB\AdapterPgSql',
			'driver' => 'pdo_pgsql',
			'wrapperClass' => 'OC\DB\Connection',
		),
		'sqlite3' => array(
			'adapter' => '\OC\DB\AdapterSqlite',
			'driver' => 'pdo_sqlite',
			'wrapperClass' => 'OC\DB\Connection',
		),
	);

	/**
	* @brief Get default connection parameters for a given DBMS.
	* @param string $type DBMS type
	* @throws \InvalidArgumentException If $type is invalid
	* @return array Default connection parameters.
	*/
	public function getDefaultConnectionParams($type) {
		$normalizedType = $this->normalizeType($type);
		if (!isset($this->defaultConnectionParams[$normalizedType])) {
			throw new \InvalidArgumentException("Unsupported type: $type");
		}
		$result = $this->defaultConnectionParams[$normalizedType];
		// \PDO::MYSQL_ATTR_FOUND_ROWS may not be defined, e.g. when the MySQL
		// driver is missing. In this case, we won't be able to connect anyway.
		if ($normalizedType === 'mysql' && defined('\PDO::MYSQL_ATTR_FOUND_ROWS')) {
			$result['driverOptions'] = array(
				\PDO::MYSQL_ATTR_FOUND_ROWS => true,
			);
		}
		return $result;
	}

	/**
	* @brief Get default connection parameters for a given DBMS.
	* @param string $type DBMS type
	* @param array $additionalConnectionParams Additional connection parameters
	* @return \OC\DB\Connection
	*/
	public function getConnection($type, $additionalConnectionParams) {
		$normalizedType = $this->normalizeType($type);
		$eventManager = new \Doctrine\Common\EventManager();
		switch ($normalizedType) {
			case 'mysql':
				// Send "SET NAMES utf8". Only required on PHP 5.3 below 5.3.6.
				// See http://stackoverflow.com/questions/4361459/php-pdo-charset-set-names#4361485
				$eventManager->addEventSubscriber(new \Doctrine\DBAL\Event\Listeners\MysqlSessionInit);
				break;
			case 'oci':
				$eventManager->addEventSubscriber(new \Doctrine\DBAL\Event\Listeners\OracleSessionInit);
				break;
			case 'sqlite3':
				$eventManager->addEventSubscriber(new SQLiteSessionInit);
				break;
		}
		$connection = \Doctrine\DBAL\DriverManager::getConnection(
			array_merge($this->getDefaultConnectionParams($type), $additionalConnectionParams),
			new \Doctrine\DBAL\Configuration(),
			$eventManager
		);
		switch ($normalizedType) {
			case 'sqlite3':
				// Sqlite doesn't handle query caching and schema changes
				// TODO: find a better way to handle this
				/** @var $connection \OC\DB\Connection */
				$connection->disableQueryStatementCaching();
				break;
		}
		return $connection;
	}

	/**
	* @brief Normalize DBMS type
	* @param string $type DBMS type
	* @return string Normalized DBMS type
	*/
	public function normalizeType($type) {
		return $type === 'sqlite' ? 'sqlite3' : $type;
	}

	/**
	* @brief Checks whether the specified DBMS type is valid.
	* @return bool
	*/
	public function isValidType($type) {
		$normalizedType = $this->normalizeType($type);
		return isset($this->defaultConnectionParams[$normalizedType]);
	}
}
