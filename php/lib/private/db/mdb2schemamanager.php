<?php
/**
 * Copyright (c) 2012 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\DB;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

class MDB2SchemaManager {
	/**
	 * @var \OC\DB\Connection $conn
	 */
	protected $conn;

	/**
	 * @param \OC\DB\Connection $conn
	 */
	public function __construct($conn) {
		$this->conn = $conn;
	}

	/**
	 * saves database scheme to xml file
	 * @param string $file name of file
	 * @param int|string $mode
	 * @return bool
	 *
	 * TODO: write more documentation
	 */
	public function getDbStructure($file, $mode = MDB2_SCHEMA_DUMP_STRUCTURE) {
		$sm = $this->conn->getSchemaManager();

		return \OC_DB_MDB2SchemaWriter::saveSchemaToFile($file, $sm);
	}

	/**
	 * Creates tables from XML file
	 * @param string $file file to read structure from
	 * @return bool
	 *
	 * TODO: write more documentation
	 */
	public function createDbFromStructure($file) {
		$schemaReader = new MDB2SchemaReader(\OC_Config::getObject(), $this->conn->getDatabasePlatform());
		$toSchema = $schemaReader->loadSchemaFromFile($file);
		return $this->executeSchemaChange($toSchema);
	}

	/**
	 * @return \OC\DB\Migrator
	 */
	public function getMigrator() {
		$platform = $this->conn->getDatabasePlatform();
		if ($platform instanceof SqlitePlatform) {
			$config = \OC::$server->getConfig();
			return new SQLiteMigrator($this->conn, $config);
		} else if ($platform instanceof OraclePlatform) {
			return new OracleMigrator($this->conn);
		} else if ($platform instanceof MySqlPlatform) {
			return new MySQLMigrator($this->conn);
		} else if ($platform instanceof SQLServerPlatform) {
			return new MsSqlMigrator($this->conn);
		} else if ($platform instanceof PostgreSqlPlatform) {
			return new Migrator($this->conn);
		} else {
			return new NoCheckMigrator($this->conn);
		}
	}

	/**
	 * Reads database schema from file
	 *
	 * @param string $file file to read from
	 */
	private function readSchemaFromFile($file) {
		$platform = $this->conn->getDatabasePlatform();
		$schemaReader = new MDB2SchemaReader(\OC_Config::getObject(), $platform);
		return $schemaReader->loadSchemaFromFile($file);
	}

	/**
	 * update the database scheme
	 * @param string $file file to read structure from
	 * @param bool $generateSql only return the sql needed for the upgrade
	 * @return string|boolean
	 */
	public function updateDbFromStructure($file, $generateSql = false) {
		$toSchema = $this->readSchemaFromFile($file);
		$migrator = $this->getMigrator();

		if ($generateSql) {
			return $migrator->generateChangeScript($toSchema);
		} else {
			$migrator->migrate($toSchema);
			return true;
		}
	}

	/**
	 * update the database scheme
	 * @param string $file file to read structure from
	 * @return string|boolean
	 */
	public function simulateUpdateDbFromStructure($file) {
		$toSchema = $this->readSchemaFromFile($file);
		$this->getMigrator()->checkMigrate($toSchema);
		return true;
	}

	/**
	 * @param \Doctrine\DBAL\Schema\Schema $schema
	 * @return string
	 */
	public function generateChangeScript($schema) {
		$migrator = $this->getMigrator();
		return $migrator->generateChangeScript($schema);
	}

	/**
	 * remove all tables defined in a database structure xml file
	 *
	 * @param string $file the xml file describing the tables
	 */
	public function removeDBStructure($file) {
		$schemaReader = new MDB2SchemaReader(\OC_Config::getObject(), $this->conn->getDatabasePlatform());
		$fromSchema = $schemaReader->loadSchemaFromFile($file);
		$toSchema = clone $fromSchema;
		/** @var $table \Doctrine\DBAL\Schema\Table */
		foreach ($toSchema->getTables() as $table) {
			$toSchema->dropTable($table->getName());
		}
		$comparator = new \Doctrine\DBAL\Schema\Comparator();
		$schemaDiff = $comparator->compare($fromSchema, $toSchema);
		$this->executeSchemaChange($schemaDiff);
	}

	/**
	 * @param \Doctrine\DBAL\Schema\Schema $schema
	 * @return bool
	 */
	private function executeSchemaChange($schema) {
		$this->conn->beginTransaction();
		foreach ($schema->toSql($this->conn->getDatabasePlatform()) as $sql) {
			$this->conn->query($sql);
		}
		$this->conn->commit();

		if ($this->conn->getDatabasePlatform() instanceof SqlitePlatform) {
			\OC_DB::reconnect();
		}
		return true;
	}
}
