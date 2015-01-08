<?php

/**
 * ownCloud - Documents App
 *
 * @author Victor Dubiniuk
 * @copyright 2013 Victor Dubiniuk victor.dubiniuk@gmail.com
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\Documents;

class SessionController extends Controller{
	
	public static function joinAsGuest($args){
		self::preDispatchGuest();
		
		$uid = Helper::getArrayValueByKey($_POST, 'name');
		$uid = substr($uid, 0, 16);
		
		try {
			$token = Helper::getArrayValueByKey($args, 'token');
			$file = File::getByShareToken($token);
			if ($file->isPasswordProtected() && !$file->checkPassword('')){
				throw new \Exception('Not authorized');
			}
			$session = Db\Session::start($uid, $file);
			\OCP\JSON::success($session);
		} catch (\Exception $e){
			Helper::warnLog('Starting a session failed. Reason: ' . $e->getMessage());
			\OCP\JSON::error();
			exit();
		}
	}

	public static function joinAsUser($args){
		$uid = self::preDispatch();
		$fileId = Helper::getArrayValueByKey($args, 'file_id');
		
		try {
			$view = \OC\Files\Filesystem::getView();
			$path = $view->getPath($fileId);
			
			if ($view->isUpdatable($path)) {
				$file = new File($fileId);
				$session = Db\Session::start($uid, $file);
				\OCP\JSON::success($session);
			} else {
				$info = $view->getFileInfo($path);
				\OCP\JSON::success(array(
					'permissions' => $info['permissions'],
					'id' => $fileId
				));
			}
			exit();
		} catch (\Exception $e){
			Helper::warnLog('Starting a session failed. Reason: ' . $e->getMessage());
			\OCP\JSON::error();
			exit();
		}
	}
	

	/**
	 * Store the document content to its origin
	 */
	public static function save(){
		try {
			$esId = @$_SERVER['HTTP_WEBODF_SESSION_ID'];
			if (!$esId){
				throw new \Exception('Session id can not be empty');
			}
			
			$memberId = @$_SERVER['HTTP_WEBODF_MEMBER_ID'];
			$currentMember = new Db\Member();
			$currentMember->load($memberId);
			if (is_null($currentMember->getIsGuest()) || $currentMember->getIsGuest()){
				self::preDispatchGuest();
			} else {
				$uid = self::preDispatch();
			}
			
			//check if member belongs to the session
			if ($esId != $currentMember->getEsId()){
				throw new \Exception($memberId . ' does not belong to session ' . $esId);
			}
			
			// Extra info for future usage
			// $sessionRevision = Helper::getArrayValueByKey($_SERVER, 'HTTP_WEBODF_SESSION_REVISION');
			
			$stream = fopen('php://input','r');
			if (!$stream){
				throw new \Exception('New content missing');
			}
			$content = stream_get_contents($stream);

			$session = new Db\Session();
			$session->load($esId);
			
			if (!$session->getEsId()){
				throw new \Exception('Session does not exist');
			}

			try {
				if ($currentMember->getIsGuest()){
					$file = File::getByShareToken($currentMember->getToken());
				} else {
					$file = new File($session->getFileId());
				}
				
				list($view, $path) = $file->getOwnerViewAndPath(true);
			} catch (\Exception $e){
				//File was deleted or unshared. We need to save content as new file anyway
				//Sorry, but for guests it would be lost :(
				if (isset($uid)){
					$view = new \OC\Files\View('/' . $uid . '/files');
		
					$dir = \OCP\Config::getUserValue(\OCP\User::getUser(), 'documents', 'save_path', '');
					$path = Helper::getNewFileName($view, $dir . 'New Document.odt');
				}
			}
			
			$member = new Db\Member();
			$members = $member->getActiveCollection($esId);
			$memberIds = array_map(
				function($x){
					return ($x['member_id']);
				},
				$members
			);
			
			// Active users except current user
			$memberCount = count($memberIds) - 1;
			
			if ($view->file_exists($path)){
				$proxyStatus = \OC_FileProxy::$enabled;
				\OC_FileProxy::$enabled = false;	
				$currentHash = sha1($view->file_get_contents($path));
				\OC_FileProxy::$enabled = $proxyStatus;
				
				if (!Helper::isVersionsEnabled() && $currentHash !== $session->getGenesisHash()){
					// Original file was modified externally. Save to a new one
					$path = Helper::getNewFileName($view, $path, '-conflict');
				}
				
				$mimetype = $view->getMimeType($path);
			} else {
				$mimetype = Storage::MIMETYPE_LIBREOFFICE_WORDPROCESSOR;
			}
			
			$data = Filter::write($content, $mimetype);
			
			if ($view->file_put_contents($path, $data['content'])){
				// Not a last user
				if ($memberCount>0){
					// Update genesis hash to prevent conflicts
					Helper::debugLog('Update hash');
					$session->updateGenesisHash($esId, sha1($data['content']));
				} else {
					// Last user. Kill session data
					Db\Session::cleanUp($esId);
				}
				
				$view->touch($path);
			}
			\OCP\JSON::success();
		} catch (\Exception $e){
			Helper::warnLog('Saving failed. Reason:' . $e->getMessage());
			//\OCP\JSON::error(array('message'=>$e->getMessage()));
			\OC_Response::setStatus(500);
		}
		exit();
	}
}
