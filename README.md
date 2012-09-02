ownCloud on OpenShift
=========================

ownCloud is a flexible, open source file sync and share solution. Whether using a mobile device, a workstation, or a web client, ownCloud provides the ability to put the right files at their employees’ fingertips on any device in one simple-to-use, secure, private and controlled solution.

Think of ownCloud as a way to roll your own Google Drive or Dropbox on-premise solution.

More information can be found at http://owncloud.org/

Running on OpenShift
--------------------

Create an account at http://openshift.redhat.com/

Create a PHP application

	rhc app create -a owncloud -t php-5.3

Add MySQL support to your application
    
	rhc app cartridge add -a owncloud -c mysql-5.1
    
Add this upstream ownCloud quickstart repo

	cd owncloud
	rm php/index.php
	git remote add upstream -m master git://github.com/ichristo/owncloud-openshift-quickstart.git
	git pull -s recursive -X theirs upstream master

Push the repo upstream to OpenShift

	git push        

Head to your application at:

	http://owncloud-$yourdomain.rhcloud.com

Default Credentials
-------------------
<table>
<tr><td>Default Admin Username</td><td>admin</td></tr>
<tr><td>Default Admin Password</td><td>OpenShiftAdmin</td></tr>
</table>

To download clients that will sync your ownCloud instance with desktop clients, visit http://owncloud.org/sync-clients/

To give your new planet site a web address of its own, add your desired alias:

	rhc app add-alias -a owncloud --alias "$whatever.$mydomain.com"

Then add a cname entry in your domain's dns configuration pointing your alias to $whatever-$yourdomain.rhcloud.com.
