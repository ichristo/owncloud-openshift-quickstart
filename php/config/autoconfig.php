<?php
define("DIRECTORY",$_SERVER['OPENSHIFT_DATA_DIR'] );
define("DBNAME",$_SERVER['OPENSHIFT_APP_NAME'] );
define("DBUSER",$_SERVER['OPENSHIFT_MYSQL_DB_USERNAME'] );
define("DBPASS",$_SERVER['OPENSHIFT_MYSQL_DB_PASSWORD'] );
define("DBHOST",$_SERVER['OPENSHIFT_MYSQL_DB_HOST'] . ':' . $_SERVER['OPENSHIFT_MYSQL_DB_PORT'] );

$AUTOCONFIG = array(
 'installed' => false,
 'dbtype' => 'mysql',
 'dbtableprefix' => 'oc_',
 'adminlogin' => 'admin',
 'adminpass' => 'OpenShiftAdmin',
 'directory' => DIRECTORY,
 'dbname' => DBNAME,
 'dbuser' => DBUSER,
 'dbpass' => DBPASS,
 'dbhost' => DBHOST, 
);
?>
