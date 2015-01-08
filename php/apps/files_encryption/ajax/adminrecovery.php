<?php

/**
 * Copyright (c) 2013, Sam Tuke <samtuke@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or later.
 * See the COPYING-README file.
 *
 * Script to handle admin settings for encrypted key recovery
 */
use OCA\Encryption;

\OCP\JSON::checkAdminUser();
\OCP\JSON::checkAppEnabled('files_encryption');
\OCP\JSON::callCheck();

$l = OC_L10N::get('files_encryption');

$return = false;
$errorMessage = $l->t("Unknown error");

//check if both passwords are the same
if (empty($_POST['recoveryPassword'])) {
	$errorMessage = $l->t('Missing recovery key password');
	\OCP\JSON::error(array('data' => array('message' => $errorMessage)));
	exit();
}

if (empty($_POST['confirmPassword'])) {
	$errorMessage = $l->t('Please repeat the recovery key password');
	\OCP\JSON::error(array('data' => array('message' => $errorMessage)));
	exit();
}

if ($_POST['recoveryPassword'] !== $_POST['confirmPassword']) {
	$errorMessage = $l->t('Repeated recovery key password does not match the provided recovery key password');
	\OCP\JSON::error(array('data' => array('message' => $errorMessage)));
	exit();
}

// Enable recoveryAdmin
$recoveryKeyId = \OC::$server->getAppConfig()->getValue('files_encryption', 'recoveryKeyId');

if (isset($_POST['adminEnableRecovery']) && $_POST['adminEnableRecovery'] === '1') {

	$return = \OCA\Encryption\Helper::adminEnableRecovery($recoveryKeyId, $_POST['recoveryPassword']);

	// Return success or failure
	if ($return) {
		$successMessage = $l->t('Recovery key successfully enabled');
	} else {
		$errorMessage = $l->t('Could not disable recovery key. Please check your recovery key password!');
	}

// Disable recoveryAdmin
} elseif (
	isset($_POST['adminEnableRecovery'])
	&& '0' === $_POST['adminEnableRecovery']
) {
	$return = \OCA\Encryption\Helper::adminDisableRecovery($_POST['recoveryPassword']);

	if ($return) {
		$successMessage = $l->t('Recovery key successfully disabled');
	} else {
		$errorMessage = $l->t('Could not disable recovery key. Please check your recovery key password!');
	}
}

// Return success or failure
if ($return) {
	\OCP\JSON::success(array('data' => array('message' => $successMessage)));
} else {
	\OCP\JSON::error(array('data' => array('message' => $errorMessage)));
}
