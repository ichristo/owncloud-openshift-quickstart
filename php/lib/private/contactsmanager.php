<?php
/**
 * ownCloud
 *
 * @author Thomas Müller
 * @copyright 2013 Thomas Müller thomas.mueller@tmit.eu
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

namespace OC {

	class ContactsManager implements \OCP\Contacts\IManager {

		/**
		 * This function is used to search and find contacts within the users address books.
		 * In case $pattern is empty all contacts will be returned.
		 *
		 * @param string $pattern which should match within the $searchProperties
		 * @param array $searchProperties defines the properties within the query pattern should match
		 * @param array $options - for future use. One should always have options!
		 * @return array an array of contacts which are arrays of key-value-pairs
		 */
		public function search($pattern, $searchProperties = array(), $options = array()) {
			$this->loadAddressBooks();
			$result = array();
			foreach($this->addressBooks as $addressBook) {
				$r = $addressBook->search($pattern, $searchProperties, $options);
				$contacts = array();
				foreach($r as $c){
					$c['addressbook-key'] = $addressBook->getKey();
					$contacts[] = $c;
				}
				$result = array_merge($result, $contacts);
			}

			return $result;
		}

		/**
		 * This function can be used to delete the contact identified by the given id
		 *
		 * @param object $id the unique identifier to a contact
		 * @param string $addressBookKey identifier of the address book in which the contact shall be deleted
		 * @return bool successful or not
		 */
		public function delete($id, $addressBookKey) {
			$addressBook = $this->getAddressBook($addressBookKey);
			if (!$addressBook) {
				return null;
			}

			if ($addressBook->getPermissions() & \OCP\PERMISSION_DELETE) {
				return null;
			}

			return $addressBook->delete($id);
		}

		/**
		 * This function is used to create a new contact if 'id' is not given or not present.
		 * Otherwise the contact will be updated by replacing the entire data set.
		 *
		 * @param array $properties this array if key-value-pairs defines a contact
		 * @param string $addressBookKey identifier of the address book in which the contact shall be created or updated
		 * @return array representing the contact just created or updated
		 */
		public function createOrUpdate($properties, $addressBookKey) {
			$addressBook = $this->getAddressBook($addressBookKey);
			if (!$addressBook) {
				return null;
			}

			if ($addressBook->getPermissions() & \OCP\PERMISSION_CREATE) {
				return null;
			}

			return $addressBook->createOrUpdate($properties);
		}

		/**
		 * Check if contacts are available (e.g. contacts app enabled)
		 *
		 * @return bool true if enabled, false if not
		 */
		public function isEnabled() {
			return !empty($this->addressBooks) || !empty($this->addressBookLoaders);
		}

		/**
		 * @param \OCP\IAddressBook $addressBook
		 */
		public function registerAddressBook(\OCP\IAddressBook $addressBook) {
			$this->addressBooks[$addressBook->getKey()] = $addressBook;
		}

		/**
		 * @param \OCP\IAddressBook $addressBook
		 */
		public function unregisterAddressBook(\OCP\IAddressBook $addressBook) {
			unset($this->addressBooks[$addressBook->getKey()]);
		}

		/**
		 * @return array
		 */
		public function getAddressBooks() {
			$this->loadAddressBooks();
			$result = array();
			foreach($this->addressBooks as $addressBook) {
				$result[$addressBook->getKey()] = $addressBook->getDisplayName();
			}

			return $result;
		}

		/**
		 * removes all registered address book instances
		 */
		public function clear() {
			$this->addressBooks = array();
			$this->addressBookLoaders = array();
		}

		/**
		 * @var \OCP\IAddressBook[] which holds all registered address books
		 */
		private $addressBooks = array();

		/**
		 * @var \Closure[] to call to load/register address books
		 */
		private $addressBookLoaders = array();

		/**
		 * In order to improve lazy loading a closure can be registered which will be called in case
		 * address books are actually requested
		 *
		 * @param \Closure $callable
		 */
		public function register(\Closure $callable)
		{
			$this->addressBookLoaders[] = $callable;
		}

		/**
		 * Get (and load when needed) the address book for $key
		 *
		 * @param string $addressBookKey
		 * @return \OCP\IAddressBook
		 */
		protected function getAddressBook($addressBookKey)
		{
			$this->loadAddressBooks();
			if (!array_key_exists($addressBookKey, $this->addressBooks)) {
				return null;
			}

			return $this->addressBooks[$addressBookKey];
		}

		/**
		 * Load all address books registered with 'register'
		 */
		protected function loadAddressBooks()
		{
			foreach($this->addressBookLoaders as $callable) {
				$callable($this);
			}
			$this->addressBookLoaders = array();
		}
	}
}
