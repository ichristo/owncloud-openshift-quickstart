<?php
$l = OC_L10N::get('files_trashbin');

OC::$CLASSPATH['OCA\Files_Trashbin\Exceptions\CopyRecursiveException'] = 'files_trashbin/lib/exceptions.php';

// register hooks
\OCA\Files_Trashbin\Trashbin::registerHooks();

\OCA\Files\App::getNavigationManager()->add(
array(
	"id" => 'trashbin',
	"appname" => 'files_trashbin',
	"script" => 'list.php',
	"order" => 50,
	"name" => $l->t('Deleted files')
)
);
