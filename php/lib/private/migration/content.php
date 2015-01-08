<?php
/**
 * ownCloud
 *
 * @author Tom Needham
 * @copyright 2012 Tom Needham tom@owncloud.com
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


/**
 * provides methods to add and access data from the migration
 */
class OC_Migration_Content{

	private $zip=false;
	// Holds the database object
	private $db=null;
	// Holds an array of tmpfiles to delete after zip creation
	private $tmpfiles=array();

	/**
	* sets up the
	* @param ZipArchive $zip ZipArchive object
	* @param object $db a database object (required for exporttype user)
	* @return bool|null
	*/
	public function __construct( $zip, $db=null ) {

		$this->zip = $zip;
		$this->db = $db;

	}

	/**
	 * prepares the db
	 * @param string $query the sql query to prepare
	 */
	public function prepare( $query ) {

		// Only add database to tmpfiles if actually used
		if( !is_null( $this->db ) ) {
			// Get db path
			$db = $this->db->getDatabase();
			if(!in_array($db, $this->tmpfiles)) {
				$this->tmpfiles[] = $db;
			}
		}

		// Optimize the query
		$query = $this->processQuery( $query );

		// Optimize the query
		$query = $this->db->prepare( $query );
		$query = new OC_DB_StatementWrapper($query, false);

		return $query;
	}

	/**
	* processes the db query
	* @param string $query the query to process
	* @return string of processed query
	*/
	private function processQuery( $query ) {
		$query = str_replace( '`', '\'', $query );
		$query = str_replace( 'NOW()', 'datetime(\'now\')', $query );
		$query = str_replace( 'now()', 'datetime(\'now\')', $query );
		// remove table prefixes
		$query = str_replace( '*PREFIX*', '', $query );
		return $query;
	}

	/**
	* copys rows to migration.db from the main database
	* @param array $options array of options.
	* @return bool
	*/
	public function copyRows( $options ) {
		if( !array_key_exists( 'table', $options ) ) {
			return false;
		}

		$return = array();

		// Need to include 'where' in the query?
		if( array_key_exists( 'matchval', $options ) && array_key_exists( 'matchcol', $options ) ) {

			// If only one matchval, create an array
			if(!is_array($options['matchval'])) {
				$options['matchval'] = array( $options['matchval'] );
			}

			foreach( $options['matchval'] as $matchval ) {
				// Run the query for this match value (where x = y value)
				$sql = 'SELECT * FROM `*PREFIX*' . $options['table'] . '` WHERE `' . $options['matchcol'] . '` = ?';
				$query = OC_DB::prepare( $sql );
				$results = $query->execute( array( $matchval ) );
				$newreturns = $this->insertData( $results, $options );
				$return = array_merge( $return, $newreturns );
			}

		} else {
			// Just get everything
			$sql = 'SELECT * FROM `*PREFIX*' . $options['table'] . '`';
			$query = OC_DB::prepare( $sql );
			$results = $query->execute();
			$return = $this->insertData( $results, $options );

		}

		return $return;

	}

	/**
	* saves a sql data set into migration.db
	* @param OC_DB_StatementWrapper $data a sql data set returned from self::prepare()->query()
	* @param array $options array of copyRows options
	* @return void
	*/
	private function insertData( $data, $options ) {
		$return = array();
		// Foreach row of data to insert
		while( $row = $data->fetchRow() ) {
			// Now save all this to the migration.db
			foreach($row as $field=>$value) {
				$fields[] = $field;
				$values[] = $value;
			}

			// Generate some sql
			$sql = "INSERT INTO `" . $options['table'] . '` ( `';
			$fieldssql = implode( '`, `', $fields );
			$sql .= $fieldssql . "` ) VALUES( ";
			$valuessql = substr( str_repeat( '?, ', count( $fields ) ), 0, -2 );
			$sql .= $valuessql . " )";
			// Make the query
			$query = $this->prepare( $sql );
			$query->execute( $values );
			// Do we need to return some values?
			if( array_key_exists( 'idcol', $options ) ) {
				// Yes we do
				$return[] = $row[$options['idcol']];
			} else {
				// Take a guess and return the first field :)
				$return[] = reset($row);
			}
			$fields = '';
			$values = '';
		}
		return $return;
	}

	/**
	* adds a directory to the zip object
	* @param boolean|string $dir string path of the directory to add
	* @param bool $recursive
	* @param string $internaldir path of folder to add dir to in zip
	* @return bool
	*/
	public function addDir( $dir, $recursive=true, $internaldir='' ) {
		$dirname = basename($dir);
		$this->zip->addEmptyDir($internaldir . $dirname);
		$internaldir.=$dirname.='/';
		if( !file_exists( $dir ) ) {
			return false;
		}
		$dirhandle = opendir($dir);
		if(is_resource($dirhandle)) {
			while (false !== ( $file = readdir($dirhandle))) {

				if (( $file != '.' ) && ( $file != '..' )) {

					if (is_dir($dir . '/' . $file) && $recursive) {
						$this->addDir($dir . '/' . $file, $recursive, $internaldir);
					} elseif (is_file($dir . '/' . $file)) {
						$this->zip->addFile($dir . '/' . $file, $internaldir . $file);
					}
				}
			}
			closedir($dirhandle);
		} else {
			OC_Log::write('admin_export', "Was not able to open directory: " . $dir, OC_Log::ERROR);
			return false;
		}
		return true;
	}

	/**
	* adds a file to the zip from a given string
	* @param string $data string of data to add
	* @param string $path the relative path inside of the zip to save the file to
	* @return bool
	*/
	public function addFromString( $data, $path ) {
		// Create a temp file
		$file = tempnam( get_temp_dir(). '/', 'oc_export_tmp_' );
		$this->tmpfiles[] = $file;
		if( !file_put_contents( $file, $data ) ) {
			OC_Log::write( 'migation', 'Failed to save data to a temporary file', OC_Log::ERROR );
			return false;
		}
		// Add file to the zip
		$this->zip->addFile( $file, $path );
		return true;
	}

	/**
	* closes the zip, removes temp files
	* @return bool
	*/
	public function finish() {
		if( !$this->zip->close() ) {
			OC_Log::write( 'migration',
				'Failed to write the zip file with error: '.$this->zip->getStatusString(),
				OC_Log::ERROR );
			return false;
		}
		$this->cleanup();
		return true;
	}

		/**
	* cleans up after the zip
	*/
	private function cleanup() {
		// Delete tmp files
		foreach($this->tmpfiles as $i) {
			unlink( $i );
		}
	}
}
