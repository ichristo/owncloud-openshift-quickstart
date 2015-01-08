<?php
/**
 * ownCloud
 *
 * @author Frank Karlitschek
 * @copyright 2012 Frank Karlitschek frank@owncloud.org
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

define('MDB2_SCHEMA_DUMP_STRUCTURE', '1');

class DatabaseException extends Exception {
	private $query;

	//FIXME getQuery seems to be unused, maybe use parent constructor with $message, $code and $previous
	public function __construct($message, $query = null){
		parent::__construct($message);
		$this->query = $query;
	}

	public function getQuery() {
		return $this->query;
	}
}

/**
 * This class manages the access to the database. It basically is a wrapper for
 * Doctrine with some adaptions.
 */
class OC_DB {
	/**
	 * @var \OC\DB\Connection $connection
	 */
	static private $connection; //the preferred connection to use, only Doctrine

	/**
	 * connects to the database
	 * @return boolean|null true if connection can be established or false on error
	 *
	 * Connects to the database as specified in config.php
	 */
	public static function connect() {
		if(self::$connection) {
			return true;
		}

		$type = OC_Config::getValue('dbtype', 'sqlite');
		$factory = new \OC\DB\ConnectionFactory();
		if (!$factory->isValidType($type)) {
			return false;
		}

		$connectionParams = array(
			'user' => OC_Config::getValue('dbuser', ''),
			'password' => OC_Config::getValue('dbpassword', ''),
		);
		$name = OC_Config::getValue('dbname', 'owncloud');

		if ($factory->normalizeType($type) === 'sqlite3') {
			$datadir = OC_Config::getValue("datadirectory", OC::$SERVERROOT.'/data');
			$connectionParams['path'] = $datadir.'/'.$name.'.db';
		} else {
			$host = OC_Config::getValue('dbhost', '');
			if (strpos($host, ':')) {
				// Host variable may carry a port or socket.
				list($host, $portOrSocket) = explode(':', $host, 2);
				if (ctype_digit($portOrSocket)) {
					$connectionParams['port'] = $portOrSocket;
				} else {
					$connectionParams['unix_socket'] = $portOrSocket;
				}
			}
			$connectionParams['host'] = $host;
			$connectionParams['dbname'] = $name;
		}

		$connectionParams['tablePrefix'] = OC_Config::getValue('dbtableprefix', 'oc_');

		//additional driver options, eg. for mysql ssl
		$driverOptions = OC_Config::getValue('dbdriveroptions', null);
		if ($driverOptions) {
			$connectionParams['driverOptions'] = $driverOptions;
		}

		try {
			self::$connection = $factory->getConnection($type, $connectionParams);
		} catch(\Doctrine\DBAL\DBALException $e) {
			OC_Log::write('core', $e->getMessage(), OC_Log::FATAL);
			OC_User::setUserId(null);

			// send http status 503
			header('HTTP/1.1 503 Service Temporarily Unavailable');
			header('Status: 503 Service Temporarily Unavailable');
			OC_Template::printErrorPage('Failed to connect to database');
			die();
		}

		return true;
	}

	/**
	 * The existing database connection is closed and connected again
	 */
	public static function reconnect() {
		if(self::$connection) {
			self::$connection->close();
			self::$connection->connect();
		}
	}

	/**
	 * @return \OC\DB\Connection
	 */
	static public function getConnection() {
		self::connect();
		return self::$connection;
	}

	/**
	 * get MDB2 schema manager
	 *
	 * @return \OC\DB\MDB2SchemaManager
	 */
	private static function getMDB2SchemaManager()
	{
		return new \OC\DB\MDB2SchemaManager(self::getConnection());
	}

	/**
	 * Prepare a SQL query
	 * @param string $query Query string
	 * @param int $limit
	 * @param int $offset
	 * @param bool $isManipulation
	 * @throws DatabaseException
	 * @return OC_DB_StatementWrapper prepared SQL query
	 *
	 * SQL query via Doctrine prepare(), needs to be execute()'d!
	 */
	static public function prepare( $query , $limit = null, $offset = null, $isManipulation = null) {
		self::connect();

		if ($isManipulation === null) {
			//try to guess, so we return the number of rows on manipulations
			$isManipulation = self::isManipulation($query);
		}

		// return the result
		try {
			$result = self::$connection->prepare($query, $limit, $offset);
		} catch (\Doctrine\DBAL\DBALException $e) {
			throw new \DatabaseException($e->getMessage(), $query);
		}
		// differentiate between query and manipulation
		$result = new OC_DB_StatementWrapper($result, $isManipulation);
		return $result;
	}

