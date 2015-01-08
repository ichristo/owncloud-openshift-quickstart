<?php
/**
 * ownCloud
 *
 * @author Thomas Müller
 * @copyright 2012 Thomas Müller thomas.mueller@tmit.eu
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
 * Public interface of ownCloud for apps to use.
 * Contacts Class
 *
 */

// use OCP namespace for all classes that are considered public.
// This means that they should be used by apps instead of the internal ownCloud classes
namespace OCP {

	/**
	 * This class provides access to the contacts app. Use this class exclusively if you want to access contacts.
	 *
	 * Contacts in general will be expressed as an array of key-value-pairs.
	 * The keys will match the property names defined in https://tools.ietf.org/html/rfc2426#section-1
	 *
	 * Proposed workflow for working with contacts:
	 *  - search for the contacts
	 *  - manipulate the results array
	 *  - createOrUpdate will save the given contacts overwriting the existing data
	 *
	 * For updating it is mandatory to keep the id.
	 * Without an id a new contact will be created.
	 *
	 */
	class Contacts {

		/**
		 * This function is used to search and find contacts within the users address books.
		 * In case $pattern is empty all contacts will be returned.
		 *
		 * Example:
		 *  Following function shows how to search for contacts for the name and the email address.
		 *
		 *		public static function getMatchingRecipient($term) {
		 *			// The API is not active -> nothing to do
		 *			if (!\OCP\Contacts::isEnabled()) {
		 *				return array();
		 *			}
		 *
		 *			$result = \OCP\Contacts::search($term, array('FN', 'EMAIL'));
		 *			$receivers = array();
		 *			foreach ($result as $r) {
		 *				$id = $r['id'];
		 *				$fn = $r['FN'];
		 *				$email = $r['EMAIL'];
		 *				if (!is_array($email)) {
		 *					$email = array($email);
		 *				}
		 *
		 *				// loop through all email addresses of this contact
		 *				foreach ($email as $e) {
		 *				$displayName = $fn . " <$e>";
		 *				$receivers[] = array(
		 *					'id'    => $id,
		 *					'label' => $displayName,
		 *					'value' => $displayName);
		 *				}
		 *			}
		 *
		 *			return $receivers;
		 *		}
		 *
		 *
		 * @param string $pattern which should match within the $searchProperties
		 * @param array $searchProperties defines the properties within the query pattern should match
		 * @param array $options - for future use. One should always have options!
		 * @return array an array of contacts which are arrays of key-value-pairs
		 */
		public static function search($pattern, $searchProperties = array(), $options = array()) {
			$cm = \OC::$server->getContactsManager();
			return $cm->search($pattern, $searchProperties, $options);
		}

		/**
		 * This function can be used to delete the contact identified by the given id
		 *
		 * @param object $id the unique identifier to a contact
		 * @param string $address_book_key
		 * @return bool successful or not
		 */
		public static function delete($id, $address_book_key) {
			$cm = \OC::$server->getContactsManager();
			return $cm->delete($id, $address_book_key);
		}

		/**
		 * This function is used to create a new contact if 'id' is not given or not present.
		 * Otherwise the contact will be updated by replacing the entire data set.
		 *
		 * @param array $properties this array if key-value-pairs defines a contact
		 * @param string $address_book_key identifier of the address book in which the contact shall be created or updated
		 * @return array an array representing the contact just created or updated
		 */
		public static function createOrUpdate($properties, $address_book_key) {
			$cm = \OC::$server->getContactsManager();
			return $cm->createOrUpdate($properties, $address_book_key);
		}

		/**
		 * Check if contacts are available (e.g. contacts app enabled)
		 *
		 * @return bool true if enabled, false if not
		 */
		public static function isEnabled() {
			$cm = \OC::$server->getContactsManager();
			return $cm->isEnabled();
		}

		/**
		 * @param \OCP\IAddressBook $address_book
		 */
		public static function registerAddressBook(\OCP\IAddressBook $address_book) {
			$cm = \OC::$server->getContactsManager();
			$cm->registerAddressBook($address_book);
		}

		/**
		 * @param \OCP\IAddressBook $address_book
		 */
		public static function unregisterAddressBook(\OCP\IAddressBook $address_book) {
			$cm = \OC::$server->getContactsManager();
			$cm->unregisterAddressBook($address_book);
		}

		/**
		 * @return array
		 */
		public static function getAddressBooks() {
			$cm = \OC::$server->getContactsManager();
			return $cm->getAddressBooks();
		}

		/**
		 * removes all registered address book instances
		 */
		public static function clear() {
			$cm = \OC::$server->getContactsManager();
			$cm->clear();
		}
	}
}
