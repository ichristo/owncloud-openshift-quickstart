<!DOCTYPE html>
<!--[if lt IE 7]><html class="ng-csp ie ie6 lte9 lte8 lte7" data-placeholder-focus="false"><![endif]-->
<!--[if IE 7]><html class="ng-csp ie ie7 lte9 lte8 lte7" data-placeholder-focus="false"><![endif]-->
<!--[if IE 8]><html class="ng-csp ie ie8 lte9 lte8" data-placeholder-focus="false"><![endif]-->
<!--[if IE 9]><html class="ng-csp ie ie9 lte9" data-placeholder-focus="false"><![endif]-->
<!--[if gt IE 9]><html class="ng-csp ie" data-placeholder-focus="false"><![endif]-->
<!--[if !IE]><!--><html class="ng-csp" data-placeholder-focus="false"><!--<![endif]-->

	<head data-user="<?php p($_['user_uid']); ?>" data-requesttoken="<?php p($_['requesttoken']); ?>">
		<title>
			<?php
				p(!empty($_['application'])?$_['application'].' - ':'');
				p($theme->getTitle());
			?>
		</title>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0">
		<meta name="apple-itunes-app" content="app-id=543672169">
		<link rel="shortcut icon" href="<?php print_unescaped(image_path('', 'favicon.png')); ?>" />
		<link rel="apple-touch-icon-precomposed" href="<?php print_unescaped(image_path('', 'favicon-touch.png')); ?>" />
		<?php foreach($_['cssfiles'] as $cssfile): ?>
			<link rel="stylesheet" href="<?php print_unescaped($cssfile); ?>" type="text/css" media="screen" />
		<?php endforeach; ?>
		<?php foreach($_['jsfiles'] as $jsfile): ?>
			<script type="text/javascript" src="<?php print_unescaped($jsfile); ?>"></script>
		<?php endforeach; ?>
		<?php foreach($_['headers'] as $header): ?>
			<?php
				print_unescaped('<'.$header['tag'].' ');
				foreach($header['attributes'] as $name=>$value) {
					print_unescaped("$name='$value' ");
				};
				print_unescaped('/>');
			?>
		<?php endforeach; ?>
	</head>
	<body id="<?php p($_['bodyid']);?>">
	<noscript><div id="nojavascript"><div><?php print_unescaped($l->t('This application requires JavaScript for correct operation. Please <a href="http://enable-javascript.com/" target="_blank">enable JavaScript</a> and reload the page.')); ?></div></div></noscript>
	<div id="notification-container">
		<div id="notification"></div>
		<?php if ($_['updateAvailable']): ?>
			<div id="update-notification" style="display: inline;"><a href="<?php print_unescaped($_['updateLink']); ?>"><?php p($l->t('%s is available. Get more information on how to update.', array($_['updateVersion']))); ?></a></div>
		<?php endif; ?>
	</div>
	<header><div id="header">
			<a href="<?php print_unescaped(link_to('', 'index.php')); ?>" title="" id="owncloud">
				<div class="logo-icon svg"></div>
			</a>
			<a href="#" class="menutoggle">
				<div class="header-appname">
					<?php
						if(OC_Util::getEditionString() === '') {
							p(!empty($_['application'])?$_['application']: $l->t('Apps'));
						} else {
							print_unescaped($theme->getHTMLName());
						}
					?>
				</div>
				<div class="icon-caret svg"></div>
			</a>
			<div id="logo-claim" style="display:none;"><?php p($theme->getLogoClaim()); ?></div>
			<div id="settings" class="svg">
				<span id="expand" tabindex="0" role="link">
					<?php if ($_['enableAvatars']): ?>
					<div class="avatardiv"></div>
					<?php endif; ?>
					<span id="expandDisplayName"><?php  p(trim($_['user_displayname']) != '' ? $_['user_displayname'] : $_['user_uid']) ?></span>
					<img class="svg" alt="" src="<?php print_unescaped(image_path('', 'actions/caret.svg')); ?>" />
				</span>
				<div id="expanddiv">
				<ul>
				<?php foreach($_['settingsnavigation'] as $entry):?>
					<li>
						<a href="<?php print_unescaped($entry['href']); ?>" title=""
							<?php if( $entry["active"] ): ?> class="active"<?php endif; ?>>
							<img class="svg" alt="" src="<?php print_unescaped($entry['icon']); ?>">
							<?php p($entry['name']) ?>
						</a>
					</li>
				<?php endforeach; ?>
					<li>
						<a id="logout" <?php print_unescaped(OC_User::getLogoutAttribute()); ?>>
							<img class="svg" alt="" src="<?php print_unescaped(image_path('', 'actions/logout.svg')); ?>" />
							<?php p($l->t('Log out'));?>
						</a>
					</li>
				</ul>
				</div>
			</div>

			<form class="searchbox" action="#" method="post">
				<input id="searchbox" class="svg" type="search" name="query"
					value="<?php if(isset($_POST['query'])) {p($_POST['query']);};?>"
					autocomplete="off" x-webkit-speech />
			</form>
		</div></header>

		<nav><div id="navigation">
			<div id="apps" class="svg">
				<ul>
				<?php foreach($_['navigation'] as $entry): ?>
					<li data-id="<?php p($entry['id']); ?>">
						<a href="<?php print_unescaped($entry['href']); ?>" title=""
							<?php if( $entry['active'] ): ?> class="active"<?php endif; ?>>
							<img class="app-icon svg" alt="" src="<?php print_unescaped($entry['icon']); ?>"/>
							<div class="icon-loading-dark" style="display:none;"></div>
							<span>
								<?php p($entry['name']); ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>

				<!-- show "More apps" link to app administration directly in app navigation, as last entry -->
				<?php if(OC_User::isAdminUser(OC_User::getUser())): ?>
					<li id="apps-management">
						<a href="<?php print_unescaped(OC_Helper::linkToRoute('settings_apps').'?installed'); ?>" title=""
							<?php if( $_['appsmanagement_active'] ): ?> class="active"<?php endif; ?>>
							<img class="app-icon svg" alt="" src="<?php print_unescaped(OC_Helper::imagePath('settings', 'apps.svg')); ?>"/>
							<div class="icon-loading-dark" style="display:none;"></div>
							<span>
								<?php p($l->t('Apps')); ?>
							</span>
						</a>
					</li>
				<?php endif; ?>

				</ul>
			</div>
		</div></nav>

		<div id="content-wrapper">
			<div id="content" class="app-<?php p($_['appid']) ?>">
				<?php print_unescaped($_['content']); ?>
			</div>
		</div>
	</body>
</html>
