<?php
/**
 * Copyright (c) 2012 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Route;

use OCP\Route\IRouter;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

class Router implements IRouter {
	/**
	 * @var \Symfony\Component\Routing\RouteCollection[]
	 */
	protected $collections = array();

	/**
	 * @var \Symfony\Component\Routing\RouteCollection
	 */
	protected $collection = null;

	/**
	 * @var string
	 */
	protected $collectionName = null;

	/**
	 * @var \Symfony\Component\Routing\RouteCollection
	 */
	protected $root = null;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGenerator
	 */
	protected $generator = null;

	/**
	 * @var string[]
	 */
	protected $routingFiles;

	/**
	 * @var string
	 */
	protected $cacheKey;

	protected $loaded = false;

	protected $loadedApps = array();

	public function __construct() {
		$baseUrl = \OC_Helper::linkTo('', 'index.php');
		if (!\OC::$CLI) {
			$method = $_SERVER['REQUEST_METHOD'];
		} else {
			$method = 'GET';
		}
		$host = \OC_Request::serverHost();
		$schema = \OC_Request::serverProtocol();
		$this->context = new RequestContext($baseUrl, $method, $host, $schema);
		// TODO cache
		$this->root = $this->getCollection('root');
	}

	/**
	 * Get the files to load the routes from
	 *
	 * @return string[]
	 */
	public function getRoutingFiles() {
		if (!isset($this->routingFiles)) {
			$this->routingFiles = array();
			foreach (\OC_APP::getEnabledApps() as $app) {
				$file = \OC_App::getAppPath($app) . '/appinfo/routes.php';
				if (file_exists($file)) {
					$this->routingFiles[$app] = $file;
				}
			}
		}
		return $this->routingFiles;
	}

	/**
	 * @return string
	 */
	public function getCacheKey() {
		if (!isset($this->cacheKey)) {
			$files = $this->getRoutingFiles();
			$files[] = 'settings/routes.php';
			$files[] = 'core/routes.php';
			$files[] = 'ocs/routes.php';
			$this->cacheKey = \OC\Cache::generateCacheKeyFromFiles($files);
		}
		return $this->cacheKey;
	}

	/**
	 * loads the api routes
	 * @return void
	 */
	public function loadRoutes($app = null) {
		if ($this->loaded) {
			return;
		}
		if (is_null($app)) {
			$this->loaded = true;
			$routingFiles = $this->getRoutingFiles();
		} else {
			if (isset($this->loadedApps[$app])) {
				return;
			}
			$file = \OC_App::getAppPath($app) . '/appinfo/routes.php';
			if (file_exists($file)) {
				$routingFiles = array($app => $file);
			} else {
				$routingFiles = array();
			}
		}
		foreach ($routingFiles as $app => $file) {
			if (!isset($this->loadedApps[$app])) {
				$this->loadedApps[$app] = true;
				$this->useCollection($app);
				$this->requireRouteFile($file);
				$collection = $this->getCollection($app);
				$collection->addPrefix('/apps/' . $app);
				$this->root->addCollection($collection);
			}
		}
		if (!isset($this->loadedApps['core'])) {
			$this->loadedApps['core'] = true;
			$this->useCollection('root');
			require_once 'settings/routes.php';
			require_once 'core/routes.php';

			// include ocs routes
			require_once 'ocs/routes.php';
			$collection = $this->getCollection('ocs');
			$collection->addPrefix('/ocs');
			$this->root->addCollection($collection);
		}
	}

	/**
	 * @param string $name
	 * @return \Symfony\Component\Routing\RouteCollection
	 */
	protected function getCollection($name) {
		if (!isset($this->collections[$name])) {
			$this->collections[$name] = new RouteCollection();
		}
		return $this->collections[$name];
	}

	/**
	 * Sets the collection to use for adding routes
	 *
	 * @param string $name Name of the collection to use.
	 * @return void
	 */
	public function useCollection($name) {
		$this->collection = $this->getCollection($name);
		$this->collectionName = $name;
	}

	/**
	 * returns the current collection name in use for adding routes
	 *
	 * @return string the collection name
	 */
	public function getCurrentCollection() {
		return $this->collectionName;
	}


	/**
	 * Create a \OC\Route\Route.
	 *
	 * @param string $name Name of the route to create.
	 * @param string $pattern The pattern to match
	 * @param array $defaults An array of default parameter values
	 * @param array $requirements An array of requirements for parameters (regexes)
	 * @return \OC\Route\Route
	 */
	public function create($name, $pattern, array $defaults = array(), array $requirements = array()) {
		$route = new Route($pattern, $defaults, $requirements);
		$this->collection->add($name, $route);
		return $route;
	}

	/**
	 * Find the route matching $url
	 *
	 * @param string $url The url to find
	 * @throws \Exception
	 * @return void
	 */
	public function match($url) {
		if (substr($url, 0, 6) === '/apps/') {
			// empty string / 'apps' / $app / rest of the route
			list(, , $app,) = explode('/', $url, 4);
			\OC::$REQUESTEDAPP = $app;
			$this->loadRoutes($app);
		} else if (substr($url, 0, 6) === '/core/' or substr($url, 0, 10) === '/settings/') {
			\OC::$REQUESTEDAPP = $url;
			if (!\OC_Config::getValue('maintenance', false) && !\OCP\Util::needUpgrade()) {
				\OC_App::loadApps();
			}
			$this->loadRoutes('core');
		} else {
			$this->loadRoutes();
		}
		$matcher = new UrlMatcher($this->root, $this->context);
		$parameters = $matcher->match($url);
		if (isset($parameters['action'])) {
			$action = $parameters['action'];
			if (!is_callable($action)) {
				var_dump($action);
				throw new \Exception('not a callable action');
			}
			unset($parameters['action']);
			call_user_func($action, $parameters);
		} elseif (isset($parameters['file'])) {
			include $parameters['file'];
		} else {
			throw new \Exception('no action available');
		}
	}

	/**
	 * Get the url generator
	 * @return \Symfony\Component\Routing\Generator\UrlGenerator
	 *
	 */
	public function getGenerator() {
		if (null !== $this->generator) {
			return $this->generator;
		}

		return $this->generator = new UrlGenerator($this->root, $this->context);
	}

	/**
	 * Generate url based on $name and $parameters
	 *
	 * @param string $name Name of the route to use.
	 * @param array $parameters Parameters for the route
	 * @param bool $absolute
	 * @return string
	 */
	public function generate($name, $parameters = array(), $absolute = false) {
		$this->loadRoutes();
		return $this->getGenerator()->generate($name, $parameters, $absolute);
	}

	/**
	 * To isolate the variable scope used inside the $file it is required in it's own method
	 * @param string $file
	 */
	private function requireRouteFile($file) {
		require_once $file;
	}

}
