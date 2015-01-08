<?php

/**
 * ownCloud - App Framework
 *
 * @author Bernhard Posselt, Thomas Tanghus, Bart Visscher
 * @copyright 2012 Bernhard Posselt <dev@bernhard-posselt.com>
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


namespace OC\AppFramework\Http;

use \OC\AppFramework\Middleware\MiddlewareDispatcher;
use \OC\AppFramework\Http;
use \OC\AppFramework\Utility\ControllerMethodReflector;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;


/**
 * Class to dispatch the request to the middleware dispatcher
 */
class Dispatcher {

	private $middlewareDispatcher;
	private $protocol;
	private $reflector;
	private $request;

	/**
	 * @param Http $protocol the http protocol with contains all status headers
	 * @param MiddlewareDispatcher $middlewareDispatcher the dispatcher which
	 * runs the middleware
	 * @param ControllerMethodReflector the reflector that is used to inject
	 * the arguments for the controller
	 * @param IRequest $request the incoming request
	 */
	public function __construct(Http $protocol,
								MiddlewareDispatcher $middlewareDispatcher,
								ControllerMethodReflector $reflector,
								IRequest $request) {
		$this->protocol = $protocol;
		$this->middlewareDispatcher = $middlewareDispatcher;
		$this->reflector = $reflector;
		$this->request = $request;
	}


	/**
	 * Handles a request and calls the dispatcher on the controller
	 * @param Controller $controller the controller which will be called
	 * @param string $methodName the method name which will be called on
	 * the controller
	 * @return array $array[0] contains a string with the http main header,
	 * $array[1] contains headers in the form: $key => value, $array[2] contains
	 * the response output
	 */
	public function dispatch(Controller $controller, $methodName) {
		$out = array(null, array(), null);

		try {
			// prefill reflector with everything thats needed for the
			// middlewares
			$this->reflector->reflect($controller, $methodName);

			$this->middlewareDispatcher->beforeController($controller,
				$methodName);
			$response = $this->executeController($controller, $methodName);

			// if an exception appears, the middleware checks if it can handle the
			// exception and creates a response. If no response is created, it is
			// assumed that theres no middleware who can handle it and the error is
			// thrown again
		} catch(\Exception $exception){
			$response = $this->middlewareDispatcher->afterException(
				$controller, $methodName, $exception);
			if (is_null($response)) {
				throw $exception;
			}
		}

		$response = $this->middlewareDispatcher->afterController(
			$controller, $methodName, $response);

		// get the output which should be printed and run the after output
		// middleware to modify the response
		$output = $response->render();
		$out[2] = $this->middlewareDispatcher->beforeOutput(
			$controller, $methodName, $output);

		// depending on the cache object the headers need to be changed
		$out[0] = $this->protocol->getStatusHeader($response->getStatus(),
			$response->getLastModified(), $response->getETag());
		$out[1] = $response->getHeaders();

		return $out;
	}


	/**
	 * Uses the reflected parameters, types and request parameters to execute
	 * the controller
	 * @param Controller $controller the controller to be executed
	 * @param string $methodName the method on the controller that should be executed
	 * @return Response
	 */
	private function executeController($controller, $methodName) {
		$arguments = array();

		// valid types that will be casted
		$types = array('int', 'integer', 'bool', 'boolean', 'float');

		foreach($this->reflector->getParameters() as $param => $default) {

			// try to get the parameter from the request object and cast
			// it to the type annotated in the @param annotation
			$value = $this->request->getParam($param, $default);
			$type = $this->reflector->getType($param);

			// if this is submitted using GET or a POST form, 'false' should be
			// converted to false
			if(($type === 'bool' || $type === 'boolean') &&
				$value === 'false' &&
				(
					$this->request->method === 'GET' ||
					strpos($this->request->getHeader('Content-Type'),
						'application/x-www-form-urlencoded') !== false
				)
			) {
				$value = false;

			} elseif($value !== null && in_array($type, $types)) {
				settype($value, $type);
			}

			$arguments[] = $value;
		}

		$response = call_user_func_array(array($controller, $methodName), $arguments);

		// format response if not of type response
		if(!($response instanceof Response)) {

			// get format from the url format or request format parameter
			$format = $this->request->getParam('format');

			// if none is given try the first Accept header
			if($format === null) {
				$headers = $this->request->getHeader('Accept');
				$format = $controller->getResponderByHTTPHeader($headers);
			}

			$response = $controller->buildResponse($response, $format);
		}

		return $response;
	}

}
