<?php

/**
 * ownCloud - App Framework
 *
 * @author Jörn Dreyer
 * @copyright 2014 Jörn Dreyer jfd@owncloud.com
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

namespace OCP;


/**
 * Small Interface for Search
 */
interface ISearch {

	/**
	 * Search all providers for $query
	 * @param string $query
	 * @return array An array of OCP\Search\Result's
	 */
	public function search($query);

	/**
	 * Register a new search provider to search with
	 * @param string $class class name of a OCP\Search\Provider
	 * @param array $options optional
	 */
	public function registerProvider($class, $options = array());

	/**
	 * Remove one existing search provider
	 * @param string $provider class name of a OCP\Search\Provider
	 */
	public function removeProvider($provider);

	/**
	 * Remove all registered search providers
	 */
	public function clearProviders();

}