	/**
	 * tries to guess the type of statement based on the first 10 characters
	 * the current check allows some whitespace but does not work with IF EXISTS or other more complex statements
	 *
	 * @param string $sql
	 * @return bool
	 */
	static public function isManipulation( $sql ) {
		$selectOccurrence = stripos($sql, 'SELECT');
		if ($selectOccurrence !== false && $selectOccurrence < 10) {
			return false;
		}
		$insertOccurrence = stripos($sql, 'INSERT');
		if ($insertOccurrence !== false && $insertOccurrence < 10) {
			return true;
		}
		$updateOccurrence = stripos($sql, 'UPDATE');
		if ($updateOccurrence !== false && $updateOccurrence < 10) {
			return true;
		}
		$deleteOccurrence = stripos($sql, 'DELETE');
		if ($deleteOccurrence !== false && $deleteOccurrence < 10) {
			return true;
		}
		return false;
	}

	/**
	 * execute a prepared statement, on error write log and throw exception
	 * @param mixed $stmt OC_DB_StatementWrapper,
	 *					  an array with 'sql' and optionally 'limit' and 'offset' keys
	 *					.. or a simple sql query string
	 * @param array $parameters
	 * @return OC_DB_StatementWrapper
	 * @throws DatabaseException
	 */
	static public function executeAudited( $stmt, array $parameters = null) {
		if (is_string($stmt)) {
			// convert to an array with 'sql'
			if (stripos($stmt, 'LIMIT') !== false) { //OFFSET requires LIMIT, so we only need to check for LIMIT
				// TODO try to convert LIMIT OFFSET notation to parameters, see fixLimitClauseForMSSQL
				$message = 'LIMIT and OFFSET are forbidden for portability reasons,'
						 . ' pass an array with \'limit\' and \'offset\' instead';
				throw new DatabaseException($message);
			}
			$stmt = array('sql' => $stmt, 'limit' => null, 'offset' => null);
		}
		if (is_array($stmt)) {
			// convert to prepared statement
			if ( ! array_key_exists('sql', $stmt) ) {
				$message = 'statement array must at least contain key \'sql\'';
				throw new DatabaseException($message);
			}
			if ( ! array_key_exists('limit', $stmt) ) {
				$stmt['limit'] = null;
			}
			if ( ! array_key_exists('limit', $stmt) ) {
				$stmt['offset'] = null;
			}
			$stmt = self::prepare($stmt['sql'], $stmt['limit'], $stmt['offset']);
		}
		self::raiseExceptionOnError($stmt, 'Could not prepare statement');
		if ($stmt instanceof OC_DB_StatementWrapper) {
			$result = $stmt->execute($parameters);
			self::raiseExceptionOnError($result, 'Could not execute statement');
		} else {
			if (is_object($stmt)) {
				$message = 'Expected a prepared statement or array got ' . get_class($stmt);
			} else {
				$message = 'Expected a prepared statement or array got ' . gettype($stmt);
			}
			throw new DatabaseException($message);
		}
		return $result;
	}

	/**
	 * gets last value of autoincrement
	 * @param string $table The optional table name (will replace *PREFIX*) and add sequence suffix
	 * @return string id
	 * @throws DatabaseException
	 *
	 * \Doctrine\DBAL\Connection lastInsertId
	 *
	 * Call this method right after the insert command or other functions may
	 * cause trouble!
	 */
	public static function insertid($table=null) {
		self::connect();
		return self::$connection->lastInsertId($table);
	}

	/**
	 * Insert a row if a matching row doesn't exists.
	 * @param string $table The table to insert into in the form '*PREFIX*tableName'
	 * @param array $input An array of fieldname/value pairs
	 * @return boolean number of updated rows
	 */
	public static function insertIfNotExist($table, $input) {
		self::connect();
		return self::$connection->insertIfNotExist($table, $input);
	}

	/**
	 * Start a transaction
	 */
	public static function beginTransaction() {
		self::connect();
		self::$connection->beginTransaction();
	}

	/**
	 * Commit the database changes done during a transaction that is in progress
	 */
	public static function commit() {
		self::connect();
		self::$connection->commit();
	}

	/**
	 * saves database schema to xml file
	 * @param string $file name of file
	 * @param int $mode
	 * @return bool
	 *
	 * TODO: write more documentation
	 */
	public static function getDbStructure( $file, $mode = 0) {
		$schemaManager = self::getMDB2SchemaManager();
		return $schemaManager->getDbStructure($file);
	}

	/**
	 * Creates tables from XML file
	 * @param string $file file to read structure from
	 * @return bool
	 *
	 * TODO: write more documentation
	 */
	public static function createDbFromStructure( $file ) {
		$schemaManager = self::getMDB2SchemaManager();
		$result = $schemaManager->createDbFromStructure($file);
		return $result;
	}

