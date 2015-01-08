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


namespace OC\AppFramework;

use OCP\AppFramework\Http as BaseHttp;

class Http extends BaseHttp {

	private $server;
	private $protocolVersion;
	protected $headers;

	/**
	 * @param array $server $_SERVER
	 * @param string $protocolVersion the http version to use defaults to HTTP/1.1
	 */
	public function __construct($server, $protocolVersion='HTTP/1.1') {
		$this->server = $server;
		$this->protocolVersion = $protocolVersion;

		$this->headers = array(
			self::STATUS_CONTINUE => 'Continue',
			self::STATUS_SWITCHING_PROTOCOLS => 'Switching Protocols',
			self::STATUS_PROCESSING => 'Processing',
			self::STATUS_OK => 'OK',
			self::STATUS_CREATED => 'Created',
			self::STATUS_ACCEPTED => 'Accepted',
			self::STATUS_NON_AUTHORATIVE_INFORMATION => 'Non-Authorative Information',
			self::STATUS_NO_CONTENT => 'No Content',
			self::STATUS_RESET_CONTENT => 'Reset Content',
			self::STATUS_PARTIAL_CONTENT => 'Partial Content',
			self::STATUS_MULTI_STATUS => 'Multi-Status', // RFC 4918
			self::STATUS_ALREADY_REPORTED => 'Already Reported', // RFC 5842
			self::STATUS_IM_USED => 'IM Used', // RFC 3229
			self::STATUS_MULTIPLE_CHOICES => 'Multiple Choices',
			self::STATUS_MOVED_PERMANENTLY => 'Moved Permanently',
			self::STATUS_FOUND => 'Found',
			self::STATUS_SEE_OTHER => 'See Other',
			self::STATUS_NOT_MODIFIED => 'Not Modified',
			self::STATUS_USE_PROXY => 'Use Proxy',
			self::STATUS_RESERVED => 'Reserved',
			self::STATUS_TEMPORARY_REDIRECT => 'Temporary Redirect',
			self::STATUS_BAD_REQUEST => 'Bad request',
			self::STATUS_UNAUTHORIZED => 'Unauthorized',
			self::STATUS_PAYMENT_REQUIRED => 'Payment Required',
			self::STATUS_FORBIDDEN => 'Forbidden',
			self::STATUS_NOT_FOUND => 'Not Found',
			self::STATUS_METHOD_NOT_ALLOWED => 'Method Not Allowed',
			self::STATUS_NOT_ACCEPTABLE => 'Not Acceptable',
			self::STATUS_PROXY_AUTHENTICATION_REQUIRED => 'Proxy Authentication Required',
			self::STATUS_REQUEST_TIMEOUT => 'Request Timeout',
			self::STATUS_CONFLICT => 'Conflict',
			self::STATUS_GONE => 'Gone',
			self::STATUS_LENGTH_REQUIRED => 'Length Required',
			self::STATUS_PRECONDITION_FAILED => 'Precondition failed',
			self::STATUS_REQUEST_ENTITY_TOO_LARGE => 'Request Entity Too Large',
			self::STATUS_REQUEST_URI_TOO_LONG => 'Request-URI Too Long',
			self::STATUS_UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
			self::STATUS_REQUEST_RANGE_NOT_SATISFIABLE => 'Requested Range Not Satisfiable',
			self::STATUS_EXPECTATION_FAILED => 'Expectation Failed',
			self::STATUS_IM_A_TEAPOT => 'I\'m a teapot', // RFC 2324
			self::STATUS_UNPROCESSABLE_ENTITY => 'Unprocessable Entity', // RFC 4918
			self::STATUS_LOCKED => 'Locked', // RFC 4918
			self::STATUS_FAILED_DEPENDENCY => 'Failed Dependency', // RFC 4918
			self::STATUS_UPGRADE_REQUIRED => 'Upgrade required',
			self::STATUS_PRECONDITION_REQUIRED => 'Precondition required', // draft-nottingham-http-new-status
			self::STATUS_TOO_MANY_REQUESTS => 'Too Many Requests', // draft-nottingham-http-new-status
			self::STATUS_REQUEST_HEADER_FIELDS_TOO_LARGE => 'Request Header Fields Too Large', // draft-nottingham-http-new-status
			self::STATUS_INTERNAL_SERVER_ERROR => 'Internal Server Error',
			self::STATUS_NOT_IMPLEMENTED => 'Not Implemented',
			self::STATUS_BAD_GATEWAY => 'Bad Gateway',
			self::STATUS_SERVICE_UNAVAILABLE => 'Service Unavailable',
			self::STATUS_GATEWAY_TIMEOUT => 'Gateway Timeout',
			self::STATUS_HTTP_VERSION_NOT_SUPPORTED => 'HTTP Version not supported',
			self::STATUS_VARIANT_ALSO_NEGOTIATES => 'Variant Also Negotiates',
			self::STATUS_INSUFFICIENT_STORAGE => 'Insufficient Storage', // RFC 4918
			self::STATUS_LOOP_DETECTED => 'Loop Detected', // RFC 5842
			self::STATUS_BANDWIDTH_LIMIT_EXCEEDED => 'Bandwidth Limit Exceeded', // non-standard
			self::STATUS_NOT_EXTENDED => 'Not extended',
			self::STATUS_NETWORK_AUTHENTICATION_REQUIRED => 'Network Authentication Required', // draft-nottingham-http-new-status
		);
	}


	/**
	 * Gets the correct header
	 * @param Http::CONSTANT $status the constant from the Http class
	 * @param \DateTime $lastModified formatted last modified date
	 * @param string $ETag the etag
	 */
	public function getStatusHeader($status, \DateTime $lastModified=null, 
	                                $ETag=null) {

		if(!is_null($lastModified)) {
			$lastModified = $lastModified->format(\DateTime::RFC2822);
		}

		// if etag or lastmodified have not changed, return a not modified
		if ((isset($this->server['HTTP_IF_NONE_MATCH'])
			&& trim($this->server['HTTP_IF_NONE_MATCH']) === $ETag) 

			||

			(isset($this->server['HTTP_IF_MODIFIED_SINCE'])
			&& trim($this->server['HTTP_IF_MODIFIED_SINCE']) === 
				$lastModified)) {

			$status = self::STATUS_NOT_MODIFIED;
		}

		// we have one change currently for the http 1.0 header that differs
		// from 1.1: STATUS_TEMPORARY_REDIRECT should be STATUS_FOUND
		// if this differs any more, we want to create childclasses for this
		if($status === self::STATUS_TEMPORARY_REDIRECT 
			&& $this->protocolVersion === 'HTTP/1.0') {

			$status = self::STATUS_FOUND;
		}

		return $this->protocolVersion . ' ' . $status . ' ' . 
			$this->headers[$status];
	}


}


