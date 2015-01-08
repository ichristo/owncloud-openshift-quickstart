<?php

/**
* ownCloud - ajax frontend
*
* @author Jakob Sack
* @copyright 2011 Jakob Sack kde@jakobsack.de
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

$app = isset($_POST["app"]) ? $_POST["app"] : "";

$app = OC_App::cleanAppId($app);

$l = OC_L10N::get( $app );

OC_JSON::success(array('data' => $l->getTranslations(), 'plural_form' => $l->getPluralFormString()));
