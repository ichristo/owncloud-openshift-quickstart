<input type='hidden' id='hasMySQL' value='<?php p($_['hasMySQL']) ?>'>
<input type='hidden' id='hasSQLite' value='<?php p($_['hasSQLite']) ?>'>
<input type='hidden' id='hasPostgreSQL' value='<?php p($_['hasPostgreSQL']) ?>'>
<input type='hidden' id='hasOracle' value='<?php p($_['hasOracle']) ?>'>
<input type='hidden' id='hasMSSQL' value='<?php p($_['hasMSSQL']) ?>'>
<form action="index.php" method="post">
<input type="hidden" name="install" value="true" />
	<?php if(count($_['errors']) > 0): ?>
	<fieldset class="warning">
		<legend><strong><?php p($l->t('Error'));?></strong></legend>
		<?php foreach($_['errors'] as $err): ?>
		<p>
			<?php if(is_array($err)):?>
				<?php print_unescaped($err['error']); ?>
				<span class='hint'><?php print_unescaped($err['hint']); ?></span>
			<?php else: ?>
				<?php print_unescaped($err); ?>
			<?php endif; ?>
		</p>
		<?php endforeach; ?>
	</fieldset>
	<?php endif; ?>
	<?php if($_['vulnerableToNullByte']): ?>
	<fieldset class="warning">
		<legend><strong><?php p($l->t('Security Warning'));?></strong></legend>
		<p><?php p($l->t('Your PHP version is vulnerable to the NULL Byte attack (CVE-2006-7243)'));?><br/>
		<?php p($l->t('Please update your PHP installation to use %s securely.', $theme->getName() )); ?></p>
	</fieldset>
	<?php endif; ?>
	<?php if(!$_['secureRNG']): ?>
	<fieldset class="warning">
		<legend><strong><?php p($l->t('Security Warning'));?></strong></legend>
		<p><?php p($l->t('No secure random number generator is available, please enable the PHP OpenSSL extension.'));?><br/>
		<?php p($l->t('Without a secure random number generator an attacker may be able to predict password reset tokens and take over your account.'));?></p>
	</fieldset>
	<?php endif; ?>
	<?php if(!$_['htaccessWorking']): ?>
	<fieldset class="warning">
		<legend><strong><?php p($l->t('Security Warning'));?></strong></legend>
		<p><?php p($l->t('Your data directory and files are probably accessible from the internet because the .htaccess file does not work.'));?><br>
		<?php print_unescaped($l->t(
			'For information how to properly configure your server, please see the <a href="%s" target="_blank">documentation</a>.',
			link_to_docs('admin-install')
		)); ?></p>
	</fieldset>
	<?php endif; ?>
	<fieldset id="adminaccount">
		<legend><?php print_unescaped($l->t( 'Create an <strong>admin account</strong>' )); ?></legend>
		<p class="grouptop">
			<input type="text" name="adminlogin" id="adminlogin"
				placeholder="<?php p($l->t( 'Username' )); ?>"
				value="<?php p($_['adminlogin']); ?>"
				autocomplete="off" autocapitalize="off" autocorrect="off" autofocus required />
			<label for="adminlogin" class="infield"><?php p($l->t( 'Username' )); ?></label>
			<img class="svg" src="<?php p(image_path('', 'actions/user.svg')); ?>" alt="" />
		</p>
		<p class="groupbottom">
			<input type="password" name="adminpass" data-typetoggle="#show" id="adminpass"
				placeholder="<?php p($l->t( 'Password' )); ?>"
				value="<?php p($_['adminpass']); ?>"
				autocomplete="off" autocapitalize="off" autocorrect="off" required />
			<label for="adminpass" class="infield"><?php p($l->t( 'Password' )); ?></label>
			<img class="svg" id="adminpass-icon" src="<?php print_unescaped(image_path('', 'actions/password.svg')); ?>" alt="" />
			<input type="checkbox" id="show" name="show" />
			<label for="show"></label>
			<div class="strengthify-wrapper"></div>
		</p>
	</fieldset>

	<?php if(!$_['directoryIsSet'] OR !$_['dbIsSet'] OR count($_['errors']) > 0): ?>
	<fieldset id="advancedHeader">
		<legend><a id="showAdvanced"><?php p($l->t( 'Storage & database' )); ?> <img class="svg" src="<?php print_unescaped(image_path('', 'actions/caret.svg')); ?>" /></a></legend>
	</fieldset>
	<?php endif; ?>

	<?php if(!$_['directoryIsSet'] OR count($_['errors']) > 0): ?>
	<fieldset id="datadirField">
		<div id="datadirContent">
			<label for="directory"><?php p($l->t( 'Data folder' )); ?></label>
			<input type="text" name="directory" id="directory"
				placeholder="<?php p(OC::$SERVERROOT.'/data'); ?>"
				value="<?php p($_['directory']); ?>"
				autocomplete="off" autocapitalize="off" autocorrect="off" />
		</div>
	</fieldset>
	<?php endif; ?>

	<?php if(!$_['dbIsSet'] OR count($_['errors']) > 0): ?>
	<fieldset id='databaseBackend'>
		<?php if($_['hasMySQL'] or $_['hasPostgreSQL'] or $_['hasOracle'] or $_['hasMSSQL'])
			$hasOtherDB = true; else $hasOtherDB =false; //other than SQLite ?>
		<legend><?php p($l->t( 'Configure the database' )); ?></legend>
		<div id="selectDbType">
		<?php foreach($_['databases'] as $type => $label): ?>
		<?php if(count($_['databases']) === 1): ?>
		<p class="info"><?php p($l->t( 'Only %s is available.', array($label) )); ?>.</p>
		<input type="hidden" id="dbtype" name="dbtype" value="<?php p($type) ?>" />
		<?php else: ?>
		<input type="radio" name="dbtype" value="<?php p($type) ?>" id="<?php p($type) ?>"
			<?php print_unescaped($_['dbtype'] === $type ? 'checked="checked" ' : '') ?>/>
		<label class="<?php p($type) ?>" for="<?php p($type) ?>"><?php p($label) ?></label>
		<?php endif; ?>
		<?php endforeach; ?>
		</div>
	</fieldset>

		<?php if($hasOtherDB): ?>
		<fieldset id='databaseField'>
		<div id="use_other_db">
			<p class="grouptop">
				<label for="dbuser" class="infield"><?php p($l->t( 'Database user' )); ?></label>
				<input type="text" name="dbuser" id="dbuser"
					placeholder="<?php p($l->t( 'Database user' )); ?>"
					value="<?php p($_['dbuser']); ?>"
					autocomplete="off" autocapitalize="off" autocorrect="off" />
			</p>
			<p class="groupmiddle">
				<input type="password" name="dbpass" id="dbpass" data-typetoggle="#dbpassword"
					placeholder="<?php p($l->t( 'Database password' )); ?>"
					value="<?php p($_['dbpass']); ?>"
					autocomplete="off" autocapitalize="off" autocorrect="off" />
				<label for="dbpass" class="infield"><?php p($l->t( 'Database password' )); ?></label>
				<input type="checkbox" id="dbpassword" name="dbpassword" />
				<label for="dbpassword"></label>
			</p>
			<p class="groupmiddle">
				<label for="dbname" class="infield"><?php p($l->t( 'Database name' )); ?></label>
				<input type="text" name="dbname" id="dbname"
					placeholder="<?php p($l->t( 'Database name' )); ?>"
					value="<?php p($_['dbname']); ?>"
					autocomplete="off" autocapitalize="off" autocorrect="off"
					pattern="[0-9a-zA-Z$_-]+" />
			</p>
			<?php if($_['hasOracle']): ?>
			<div id="use_oracle_db">
				<p class="groupmiddle">
					<label for="dbtablespace" class="infield"><?php p($l->t( 'Database tablespace' )); ?></label>
					<input type="text" name="dbtablespace" id="dbtablespace"
						placeholder="<?php p($l->t( 'Database tablespace' )); ?>"
						value="<?php p($_['dbtablespace']); ?>"
						autocomplete="off" autocapitalize="off" autocorrect="off" />
				</p>
			</div>
			<?php endif; ?>
			<p class="groupbottom">
				<label for="dbhost" class="infield"><?php p($l->t( 'Database host' )); ?></label>
				<input type="text" name="dbhost" id="dbhost"
					placeholder="<?php p($l->t( 'Database host' )); ?>"
					value="<?php p($_['dbhost']); ?>"
					autocomplete="off" autocapitalize="off" autocorrect="off" />
			</p>
		</div>
		</fieldset>
		<?php endif; ?>
	<?php endif; ?>

	<p id="sqliteInformation" class="info"><?php p($l->t('SQLite will be used as database. For larger installations we recommend to change this.'));?></p>

	<div class="buttons"><input type="submit" class="primary" value="<?php p($l->t( 'Finish setup' )); ?>" data-finishing="<?php p($l->t( 'Finishing …' )); ?>" /></div>
</form>
