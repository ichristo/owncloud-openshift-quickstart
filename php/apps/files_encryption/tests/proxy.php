<?php
/**
 * ownCloud
 *
 * @author Bjoern Schiessle
 * @copyright 2013 Bjoern Schiessle <schiessle@owncloud.com>
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

require_once __DIR__ . '/../../../lib/base.php';
require_once __DIR__ . '/../lib/crypt.php';
require_once __DIR__ . '/../lib/keymanager.php';
require_once __DIR__ . '/../lib/proxy.php';
require_once __DIR__ . '/../lib/stream.php';
require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../appinfo/app.php';

use OCA\Encryption;

/**
 * Class Test_Encryption_Proxy
 * this class provide basic proxy app tests
 */
class Test_Encryption_Proxy extends \OCA\Files_Encryption\Tests\TestCase {

	const TEST_ENCRYPTION_PROXY_USER1 = "test-proxy-user1";

	public $userId;
	public $pass;
	/**
	 * @var \OC\Files\View
	 */
	public $view;     // view in /data/user/files
	public $rootView; // view on /data/user
	public $data;
	public $dataLong;
	public $filename;

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();

		// reset backend
		\OC_User::clearBackends();
		\OC_User::useBackend('database');

		\OC_Hook::clear('OC_Filesystem');
		\OC_Hook::clear('OC_User');

		// Filesystem related hooks
		\OCA\Encryption\Helper::registerFilesystemHooks();

		// clear and register hooks
		\OC_FileProxy::clearProxies();
		\OC_FileProxy::register(new OCA\Encryption\Proxy());

		// create test user
		self::loginHelper(\Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1, true);
	}

	protected function setUp() {
		parent::setUp();

		// set user id
		\OC_User::setUserId(\Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1);
		$this->userId = \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1;
		$this->pass = \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1;

		// init filesystem view
		$this->view = new \OC\Files\View('/'. \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1 . '/files');
		$this->rootView = new \OC\Files\View('/'. \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1 );

		// init short data
		$this->data = 'hats';
		$this->dataLong = file_get_contents(__DIR__ . '/../lib/crypt.php');
		$this->filename = 'enc_proxy_tests-' . $this->getUniqueID() . '.txt';

	}

	public static function tearDownAfterClass() {
		// cleanup test user
		\OC_User::deleteUser(\Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1);

		\OC_Hook::clear();
		\OC_FileProxy::clearProxies();

		// Delete keys in /data/
		$view = new \OC\Files\View('/');
		$view->rmdir('public-keys');
		$view->rmdir('owncloud_private_key');

		parent::tearDownAfterClass();
	}

	/**
	 * @medium
	 * test if postFileSize returns the unencrypted file size
	 */
	function testPostFileSize() {

		$this->view->file_put_contents($this->filename, $this->dataLong);
		$size = strlen($this->dataLong);

		\OC_FileProxy::$enabled = false;

		$encryptedSize = $this->view->filesize($this->filename);

		\OC_FileProxy::$enabled = true;

		$unencryptedSize = $this->view->filesize($this->filename);

		$this->assertTrue($encryptedSize > $unencryptedSize);
		$this->assertSame($size, $unencryptedSize);

		// cleanup
		$this->view->unlink($this->filename);

	}

	function testPostFileSizeWithDirectory() {

		$this->view->file_put_contents($this->filename, $this->data);

		\OC_FileProxy::$enabled = false;

		// get root size, must match the file's unencrypted size
		$unencryptedSize = $this->view->filesize('');

		\OC_FileProxy::$enabled = true;

		$encryptedSize = $this->view->filesize('');

		$this->assertTrue($encryptedSize !== $unencryptedSize);

		// cleanup
		$this->view->unlink($this->filename);

	}

	/**
	 * @dataProvider isExcludedPathProvider
	 */
	function testIsExcludedPath($path, $expected) {
		$this->view->mkdir(dirname($path));
		$this->view->file_put_contents($path, "test");

		$testClass = new DummyProxy();

		$result = $testClass->isExcludedPathTesting($path, $this->userId);
		$this->assertSame($expected, $result);

		$this->view->deleteAll(dirname($path));

	}

	public function isExcludedPathProvider() {
		return array(
			array ('/' . \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1 . '/files/test.txt', false),
			array (\Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1 . '/files/test.txt', false),
			array ('/files/test.txt', true),
			array ('/' . \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1 . '/files/versions/test.txt', false),
			array ('/' . \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1 . '/files_versions/test.txt', false),
			array ('/' . \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1 . '/files_trashbin/test.txt', true),
			array ('/' . \Test_Encryption_Proxy::TEST_ENCRYPTION_PROXY_USER1 . '/file/test.txt', true),
		);
	}

}


/**
 * Dummy class to make protected methods available for testing
 */
class DummyProxy extends \OCA\Encryption\Proxy {
	public function isExcludedPathTesting($path, $uid) {
		return $this->isExcludedPath($path, $uid);
	}
}
