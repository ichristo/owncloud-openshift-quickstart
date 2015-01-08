<?php
/**
 * Copyright (c) 2012 Sam Tuke <samtuke@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
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
 * Class Test_Encryption_Util
 */
class Test_Encryption_Util extends \OCA\Files_Encryption\Tests\TestCase {

	const TEST_ENCRYPTION_UTIL_USER1 = "test-util-user1";
	const TEST_ENCRYPTION_UTIL_USER2 = "test-util-user2";
	const TEST_ENCRYPTION_UTIL_GROUP1 = "test-util-group1";
	const TEST_ENCRYPTION_UTIL_GROUP2 = "test-util-group2";
	const TEST_ENCRYPTION_UTIL_LEGACY_USER = "test-legacy-user";

	public $userId;
	public $encryptionDir;
	public $publicKeyDir;
	public $pass;
	/**
	 * @var OC\Files\View
	 */
	public $view;
	public $keyfilesPath;
	public $publicKeyPath;
	public $privateKeyPath;
	/**
	 * @var \OCA\Encryption\Util
	 */
	public $util;
	public $dataShort;
	public $legacyEncryptedData;
	public $legacyEncryptedDataKey;
	public $legacyKey;
	public $stateFilesTrashbin;

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();

		// reset backend
		\OC_User::clearBackends();
		\OC_User::useBackend('database');

		self::setupHooks();

		// create test user
		self::loginHelper(self::TEST_ENCRYPTION_UTIL_USER1, true);
		self::loginHelper(self::TEST_ENCRYPTION_UTIL_USER2, true);
		self::loginHelper(self::TEST_ENCRYPTION_UTIL_LEGACY_USER, true);

		// create groups
		\OC_Group::createGroup(self::TEST_ENCRYPTION_UTIL_GROUP1);
		\OC_Group::createGroup(self::TEST_ENCRYPTION_UTIL_GROUP2);

