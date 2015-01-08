<?php
/**
 * ownCloud
 *
 * @author Bjoern Schiessle, Michael Gapczynski
 * @copyright 2012 Michael Gapczynski <mtgap@owncloud.com>
 *            2014 Bjoern Schiessle <schiessle@owncloud.com>
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
 */

namespace OC\Share;

/**
 * This class provides the ability for apps to share their content between users.
 * Apps must create a backend class that implements OCP\Share_Backend and register it with this class.
 *
 * It provides the following hooks:
 *  - post_shared
 */
class Share extends \OC\Share\Constants {

	/** CRUDS permissions (Create, Read, Update, Delete, Share) using a bitmask
	 * Construct permissions for share() and setPermissions with Or (|) e.g.
	 * Give user read and update permissions: PERMISSION_READ | PERMISSION_UPDATE
	 *
	 * Check if permission is granted with And (&) e.g. Check if delete is
	 * granted: if ($permissions & PERMISSION_DELETE)
	 *
	 * Remove permissions with And (&) and Not (~) e.g. Remove the update
	 * permission: $permissions &= ~PERMISSION_UPDATE
	 *
	 * Apps are required to handle permissions on their own, this class only
	 * stores and manages the permissions of shares
	 * @see lib/public/constants.php
	 */

	/**
	 * Register a sharing backend class that implements OCP\Share_Backend for an item type
	 * @param string $itemType Item type
	 * @param string $class Backend class
	 * @param string $collectionOf (optional) Depends on item type
	 * @param array $supportedFileExtensions (optional) List of supported file extensions if this item type depends on files
	 * @return boolean true if backend is registered or false if error
	 */
	public static function registerBackend($itemType, $class, $collectionOf = null, $supportedFileExtensions = null) {
		if (self::isEnabled()) {
			if (!isset(self::$backendTypes[$itemType])) {
				self::$backendTypes[$itemType] = array(
					'class' => $class,
					'collectionOf' => $collectionOf,
					'supportedFileExtensions' => $supportedFileExtensions
				);
				if(count(self::$backendTypes) === 1) {
					\OC_Util::addScript('core', 'share');
					\OC_Util::addStyle('core', 'share');
				}
				return true;
			}
			\OC_Log::write('OCP\Share',
				'Sharing backend '.$class.' not registered, '.self::$backendTypes[$itemType]['class']
				.' is already registered for '.$itemType,
				\OC_Log::WARN);
		}
		return false;
	}

	/**
	 * Check if the Share API is enabled
	 * @return boolean true if enabled or false
	 *
	 * The Share API is enabled by default if not configured
	 */
	public static function isEnabled() {
		if (\OC_Appconfig::getValue('core', 'shareapi_enabled', 'yes') == 'yes') {
			return true;
		}
		return false;
	}

