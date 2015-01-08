<?php
/**
 * ownCloud
 *
 * @author Thomas Müller
 * @copyright 2013 Thomas Müller deepdiver@owncloud.com
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
 * AppFramework/App class
 */

namespace OCP\AppFramework;
use OC\AppFramework\routing\RouteConfig;


/**
 * Class App
 * @package OCP\AppFramework
 *
 * Any application must inherit this call - all controller instances to be used are
 * to be registered using IContainer::registerService
 */
class App {
	/**
	 * @param array $urlParams an array with variables extracted from the routes
	 */
	public function __construct($appName, $urlParams = array()) {
		$this->container = new \OC\AppFramework\DependencyInjection\DIContainer($appName, $urlParams);
	}

	private $container;

	/**
	 * @return IAppContainer
	 */
	public function getContainer() {
		return $this->container;
	}

	/**
	 * This function is to be called to create single routes and restful routes based on the given $routes array.
	 *
	 * Example code in routes.php of tasks app (it will register two restful resources):
	 * $routes = array(
	 *		'resources' => array(
	 *		'lists' => array('url' => '/tasklists'),
	 *		'tasks' => array('url' => '/tasklists/{listId}/tasks')
	 *	)
	 *	);
	 *
	 * $a = new TasksApp();
	 * $a->registerRoutes($this, $routes);
	 *
	 * @param \OCP\Route\IRouter $router
	 * @param array $routes
	 */
	public function registerRoutes($router, $routes) {
		$routeConfig = new RouteConfig($this->container, $router, $routes);
		$routeConfig->register();
	}

	/**
	 * This function is called by the routing component to fire up the frameworks dispatch mechanism.
	 *
	 * Example code in routes.php of the task app:
	 * $this->create('tasks_index', '/')->get()->action(
	 *		function($params){
	 *			$app = new TaskApp($params);
	 *			$app->dispatch('PageController', 'index');
	 *		}
	 *	);
	 *
	 *
	 * Example for for TaskApp implementation:
	 * class TaskApp extends \OCP\AppFramework\App {
	 *
	 *		public function __construct($params){
	 *			parent::__construct('tasks', $params);
	 *
	 *			$this->getContainer()->registerService('PageController', function(IAppContainer $c){
	 *				$a = $c->query('API');
	 *				$r = $c->query('Request');
	 *				return new PageController($a, $r);
	 *			});
	 *		}
	 *	}
	 *
	 * @param string $controllerName the name of the controller under which it is
	 *                               stored in the DI container
	 * @param string $methodName the method that you want to call
	 */
	public function dispatch($controllerName, $methodName) {
		\OC\AppFramework\App::main($controllerName, $methodName, $this->container);
	}
}
