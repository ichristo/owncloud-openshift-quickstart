<?php
/**
 * Copyright (c) 2013 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\DB;

class OracleConnection extends Connection {
	/**
	 * Quote the keys of the array
	 */
	private function quoteKeys(array $data) {
		$return = array();
		foreach($data as $key => $value) {
			$return[$this->quoteIdentifier($key)] = $value;
		}
		return $return;
	}

	/*
	 * {@inheritDoc}
	 */
	public function insert($tableName, array $data, array $types = array()) {
		$tableName = $this->quoteIdentifier($tableName);
		$data = $this->quoteKeys($data);
		return parent::insert($tableName, $data, $types);
	}

	/*
	 * {@inheritDoc}
	 */
	public function update($tableName, array $data, array $identifier, array $types = array()) {
		$tableName = $this->quoteIdentifier($tableName);
		$data = $this->quoteKeys($data);
		$identifier = $this->quoteKeys($identifier);
		return parent::update($tableName, $data, $identifier, $types);
	}

	/*
	 * {@inheritDoc}
	 */
	public function delete($tableName, array $identifier) {
		$tableName = $this->quoteIdentifier($tableName);
		$identifier = $this->quoteKeys($identifier);
		return parent::delete($tableName, $identifier);
	}
}