	/**
	 * Find which users can access a shared item
	 * @param string $path to the file
	 * @param string $ownerUser owner of the file
	 * @param boolean $includeOwner include owner to the list of users with access to the file
	 * @param boolean $returnUserPaths Return an array with the user => path map
	 * @return array
	 * @note $path needs to be relative to user data dir, e.g. 'file.txt'
	 *       not '/admin/data/file.txt'
	 */
	public static function getUsersSharingFile($path, $ownerUser, $includeOwner = false, $returnUserPaths = false) {

		$shares = $sharePaths = $fileTargets = array();
		$publicShare = false;
		$source = -1;
		$cache = false;

		$view = new \OC\Files\View('/' . $ownerUser . '/files');
		if ($view->file_exists($path)) {
			$meta = $view->getFileInfo($path);
			$path = substr($meta->getPath(), strlen('/' . $ownerUser . '/files'));
		} else {
			// if the file doesn't exists yet we start with the parent folder
			$meta = $view->getFileInfo(dirname($path));
		}

		if($meta !== false) {
			$source = $meta['fileid'];
			$cache = new \OC\Files\Cache\Cache($meta['storage']);
		}

		while ($source !== -1) {
			// Fetch all shares with another user
			$query = \OC_DB::prepare(
				'SELECT `share_with`, `file_source`, `file_target`
				FROM
				`*PREFIX*share`
				WHERE
				`item_source` = ? AND `share_type` = ? AND `item_type` IN (\'file\', \'folder\')'
			);

			$result = $query->execute(array($source, self::SHARE_TYPE_USER));

			if (\OCP\DB::isError($result)) {
				\OCP\Util::writeLog('OCP\Share', \OC_DB::getErrorMessage($result), \OC_Log::ERROR);
			} else {
				while ($row = $result->fetchRow()) {
					$shares[] = $row['share_with'];
					if ($returnUserPaths) {
						$fileTargets[(int) $row['file_source']][$row['share_with']] = $row;
					}
				}
			}

			// We also need to take group shares into account
			$query = \OC_DB::prepare(
				'SELECT `share_with`, `file_source`, `file_target`
				FROM
				`*PREFIX*share`
				WHERE
				`item_source` = ? AND `share_type` = ? AND `item_type` IN (\'file\', \'folder\')'
			);

			$result = $query->execute(array($source, self::SHARE_TYPE_GROUP));

			if (\OCP\DB::isError($result)) {
				\OCP\Util::writeLog('OCP\Share', \OC_DB::getErrorMessage($result), \OC_Log::ERROR);
			} else {
				while ($row = $result->fetchRow()) {
					$usersInGroup = \OC_Group::usersInGroup($row['share_with']);
					$shares = array_merge($shares, $usersInGroup);
					if ($returnUserPaths) {
						foreach ($usersInGroup as $user) {
							$fileTargets[(int) $row['file_source']][$user] = $row;
						}
					}
				}
			}

			//check for public link shares
			if (!$publicShare) {
				$query = \OC_DB::prepare(
					'SELECT `share_with`
					FROM
					`*PREFIX*share`
					WHERE
					`item_source` = ? AND `share_type` = ? AND `item_type` IN (\'file\', \'folder\')'
				);

				$result = $query->execute(array($source, self::SHARE_TYPE_LINK));

				if (\OCP\DB::isError($result)) {
					\OCP\Util::writeLog('OCP\Share', \OC_DB::getErrorMessage($result), \OC_Log::ERROR);
				} else {
					if ($result->fetchRow()) {
						$publicShare = true;
					}
				}
			}

			// let's get the parent for the next round
			$meta = $cache->get((int)$source);
			if($meta !== false) {
				$source = (int)$meta['parent'];
			} else {
				$source = -1;
			}
		}

		// Include owner in list of users, if requested
		if ($includeOwner) {
			$shares[] = $ownerUser;
			if ($returnUserPaths) {
				$sharePaths[$ownerUser] = $path;
			}
		}

		if ($returnUserPaths) {
			$fileTargetIDs = array_keys($fileTargets);
			$fileTargetIDs = array_unique($fileTargetIDs);

			if (!empty($fileTargetIDs)) {
				$query = \OC_DB::prepare(
					'SELECT `fileid`, `path`
					FROM `*PREFIX*filecache`
					WHERE `fileid` IN (' . implode(',', $fileTargetIDs) . ')'
				);
				$result = $query->execute();

				if (\OCP\DB::isError($result)) {
					\OCP\Util::writeLog('OCP\Share', \OC_DB::getErrorMessage($result), \OC_Log::ERROR);
				} else {
					while ($row = $result->fetchRow()) {
						foreach ($fileTargets[$row['fileid']] as $uid => $shareData) {
							$sharedPath = $shareData['file_target'];
							$sharedPath .= substr($path, strlen($row['path']) -5);
							$sharePaths[$uid] = $sharedPath;
						}
					}
				}
			}

			return $sharePaths;
		}

		return array("users" => array_unique($shares), "public" => $publicShare);
	}

	/**
	 * Get the items of item type shared with the current user
	 * @param string $itemType
	 * @param int $format (optional) Format type must be defined by the backend
	 * @param mixed $parameters (optional)
	 * @param int $limit Number of items to return (optional) Returns all by default
	 * @param boolean $includeCollections (optional)
	 * @return mixed Return depends on format
	 */
	public static function getItemsSharedWith($itemType, $format = self::FORMAT_NONE,
		$parameters = null, $limit = -1, $includeCollections = false) {
		return self::getItems($itemType, null, self::$shareTypeUserAndGroups, \OC_User::getUser(), null, $format,
			$parameters, $limit, $includeCollections);
	}

	/**
	 * Get the items of item type shared with a user
	 * @param string $itemType
	 * @param string $user id for which user we want the shares
	 * @param int $format (optional) Format type must be defined by the backend
	 * @param mixed $parameters (optional)
	 * @param int $limit Number of items to return (optional) Returns all by default
	 * @param boolean $includeCollections (optional)
	 * @return mixed Return depends on format
	 */
	public static function getItemsSharedWithUser($itemType, $user, $format = self::FORMAT_NONE,
		$parameters = null, $limit = -1, $includeCollections = false) {
		return self::getItems($itemType, null, self::$shareTypeUserAndGroups, $user, null, $format,
			$parameters, $limit, $includeCollections);
	}

	/**
	 * Get the item of item type shared with the current user
	 * @param string $itemType
	 * @param string $itemTarget
	 * @param int $format (optional) Format type must be defined by the backend
	 * @param mixed $parameters (optional)
	 * @param boolean $includeCollections (optional)
	 * @return mixed Return depends on format
	 */
	public static function getItemSharedWith($itemType, $itemTarget, $format = self::FORMAT_NONE,
		$parameters = null, $includeCollections = false) {
		return self::getItems($itemType, $itemTarget, self::$shareTypeUserAndGroups, \OC_User::getUser(), null, $format,
			$parameters, 1, $includeCollections);
	}

	/**
	 * Get the item of item type shared with a given user by source
	 * @param string $itemType
	 * @param string $itemSource
	 * @param string $user User user to whom the item was shared
	 * @param int $shareType only look for a specific share type
	 * @return array Return list of items with file_target, permissions and expiration
	 */
	public static function getItemSharedWithUser($itemType, $itemSource, $user, $shareType = null) {

		$shares = array();
		$fileDependend = false;

		if ($itemType === 'file' || $itemType === 'folder') {
			$fileDependend = true;
			$column = 'file_source';
			$where = 'INNER JOIN `*PREFIX*filecache` ON `file_source` = `*PREFIX*filecache`.`fileid` WHERE';
		} else {
			$column = 'item_source';
			$where = 'WHERE';
		}

		$select = self::createSelectStatement(self::FORMAT_NONE, $fileDependend);

		$where .= ' `' . $column . '` = ? AND `item_type` = ? ';
		$arguments = array($itemSource, $itemType);
		// for link shares $user === null
		if ($user !== null) {
			$where .= ' AND `share_with` = ? ';
			$arguments[] = $user;
		}

		if ($shareType !== null) {
			$where .= ' AND `share_type` = ? ';
			$arguments[] = $shareType;
		}

		$query = \OC_DB::prepare('SELECT ' . $select . ' FROM `*PREFIX*share` '. $where);

		$result = \OC_DB::executeAudited($query, $arguments);

		while ($row = $result->fetchRow()) {
			$shares[] = $row;
		}

		//if didn't found a result than let's look for a group share.
		if(empty($shares) && $user !== null) {
			$groups = \OC_Group::getUserGroups($user);

			$query = \OC_DB::prepare(
					'SELECT *
						FROM
						`*PREFIX*share`
						WHERE
						`' . $column . '` = ? AND `item_type` = ? AND `share_with` in (?)'
					);

			$result = \OC_DB::executeAudited($query, array($itemSource, $itemType, implode(',', $groups)));

			while ($row = $result->fetchRow()) {
				$shares[] = $row;
			}
		}

		return $shares;

	}

	/**
	 * Get the item of item type shared with the current user by source
	 * @param string $itemType
	 * @param string $itemSource
	 * @param int $format (optional) Format type must be defined by the backend
	 * @param mixed $parameters
	 * @param boolean $includeCollections
	 * @param string $shareWith (optional) define against which user should be checked, default: current user
	 * @return array
	 */
	public static function getItemSharedWithBySource($itemType, $itemSource, $format = self::FORMAT_NONE,
		$parameters = null, $includeCollections = false, $shareWith = null) {
		$shareWith = ($shareWith === null) ? \OC_User::getUser() : $shareWith;
		return self::getItems($itemType, $itemSource, self::$shareTypeUserAndGroups, $shareWith, null, $format,
			$parameters, 1, $includeCollections, true);
	}

	/**
	 * Get the item of item type shared by a link
	 * @param string $itemType
	 * @param string $itemSource
	 * @param string $uidOwner Owner of link
	 * @return array
	 */
	public static function getItemSharedWithByLink($itemType, $itemSource, $uidOwner) {
		return self::getItems($itemType, $itemSource, self::SHARE_TYPE_LINK, null, $uidOwner, self::FORMAT_NONE,
			null, 1);
	}

	/**
	 * Based on the given token the share information will be returned - password protected shares will be verified
	 * @param string $token
	 * @return array|boolean false will be returned in case the token is unknown or unauthorized
	 */
	public static function getShareByToken($token, $checkPasswordProtection = true) {
		$query = \OC_DB::prepare('SELECT * FROM `*PREFIX*share` WHERE `token` = ?', 1);
		$result = $query->execute(array($token));
		if (\OC_DB::isError($result)) {
			\OC_Log::write('OCP\Share', \OC_DB::getErrorMessage($result) . ', token=' . $token, \OC_Log::ERROR);
		}
		$row = $result->fetchRow();
		if ($row === false) {
			return false;
		}
		if (is_array($row) and self::expireItem($row)) {
			return false;
		}

		// password protected shares need to be authenticated
		if ($checkPasswordProtection && !\OCP\Share::checkPasswordProtectedShare($row)) {
			return false;
		}

		return $row;
	}

	/**
	 * resolves reshares down to the last real share
	 * @param array $linkItem
	 * @return array file owner
	 */
	public static function resolveReShare($linkItem)
	{
		if (isset($linkItem['parent'])) {
			$parent = $linkItem['parent'];
			while (isset($parent)) {
				$query = \OC_DB::prepare('SELECT * FROM `*PREFIX*share` WHERE `id` = ?', 1);
				$item = $query->execute(array($parent))->fetchRow();
				if (isset($item['parent'])) {
					$parent = $item['parent'];
				} else {
					return $item;
				}
			}
		}
		return $linkItem;
	}


	/**
	 * Get the shared items of item type owned by the current user
	 * @param string $itemType
	 * @param int $format (optional) Format type must be defined by the backend
	 * @param mixed $parameters
	 * @param int $limit Number of items to return (optional) Returns all by default
	 * @param boolean $includeCollections
	 * @return mixed Return depends on format
	 */
	public static function getItemsShared($itemType, $format = self::FORMAT_NONE, $parameters = null,
		$limit = -1, $includeCollections = false) {
		return self::getItems($itemType, null, null, null, \OC_User::getUser(), $format,
			$parameters, $limit, $includeCollections);
	}

	/**
	 * Get the shared item of item type owned by the current user
	 * @param string $itemType
	 * @param string $itemSource
	 * @param int $format (optional) Format type must be defined by the backend
	 * @param mixed $parameters
	 * @param boolean $includeCollections
	 * @return mixed Return depends on format
	 */
	public static function getItemShared($itemType, $itemSource, $format = self::FORMAT_NONE,
	                                     $parameters = null, $includeCollections = false) {
		return self::getItems($itemType, $itemSource, null, null, \OC_User::getUser(), $format,
			$parameters, -1, $includeCollections);
	}

	/**
	 * Get all users an item is shared with
	 * @param string $itemType
	 * @param string $itemSource
	 * @param string $uidOwner
	 * @param boolean $includeCollections
	 * @param boolean $checkExpireDate
	 * @return array Return array of users
	 */
	public static function getUsersItemShared($itemType, $itemSource, $uidOwner, $includeCollections = false, $checkExpireDate = true) {

		$users = array();
		$items = self::getItems($itemType, $itemSource, null, null, $uidOwner, self::FORMAT_NONE, null, -1, $includeCollections, false, $checkExpireDate);
		if ($items) {
			foreach ($items as $item) {
				if ((int)$item['share_type'] === self::SHARE_TYPE_USER) {
					$users[] = $item['share_with'];
				} else if ((int)$item['share_type'] === self::SHARE_TYPE_GROUP) {
					$users = array_merge($users, \OC_Group::usersInGroup($item['share_with']));
				}
			}
		}
		return $users;
	}

	/**
	 * Share an item with a user, group, or via private link
	 * @param string $itemType
	 * @param string $itemSource
	 * @param int $shareType SHARE_TYPE_USER, SHARE_TYPE_GROUP, or SHARE_TYPE_LINK
	 * @param string $shareWith User or group the item is being shared with
	 * @param int $permissions CRUDS
	 * @param string $itemSourceName
	 * @param \DateTime $expirationDate
	 * @return boolean|string Returns true on success or false on failure, Returns token on success for links
	 * @throws \Exception
	 */
	public static function shareItem($itemType, $itemSource, $shareType, $shareWith, $permissions, $itemSourceName = null, \DateTime $expirationDate = null) {
		$uidOwner = \OC_User::getUser();
		$shareWithinGroupOnly = self::shareWithGroupMembersOnly();
		$l = \OC_L10N::get('lib');

		if (is_null($itemSourceName)) {
			$itemSourceName = $itemSource;
		}

		// check if file can be shared
		if ($itemType === 'file' or $itemType === 'folder') {
			$path = \OC\Files\Filesystem::getPath($itemSource);
			// verify that the file exists before we try to share it
			if (!$path) {
				$message = 'Sharing %s failed, because the file does not exist';
				$message_t = $l->t('Sharing %s failed, because the file does not exist', array($itemSourceName));
				\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
			// verify that the user has share permission
			if (!\OC\Files\Filesystem::isSharable($path)) {
				$message = 'You are not allowed to share %s';
				$message_t = $l->t('You are not allowed to share %s', array($itemSourceName));
				\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
		}

		//verify that we don't share a folder which already contains a share mount point
		if ($itemType === 'folder') {
			$path = '/' . $uidOwner . '/files' . \OC\Files\Filesystem::getPath($itemSource) . '/';
			$mountManager = \OC\Files\Filesystem::getMountManager();
			$mounts = $mountManager->findIn($path);
			foreach ($mounts as $mount) {
				if ($mount->getStorage()->instanceOfStorage('\OCA\Files_Sharing\ISharedStorage')) {
					$message = 'Sharing "' . $itemSourceName . '" failed, because it contains files shared with you!';
					\OC_Log::write('OCP\Share', $message, \OC_Log::ERROR);
					throw new \Exception($message);
				}

			}
		}

		// single file shares should never have delete permissions
		if ($itemType === 'file') {
			$permissions = (int)$permissions & ~\OCP\PERMISSION_DELETE;
		}

		// Verify share type and sharing conditions are met
		if ($shareType === self::SHARE_TYPE_USER) {
			if ($shareWith == $uidOwner) {
				$message = 'Sharing %s failed, because the user %s is the item owner';
				$message_t = $l->t('Sharing %s failed, because the user %s is the item owner', array($itemSourceName, $shareWith));
				\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $shareWith), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
			if (!\OC_User::userExists($shareWith)) {
				$message = 'Sharing %s failed, because the user %s does not exist';
				$message_t = $l->t('Sharing %s failed, because the user %s does not exist', array($itemSourceName, $shareWith));
				\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $shareWith), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
			if ($shareWithinGroupOnly) {
				$inGroup = array_intersect(\OC_Group::getUserGroups($uidOwner), \OC_Group::getUserGroups($shareWith));
				if (empty($inGroup)) {
					$message = 'Sharing %s failed, because the user '
						.'%s is not a member of any groups that %s is a member of';
					$message_t = $l->t('Sharing %s failed, because the user %s is not a member of any groups that %s is a member of', array($itemSourceName, $shareWith, $uidOwner));
					\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $shareWith, $uidOwner), \OC_Log::ERROR);
					throw new \Exception($message_t);
				}
			}
			// Check if the item source is already shared with the user, either from the same owner or a different user
			if ($checkExists = self::getItems($itemType, $itemSource, self::$shareTypeUserAndGroups,
				$shareWith, null, self::FORMAT_NONE, null, 1, true, true)) {
				// Only allow the same share to occur again if it is the same
				// owner and is not a user share, this use case is for increasing
				// permissions for a specific user
				if ($checkExists['uid_owner'] != $uidOwner || $checkExists['share_type'] == $shareType) {
					$message = 'Sharing %s failed, because this item is already shared with %s';
					$message_t = $l->t('Sharing %s failed, because this item is already shared with %s', array($itemSourceName, $shareWith));
					\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $shareWith), \OC_Log::ERROR);
					throw new \Exception($message_t);
				}
			}
		} else if ($shareType === self::SHARE_TYPE_GROUP) {
			if (!\OC_Group::groupExists($shareWith)) {
				$message = 'Sharing %s failed, because the group %s does not exist';
				$message_t = $l->t('Sharing %s failed, because the group %s does not exist', array($itemSourceName, $shareWith));
				\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $shareWith), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
			if ($shareWithinGroupOnly && !\OC_Group::inGroup($uidOwner, $shareWith)) {
				$message = 'Sharing %s failed, because '
					.'%s is not a member of the group %s';
				$message_t = $l->t('Sharing %s failed, because %s is not a member of the group %s', array($itemSourceName, $uidOwner, $shareWith));
				\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $uidOwner, $shareWith), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
			// Check if the item source is already shared with the group, either from the same owner or a different user
			// The check for each user in the group is done inside the put() function
			if ($checkExists = self::getItems($itemType, $itemSource, self::SHARE_TYPE_GROUP, $shareWith,
				null, self::FORMAT_NONE, null, 1, true, true)) {
				// Only allow the same share to occur again if it is the same
				// owner and is not a group share, this use case is for increasing
				// permissions for a specific user
				if ($checkExists['uid_owner'] != $uidOwner || $checkExists['share_type'] == $shareType) {
					$message = 'Sharing %s failed, because this item is already shared with %s';
					$message_t = $l->t('Sharing %s failed, because this item is already shared with %s', array($itemSourceName, $shareWith));
					\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $shareWith), \OC_Log::ERROR);
					throw new \Exception($message_t);
				}
			}
			// Convert share with into an array with the keys group and users
			$group = $shareWith;
			$shareWith = array();
			$shareWith['group'] = $group;
			$shareWith['users'] = array_diff(\OC_Group::usersInGroup($group), array($uidOwner));
		} else if ($shareType === self::SHARE_TYPE_LINK) {
			$updateExistingShare = false;
			if (\OC_Appconfig::getValue('core', 'shareapi_allow_links', 'yes') == 'yes') {

				// when updating a link share
				// FIXME Don't delete link if we update it
				if ($checkExists = self::getItems($itemType, $itemSource, self::SHARE_TYPE_LINK, null,
					$uidOwner, self::FORMAT_NONE, null, 1)) {
					// remember old token
					$oldToken = $checkExists['token'];
					$oldPermissions = $checkExists['permissions'];
					//delete the old share
					Helper::delete($checkExists['id']);
					$updateExistingShare = true;
				}

				// Generate hash of password - same method as user passwords
				if (!empty($shareWith)) {
					$forcePortable = (CRYPT_BLOWFISH != 1);
					$hasher = new \PasswordHash(8, $forcePortable);
					$shareWith = $hasher->HashPassword($shareWith.\OC_Config::getValue('passwordsalt', ''));
				} else {
					// reuse the already set password, but only if we change permissions
					// otherwise the user disabled the password protection
					if ($checkExists && (int)$permissions !== (int)$oldPermissions) {
						$shareWith = $checkExists['share_with'];
					}
				}

				if (\OCP\Util::isPublicLinkPasswordRequired() && empty($shareWith)) {
					$message = 'You need to provide a password to create a public link, only protected links are allowed';
					$message_t = $l->t('You need to provide a password to create a public link, only protected links are allowed');
					\OC_Log::write('OCP\Share', $message, \OC_Log::ERROR);
					throw new \Exception($message_t);
				}

				if ($updateExistingShare === false &&
						self::isDefaultExpireDateEnabled() &&
						empty($expirationDate)) {
					$expirationDate = Helper::calcExpireDate();
				}

				// Generate token
				if (isset($oldToken)) {
					$token = $oldToken;
				} else {
					$token = \OC_Util::generateRandomBytes(self::TOKEN_LENGTH);
				}
				$result = self::put($itemType, $itemSource, $shareType, $shareWith, $uidOwner, $permissions,
					null, $token, $itemSourceName, $expirationDate);
				if ($result) {
					return $token;
				} else {
					return false;
				}
			}
			$message = 'Sharing %s failed, because sharing with links is not allowed';
			$message_t = $l->t('Sharing %s failed, because sharing with links is not allowed', array($itemSourceName));
			\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName), \OC_Log::ERROR);
			throw new \Exception($message_t);
			return false;
		} else {
			// Future share types need to include their own conditions
			$message = 'Share type %s is not valid for %s';
			$message_t = $l->t('Share type %s is not valid for %s', array($shareType, $itemSource));
			\OC_Log::write('OCP\Share', sprintf($message, $shareType, $itemSource), \OC_Log::ERROR);
			throw new \Exception($message_t);
		}

		// Put the item into the database
		return self::put($itemType, $itemSource, $shareType, $shareWith, $uidOwner, $permissions, null, null, $itemSourceName, $expirationDate);
	}

	/**
	 * Unshare an item from a user, group, or delete a private link
	 * @param string $itemType
	 * @param string $itemSource
	 * @param int $shareType SHARE_TYPE_USER, SHARE_TYPE_GROUP, or SHARE_TYPE_LINK
	 * @param string $shareWith User or group the item is being shared with
	 * @return boolean true on success or false on failure
	 */
	public static function unshare($itemType, $itemSource, $shareType, $shareWith) {

		// check if it is a valid itemType
		self::getBackend($itemType);

		$items = self::getItemSharedWithUser($itemType, $itemSource, $shareWith, $shareType);

		$toDelete = array();
		$newParent = null;
		$currentUser = \OC_User::getUser();
		foreach ($items as $item) {
			// delete the item with the expected share_type and owner
			if ((int)$item['share_type'] === (int)$shareType && $item['uid_owner'] === $currentUser) {
				$toDelete = $item;
			// if there is more then one result we don't have to delete the children
			// but update their parent. For group shares the new parent should always be
			// the original group share and not the db entry with the unique name
			} else if ((int)$item['share_type'] === self::$shareTypeGroupUserUnique) {
				$newParent = $item['parent'];
			} else {
				$newParent = $item['id'];
			}
		}

		if (!empty($toDelete)) {
			self::unshareItem($toDelete, $newParent);
			return true;
		}
		return false;
	}

	/**
	 * Unshare an item from all users, groups, and remove all links
	 * @param string $itemType
	 * @param string $itemSource
	 * @return boolean true on success or false on failure
	 */
	public static function unshareAll($itemType, $itemSource) {
		// Get all of the owners of shares of this item.
		$query = \OC_DB::prepare( 'SELECT `uid_owner` from `*PREFIX*share` WHERE `item_type`=? AND `item_source`=?' );
		$result = $query->execute(array($itemType, $itemSource));
		$shares = array();
		// Add each owner's shares to the array of all shares for this item.
		while ($row = $result->fetchRow()) {
			$shares = array_merge($shares, self::getItems($itemType, $itemSource, null, null, $row['uid_owner']));
		}
		if (!empty($shares)) {
			// Pass all the vars we have for now, they may be useful
			$hookParams = array(
				'itemType' => $itemType,
				'itemSource' => $itemSource,
				'shares' => $shares,
			);
			\OC_Hook::emit('OCP\Share', 'pre_unshareAll', $hookParams);
			foreach ($shares as $share) {
				self::unshareItem($share);
			}
			\OC_Hook::emit('OCP\Share', 'post_unshareAll', $hookParams);
			return true;
		}
		return false;
	}

	/**
	 * Unshare an item shared with the current user
	 * @param string $itemType
	 * @param string $itemTarget
	 * @return boolean true on success or false on failure
	 *
	 * Unsharing from self is not allowed for items inside collections
	 */
	public static function unshareFromSelf($itemType, $itemTarget) {

		$uid = \OCP\User::getUser();

		if ($itemType === 'file' || $itemType === 'folder') {
			$statement = 'SELECT * FROM `*PREFIX*share` WHERE `item_type` = ? and `file_target` = ?';
		} else {
			$statement = 'SELECT * FROM `*PREFIX*share` WHERE `item_type` = ? and `item_target` = ?';
		}

		$query = \OCP\DB::prepare($statement);
		$result = $query->execute(array($itemType, $itemTarget));

		$shares = $result->fetchAll();

		$listOfUnsharedItems = array();

		$itemUnshared = false;
		foreach ($shares as $share) {
			if ((int)$share['share_type'] === \OCP\Share::SHARE_TYPE_USER &&
					$share['share_with'] === $uid) {
				$deletedShares = Helper::delete($share['id']);
				$shareTmp = array(
					'id' => $share['id'],
					'shareWith' => $share['share_with'],
					'itemTarget' => $share['item_target'],
					'itemType' => $share['item_type'],
					'shareType' => (int)$share['share_type'],
				);
				if (isset($share['file_target'])) {
					$shareTmp['fileTarget'] = $share['file_target'];
				}
				$listOfUnsharedItems = array_merge($listOfUnsharedItems, $deletedShares, array($shareTmp));
				$itemUnshared = true;
				break;
			} elseif ((int)$share['share_type'] === \OCP\Share::SHARE_TYPE_GROUP) {
				if (\OC_Group::inGroup($uid, $share['share_with'])) {
					$groupShare = $share;
				}
			} elseif ((int)$share['share_type'] === self::$shareTypeGroupUserUnique &&
					$share['share_with'] === $uid) {
				$uniqueGroupShare = $share;
			}
		}

		if (!$itemUnshared && isset($groupShare) && !isset($uniqueGroupShare)) {
			$query = \OC_DB::prepare('INSERT INTO `*PREFIX*share`'
					.' (`item_type`, `item_source`, `item_target`, `parent`, `share_type`,'
					.' `share_with`, `uid_owner`, `permissions`, `stime`, `file_source`, `file_target`)'
					.' VALUES (?,?,?,?,?,?,?,?,?,?,?)');
			$query->execute(array($groupShare['item_type'], $groupShare['item_source'], $groupShare['item_target'],
				$groupShare['id'], self::$shareTypeGroupUserUnique,
				\OC_User::getUser(), $groupShare['uid_owner'], 0, $groupShare['stime'], $groupShare['file_source'],
				$groupShare['file_target']));
			$shareTmp = array(
				'id' => $groupShare['id'],
				'shareWith' => $groupShare['share_with'],
				'itemTarget' => $groupShare['item_target'],
				'itemType' => $groupShare['item_type'],
				'shareType' => (int)$groupShare['share_type'],
				);
			if (isset($groupShare['file_target'])) {
				$shareTmp['fileTarget'] = $groupShare['file_target'];
			}
			$listOfUnsharedItems = array_merge($listOfUnsharedItems, array($groupShare));
			$itemUnshared = true;
		} elseif (!$itemUnshared && isset($uniqueGroupShare)) {
			$query = \OC_DB::prepare('UPDATE `*PREFIX*share` SET `permissions` = ? WHERE `id` = ?');
			$query->execute(array(0, $uniqueGroupShare['id']));
			$shareTmp = array(
				'id' => $uniqueGroupShare['id'],
				'shareWith' => $uniqueGroupShare['share_with'],
				'itemTarget' => $uniqueGroupShare['item_target'],
				'itemType' => $uniqueGroupShare['item_type'],
				'shareType' => (int)$uniqueGroupShare['share_type'],
				);
			if (isset($uniqueGroupShare['file_target'])) {
				$shareTmp['fileTarget'] = $uniqueGroupShare['file_target'];
			}
			$listOfUnsharedItems = array_merge($listOfUnsharedItems, array($uniqueGroupShare));
			$itemUnshared = true;
		}

		if ($itemUnshared) {
			\OC_Hook::emit('OCP\Share', 'post_unshareFromSelf',
					array('unsharedItems' => $listOfUnsharedItems, 'itemType' => $itemType));
		}

		return $itemUnshared;
	}

	/**
	 * sent status if users got informed by mail about share
	 * @param string $itemType
	 * @param string $itemSource
	 * @param int $shareType SHARE_TYPE_USER, SHARE_TYPE_GROUP, or SHARE_TYPE_LINK
	 * @param string $recipient with whom was the file shared
	 * @param boolean $status
	 */
	public static function setSendMailStatus($itemType, $itemSource, $shareType, $recipient, $status) {
		$status = $status ? 1 : 0;

		$query = \OC_DB::prepare(
				'UPDATE `*PREFIX*share`
					SET `mail_send` = ?
					WHERE `item_type` = ? AND `item_source` = ? AND `share_type` = ? AND `share_with` = ?');

		$result = $query->execute(array($status, $itemType, $itemSource, $shareType, $recipient));

		if($result === false) {
			\OC_Log::write('OCP\Share', 'Couldn\'t set send mail status', \OC_Log::ERROR);
		}
	}

	/**
	 * Set the permissions of an item for a specific user or group
	 * @param string $itemType
	 * @param string $itemSource
	 * @param int $shareType SHARE_TYPE_USER, SHARE_TYPE_GROUP, or SHARE_TYPE_LINK
	 * @param string $shareWith User or group the item is being shared with
	 * @param int $permissions CRUDS permissions
	 * @return boolean true on success or false on failure
	 */
	public static function setPermissions($itemType, $itemSource, $shareType, $shareWith, $permissions) {
		$l = \OC_L10N::get('lib');
		if ($item = self::getItems($itemType, $itemSource, $shareType, $shareWith,
			\OC_User::getUser(), self::FORMAT_NONE, null, 1, false)) {
			// Check if this item is a reshare and verify that the permissions
			// granted don't exceed the parent shared item
			if (isset($item['parent'])) {
				$query = \OC_DB::prepare('SELECT `permissions` FROM `*PREFIX*share` WHERE `id` = ?', 1);
				$result = $query->execute(array($item['parent']))->fetchRow();
				if (~(int)$result['permissions'] & $permissions) {
					$message = 'Setting permissions for %s failed,'
						.' because the permissions exceed permissions granted to %s';
					$message_t = $l->t('Setting permissions for %s failed, because the permissions exceed permissions granted to %s', array($itemSource, \OC_User::getUser()));
					\OC_Log::write('OCP\Share', sprintf($message, $itemSource, \OC_User::getUser()), \OC_Log::ERROR);
					throw new \Exception($message_t);
				}
			}
			$query = \OC_DB::prepare('UPDATE `*PREFIX*share` SET `permissions` = ? WHERE `id` = ?');
			$query->execute(array($permissions, $item['id']));
			if ($itemType === 'file' || $itemType === 'folder') {
				\OC_Hook::emit('OCP\Share', 'post_update_permissions', array(
					'itemType' => $itemType,
					'itemSource' => $itemSource,
					'shareType' => $shareType,
					'shareWith' => $shareWith,
					'uidOwner' => \OC_User::getUser(),
					'permissions' => $permissions,
					'path' => $item['path'],
				));
			}
			// Check if permissions were removed
			if ($item['permissions'] & ~$permissions) {
				// If share permission is removed all reshares must be deleted
				if (($item['permissions'] & \OCP\PERMISSION_SHARE) && (~$permissions & \OCP\PERMISSION_SHARE)) {
					Helper::delete($item['id'], true);
				} else {
					$ids = array();
					$parents = array($item['id']);
					while (!empty($parents)) {
						$parents = "'".implode("','", $parents)."'";
						$query = \OC_DB::prepare('SELECT `id`, `permissions` FROM `*PREFIX*share`'
							.' WHERE `parent` IN ('.$parents.')');
						$result = $query->execute();
						// Reset parents array, only go through loop again if
						// items are found that need permissions removed
						$parents = array();
						while ($item = $result->fetchRow()) {
							// Check if permissions need to be removed
							if ($item['permissions'] & ~$permissions) {
								// Add to list of items that need permissions removed
								$ids[] = $item['id'];
								$parents[] = $item['id'];
							}
						}
					}
					// Remove the permissions for all reshares of this item
					if (!empty($ids)) {
						$ids = "'".implode("','", $ids)."'";
						// TODO this should be done with Doctrine platform objects
						if (\OC_Config::getValue( "dbtype") === 'oci') {
							$andOp = 'BITAND(`permissions`, ?)';
						} else {
							$andOp = '`permissions` & ?';
						}
						$query = \OC_DB::prepare('UPDATE `*PREFIX*share` SET `permissions` = '.$andOp
							.' WHERE `id` IN ('.$ids.')');
						$query->execute(array($permissions));
					}
				}
			}
			return true;
		}
		$message = 'Setting permissions for %s failed, because the item was not found';
		$message_t = $l->t('Setting permissions for %s failed, because the item was not found', array($itemSource));

		\OC_Log::write('OCP\Share', sprintf($message, $itemSource), \OC_Log::ERROR);
		throw new \Exception($message_t);
	}

	/**
	 * validate expire date if it meets all constraints
	 *
	 * @param string $expireDate well formate date string, e.g. "DD-MM-YYYY"
	 * @param string $shareTime timestamp when the file was shared
	 * @param string $itemType
	 * @param string $itemSource
	 * @return DateTime validated date
	 * @throws \Exception
	 */
	private static function validateExpireDate($expireDate, $shareTime, $itemType, $itemSource) {
		$l = \OC_L10N::get('lib');
		$date = new \DateTime($expireDate);
		$today = new \DateTime('now');

		// if the user doesn't provide a share time we need to get it from the database
		// fall-back mode to keep API stable, because the $shareTime parameter was added later
		$defaultExpireDateEnforced = \OCP\Util::isDefaultExpireDateEnforced();
		if ($defaultExpireDateEnforced && $shareTime === null) {
			$items = self::getItemShared($itemType, $itemSource);
			$firstItem = reset($items);
			$shareTime = (int)$firstItem['stime'];
		}

		if ($defaultExpireDateEnforced) {
			// initialize max date with share time
			$maxDate = new \DateTime();
			$maxDate->setTimestamp($shareTime);
			$maxDays = \OCP\Config::getAppValue('core', 'shareapi_expire_after_n_days', '7');
			$maxDate->add(new \DateInterval('P' . $maxDays . 'D'));
			if ($date > $maxDate) {
				$warning = 'Can not set expire date. Shares can not expire later then ' . $maxDays . ' after they where shared';
				$warning_t = $l->t('Can not set expire date. Shares can not expire later then %s after they where shared', array($maxDays));
				\OCP\Util::writeLog('OCP\Share', $warning, \OCP\Util::WARN);
				throw new \Exception($warning_t);
			}
		}

		if ($date < $today) {
			$message = 'Can not set expire date. Expire date is in the past';
			$message_t = $l->t('Can not set expire date. Expire date is in the past');
			\OCP\Util::writeLog('OCP\Share', $message, \OCP\Util::WARN);
			throw new \Exception($message_t);
		}

		return $date;
	}

	/**
	 * Set expiration date for a share
	 * @param string $itemType
	 * @param string $itemSource
	 * @param string $date expiration date
	 * @param int $shareTime timestamp from when the file was shared
	 * @throws \Exception
	 * @return boolean
	 */
	public static function setExpirationDate($itemType, $itemSource, $date, $shareTime = null) {
		$user = \OC_User::getUser();

		if ($date == '') {
			$date = null;
		} else {
			$date = self::validateExpireDate($date, $shareTime, $itemType, $itemSource);
		}
		$query = \OC_DB::prepare('UPDATE `*PREFIX*share` SET `expiration` = ? WHERE `item_type` = ? AND `item_source` = ?  AND `uid_owner` = ? AND `share_type` = ?');
		$query->bindValue(1, $date, 'datetime');
		$query->bindValue(2, $itemType);
		$query->bindValue(3, $itemSource);
		$query->bindValue(4, $user);
		$query->bindValue(5, \OCP\Share::SHARE_TYPE_LINK);

		$query->execute();

		\OC_Hook::emit('OCP\Share', 'post_set_expiration_date', array(
			'itemType' => $itemType,
			'itemSource' => $itemSource,
			'date' => $date,
			'uidOwner' => $user
		));

		return true;
	}

	/**
	 * Checks whether a share has expired, calls unshareItem() if yes.
	 * @param array $item Share data (usually database row)
	 * @return boolean True if item was expired, false otherwise.
	 */
	protected static function expireItem(array $item) {

		$result = false;

		// only use default expire date for link shares
		if ((int) $item['share_type'] === self::SHARE_TYPE_LINK) {

			// calculate expire date
			if (!empty($item['expiration'])) {
				$userDefinedExpire = new \DateTime($item['expiration']);
				$expires = $userDefinedExpire->getTimestamp();
			} else {
				$expires = null;
			}


			// get default expire settings
			$defaultSettings = Helper::getDefaultExpireSetting();
			$expires = Helper::calculateExpireDate($defaultSettings, $item['stime'], $expires);


			if (is_int($expires)) {
				$now = time();
				if ($now > $expires) {
					self::unshareItem($item);
					$result = true;
				}
			}
		}
		return $result;
	}

	/**
	 * Unshares a share given a share data array
	 * @param array $item Share data (usually database row)
	 * @param int new parent ID
	 * @return null
	 */
	protected static function unshareItem(array $item, $newParent = null) {
		// Pass all the vars we have for now, they may be useful
		$hookParams = array(
			'id'            => $item['id'],
			'itemType'      => $item['item_type'],
			'itemSource'    => $item['item_source'],
			'shareType'     => (int)$item['share_type'],
			'shareWith'     => $item['share_with'],
			'itemParent'    => $item['parent'],
			'uidOwner'      => $item['uid_owner'],
		);
		if($item['item_type'] === 'file' || $item['item_type'] === 'folder') {
			$hookParams['fileSource'] = $item['file_source'];
			$hookParams['fileTarget'] = $item['file_target'];
		}

		\OC_Hook::emit('OCP\Share', 'pre_unshare', $hookParams);
		$deletedShares = Helper::delete($item['id'], false, null, $newParent);
		$deletedShares[] = $hookParams;
		$hookParams['deletedShares'] = $deletedShares;
		\OC_Hook::emit('OCP\Share', 'post_unshare', $hookParams);
	}

	/**
	 * Get the backend class for the specified item type
	 * @param string $itemType
	 * @throws \Exception
	 * @return \OCP\Share_Backend
	 */
	public static function getBackend($itemType) {
		$l = \OC_L10N::get('lib');
		if (isset(self::$backends[$itemType])) {
			return self::$backends[$itemType];
		} else if (isset(self::$backendTypes[$itemType]['class'])) {
			$class = self::$backendTypes[$itemType]['class'];
			if (class_exists($class)) {
				self::$backends[$itemType] = new $class;
				if (!(self::$backends[$itemType] instanceof \OCP\Share_Backend)) {
					$message = 'Sharing backend %s must implement the interface OCP\Share_Backend';
					$message_t = $l->t('Sharing backend %s must implement the interface OCP\Share_Backend', array($class));
					\OC_Log::write('OCP\Share', sprintf($message, $class), \OC_Log::ERROR);
					throw new \Exception($message_t);
				}
				return self::$backends[$itemType];
			} else {
				$message = 'Sharing backend %s not found';
				$message_t = $l->t('Sharing backend %s not found', array($class));
				\OC_Log::write('OCP\Share', sprintf($message, $class), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
		}
		$message = 'Sharing backend for %s not found';
		$message_t = $l->t('Sharing backend for %s not found', array($itemType));
		\OC_Log::write('OCP\Share', sprintf($message, $itemType), \OC_Log::ERROR);
		throw new \Exception($message_t);
	}

	/**
	 * Check if resharing is allowed
	 * @return boolean true if allowed or false
	 *
	 * Resharing is allowed by default if not configured
	 */
	public static function isResharingAllowed() {
		if (!isset(self::$isResharingAllowed)) {
			if (\OC_Appconfig::getValue('core', 'shareapi_allow_resharing', 'yes') == 'yes') {
				self::$isResharingAllowed = true;
			} else {
				self::$isResharingAllowed = false;
			}
		}
		return self::$isResharingAllowed;
	}

	/**
	 * Get a list of collection item types for the specified item type
	 * @param string $itemType
	 * @return array
	 */
	private static function getCollectionItemTypes($itemType) {
		$collectionTypes = array($itemType);
		foreach (self::$backendTypes as $type => $backend) {
			if (in_array($backend['collectionOf'], $collectionTypes)) {
				$collectionTypes[] = $type;
			}
		}
		// TODO Add option for collections to be collection of themselves, only 'folder' does it now...
		if (!self::getBackend($itemType) instanceof \OCP\Share_Backend_Collection || $itemType != 'folder') {
			unset($collectionTypes[0]);
		}
		// Return array if collections were found or the item type is a
		// collection itself - collections can be inside collections
		if (count($collectionTypes) > 0) {
			return $collectionTypes;
		}
		return false;
	}

	/**
	 * Get shared items from the database
	 * @param string $itemType
	 * @param string $item Item source or target (optional)
	 * @param int $shareType SHARE_TYPE_USER, SHARE_TYPE_GROUP, SHARE_TYPE_LINK, $shareTypeUserAndGroups, or $shareTypeGroupUserUnique
	 * @param string $shareWith User or group the item is being shared with
	 * @param string $uidOwner User that is the owner of shared items (optional)
	 * @param int $format Format to convert items to with formatItems() (optional)
	 * @param mixed $parameters to pass to formatItems() (optional)
	 * @param int $limit Number of items to return, -1 to return all matches (optional)
	 * @param boolean $includeCollections Include collection item types (optional)
	 * @param boolean $itemShareWithBySource (optional)
	 * @param boolean $checkExpireDate
	 * @return array
	 *
	 * See public functions getItem(s)... for parameter usage
	 *
	 */
	public static function getItems($itemType, $item = null, $shareType = null, $shareWith = null,
		$uidOwner = null, $format = self::FORMAT_NONE, $parameters = null, $limit = -1,
		$includeCollections = false, $itemShareWithBySource = false, $checkExpireDate  = true) {
		if (!self::isEnabled()) {
			return array();
		}
		$backend = self::getBackend($itemType);
		$collectionTypes = false;
		// Get filesystem root to add it to the file target and remove from the
		// file source, match file_source with the file cache
		if ($itemType == 'file' || $itemType == 'folder') {
			if(!is_null($uidOwner)) {
				$root = \OC\Files\Filesystem::getRoot();
			} else {
				$root = '';
			}
			$where = 'INNER JOIN `*PREFIX*filecache` ON `file_source` = `*PREFIX*filecache`.`fileid`';
			if (!isset($item)) {
				$where .= ' WHERE `file_target` IS NOT NULL';
			}
			$fileDependent = true;
			$queryArgs = array();
		} else {
			$fileDependent = false;
			$root = '';
			$collectionTypes = self::getCollectionItemTypes($itemType);
			if ($includeCollections && !isset($item) && $collectionTypes) {
				// If includeCollections is true, find collections of this item type, e.g. a music album contains songs
				if (!in_array($itemType, $collectionTypes)) {
					$itemTypes = array_merge(array($itemType), $collectionTypes);
				} else {
					$itemTypes = $collectionTypes;
				}
				$placeholders = join(',', array_fill(0, count($itemTypes), '?'));
				$where = ' WHERE `item_type` IN ('.$placeholders.'))';
				$queryArgs = $itemTypes;
			} else {
				$where = ' WHERE `item_type` = ?';
				$queryArgs = array($itemType);
			}
		}
		if (\OC_Appconfig::getValue('core', 'shareapi_allow_links', 'yes') !== 'yes') {
			$where .= ' AND `share_type` != ?';
			$queryArgs[] = self::SHARE_TYPE_LINK;
		}
		if (isset($shareType)) {
			// Include all user and group items
			if ($shareType == self::$shareTypeUserAndGroups && isset($shareWith)) {
				$where .= ' AND ((`share_type` in (?, ?) AND `share_with` = ?) ';
				$queryArgs[] = self::SHARE_TYPE_USER;
				$queryArgs[] = self::$shareTypeGroupUserUnique;
				$queryArgs[] = $shareWith;
				$groups = \OC_Group::getUserGroups($shareWith);
				if (!empty($groups)) {
					$placeholders = join(',', array_fill(0, count($groups), '?'));
					$where .= ' OR (`share_type` = ? AND `share_with` IN ('.$placeholders.')) ';
					$queryArgs[] = self::SHARE_TYPE_GROUP;
					$queryArgs = array_merge($queryArgs, $groups);
				}
				$where .= ')';
				// Don't include own group shares
				$where .= ' AND `uid_owner` != ?';
				$queryArgs[] = $shareWith;
			} else {
				$where .= ' AND `share_type` = ?';
				$queryArgs[] = $shareType;
				if (isset($shareWith)) {
					$where .= ' AND `share_with` = ?';
					$queryArgs[] = $shareWith;
				}
			}
		}
		if (isset($uidOwner)) {
			$where .= ' AND `uid_owner` = ?';
			$queryArgs[] = $uidOwner;
			if (!isset($shareType)) {
				// Prevent unique user targets for group shares from being selected
				$where .= ' AND `share_type` != ?';
				$queryArgs[] = self::$shareTypeGroupUserUnique;
			}
			if ($fileDependent) {
				$column = 'file_source';
			} else {
				$column = 'item_source';
			}
		} else {
			if ($fileDependent) {
				$column = 'file_target';
			} else {
				$column = 'item_target';
			}
		}
		if (isset($item)) {
			$collectionTypes = self::getCollectionItemTypes($itemType);
			if ($includeCollections && $collectionTypes && !in_array('folder', $collectionTypes)) {
				$where .= ' AND (';
			} else {
				$where .= ' AND';
			}
			// If looking for own shared items, check item_source else check item_target
			if (isset($uidOwner) || $itemShareWithBySource) {
				// If item type is a file, file source needs to be checked in case the item was converted
				if ($fileDependent) {
					$where .= ' `file_source` = ?';
					$column = 'file_source';
				} else {
					$where .= ' `item_source` = ?';
					$column = 'item_source';
				}
			} else {
				if ($fileDependent) {
					$where .= ' `file_target` = ?';
					$item = \OC\Files\Filesystem::normalizePath($item);
				} else {
					$where .= ' `item_target` = ?';
				}
			}
			$queryArgs[] = $item;
			if ($includeCollections && $collectionTypes && !in_array('folder', $collectionTypes)) {
				$placeholders = join(',', array_fill(0, count($collectionTypes), '?'));
				$where .= ' OR `item_type` IN ('.$placeholders.'))';
				$queryArgs = array_merge($queryArgs, $collectionTypes);
			}
		}

		if ($shareType == self::$shareTypeUserAndGroups && $limit === 1) {
			// Make sure the unique user target is returned if it exists,
			// unique targets should follow the group share in the database
			// If the limit is not 1, the filtering can be done later
			$where .= ' ORDER BY `*PREFIX*share`.`id` DESC';
		} else {
			$where .= ' ORDER BY `*PREFIX*share`.`id` ASC';
		}

		if ($limit != -1 && !$includeCollections) {
			// The limit must be at least 3, because filtering needs to be done
			if ($limit < 3) {
				$queryLimit = 3;
			} else {
				$queryLimit = $limit;
			}
		} else {
			$queryLimit = null;
		}
		$select = self::createSelectStatement($format, $fileDependent, $uidOwner);
		$root = strlen($root);
		$query = \OC_DB::prepare('SELECT '.$select.' FROM `*PREFIX*share` '.$where, $queryLimit);
		$result = $query->execute($queryArgs);
		if (\OC_DB::isError($result)) {
			\OC_Log::write('OCP\Share',
				\OC_DB::getErrorMessage($result) . ', select=' . $select . ' where=',
				\OC_Log::ERROR);
		}
		$items = array();
		$targets = array();
		$switchedItems = array();
		$mounts = array();
		while ($row = $result->fetchRow()) {
			self::transformDBResults($row);
			// Filter out duplicate group shares for users with unique targets
			if ($row['share_type'] == self::$shareTypeGroupUserUnique && isset($items[$row['parent']])) {
				$row['share_type'] = self::SHARE_TYPE_GROUP;
				$row['unique_name'] = true; // remember that we use a unique name for this user
				$row['share_with'] = $items[$row['parent']]['share_with'];
				// if the group share was unshared from the user we keep the permission, otherwise
				// we take the permission from the parent because this is always the up-to-date
				// permission for the group share
				if ($row['permissions'] > 0) {
					$row['permissions'] = $items[$row['parent']]['permissions'];
				}
				// Remove the parent group share
				unset($items[$row['parent']]);
				if ($row['permissions'] == 0) {
					continue;
				}
			} else if (!isset($uidOwner)) {
				// Check if the same target already exists
				if (isset($targets[$row['id']])) {
					// Check if the same owner shared with the user twice
					// through a group and user share - this is allowed
					$id = $targets[$row['id']];
					if (isset($items[$id]) && $items[$id]['uid_owner'] == $row['uid_owner']) {
						// Switch to group share type to ensure resharing conditions aren't bypassed
						if ($items[$id]['share_type'] != self::SHARE_TYPE_GROUP) {
							$items[$id]['share_type'] = self::SHARE_TYPE_GROUP;
							$items[$id]['share_with'] = $row['share_with'];
						}
						// Switch ids if sharing permission is granted on only
						// one share to ensure correct parent is used if resharing
						if (~(int)$items[$id]['permissions'] & \OCP\PERMISSION_SHARE
							&& (int)$row['permissions'] & \OCP\PERMISSION_SHARE) {
							$items[$row['id']] = $items[$id];
							$switchedItems[$id] = $row['id'];
							unset($items[$id]);
							$id = $row['id'];
						}
						$items[$id]['permissions'] |= (int)$row['permissions'];

					}
					continue;
				} elseif (!empty($row['parent'])) {
					$targets[$row['parent']] = $row['id'];
				}
			}
			// Remove root from file source paths if retrieving own shared items
			if (isset($uidOwner) && isset($row['path'])) {
				if (isset($row['parent'])) {
					$query = \OC_DB::prepare('SELECT `file_target` FROM `*PREFIX*share` WHERE `id` = ?');
					$parentResult = $query->execute(array($row['parent']));
					if (\OC_DB::isError($result)) {
						\OC_Log::write('OCP\Share', 'Can\'t select parent: ' .
								\OC_DB::getErrorMessage($result) . ', select=' . $select . ' where=' . $where,
								\OC_Log::ERROR);
					} else {
						$parentRow = $parentResult->fetchRow();
						$tmpPath = $parentRow['file_target'];
						// find the right position where the row path continues from the target path
						$pos = strrpos($row['path'], $parentRow['file_target']);
						$subPath = substr($row['path'], $pos);
						$splitPath = explode('/', $subPath);
						foreach (array_slice($splitPath, 2) as $pathPart) {
							$tmpPath = $tmpPath . '/' . $pathPart;
						}
						$row['path'] = $tmpPath;
					}
				} else {
					if (!isset($mounts[$row['storage']])) {
						$mountPoints = \OC\Files\Filesystem::getMountByNumericId($row['storage']);
						if (is_array($mountPoints) && !empty($mountPoints)) {
							$mounts[$row['storage']] = current($mountPoints);
						}
					}
					if (!empty($mounts[$row['storage']])) {
						$path = $mounts[$row['storage']]->getMountPoint().$row['path'];
						$relPath = substr($path, $root); // path relative to data/user
						$row['path'] = rtrim($relPath, '/');
					}
				}
			}

			if($checkExpireDate) {
				if (self::expireItem($row)) {
					continue;
				}
			}
			// Check if resharing is allowed, if not remove share permission
			if (isset($row['permissions']) && (!self::isResharingAllowed() | \OC_Util::isSharingDisabledForUser())) {
				$row['permissions'] &= ~\OCP\PERMISSION_SHARE;
			}
			// Add display names to result
			if ( isset($row['share_with']) && $row['share_with'] != '' &&
					isset($row['share_with']) && $row['share_type'] === self::SHARE_TYPE_USER) {
				$row['share_with_displayname'] = \OCP\User::getDisplayName($row['share_with']);
			} else {
				$row['share_with_displayname'] = $row['share_with'];
			}
			if ( isset($row['uid_owner']) && $row['uid_owner'] != '') {
				$row['displayname_owner'] = \OCP\User::getDisplayName($row['uid_owner']);
			}

			if ($row['permissions'] > 0) {
				$items[$row['id']] = $row;
			}

		}

		// group items if we are looking for items shared with the current user
		if (isset($shareWith) && $shareWith === \OCP\User::getUser()) {
			$items = self::groupItems($items, $itemType);
		}

		if (!empty($items)) {
			$collectionItems = array();
			foreach ($items as &$row) {
				// Return only the item instead of a 2-dimensional array
				if ($limit == 1 && $row[$column] == $item && ($row['item_type'] == $itemType || $itemType == 'file')) {
					if ($format == self::FORMAT_NONE) {
						return $row;
					} else {
						break;
					}
				}
				// Check if this is a collection of the requested item type
				if ($includeCollections && $collectionTypes && $row['item_type'] !== 'folder' && in_array($row['item_type'], $collectionTypes)) {
					if (($collectionBackend = self::getBackend($row['item_type']))
						&& $collectionBackend instanceof \OCP\Share_Backend_Collection) {
						// Collections can be inside collections, check if the item is a collection
						if (isset($item) && $row['item_type'] == $itemType && $row[$column] == $item) {
							$collectionItems[] = $row;
						} else {
							$collection = array();
							$collection['item_type'] = $row['item_type'];
							if ($row['item_type'] == 'file' || $row['item_type'] == 'folder') {
								$collection['path'] = basename($row['path']);
							}
							$row['collection'] = $collection;
							// Fetch all of the children sources
							$children = $collectionBackend->getChildren($row[$column]);
							foreach ($children as $child) {
								$childItem = $row;
								$childItem['item_type'] = $itemType;
								if ($row['item_type'] != 'file' && $row['item_type'] != 'folder') {
									$childItem['item_source'] = $child['source'];
									$childItem['item_target'] = $child['target'];
								}
								if ($backend instanceof \OCP\Share_Backend_File_Dependent) {
									if ($row['item_type'] == 'file' || $row['item_type'] == 'folder') {
										$childItem['file_source'] = $child['source'];
									} else { // TODO is this really needed if we already know that we use the file backend?
										$meta = \OC\Files\Filesystem::getFileInfo($child['file_path']);
										$childItem['file_source'] = $meta['fileid'];
									}
									$childItem['file_target'] =
										\OC\Files\Filesystem::normalizePath($child['file_path']);
								}
								if (isset($item)) {
									if ($childItem[$column] == $item) {
										// Return only the item instead of a 2-dimensional array
										if ($limit == 1) {
											if ($format == self::FORMAT_NONE) {
												return $childItem;
											} else {
												// Unset the items array and break out of both loops
												$items = array();
												$items[] = $childItem;
												break 2;
											}
										} else {
											$collectionItems[] = $childItem;
										}
									}
								} else {
									$collectionItems[] = $childItem;
								}
							}
						}
					}
					// Remove collection item
					$toRemove = $row['id'];
					if (array_key_exists($toRemove, $switchedItems)) {
						$toRemove = $switchedItems[$toRemove];
					}
					unset($items[$toRemove]);
				} elseif ($includeCollections && $collectionTypes && in_array($row['item_type'], $collectionTypes)) {
					// FIXME: Thats a dirty hack to improve file sharing performance,
					// see github issue #10588 for more details
					// Need to find a solution which works for all back-ends
					$collectionBackend = self::getBackend($row['item_type']);
					$sharedParents = $collectionBackend->getParents($row['item_source']);
					foreach ($sharedParents as $parent) {
						$collectionItems[] = $parent;
					}
				}
			}
			if (!empty($collectionItems)) {
				$items = array_merge($items, $collectionItems);
			}

			return self::formatResult($items, $column, $backend, $format, $parameters);
		} elseif ($includeCollections && $collectionTypes && in_array('folder', $collectionTypes)) {
			// FIXME: Thats a dirty hack to improve file sharing performance,
			// see github issue #10588 for more details
			// Need to find a solution which works for all back-ends
			$collectionItems = array();
			$collectionBackend = self::getBackend('folder');
			$sharedParents = $collectionBackend->getParents($item, $shareWith);
			foreach ($sharedParents as $parent) {
				$collectionItems[] = $parent;
			}
			if ($limit === 1) {
				return reset($collectionItems);
			}
			return self::formatResult($collectionItems, $column, $backend, $format, $parameters);
		}

		return array();
	}

	/**
	 * group items with link to the same source
	 *
	 * @param array $items
	 * @param string $itemType
	 * @return array of grouped items
	 */
	protected static function groupItems($items, $itemType) {

		$fileSharing = ($itemType === 'file' || $itemType === 'folder') ? true : false;

		$result = array();

		foreach ($items as $item) {
			$grouped = false;
			foreach ($result as $key => $r) {
				// for file/folder shares we need to compare file_source, otherwise we compare item_source
				// only group shares if they already point to the same target, otherwise the file where shared
				// before grouping of shares was added. In this case we don't group them toi avoid confusions
				if (( $fileSharing && $item['file_source'] === $r['file_source'] && $item['file_target'] === $r['file_target']) ||
						(!$fileSharing && $item['item_source'] === $r['item_source'] && $item['item_target'] === $r['item_target'])) {
					// add the first item to the list of grouped shares
					if (!isset($result[$key]['grouped'])) {
						$result[$key]['grouped'][] = $result[$key];
					}
					$result[$key]['permissions'] = (int) $item['permissions'] | (int) $r['permissions'];
					$result[$key]['grouped'][] = $item;
					$grouped = true;
					break;
				}
			}

			if (!$grouped) {
				$result[] = $item;
			}

		}

		return $result;
	}

/**
	 * Put shared item into the database
	 * @param string $itemType Item type
	 * @param string $itemSource Item source
	 * @param int $shareType SHARE_TYPE_USER, SHARE_TYPE_GROUP, or SHARE_TYPE_LINK
	 * @param string $shareWith User or group the item is being shared with
	 * @param string $uidOwner User that is the owner of shared item
	 * @param int $permissions CRUDS permissions
	 * @param boolean|array $parentFolder Parent folder target (optional)
	 * @param string $token (optional)
	 * @param string $itemSourceName name of the source item (optional)
	 * @param \DateTime $expirationDate (optional)
	 * @throws \Exception
	 * @return boolean Returns true on success or false on failure
	 */
	private static function put($itemType, $itemSource, $shareType, $shareWith, $uidOwner,
		$permissions, $parentFolder = null, $token = null, $itemSourceName = null, \DateTime $expirationDate = null) {

		$queriesToExecute = array();
		$suggestedItemTarget = null;

		$result = self::checkReshare($itemType, $itemSource, $shareType, $shareWith, $uidOwner, $permissions, $itemSourceName, $expirationDate);
		if(!empty($result)) {
			$parent = $result['parent'];
			$itemSource = $result['itemSource'];
			$fileSource = $result['fileSource'];
			$suggestedItemTarget = $result['suggestedItemTarget'];
			$suggestedFileTarget = $result['suggestedFileTarget'];
			$filePath = $result['filePath'];
			$expirationDate = $result['expirationDate'];
		}

		$isGroupShare = false;
		if ($shareType == self::SHARE_TYPE_GROUP) {
			$isGroupShare = true;
			$users = \OC_Group::usersInGroup($shareWith['group']);
			// remove current user from list
			if (in_array(\OCP\User::getUser(), $users)) {
				unset($users[array_search(\OCP\User::getUser(), $users)]);
			}
			$groupItemTarget = Helper::generateTarget($itemType, $itemSource, $shareType, $shareWith['group'],
					$uidOwner, $suggestedItemTarget);
			$groupFileTarget = $filePath;

			// add group share to table and remember the id as parent
			$queriesToExecute['groupShare'] = array(
				'itemType'			=> $itemType,
				'itemSource'		=> $itemSource,
				'itemTarget'		=> $groupItemTarget,
				'shareType'			=> $shareType,
				'shareWith'			=> $shareWith['group'],
				'uidOwner'			=> $uidOwner,
				'permissions'		=> $permissions,
				'shareTime'			=> time(),
				'fileSource'		=> $fileSource,
				'fileTarget'		=> $filePath,
				'token'				=> $token,
				'parent'			=> $parent,
				'expiration'		=> $expirationDate,
			);

		} else {
			$users = array($shareWith);
			$itemTarget = Helper::generateTarget($itemType, $itemSource, $shareType, $shareWith, $uidOwner,
					$suggestedItemTarget);
		}

		$run = true;
		$error = '';
		$preHookData = array(
			'itemType' => $itemType,
			'itemSource' => $itemSource,
			'shareType' => $shareType,
			'uidOwner' => $uidOwner,
			'permissions' => $permissions,
			'fileSource' => $fileSource,
			'expiration' => $expirationDate,
			'token' => $token,
			'run' => &$run,
			'error' => &$error
		);

		$preHookData['itemTarget'] = ($isGroupShare) ? $groupItemTarget : $itemTarget;
		$preHookData['shareWith'] = ($isGroupShare) ? $shareWith['group'] : $shareWith;

		\OC_Hook::emit('OCP\Share', 'pre_shared', $preHookData);

		if ($run === false) {
			throw new \Exception($error);
		}

		foreach ($users as $user) {
			$sourceId = ($itemType === 'file' || $itemType === 'folder') ? $fileSource : $itemSource;
			$sourceExists = self::getItemSharedWithBySource($itemType, $sourceId, self::FORMAT_NONE, null, true, $user);

			$shareType = ($isGroupShare) ? self::$shareTypeGroupUserUnique : $shareType;

			if ($sourceExists) {
				$fileTarget = $sourceExists['file_target'];
				$itemTarget = $sourceExists['item_target'];

				// for group shares we don't need a additional entry if the target is the same
				if($isGroupShare && $groupItemTarget === $itemTarget) {
					continue;
				}

			} elseif(!$sourceExists && !$isGroupShare)  {

				$itemTarget = Helper::generateTarget($itemType, $itemSource, $shareType, $user,
					$uidOwner, $suggestedItemTarget, $parent);
				if (isset($fileSource)) {
					if ($parentFolder) {
						if ($parentFolder === true) {
							$fileTarget = Helper::generateTarget('file', $filePath, $shareType, $user,
								$uidOwner, $suggestedFileTarget, $parent);
							if ($fileTarget != $groupFileTarget) {
								$parentFolders[$user]['folder'] = $fileTarget;
							}
						} else if (isset($parentFolder[$user])) {
							$fileTarget = $parentFolder[$user]['folder'].$itemSource;
							$parent = $parentFolder[$user]['id'];
						}
					} else {
						$fileTarget = Helper::generateTarget('file', $filePath, $shareType,
							$user, $uidOwner, $suggestedFileTarget, $parent);
					}
				} else {
					$fileTarget = null;
				}

			} else {

				// group share which doesn't exists until now, check if we need a unique target for this user

				$itemTarget = Helper::generateTarget($itemType, $itemSource, self::SHARE_TYPE_USER, $user,
					$uidOwner, $suggestedItemTarget, $parent);

				// do we also need a file target
				if (isset($fileSource)) {
					$fileTarget = Helper::generateTarget('file', $filePath, self::SHARE_TYPE_USER, $user,
							$uidOwner, $suggestedFileTarget, $parent);
				} else {
					$fileTarget = null;
				}

				if ($itemTarget === $groupItemTarget && (isset($fileSource) && $fileTarget === $groupItemTarget)) {
					continue;
				}
			}

			$queriesToExecute[] = array(
					'itemType'			=> $itemType,
					'itemSource'		=> $itemSource,
					'itemTarget'		=> $itemTarget,
					'shareType'			=> $shareType,
					'shareWith'			=> $user,
					'uidOwner'			=> $uidOwner,
					'permissions'		=> $permissions,
					'shareTime'			=> time(),
					'fileSource'		=> $fileSource,
					'fileTarget'		=> $fileTarget,
					'token'				=> $token,
					'parent'			=> $parent,
					'expiration'		=> $expirationDate,
				);

		}

		if ($isGroupShare) {
			self::insertShare($queriesToExecute['groupShare']);
			// Save this id, any extra rows for this group share will need to reference it
			$parent = \OC_DB::insertid('*PREFIX*share');
			unset($queriesToExecute['groupShare']);
		}

		foreach ($queriesToExecute as $shareQuery) {
			$shareQuery['parent'] = $parent;
			self::insertShare($shareQuery);
		}

		$postHookData = array(
			'itemType' => $itemType,
			'itemSource' => $itemSource,
			'parent' => $parent,
			'shareType' => $shareType,
			'uidOwner' => $uidOwner,
			'permissions' => $permissions,
			'fileSource' => $fileSource,
			'id' => $parent,
			'token' => $token,
			'expirationDate' => $expirationDate,
		);

		$postHookData['shareWith'] = ($isGroupShare) ? $shareWith['group'] : $shareWith;
		$postHookData['itemTarget'] = ($isGroupShare) ? $groupItemTarget : $itemTarget;
		$postHookData['fileTarget'] = ($isGroupShare) ? $groupFileTarget : $fileTarget;

		\OC_Hook::emit('OCP\Share', 'post_shared', $postHookData);


		return true;
	}

	private static function checkReshare($itemType, $itemSource, $shareType, $shareWith, $uidOwner, $permissions, $itemSourceName, $expirationDate) {
		$backend = self::getBackend($itemType);
		$l = \OC_L10N::get('lib');

		$column = ($itemType === 'file' || $itemType === 'folder') ? 'file_source' : 'item_source';

		$checkReshare = self::getItemSharedWithBySource($itemType, $itemSource, self::FORMAT_NONE, null, true);
		if ($checkReshare) {
			// Check if attempting to share back to owner
			if ($checkReshare['uid_owner'] == $shareWith && $shareType == self::SHARE_TYPE_USER) {
				$message = 'Sharing %s failed, because the user %s is the original sharer';
				$message_t = $l->t('Sharing %s failed, because the user %s is the original sharer', array($itemSourceName, $shareWith));

				\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $shareWith), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}

			// Check if share permissions is granted
			if (self::isResharingAllowed() && (int)$checkReshare['permissions'] & \OCP\PERMISSION_SHARE) {
				if (~(int)$checkReshare['permissions'] & $permissions) {
					$message = 'Sharing %s failed, because the permissions exceed permissions granted to %s';
					$message_t = $l->t('Sharing %s failed, because the permissions exceed permissions granted to %s', array($itemSourceName, $uidOwner));

					\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName, $uidOwner), \OC_Log::ERROR);
					throw new \Exception($message_t);
				} else {
					// TODO Don't check if inside folder
					$result['parent'] = $checkReshare['id'];
					$result['expirationDate'] = min($expirationDate, $checkReshare['expiration']);
					// only suggest the same name as new target if it is a reshare of the
					// same file/folder and not the reshare of a child
					if ($checkReshare[$column] === $itemSource) {
						$result['filePath'] = $checkReshare['file_target'];
						$result['itemSource'] = $checkReshare['item_source'];
						$result['fileSource'] = $checkReshare['file_source'];
						$result['suggestedItemTarget'] = $checkReshare['item_target'];
						$result['suggestedFileTarget'] = $checkReshare['file_target'];
					} else {
						$result['filePath'] = ($backend instanceof \OCP\Share_Backend_File_Dependent) ? $backend->getFilePath($itemSource, $uidOwner) : null;
						$result['suggestedItemTarget'] = null;
						$result['suggestedFileTarget'] = null;
						$result['itemSource'] = $itemSource;
						$result['fileSource'] = ($backend instanceof \OCP\Share_Backend_File_Dependent) ? $itemSource : null;
					}
				}
			} else {
				$message = 'Sharing %s failed, because resharing is not allowed';
				$message_t = $l->t('Sharing %s failed, because resharing is not allowed', array($itemSourceName));

				\OC_Log::write('OCP\Share', sprintf($message, $itemSourceName), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
		} else {
			$result['parent'] = null;
			$result['suggestedItemTarget'] = null;
			$result['suggestedFileTarget'] = null;
			$result['itemSource'] = $itemSource;
			$result['expirationDate'] = $expirationDate;
			if (!$backend->isValidSource($itemSource, $uidOwner)) {
				$message = 'Sharing %s failed, because the sharing backend for '
					.'%s could not find its source';
				$message_t = $l->t('Sharing %s failed, because the sharing backend for %s could not find its source', array($itemSource, $itemType));
				\OC_Log::write('OCP\Share', sprintf($message, $itemSource, $itemType), \OC_Log::ERROR);
				throw new \Exception($message_t);
			}
			if ($backend instanceof \OCP\Share_Backend_File_Dependent) {
				$result['filePath'] = $backend->getFilePath($itemSource, $uidOwner);
				if ($itemType == 'file' || $itemType == 'folder') {
					$result['fileSource'] = $itemSource;
				} else {
					$meta = \OC\Files\Filesystem::getFileInfo($result['filePath']);
					$result['fileSource'] = $meta['fileid'];
				}
				if ($result['fileSource'] == -1) {
					$message = 'Sharing %s failed, because the file could not be found in the file cache';
					$message_t = $l->t('Sharing %s failed, because the file could not be found in the file cache', array($itemSource));

					\OC_Log::write('OCP\Share', sprintf($message, $itemSource), \OC_Log::ERROR);
					throw new \Exception($message_t);
				}
			} else {
				$result['filePath'] = null;
				$result['fileSource'] = null;
			}
		}

		return $result;
	}

	private static function insertShare(array $shareData)
	{
		$query = \OC_DB::prepare('INSERT INTO `*PREFIX*share` ('
			.' `item_type`, `item_source`, `item_target`, `share_type`,'
			.' `share_with`, `uid_owner`, `permissions`, `stime`, `file_source`,'
			.' `file_target`, `token`, `parent`, `expiration`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
		$query->bindValue(1, $shareData['itemType']);
		$query->bindValue(2, $shareData['itemSource']);
		$query->bindValue(3, $shareData['itemTarget']);
		$query->bindValue(4, $shareData['shareType']);
		$query->bindValue(5, $shareData['shareWith']);
		$query->bindValue(6, $shareData['uidOwner']);
		$query->bindValue(7, $shareData['permissions']);
		$query->bindValue(8, $shareData['shareTime']);
		$query->bindValue(9, $shareData['fileSource']);
		$query->bindValue(10, $shareData['fileTarget']);
		$query->bindValue(11, $shareData['token']);
		$query->bindValue(12, $shareData['parent']);
		$query->bindValue(13, $shareData['expiration'], 'datetime');
		$query->execute();
	}
	/**
	 * Delete all shares with type SHARE_TYPE_LINK
	 */
	public static function removeAllLinkShares() {
		// Delete any link shares
		$query = \OC_DB::prepare('SELECT `id` FROM `*PREFIX*share` WHERE `share_type` = ?');
		$result = $query->execute(array(self::SHARE_TYPE_LINK));
		while ($item = $result->fetchRow()) {
			Helper::delete($item['id']);
		}
	}

	/**
	 * In case a password protected link is not yet authenticated this function will return false
	 *
	 * @param array $linkItem
	 * @return boolean
	 */
	public static function checkPasswordProtectedShare(array $linkItem) {
		if (!isset($linkItem['share_with'])) {
			return true;
		}
		if (!isset($linkItem['share_type'])) {
			return true;
		}
		if (!isset($linkItem['id'])) {
			return true;
		}

		if ($linkItem['share_type'] != \OCP\Share::SHARE_TYPE_LINK) {
			return true;
		}

		if ( \OC::$session->exists('public_link_authenticated')
			&& \OC::$session->get('public_link_authenticated') === $linkItem['id'] ) {
			return true;
		}

		return false;
	}

	/**
	 * construct select statement
	 * @param int $format
	 * @param boolean $fileDependent ist it a file/folder share or a generla share
	 * @param string $uidOwner
	 * @return string select statement
	 */
	private static function createSelectStatement($format, $fileDependent, $uidOwner = null) {
		$select = '*';
		if ($format == self::FORMAT_STATUSES) {
			if ($fileDependent) {
				$select = '`*PREFIX*share`.`id`, `*PREFIX*share`.`parent`, `share_type`, `path`, `storage`, `share_with`, `uid_owner` , `file_source`, `stime`, `*PREFIX*share`.`permissions`';
			} else {
				$select = '`id`, `parent`, `share_type`, `share_with`, `uid_owner`, `item_source`, `stime`, `*PREFIX*share`.`permissions`';
			}
		} else {
			if (isset($uidOwner)) {
				if ($fileDependent) {
					$select = '`*PREFIX*share`.`id`, `item_type`, `item_source`, `*PREFIX*share`.`parent`,'
							. ' `share_type`, `share_with`, `file_source`, `file_target`, `path`, `*PREFIX*share`.`permissions`, `stime`,'
							. ' `expiration`, `token`, `storage`, `mail_send`, `uid_owner`';
				} else {
					$select = '`id`, `item_type`, `item_source`, `parent`, `share_type`, `share_with`, `*PREFIX*share`.`permissions`,'
							. ' `stime`, `file_source`, `expiration`, `token`, `mail_send`, `uid_owner`';
				}
			} else {
				if ($fileDependent) {
					if ($format == \OC_Share_Backend_File::FORMAT_GET_FOLDER_CONTENTS || $format == \OC_Share_Backend_File::FORMAT_FILE_APP_ROOT) {
						$select = '`*PREFIX*share`.`id`, `item_type`, `item_source`, `*PREFIX*share`.`parent`, `uid_owner`, '
								. '`share_type`, `share_with`, `file_source`, `path`, `file_target`, `stime`, '
								. '`*PREFIX*share`.`permissions`, `expiration`, `storage`, `*PREFIX*filecache`.`parent` as `file_parent`, '
								. '`name`, `mtime`, `mimetype`, `mimepart`, `size`, `unencrypted_size`, `encrypted`, `etag`, `mail_send`';
					} else {
						$select = '`*PREFIX*share`.`id`, `item_type`, `item_source`, `item_target`,
							`*PREFIX*share`.`parent`, `share_type`, `share_with`, `uid_owner`,
							`file_source`, `path`, `file_target`, `*PREFIX*share`.`permissions`, `stime`, `expiration`, `token`, `storage`, `mail_send`';
					}
				}
			}
		}
		return $select;
	}


	/**
	 * transform db results
	 * @param array $row result
	 */
	private static function transformDBResults(&$row) {
		if (isset($row['id'])) {
			$row['id'] = (int) $row['id'];
		}
		if (isset($row['share_type'])) {
			$row['share_type'] = (int) $row['share_type'];
		}
		if (isset($row['parent'])) {
			$row['parent'] = (int) $row['parent'];
		}
		if (isset($row['file_parent'])) {
			$row['file_parent'] = (int) $row['file_parent'];
		}
		if (isset($row['file_source'])) {
			$row['file_source'] = (int) $row['file_source'];
		}
		if (isset($row['permissions'])) {
			$row['permissions'] = (int) $row['permissions'];
		}
		if (isset($row['storage'])) {
			$row['storage'] = (int) $row['storage'];
		}
		if (isset($row['stime'])) {
			$row['stime'] = (int) $row['stime'];
		}
	}

	/**
	 * format result
	 * @param array $items result
	 * @param string $column is it a file share or a general share ('file_target' or 'item_target')
	 * @param \OCP\Share_Backend $backend sharing backend
	 * @param int $format
	 * @param array $parameters additional format parameters
	 * @return array format result
	 */
	private static function formatResult($items, $column, $backend, $format = self::FORMAT_NONE , $parameters = null) {
		if ($format === self::FORMAT_NONE) {
			return $items;
		} else if ($format === self::FORMAT_STATUSES) {
			$statuses = array();
			foreach ($items as $item) {
				if ($item['share_type'] === self::SHARE_TYPE_LINK) {
					$statuses[$item[$column]]['link'] = true;
				} else if (!isset($statuses[$item[$column]])) {
					$statuses[$item[$column]]['link'] = false;
				}
				if (!empty($item['file_target'])) {
					$statuses[$item[$column]]['path'] = $item['path'];
				}
			}
			return $statuses;
		} else {
			return $backend->formatItems($items, $format, $parameters);
		}
	}

	/**
	 * check if user can only share with group members
	 * @return bool
	 */
	public static function shareWithGroupMembersOnly() {
		$value = \OC_Appconfig::getValue('core', 'shareapi_only_share_with_group_members', 'no');
		return ($value === 'yes') ? true : false;
	}

	public static function isDefaultExpireDateEnabled() {
		$defaultExpireDateEnabled = \OCP\Config::getAppValue('core', 'shareapi_default_expire_date', 'no');
		return ($defaultExpireDateEnabled === "yes") ? true : false;
	}

	public static function enforceDefaultExpireDate() {
		$enforceDefaultExpireDate = \OCP\Config::getAppValue('core', 'shareapi_enforce_expire_date', 'no');
		return ($enforceDefaultExpireDate === "yes") ? true : false;
	}

	public static function getExpireInterval() {
		return (int)\OCP\Config::getAppValue('core', 'shareapi_expire_after_n_days', '7');
	}

}
