<?php
/**
 * ownCloud
 *
 * @author Michael Gapczynski
 * @copyright 2012 Michael Gapczynski mtgap@owncloud.com
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

OC_JSON::callCheck();
OC_JSON::checkSubAdminUser();
if (isset($_GET['offset'])) {
	$offset = $_GET['offset'];
} else {
	$offset = 0;
}
if (isset($_GET['limit'])) {
	$limit = $_GET['limit'];
} else {
	$limit = 10;
}
if (isset($_GET['gid']) && !empty($_GET['gid'])) {
	$gid = $_GET['gid'];
	if ($gid === '_everyone') {
		$gid = false;
	}
} else {
	$gid = false;
}
if (isset($_GET['pattern']) && !empty($_GET['pattern'])) {
	$pattern = $_GET['pattern'];
} else {
	$pattern = '';
}
$users = array();
$userManager = \OC_User::getManager();
if (OC_User::isAdminUser(OC_User::getUser())) {
	if($gid !== false) {
		$batch = OC_Group::displayNamesInGroup($gid, $pattern, $limit, $offset);
	} else {
		$batch = OC_User::getDisplayNames($pattern, $limit, $offset);
	}
	foreach ($batch as $uid => $displayname) {
		$user = $userManager->get($uid);
		$users[] = array(
			'name' => $uid,
			'displayname' => $displayname,
			'groups' => OC_Group::getUserGroups($uid),
			'subadmin' => OC_SubAdmin::getSubAdminsGroups($uid),
			'quota' => OC_Preferences::getValue($uid, 'files', 'quota', 'default'),
			'storageLocation' => $user->getHome(),
			'lastLogin' => $user->getLastLogin(),
		);
	}
} else {
	$groups = OC_SubAdmin::getSubAdminsGroups(OC_User::getUser());
	if($gid !== false && in_array($gid, $groups)) {
		$groups = array($gid);
	} elseif($gid !== false) {
		//don't you try to investigate loops you must not know about
		$groups = array();
	}
	$batch = OC_Group::usersInGroups($groups, $pattern, $limit, $offset);
	foreach ($batch as $uid) {
		$user = $userManager->get($uid);

		// Only add the groups, this user is a subadmin of
		$userGroups = array_intersect(OC_Group::getUserGroups($uid), OC_SubAdmin::getSubAdminsGroups(OC_User::getUser()));
		$users[] = array(
			'name' => $uid,
			'displayname' => $user->getDisplayName(),
			'groups' => $userGroups,
			'quota' => OC_Preferences::getValue($uid, 'files', 'quota', 'default'),
			'storageLocation' => $user->getHome(),
			'lastLogin' => $user->getLastLogin(),
		);
	}
}
OC_JSON::success(array('data' => $users));
