<?php

/**
* ownCloud - user_ldap
*
* @author Dominik Schmidt
* @copyright 2011 Dominik Schmidt dev@dominik-schmidt.de
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

OCP\App::registerAdmin('user_ldap', 'settings');

$configPrefixes = OCA\user_ldap\lib\Helper::getServerConfigurationPrefixes(true);
$ldapWrapper = new OCA\user_ldap\lib\LDAP();
if(count($configPrefixes) === 1) {
	$ocConfig = \OC::$server->getConfig();
	$userManager = new OCA\user_ldap\lib\user\Manager($ocConfig,
		new OCA\user_ldap\lib\FilesystemHelper(),
		new OCA\user_ldap\lib\LogWrapper(),
		\OC::$server->getAvatarManager(),
		new \OCP\Image());
	$connector = new OCA\user_ldap\lib\Connection($ldapWrapper, $configPrefixes[0]);
	$ldapAccess = new OCA\user_ldap\lib\Access($connector, $ldapWrapper, $userManager);
	$userBackend  = new OCA\user_ldap\USER_LDAP($ldapAccess);
	$groupBackend = new OCA\user_ldap\GROUP_LDAP($ldapAccess);
} else if(count($configPrefixes) > 1) {
	$userBackend  = new OCA\user_ldap\User_Proxy($configPrefixes, $ldapWrapper);
	$groupBackend  = new OCA\user_ldap\Group_Proxy($configPrefixes, $ldapWrapper);
}

if(count($configPrefixes) > 0) {
	// register user backend
	OC_User::useBackend($userBackend);
	OC_Group::useBackend($groupBackend);
}

// add settings page to navigation
$entry = array(
	'id' => 'user_ldap_settings',
	'order'=>1,
	'href' => OCP\Util::linkTo( 'user_ldap', 'settings.php' ),
	'name' => 'LDAP'
);

OCP\Backgroundjob::registerJob('OCA\user_ldap\lib\Jobs');
if(OCP\App::isEnabled('user_webdavauth')) {
	OCP\Util::writeLog('user_ldap',
		'user_ldap and user_webdavauth are incompatible. You may experience unexpected behaviour',
		OCP\Util::WARN);
}