		// add user 1 to group1
		\OC_Group::addToGroup(self::TEST_ENCRYPTION_UTIL_USER1, self::TEST_ENCRYPTION_UTIL_GROUP1);
	}

	protected function setUp() {
		parent::setUp();

		// login user
		self::loginHelper(self::TEST_ENCRYPTION_UTIL_USER1);
		\OC_User::setUserId(self::TEST_ENCRYPTION_UTIL_USER1);
		$this->userId = self::TEST_ENCRYPTION_UTIL_USER1;
		$this->pass = self::TEST_ENCRYPTION_UTIL_USER1;

		// set content for encrypting / decrypting in tests
		$this->dataUrl = __DIR__ . '/../lib/crypt.php';
		$this->dataShort = 'hats';
		$this->dataLong = file_get_contents(__DIR__ . '/../lib/crypt.php');
		$this->legacyData = __DIR__ . '/legacy-text.txt';
		$this->legacyEncryptedData = __DIR__ . '/legacy-encrypted-text.txt';
		$this->legacyEncryptedDataKey = __DIR__ . '/encryption.key';
		$this->legacyKey = "30943623843030686906\0\0\0\0";

		$keypair = Encryption\Crypt::createKeypair();

		$this->genPublicKey = $keypair['publicKey'];
		$this->genPrivateKey = $keypair['privateKey'];

		$this->publicKeyDir = '/' . 'public-keys';
		$this->encryptionDir = '/' . $this->userId . '/' . 'files_encryption';
		$this->keyfilesPath = $this->encryptionDir . '/' . 'keyfiles';
		$this->publicKeyPath =
			$this->publicKeyDir . '/' . $this->userId . '.public.key'; // e.g. data/public-keys/admin.public.key
		$this->privateKeyPath =
			$this->encryptionDir . '/' . $this->userId . '.private.key'; // e.g. data/admin/admin.private.key

		$this->view = new \OC\Files\View('/');

		$this->util = new Encryption\Util($this->view, $this->userId);

		// remember files_trashbin state
		$this->stateFilesTrashbin = OC_App::isEnabled('files_trashbin');

		// we don't want to tests with app files_trashbin enabled
		\OC_App::disable('files_trashbin');
	}

	protected function tearDown() {
		// reset app files_trashbin
		if ($this->stateFilesTrashbin) {
			OC_App::enable('files_trashbin');
		}
		else {
			OC_App::disable('files_trashbin');
		}

		parent::tearDown();
	}

	public static function tearDownAfterClass() {
		// cleanup test user
		\OC_User::deleteUser(self::TEST_ENCRYPTION_UTIL_USER1);
		\OC_User::deleteUser(self::TEST_ENCRYPTION_UTIL_USER2);
		\OC_User::deleteUser(self::TEST_ENCRYPTION_UTIL_LEGACY_USER);

		//cleanup groups
		\OC_Group::deleteGroup(self::TEST_ENCRYPTION_UTIL_GROUP1);
		\OC_Group::deleteGroup(self::TEST_ENCRYPTION_UTIL_GROUP2);

		\OC_Hook::clear();
		\OC_FileProxy::clearProxies();

		// Delete keys in /data/
		$view = new \OC\Files\View('/');
		$view->rmdir('public-keys');
		$view->rmdir('owncloud_private_key');

		parent::tearDownAfterClass();
	}

	public static function setupHooks() {
		// Filesystem related hooks
		\OCA\Encryption\Helper::registerFilesystemHooks();

		// clear and register hooks
		\OC_FileProxy::clearProxies();
		\OC_FileProxy::register(new OCA\Encryption\Proxy());
	}

	/**
	 * @medium
	 * test that paths set during User construction are correct
	 */
	function testKeyPaths() {
		$util = new Encryption\Util($this->view, $this->userId);

		$this->assertEquals($this->publicKeyDir, $util->getPath('publicKeyDir'));
		$this->assertEquals($this->encryptionDir, $util->getPath('encryptionDir'));
		$this->assertEquals($this->keyfilesPath, $util->getPath('keyfilesPath'));
		$this->assertEquals($this->publicKeyPath, $util->getPath('publicKeyPath'));
		$this->assertEquals($this->privateKeyPath, $util->getPath('privateKeyPath'));

	}

	/**
	 * @medium
	 * test detection of encrypted files
	 */
	function testIsEncryptedPath() {

		$util = new Encryption\Util($this->view, $this->userId);

		self::loginHelper($this->userId);

		$unencryptedFile = '/tmpUnencrypted-' . $this->getUniqueID() . '.txt';
		$encryptedFile =  '/tmpEncrypted-' . $this->getUniqueID() . '.txt';

		// Disable encryption proxy to write a unencrypted file
		$proxyStatus = \OC_FileProxy::$enabled;
		\OC_FileProxy::$enabled = false;

		$this->view->file_put_contents($this->userId . '/files/' . $unencryptedFile, $this->dataShort);

		// Re-enable proxy - our work is done
		\OC_FileProxy::$enabled = $proxyStatus;

		// write a encrypted file
		$this->view->file_put_contents($this->userId . '/files/' . $encryptedFile, $this->dataShort);

		// test if both files are detected correctly
		$this->assertFalse($util->isEncryptedPath($this->userId . '/files/' . $unencryptedFile));
		$this->assertTrue($util->isEncryptedPath($this->userId . '/files/' . $encryptedFile));

		// cleanup
		$this->view->unlink($this->userId . '/files/' . $unencryptedFile);
		$this->view->unlink($this->userId . '/files/' . $encryptedFile);

	}

	/**
	 * @medium
	 * test setup of encryption directories
	 */
	function testSetupServerSide() {
		$this->assertEquals(true, $this->util->setupServerSide($this->pass));
	}

	/**
	 * @medium
	 * test checking whether account is ready for encryption,
	 */
	function testUserIsReady() {
		$this->assertEquals(true, $this->util->ready());
	}

	/**
	 * test checking whether account is not ready for encryption,
	 */
