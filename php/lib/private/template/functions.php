<?php
/**
 * Copyright (c) 2013 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

/**
 * Prints a sanitized string
 * @param string|array $string the string which will be escaped and printed
 */
function p($string) {
	print(OC_Util::sanitizeHTML($string));
}

/**
 * Prints an unsanitized string - usage of this function may result into XSS.
 * Consider using p() instead.
 * @param string|array $string the string which will be printed as it is
 */
function print_unescaped($string) {
	print($string);
}

/**
 * Shortcut for adding scripts to a page
 * @param string $app the appname
 * @param string|string[] $file the filename,
 * if an array is given it will add all scripts
 */
function script($app, $file) {
	if(is_array($file)) {
		foreach($file as $f) {
			OC_Util::addScript($app, $f);
		}
	} else {
		OC_Util::addScript($app, $file);
	}
}

/**
 * Shortcut for adding styles to a page
 * @param string $app the appname
 * @param string|string[] $file the filename,
 * if an array is given it will add all styles
 */
function style($app, $file) {
	if(is_array($file)) {
		foreach($file as $f) {
			OC_Util::addStyle($app, $f);
		}
	} else {
		OC_Util::addStyle($app, $file);
	}
}

/**
 * Shortcut for HTML imports
 * @param string $app the appname
 * @param string|string[] $file the path relative to the app's component folder,
 * if an array is given it will add all components
 */
function component($app, $file) {
	if(is_array($file)) {
		foreach($file as $f) {
			$url = link_to($app, 'component/' . $f . '.html');
			OC_Util::addHeader('link', array('rel' => 'import', 'href' => $url));
		}
	} else {
		$url = link_to($app, 'component/' . $file . '.html');
		OC_Util::addHeader('link', array('rel' => 'import', 'href' => $url));
	}
}

/**
 * make OC_Helper::linkTo available as a simple function
 * @param string $app app
 * @param string $file file
 * @param array $args array with param=>value, will be appended to the returned url
 * @return string link to the file
 *
 * For further information have a look at OC_Helper::linkTo
 */
function link_to( $app, $file, $args = array() ) {
	return OC_Helper::linkTo( $app, $file, $args );
}

/**
 * @param $key
 * @return string url to the online documentation
 */
function link_to_docs($key) {
	return OC_Helper::linkToDocs($key);
}

/**
 * make OC_Helper::imagePath available as a simple function
 * @param string $app app
 * @param string $image image
 * @return string link to the image
 *
 * For further information have a look at OC_Helper::imagePath
 */
function image_path( $app, $image ) {
	return OC_Helper::imagePath( $app, $image );
}

/**
 * make OC_Helper::mimetypeIcon available as a simple function
 * @param string $mimetype mimetype
 * @return string link to the image
 *
 * For further information have a look at OC_Helper::mimetypeIcon
 */
function mimetype_icon( $mimetype ) {
	return OC_Helper::mimetypeIcon( $mimetype );
}

/**
 * make preview_icon available as a simple function
 * Returns the path to the preview of the image.
 * @param string $path path of file
 * @return link to the preview
 *
 * For further information have a look at OC_Helper::previewIcon
 */
function preview_icon( $path ) {
	return OC_Helper::previewIcon( $path );
}

/**
 * @param string $path
 */
function publicPreview_icon ( $path, $token ) {
	return OC_Helper::publicPreviewIcon( $path, $token );
}

/**
 * make OC_Helper::humanFileSize available as a simple function
 * @param int $bytes size in bytes
 * @return string size as string
 *
 * For further information have a look at OC_Helper::humanFileSize
 */
function human_file_size( $bytes ) {
	return OC_Helper::humanFileSize( $bytes );
}

/**
 * Strips the timestamp of its time value
 * @param int $timestamp UNIX timestamp to strip
 * @return $timestamp without time value
 */
function strip_time($timestamp){
	$date = new \DateTime("@{$timestamp}");
	$date->setTime(0, 0, 0);
	return intval($date->format('U'));
}

/**
 * Formats timestamp relatively to the current time using
 * a human-friendly format like "x minutes ago" or "yesterday"
 * @param int $timestamp timestamp to format
 * @param int $fromTime timestamp to compare from, defaults to current time
 * @param bool $dateOnly whether to strip time information
 * @return OC_L10N_String timestamp
 */
function relative_modified_date($timestamp, $fromTime = null, $dateOnly = false) {
	$l=OC_L10N::get('lib');
	if (!isset($fromTime) || $fromTime === null){
		$fromTime = time();
	}
	if ($dateOnly){
		$fromTime = strip_time($fromTime);
		$timestamp = strip_time($timestamp);
	}
	$timediff = $fromTime - $timestamp;
	$diffminutes = round($timediff/60);
	$diffhours = round($diffminutes/60);
	$diffdays = round($diffhours/24);
	$diffmonths = round($diffdays/31);

	if(!$dateOnly && $timediff < 60) { return $l->t('seconds ago'); }
	else if(!$dateOnly && $timediff < 3600) { return $l->n('%n minute ago', '%n minutes ago', $diffminutes); }
	else if(!$dateOnly && $timediff < 86400) { return $l->n('%n hour ago', '%n hours ago', $diffhours); }
	else if((date('G', $fromTime)-$diffhours) >= 0) { return $l->t('today'); }
	else if((date('G', $fromTime)-$diffhours) >= -24) { return $l->t('yesterday'); }
	// 86400 * 31 days = 2678400
	else if($timediff < 2678400) { return $l->n('%n day go', '%n days ago', $diffdays); }
	// 86400 * 60 days = 518400
	else if($timediff < 5184000) { return $l->t('last month'); }
	else if((date('n', $fromTime)-$diffmonths) > 0) { return $l->n('%n month ago', '%n months ago', $diffmonths); }
	// 86400 * 365.25 days * 2 = 63113852
	else if($timediff < 63113852) { return $l->t('last year'); }
	else { return $l->t('years ago'); }
}

function html_select_options($options, $selected, $params=array()) {
	if (!is_array($selected)) {
		$selected=array($selected);
	}
	if (isset($params['combine']) && $params['combine']) {
		$options = array_combine($options, $options);
	}
	$value_name = $label_name = false;
	if (isset($params['value'])) {
		$value_name = $params['value'];
	}
	if (isset($params['label'])) {
		$label_name = $params['label'];
	}
	$html = '';
	foreach($options as $value => $label) {
		if ($value_name && is_array($label)) {
			$value = $label[$value_name];
		}
		if ($label_name && is_array($label)) {
			$label = $label[$label_name];
		}
		$select = in_array($value, $selected) ? ' selected="selected"' : '';
		$html .= '<option value="' . OC_Util::sanitizeHTML($value) . '"' . $select . '>' . OC_Util::sanitizeHTML($label) . '</option>'."\n";
	}
	return $html;
}
