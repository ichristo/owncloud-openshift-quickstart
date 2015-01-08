<?php

/**
* ownCloud
*
* @author Robin Appelman
* @copyright 2012 Robin Appelman icewind1991@gmail.com
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
 * wrapper for server side events (http://en.wikipedia.org/wiki/Server-sent_events)
 * includes a fallback for older browsers and IE
 *
 * use server side events with caution, to many open requests can hang the server
 */
class OC_EventSource{
	private $fallback;
	private $fallBackId=0;

	public function __construct() {
		OC_Util::obEnd();
		header('Cache-Control: no-cache');
		$this->fallback=isset($_GET['fallback']) and $_GET['fallback']=='true';
		if($this->fallback) {
			$this->fallBackId=$_GET['fallback_id'];
			header("Content-Type: text/html");
			echo str_repeat('<span></span>'.PHP_EOL, 10); //dummy data to keep IE happy
		}else{
			header("Content-Type: text/event-stream");
		}
		if( !OC_Util::isCallRegistered()) {
			$this->send('error', 'Possible CSRF attack. Connection will be closed.');
			exit();
		}
		flush();

	}

	/**
	 * send a message to the client
	 * @param string $type
	 * @param mixed $data
	 *
	 * if only one parameter is given, a typeless message will be send with that parameter as data
	 */
	public function send($type, $data=null) {
		if(is_null($data)) {
			$data=$type;
			$type=null;
		}
		if($this->fallback) {
			$fallBackId = OC_Util::sanitizeHTML($this->fallBackId);
			$response='<script type="text/javascript">window.parent.OC.EventSource.fallBackCallBack('
				.$fallBackId.',"' . $type . '",' . OCP\JSON::encode($data) . ')</script>' . PHP_EOL;
			echo $response;
		}else{
			if($type) {
				echo 'event: ' . $type.PHP_EOL;
			}
			echo 'data: ' . OCP\JSON::encode($data) . PHP_EOL;
		}
		echo PHP_EOL;
		flush();
	}

	/**
	 * close the connection of the even source
	 */
	public function close() {
		$this->send('__internal__', 'close');//server side closing can be an issue, let the client do it
	}
}
