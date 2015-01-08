<?php

/**
 * ownCloud - Core
 *
 * @author Morris Jobke
 * @copyright 2013 Morris Jobke morris.jobke@gmail.com
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

OCP\JSON::checkLoggedIn();
OCP\JSON::callCheck();
\OC::$session->close();

$files = new \OCA\Files\App(
	\OC\Files\Filesystem::getView(),
	\OC_L10n::get('files')
);
$result = $files->rename(
	$_GET["dir"],
	$_GET["file"],
	$_GET["newname"]
);

if($result['success'] === true){
	OCP\JSON::success(array('data' => $result['data']));
} else {
	OCP\JSON::error(array('data' => $result['data']));
}