	/**
	 * update the database schema
	 * @param string $file file to read structure from
	 * @throws Exception
	 * @return string|boolean
	 */
	public static function updateDbFromStructure($file) {
		$schemaManager = self::getMDB2SchemaManager();
		try {
			$result = $schemaManager->updateDbFromStructure($file);
		} catch (Exception $e) {
			OC_Log::write('core', 'Failed to update database structure ('.$e.')', OC_Log::FATAL);
			throw $e;
		}
		return $result;
	}

	/**
	 * simulate the database schema update
	 * @param string $file file to read structure from
	 * @throws Exception
	 * @return string|boolean
	 */
	public static function simulateUpdateDbFromStructure($file) {
		$schemaManager = self::getMDB2SchemaManager();
		try {
			$result = $schemaManager->simulateUpdateDbFromStructure($file);
		} catch (Exception $e) {
			OC_Log::write('core', 'Simulated database structure update failed ('.$e.')', OC_Log::FATAL);
			throw $e;
		}
		return $result;
	}

	/**
	 * drop a table - the database prefix will be prepended
	 * @param string $tableName the table to drop
	 */
	public static function dropTable($tableName) {

		$tableName = OC_Config::getValue('dbtableprefix', 'oc_' ) . trim($tableName);

		self::$connection->beginTransaction();

		$platform = self::$connection->getDatabasePlatform();
		$sql = $platform->getDropTableSQL($platform->quoteIdentifier($tableName));

		self::$connection->query($sql);

		self::$connection->commit();
	}

	/**
	 * remove all tables defined in a database structure xml file
	 * @param string $file the xml file describing the tables
	 */
	public static function removeDBStructure($file) {
		$schemaManager = self::getMDB2SchemaManager();
		$schemaManager->removeDBStructure($file);
	}

	/**
	 * check if a result is an error, works with Doctrine
	 * @param mixed $result
	 * @return bool
	 */
	public static function isError($result) {
		//Doctrine returns false on error (and throws an exception)
		return $result === false;
	}
	/**
	 * check if a result is an error and throws an exception, works with \Doctrine\DBAL\DBALException
	 * @param mixed $result
	 * @param string $message
	 * @return void
	 * @throws DatabaseException
	 */
	public static function raiseExceptionOnError($result, $message = null) {
		if(self::isError($result)) {
			if ($message === null) {
				$message = self::getErrorMessage($result);
			} else {
				$message .= ', Root cause:' . self::getErrorMessage($result);
			}
			throw new DatabaseException($message, self::getErrorCode($result));
		}
	}

	public static function getErrorCode($error) {
		$code = self::$connection->errorCode();
		return $code;
	}
	/**
	 * returns the error code and message as a string for logging
	 * works with DoctrineException
	 * @param mixed $error
	 * @return string
	 */
	public static function getErrorMessage($error) {
		if (self::$connection) {
			return self::$connection->getError();
		}
		return '';
	}

	/**
	 * @param bool $enabled
	 */
	static public function enableCaching($enabled) {
		$connection = self::getConnection();
		if ($enabled) {
			$connection->enableQueryStatementCaching();
		} else {
			$connection->disableQueryStatementCaching();
		}
	}

	/**
	 * Checks if a table exists in the database - the database prefix will be prepended
	 *
	 * @param string $table
	 * @return bool
	 * @throws DatabaseException
	 */
	public static function tableExists($table) {

		$table = OC_Config::getValue('dbtableprefix', 'oc_' ) . trim($table);

		$dbType = OC_Config::getValue( 'dbtype', 'sqlite' );
		switch ($dbType) {
			case 'sqlite':
			case 'sqlite3':
				$sql = "SELECT name FROM sqlite_master "
					.  "WHERE type = 'table' AND name = ? "
					.  "UNION ALL SELECT name FROM sqlite_temp_master "
					.  "WHERE type = 'table' AND name = ?";
				$result = \OC_DB::executeAudited($sql, array($table, $table));
				break;
			case 'mysql':
				$sql = 'SHOW TABLES LIKE ?';
				$result = \OC_DB::executeAudited($sql, array($table));
				break;
			case 'pgsql':
				$sql = 'SELECT tablename AS table_name, schemaname AS schema_name '
					.  'FROM pg_tables WHERE schemaname NOT LIKE \'pg_%\' '
					.  'AND schemaname != \'information_schema\' '
					.  'AND tablename = ?';
				$result = \OC_DB::executeAudited($sql, array($table));
				break;
			case 'oci':
				$sql = 'SELECT TABLE_NAME FROM USER_TABLES WHERE TABLE_NAME = ?';
				$result = \OC_DB::executeAudited($sql, array($table));
				break;
			case 'mssql':
				$sql = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?';
				$result = \OC_DB::executeAudited($sql, array($table));
				break;
			default:
				throw new DatabaseException("Unknown database type: $dbType");
		}

		return $result->fetchOne() === $table;
	}
}
