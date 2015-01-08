<?php
/**
 * Copyright (c) 2013 Bart Visscher <bartv@thisnet.nl>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Template;

class JSResourceLocator extends ResourceLocator {
	public function doFind( $script ) {
		$theme_dir = 'themes/'.$this->theme.'/';
		if (strpos($script, '3rdparty') === 0
			&& $this->appendIfExist($this->thirdpartyroot, $script.'.js')
			|| $this->appendIfExist($this->serverroot, $theme_dir.'apps/'.$script.$this->form_factor.'.js')
			|| $this->appendIfExist($this->serverroot, $theme_dir.'apps/'.$script.'.js')
			|| $this->appendIfExist($this->serverroot, $theme_dir.$script.$this->form_factor.'.js')
			|| $this->appendIfExist($this->serverroot, $theme_dir.$script.'.js')
			|| $this->appendIfExist($this->serverroot, $script.$this->form_factor.'.js')
			|| $this->appendIfExist($this->serverroot, $script.'.js')
			|| $this->appendIfExist($this->serverroot, $theme_dir.'core/'.$script.$this->form_factor.'.js')
			|| $this->appendIfExist($this->serverroot, $theme_dir.'core/'.$script.'.js')
			|| $this->appendIfExist($this->serverroot, 'core/'.$script.$this->form_factor.'.js')
			|| $this->appendIfExist($this->serverroot, 'core/'.$script.'.js')
		) {
			return;
		}
		$app = substr($script, 0, strpos($script, '/'));
		$script = substr($script, strpos($script, '/')+1);
		$app_path = \OC_App::getAppPath($app);
		$app_url = \OC_App::getAppWebPath($app);
		if ($this->appendIfExist($app_path, $script.$this->form_factor.'.js', $app_url)
			|| $this->appendIfExist($app_path, $script.'.js', $app_url)
		) {
			return;
		}
		throw new \Exception('js file not found: script:'.$script);
	}

	public function doFindTheme( $script ) {
	}
}
