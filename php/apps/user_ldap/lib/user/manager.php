<?php

/**
 * ownCloud – LDAP User
 *
 * @author Arthur Schiwon
 * @copyright 2014 Arthur Schiwon blizzz@owncloud.com
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

namespace OCA\user_ldap\lib\user;

use OCA\user_ldap\lib\user\IUserTools;
use OCA\user_ldap\lib\user\User;
use OCA\user_ldap\lib\LogWrapper;
use OCA\user_ldap\lib\FilesystemHelper;

/**
 * Manager
 *
 * upon request, returns an LDAP user object either by creating or from run-time
 * cache
 */
class Manager {
	/**
	 * @var IUserTools
	 */
	protected $access;
	/**
	 * @var \OCP\IConfig
	 */
	protected $ocConfig;
	/**
	 * @var FilesystemHelper
	 */
	protected $ocFilesystem;
	/**
	 * @var LogWrapper
	 */
	protected $ocLog;
	/**
	 * @var \OCP\Image
	 */
	protected $image;
	/**
	 * @param \OCP\IAvatarManager
	 */
	protected $avatarManager;
	/**
	 * @var string[][]
	 */
	protected $users = array(
		'byDN'  => array(),
		'byUid' => array(),
	);

	/**
	 * @brief Constructor
	 * @param \OCP\IConfig respectively an instance that provides the methods
	 * setUserValue and getUserValue as implemented in \OCP\Config
	 * @param \OCA\user_ldap\lib\FilesystemHelper object that gives access to
	 * necessary functions from the OC filesystem
	 * @param  \OCA\user_ldap\lib\LogWrapper
	 * @param \OCP\IAvatarManager
	 * @param \OCP\Image an empty image instance
	 * @throws Exception when the methods mentioned above do not exist
	 */
	public function __construct(\OCP\IConfig $ocConfig,
		FilesystemHelper $ocFilesystem, LogWrapper $ocLog,
		\OCP\IAvatarManager $avatarManager, \OCP\Image $image) {

		if(!method_exists($ocConfig, 'setUserValue')
		   || !method_exists($ocConfig, 'getUserValue')) {
			throw new \Exception('Invalid ownCloud User Config object');
		}
		$this->ocConfig      = $ocConfig;
		$this->ocFilesystem  = $ocFilesystem;
		$this->ocLog         = $ocLog;
		$this->avatarManager = $avatarManager;
		$this->image         = $image;
	}

	/**
	 * @brief binds manager to an instance of IUserTools (implemented by
	 * Access). It needs to be assigned first before the manager can be used.
	 * @param IUserTools
	 */
	public function setLdapAccess(IUserTools $access) {
		$this->access = $access;
	}

	/**
	 * @brief creates an instance of User and caches (just runtime) it in the
	 * property array
	 * @param string the DN of the user
	 * @param string the internal (owncloud) username
	 * @return \OCA\user_ldap\lib\User
	 */
	private function createAndCache($dn, $uid) {
		$this->checkAccess();
		$user = new User($uid, $dn, $this->access, $this->ocConfig,
			$this->ocFilesystem, clone $this->image, $this->ocLog,
			$this->avatarManager);
		$users['byDN'][$dn]   = $user;
		$users['byUid'][$uid] = $user;
		return $user;
	}

	/**
	 * @brief checks whether the Access instance has been set
	 * @throws Exception if Access has not been set
	 * @return null
	 */
	private function checkAccess() {
		if(is_null($this->access)) {
			throw new \Exception('LDAP Access instance must be set first');
		}
	}

	/**
	 * @brief returns a User object by it's DN or ownCloud username
	 * @param string the DN or username of the user
	 * @return \OCA\user_ldap\lib\User | null
	 */
	public function get($id) {
		$this->checkAccess();
		if(isset($this->users['byDN'][$id])) {
			return $this->users['byDN'][$id];
		} else if(isset($this->users['byUid'][$id])) {
			return $this->users['byUid'][$id];
		}

		if(!$this->access->stringResemblesDN($id) ) {
			//most likely a uid
			$dn = $this->access->username2dn($id);
			if($dn !== false) {
				return $this->createAndCache($dn, $id);
			}
		} else {
			//so it's a DN
			$uid = $this->access->dn2username($id);
			if($uid !== false) {
				return $this->createAndCache($id, $uid);
			}
		}
		//either funny uid or invalid. Assume funny to be on the safe side.
		$dn = $this->access->username2dn($id);
		if($dn !== false) {
			return $this->createAndCache($dn, $id);
		}
		return null;
	}

}