//	function testUserIsNotReady() {
//		$this->view->unlink($this->publicKeyDir);
//
//		$params['uid'] = $this->userId;
//		$params['password'] = $this->pass;
//		$this->assertFalse(OCA\Encryption\Hooks::login($params));
//
//		$this->view->unlink($this->privateKeyPath);
//	}

	/**
	 * @medium
	 * test checking whether account is not ready for encryption,
	 */
	function testIsLegacyUser() {
		\Test_Encryption_Util::loginHelper(\Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER);

		$userView = new \OC\Files\View('/' . \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER);

		// Disable encryption proxy to prevent recursive calls
		$proxyStatus = \OC_FileProxy::$enabled;
		\OC_FileProxy::$enabled = false;

		$encryptionKeyContent = file_get_contents($this->legacyEncryptedDataKey);
		$userView->file_put_contents('/encryption.key', $encryptionKeyContent);

		\OC_FileProxy::$enabled = $proxyStatus;

		$params['uid'] = \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER;
		$params['password'] = \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER;

		$this->setMigrationStatus(0, \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER);

		$this->assertTrue(OCA\Encryption\Hooks::login($params));

		$this->assertEquals($this->legacyKey, \OC::$session->get('legacyKey'));
	}

	/**
	 * @medium
	 */
	function testRecoveryEnabledForUser() {

		$util = new Encryption\Util($this->view, $this->userId);

		// Record the value so we can return it to it's original state later
		$enabled = $util->recoveryEnabledForUser();

		$this->assertTrue($util->setRecoveryForUser(!$enabled));

		$this->assertEquals(!$enabled, $util->recoveryEnabledForUser());

		$this->assertTrue($util->setRecoveryForUser($enabled));

		$this->assertEquals($enabled, $util->recoveryEnabledForUser());


	}

	/**
	 * @medium
	 */
	function testGetUidAndFilename() {

		\OC_User::setUserId(self::TEST_ENCRYPTION_UTIL_USER1);

		$filename = '/tmp-' . $this->getUniqueID() . '.test';

		// Disable encryption proxy to prevent recursive calls
		$proxyStatus = \OC_FileProxy::$enabled;
		\OC_FileProxy::$enabled = false;

		$this->view->file_put_contents($this->userId . '/files/' . $filename, $this->dataShort);

		// Re-enable proxy - our work is done
		\OC_FileProxy::$enabled = $proxyStatus;

		$util = new Encryption\Util($this->view, $this->userId);

		list($fileOwnerUid, $file) = $util->getUidAndFilename($filename);

		$this->assertEquals(self::TEST_ENCRYPTION_UTIL_USER1, $fileOwnerUid);

		$this->assertEquals($file, $filename);

		$this->view->unlink($this->userId . '/files/' . $filename);
	}

	/**
<	 * Test that data that is read by the crypto stream wrapper
	 */
	function testGetFileSize() {
		self::loginHelper(self::TEST_ENCRYPTION_UTIL_USER1);

		$filename = 'tmp-' . $this->getUniqueID();
		$externalFilename = '/' . $this->userId . '/files/' . $filename;

		// Test for 0 byte files
		$problematicFileSizeData = "";
		$cryptedFile = $this->view->file_put_contents($externalFilename, $problematicFileSizeData);
		$this->assertTrue(is_int($cryptedFile));
		$this->assertEquals($this->util->getFileSize($externalFilename), 0);
		$decrypt = $this->view->file_get_contents($externalFilename);
		$this->assertEquals($problematicFileSizeData, $decrypt);
		$this->view->unlink($this->userId . '/files/' . $filename);

		// Test a file with 18377 bytes as in https://github.com/owncloud/mirall/issues/1009
		$problematicFileSizeData = str_pad("", 18377, "abc");
		$cryptedFile = $this->view->file_put_contents($externalFilename, $problematicFileSizeData);
		$this->assertTrue(is_int($cryptedFile));
		$this->assertEquals($this->util->getFileSize($externalFilename), 18377);
		$decrypt = $this->view->file_get_contents($externalFilename);
		$this->assertEquals($problematicFileSizeData, $decrypt);
		$this->view->unlink($this->userId . '/files/' . $filename);
	}

	function testEncryptAll() {

		$filename = "/encryptAll" . $this->getUniqueID() . ".txt";
		$util = new Encryption\Util($this->view, $this->userId);

		// disable encryption to upload a unencrypted file
		\OC_App::disable('files_encryption');

		$this->view->file_put_contents($this->userId . '/files/' . $filename, $this->dataShort);

		$fileInfoUnencrypted = $this->view->getFileInfo($this->userId . '/files/' . $filename);

		$this->assertTrue($fileInfoUnencrypted instanceof \OC\Files\FileInfo);

		// enable file encryption again
		\OC_App::enable('files_encryption');

		// encrypt all unencrypted files
		$util->encryptAll('/' . $this->userId . '/' . 'files');

		$fileInfoEncrypted = $this->view->getFileInfo($this->userId . '/files/' . $filename);

		$this->assertTrue($fileInfoEncrypted instanceof \OC\Files\FileInfo);

		// check if mtime and etags unchanged
		$this->assertEquals($fileInfoEncrypted['mtime'], $fileInfoUnencrypted['mtime']);
		$this->assertSame($fileInfoEncrypted['etag'], $fileInfoUnencrypted['etag']);

		$this->view->unlink($this->userId . '/files/' . $filename);
	}

	function testDecryptAll() {

		$filename = "/decryptAll" . $this->getUniqueID() . ".txt";
		$datadir = \OC_Config::getValue('datadirectory', \OC::$SERVERROOT . '/data/');
		$userdir = $datadir . '/' . $this->userId . '/files/';

		$this->view->file_put_contents($this->userId . '/files/' . $filename, $this->dataShort);

		$fileInfoEncrypted = $this->view->getFileInfo($this->userId . '/files/' . $filename);

		$this->assertTrue($fileInfoEncrypted instanceof \OC\Files\FileInfo);
		$this->assertEquals($fileInfoEncrypted['encrypted'], 1);

		$encContent = file_get_contents($userdir . $filename);

		\OC_App::disable('files_encryption');

		$user = \OCP\User::getUser();
		$this->logoutHelper();
		$this->loginHelper($user, false, false, false);

		$content = file_get_contents($userdir . $filename);

		//content should be encrypted
		$this->assertSame($encContent, $content);

		// now we load the encryption app again
		OC_App::loadApp('files_encryption');

		// init encryption app
		$params = array('uid' => \OCP\User::getUser(),
			'password' => \OCP\User::getUser());

		$view = new OC\Files\View('/');
		$util = new \OCA\Encryption\Util($view, \OCP\User::getUser());

		$result = $util->initEncryption($params);

		$this->assertTrue($result instanceof \OCA\Encryption\Session);

		$successful = $util->decryptAll();

		$this->assertTrue($successful);

		$this->logoutHelper();
		$this->loginHelper($user, false, false, false);

		// file should be unencrypted and fileInfo should contain the correct values
		$content = file_get_contents($userdir . $filename);

		// now we should get the plain data
		$this->assertSame($this->dataShort, $content);

		$fileInfoUnencrypted = $this->view->getFileInfo($this->userId . '/files/' . $filename);
		$this->assertTrue($fileInfoUnencrypted instanceof \OC\Files\FileInfo);

		// check if mtime and etags unchanged
		$this->assertEquals($fileInfoEncrypted['mtime'], $fileInfoUnencrypted['mtime']);
		$this->assertSame($fileInfoEncrypted['etag'], $fileInfoUnencrypted['etag']);
		// file should no longer be encrypted
		$this->assertEquals(0, $fileInfoUnencrypted['encrypted']);

		// check if the keys where moved to the backup location
		$this->assertTrue($this->view->is_dir($this->userId . '/files_encryption/keyfiles.backup'));
		$this->assertTrue($this->view->file_exists($this->userId . '/files_encryption/keyfiles.backup/' . $filename . '.key'));
		$this->assertTrue($this->view->is_dir($this->userId . '/files_encryption/share-keys.backup'));
		$this->assertTrue($this->view->file_exists($this->userId . '/files_encryption/share-keys.backup/' . $filename . '.' . $user . '.shareKey'));

		// cleanup
		$this->view->unlink($this->userId . '/files/' . $filename);
		$this->view->deleteAll($this->userId . '/files_encryption/keyfiles.backup');
		$this->view->deleteAll($this->userId . '/files_encryption/share-keys.backup');
		OC_App::enable('files_encryption');

	}

	/**
	 * test if all keys get moved to the backup folder correctly
	 */
	function testBackupAllKeys() {
		self::loginHelper(self::TEST_ENCRYPTION_UTIL_USER1);

		// create some dummy key files
		$encPath = '/' . self::TEST_ENCRYPTION_UTIL_USER1 . '/files_encryption';
		$this->view->file_put_contents($encPath . '/keyfiles/foo.key', 'key');
		$this->view->file_put_contents($encPath . '/share-keys/foo.user1.shareKey', 'share key');

		$util = new \OCA\Encryption\Util($this->view, self::TEST_ENCRYPTION_UTIL_USER1);

		$util->backupAllKeys('testing');

		$encFolderContent = $this->view->getDirectoryContent($encPath);

		$backupPath = '';
		foreach ($encFolderContent as $c) {
			$name = $c['name'];
			if (substr($name, 0, strlen('backup'))  === 'backup') {
				$backupPath = $encPath . '/'. $c['name'];
				break;
			}
		}

		$this->assertTrue($backupPath !== '');

		// check backupDir Content
		$this->assertTrue($this->view->is_dir($backupPath . '/keyfiles'));
		$this->assertTrue($this->view->is_dir($backupPath . '/share-keys'));
		$this->assertTrue($this->view->file_exists($backupPath . '/keyfiles/foo.key'));
		$this->assertTrue($this->view->file_exists($backupPath . '/share-keys/foo.user1.shareKey'));
		$this->assertTrue($this->view->file_exists($backupPath . '/' . self::TEST_ENCRYPTION_UTIL_USER1 . '.private.key'));
		$this->assertTrue($this->view->file_exists($backupPath . '/' . self::TEST_ENCRYPTION_UTIL_USER1 . '.public.key'));

		//cleanup
		$this->view->deleteAll($backupPath);
		$this->view->unlink($encPath . '/keyfiles/foo.key', 'key');
		$this->view->unlink($encPath . '/share-keys/foo.user1.shareKey', 'share key');
	}


	function testDescryptAllWithBrokenFiles() {

		$file1 = "/decryptAll1" . $this->getUniqueID() . ".txt";
		$file2 = "/decryptAll2" . $this->getUniqueID() . ".txt";

		$util = new Encryption\Util($this->view, $this->userId);

		$this->view->file_put_contents($this->userId . '/files/' . $file1, $this->dataShort);
		$this->view->file_put_contents($this->userId . '/files/' . $file2, $this->dataShort);

		$fileInfoEncrypted1 = $this->view->getFileInfo($this->userId . '/files/' . $file1);
		$fileInfoEncrypted2 = $this->view->getFileInfo($this->userId . '/files/' . $file2);

		$this->assertTrue($fileInfoEncrypted1 instanceof \OC\Files\FileInfo);
		$this->assertTrue($fileInfoEncrypted2 instanceof \OC\Files\FileInfo);
		$this->assertEquals($fileInfoEncrypted1['encrypted'], 1);
		$this->assertEquals($fileInfoEncrypted2['encrypted'], 1);

		// rename keyfile for file1 so that the decryption for file1 fails
		// Expected behaviour: decryptAll() returns false, file2 gets decrypted anyway
		$this->view->rename($this->userId . '/files_encryption/keyfiles/' . $file1 . '.key',
				$this->userId . '/files_encryption/keyfiles/' . $file1 . '.key.moved');

		// decrypt all encrypted files
		$result = $util->decryptAll();

		$this->assertFalse($result);

		$fileInfoUnencrypted1 = $this->view->getFileInfo($this->userId . '/files/' . $file1);
		$fileInfoUnencrypted2 = $this->view->getFileInfo($this->userId . '/files/' . $file2);

		$this->assertTrue($fileInfoUnencrypted1 instanceof \OC\Files\FileInfo);
		$this->assertTrue($fileInfoUnencrypted2 instanceof \OC\Files\FileInfo);

		// file1 should be still encrypted; file2 should be decrypted
		$this->assertEquals(1, $fileInfoUnencrypted1['encrypted']);
		$this->assertEquals(0, $fileInfoUnencrypted2['encrypted']);

		// keyfiles and share keys should still exist
		$this->assertTrue($this->view->is_dir($this->userId . '/files_encryption/keyfiles/'));
		$this->assertTrue($this->view->is_dir($this->userId . '/files_encryption/share-keys/'));

		// rename the keyfile for file1 back
		$this->view->rename($this->userId . '/files_encryption/keyfiles/' . $file1 . '.key.moved',
				$this->userId . '/files_encryption/keyfiles/' . $file1 . '.key');

		// try again to decrypt all encrypted files
		$result = $util->decryptAll();

		$this->assertTrue($result);

		$fileInfoUnencrypted1 = $this->view->getFileInfo($this->userId . '/files/' . $file1);
		$fileInfoUnencrypted2 = $this->view->getFileInfo($this->userId . '/files/' . $file2);

		$this->assertTrue($fileInfoUnencrypted1 instanceof \OC\Files\FileInfo);
		$this->assertTrue($fileInfoUnencrypted2 instanceof \OC\Files\FileInfo);

		// now both files should be decrypted
		$this->assertEquals(0, $fileInfoUnencrypted1['encrypted']);
		$this->assertEquals(0, $fileInfoUnencrypted2['encrypted']);

		// keyfiles and share keys should be deleted
		$this->assertFalse($this->view->is_dir($this->userId . '/files_encryption/keyfiles/'));
		$this->assertFalse($this->view->is_dir($this->userId . '/files_encryption/share-keys/'));

		//cleanup
		$this->view->unlink($this->userId . '/files/' . $file1);
		$this->view->unlink($this->userId . '/files/' . $file2);
		$this->view->deleteAll($this->userId . '/files_encryption/keyfiles.backup');
		$this->view->deleteAll($this->userId . '/files_encryption/share-keys.backup');

	}

	/**
	 * @large
	 */
	function testEncryptLegacyFiles() {
		\Test_Encryption_Util::loginHelper(\Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER);

		$userView = new \OC\Files\View('/' . \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER);
		$view = new \OC\Files\View('/' . \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER . '/files');

		// Disable encryption proxy to prevent recursive calls
		$proxyStatus = \OC_FileProxy::$enabled;
		\OC_FileProxy::$enabled = false;

		$encryptionKeyContent = file_get_contents($this->legacyEncryptedDataKey);
		$userView->file_put_contents('/encryption.key', $encryptionKeyContent);

		$legacyEncryptedData = file_get_contents($this->legacyEncryptedData);
		$view->mkdir('/test/');
		$view->mkdir('/test/subtest/');
		$view->file_put_contents('/test/subtest/legacy-encrypted-text.txt', $legacyEncryptedData);

		$fileInfo = $view->getFileInfo('/test/subtest/legacy-encrypted-text.txt');
		$fileInfo['encrypted'] = true;
		$view->putFileInfo('/test/subtest/legacy-encrypted-text.txt', $fileInfo);

		\OC_FileProxy::$enabled = $proxyStatus;

		$params['uid'] = \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER;
		$params['password'] = \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER;

		$util = new Encryption\Util($this->view, \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER);
		$this->setMigrationStatus(0, \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER);

		$this->assertTrue(OCA\Encryption\Hooks::login($params));

		$this->assertEquals($this->legacyKey, \OC::$session->get('legacyKey'));

		$files = $util->findEncFiles('/' . \Test_Encryption_Util::TEST_ENCRYPTION_UTIL_LEGACY_USER . '/files/');

		$this->assertTrue(is_array($files));

		$found = false;
		foreach ($files['encrypted'] as $encryptedFile) {
			if ($encryptedFile['name'] === 'legacy-encrypted-text.txt') {
				$found = true;
				break;
			}
		}

		$this->assertTrue($found);
	}

	/**
	 * @dataProvider dataProviderFortestIsMountPointApplicableToUser
	 */
	function testIsMountPointApplicableToUser($mount, $expectedResult) {
		self::loginHelper(self::TEST_ENCRYPTION_UTIL_USER1);
		$dummyClass = new DummyUtilClass($this->view, self::TEST_ENCRYPTION_UTIL_USER1);
		$result = $dummyClass->testIsMountPointApplicableToUser($mount);

		$this->assertSame($expectedResult, $result);
	}

	function dataProviderFortestIsMountPointApplicableToUser() {
		return array(
			array(array('applicable' => array('groups' => array(), 'users' => array(self::TEST_ENCRYPTION_UTIL_USER1))), true),
			array(array('applicable' => array('groups' => array(), 'users' => array(self::TEST_ENCRYPTION_UTIL_USER2))), false),
			array(array('applicable' => array('groups' => array(self::TEST_ENCRYPTION_UTIL_GROUP1), 'users' => array())), true),
			array(array('applicable' => array('groups' => array(self::TEST_ENCRYPTION_UTIL_GROUP1), 'users' => array(self::TEST_ENCRYPTION_UTIL_USER2))), true),
			array(array('applicable' => array('groups' => array(self::TEST_ENCRYPTION_UTIL_GROUP2), 'users' => array(self::TEST_ENCRYPTION_UTIL_USER2))), false),
			array(array('applicable' => array('groups' => array(self::TEST_ENCRYPTION_UTIL_GROUP2), 'users' => array(self::TEST_ENCRYPTION_UTIL_USER2, 'all'))), true),
			array(array('applicable' => array('groups' => array(self::TEST_ENCRYPTION_UTIL_GROUP2), 'users' => array('all'))), true),
		);
	}

	/**
	 * Tests that filterShareReadyUsers() returns the correct list of
	 * users that are ready or not ready for encryption
	 */
	public function testFilterShareReadyUsers() {
		$appConfig = \OC::$server->getAppConfig();

		$publicShareKeyId = $appConfig->getValue('files_encryption', 'publicShareKeyId');
		$recoveryKeyId = $appConfig->getValue('files_encryption', 'recoveryKeyId');

		$usersToTest = array(
			'readyUser',
			'notReadyUser',
			'nonExistingUser',
			$publicShareKeyId,
			$recoveryKeyId,
		);
		\Test_Encryption_Util::loginHelper('readyUser', true);
		\Test_Encryption_Util::loginHelper('notReadyUser', true);
		// delete encryption dir to make it not ready
		$this->view->unlink('notReadyUser/files_encryption/');

		// login as user1
		\Test_Encryption_Util::loginHelper(\Test_Encryption_Util::TEST_ENCRYPTION_UTIL_USER1);

		$result = $this->util->filterShareReadyUsers($usersToTest);
		$this->assertEquals(
			array('readyUser', $publicShareKeyId, $recoveryKeyId),
			$result['ready']
		);
		$this->assertEquals(
			array('notReadyUser', 'nonExistingUser'),
			$result['unready']
		);
		\OC_User::deleteUser('readyUser');
	}

	/**
	 * @param string $user
	 * @param bool $create
	 * @param bool $password
	 */
	public static function loginHelper($user, $create = false, $password = false, $loadEncryption = true) {
		if ($create) {
			try {
				\OC_User::createUser($user, $user);
			} catch(\Exception $e) { // catch username is already being used from previous aborted runs

			}
		}

		if ($password === false) {
			$password = $user;
		}

		\OC_Util::tearDownFS();
		\OC_User::setUserId('');
		\OC\Files\Filesystem::tearDown();
		\OC_User::setUserId($user);
		\OC_Util::setupFS($user);

		if ($loadEncryption) {
			$params['uid'] = $user;
			$params['password'] = $password;
			OCA\Encryption\Hooks::login($params);
		}
	}

	public static function logoutHelper() {
		\OC_Util::tearDownFS();
		\OC_User::setUserId(false);
		\OC\Files\Filesystem::tearDown();
	}

	/**
	 * helper function to set migration status to the right value
	 * to be able to test the migration path
	 *
	 * @param integer $status needed migration status for test
	 * @param string $user for which user the status should be set
	 * @return boolean
	 */
	private function setMigrationStatus($status, $user) {
		return \OC_Preferences::setValue($user, 'files_encryption', 'migration_status', (string)$status);
	}

}

/**
 * dummy class extends  \OCA\Encryption\Util to access protected methods for testing
 */
class DummyUtilClass extends \OCA\Encryption\Util {
	public function testIsMountPointApplicableToUser($mount) {
		return $this->isMountPointApplicableToUser($mount);
	}
}
