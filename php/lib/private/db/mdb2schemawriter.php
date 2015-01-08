<?php
/**
 * Copyright (c) 2012 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

class OC_DB_MDB2SchemaWriter {

	/**
	 * @param string $file
	 * @param \Doctrine\DBAL\Schema\AbstractSchemaManager $sm
	 * @return bool
	 */
	static public function saveSchemaToFile($file, $sm) {
		$xml = new SimpleXMLElement('<database/>');
		$xml->addChild('name', OC_Config::getValue( "dbname", "owncloud" ));
		$xml->addChild('create', 'true');
		$xml->addChild('overwrite', 'false');
		$xml->addChild('charset', 'utf8');
		foreach ($sm->listTables() as $table) {
			self::saveTable($table, $xml->addChild('table'));
		}
		file_put_contents($file, $xml->asXML());
		return true;
	}

	/**
	 * @param SimpleXMLElement $xml
	 */
	private static function saveTable($table, $xml) {
		$xml->addChild('name', $table->getName());
		$declaration = $xml->addChild('declaration');
		foreach($table->getColumns() as $column) {
			self::saveColumn($column, $declaration->addChild('field'));
		}
		foreach($table->getIndexes() as $index) {
			if ($index->getName() == 'PRIMARY') {
				$autoincrement = false;
				foreach($index->getColumns() as $column) {
					if ($table->getColumn($column)->getAutoincrement()) {
						$autoincrement = true;
					}
				}
				if ($autoincrement) {
					continue;
				}
			}
			self::saveIndex($index, $declaration->addChild('index'));
		}
	}

	/**
	 * @param SimpleXMLElement $xml
	 */
	private static function saveColumn($column, $xml) {
		$xml->addChild('name', $column->getName());
		switch($column->getType()) {
			case 'SmallInt':
			case 'Integer':
			case 'BigInt':
				$xml->addChild('type', 'integer');
				$default = $column->getDefault();
				if (is_null($default) && $column->getAutoincrement()) {
					$default = '0';
				}
				$xml->addChild('default', $default);
				$xml->addChild('notnull', self::toBool($column->getNotnull()));
				if ($column->getAutoincrement()) {
					$xml->addChild('autoincrement', '1');
				}
				if ($column->getUnsigned()) {
					$xml->addChild('unsigned', 'true');
				}
				$length = '4';
				if ($column->getType() == 'SmallInt') {
					$length = '2';
				}
				elseif ($column->getType() == 'BigInt') {
					$length = '8';
				}
				$xml->addChild('length', $length);
				break;
			case 'String':
				$xml->addChild('type', 'text');
				$default = trim($column->getDefault());
				if ($default === '') {
					$default = false;
				}
				$xml->addChild('default', $default);
				$xml->addChild('notnull', self::toBool($column->getNotnull()));
				$xml->addChild('length', $column->getLength());
				break;
			case 'Text':
				$xml->addChild('type', 'clob');
				$xml->addChild('notnull', self::toBool($column->getNotnull()));
				break;
			case 'Decimal':
				$xml->addChild('type', 'decimal');
				$xml->addChild('default', $column->getDefault());
				$xml->addChild('notnull', self::toBool($column->getNotnull()));
				$xml->addChild('length', '15');
				break;
			case 'Boolean':
				$xml->addChild('type', 'integer');
				$xml->addChild('default', $column->getDefault());
				$xml->addChild('notnull', self::toBool($column->getNotnull()));
				$xml->addChild('length', '1');
				break;
			case 'DateTime':
				$xml->addChild('type', 'timestamp');
				$xml->addChild('default', $column->getDefault());
				$xml->addChild('notnull', self::toBool($column->getNotnull()));
				break;

		}
	}

	/**
	 * @param SimpleXMLElement $xml
	 */
	private static function saveIndex($index, $xml) {
		$xml->addChild('name', $index->getName());
		if ($index->isPrimary()) {
			$xml->addChild('primary', 'true');
		}
		elseif ($index->isUnique()) {
			$xml->addChild('unique', 'true');
		}
		foreach($index->getColumns() as $column) {
			$field = $xml->addChild('field');
			$field->addChild('name', $column);
			$field->addChild('sorting', 'ascending');
			
		}
	}

	private static function toBool($bool) {
		return $bool ? 'true' : 'false';
	}
}
