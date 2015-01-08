<?php
/**
 * Copyright (c) 2013 Georg Ehrke georg@ownCloud.com
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */
namespace OC\Preview;

// office preview is currently not supported on Windows
if (!\OC_Util::runningOnWindows()) {

	//we need imagick to convert
	class Office extends Provider {

		private $cmd;

		public function getMimeType() {
			return null;
		}

		public function getThumbnail($path, $maxX, $maxY, $scalingup, $fileview) {
			$this->initCmd();
			if(is_null($this->cmd)) {
				return false;
			}

			$absPath = $fileview->toTmpFile($path);

			$tmpDir = get_temp_dir();

			$defaultParameters = ' --headless --nologo --nofirststartwizard --invisible --norestore -convert-to pdf -outdir ';
			$clParameters = \OCP\Config::getSystemValue('preview_office_cl_parameters', $defaultParameters);

			$exec = $this->cmd . $clParameters . escapeshellarg($tmpDir) . ' ' . escapeshellarg($absPath);
			$export = 'export HOME=/' . $tmpDir;

			shell_exec($export . "\n" . $exec);

			//create imagick object from pdf
			try{
				$pdf = new \imagick($absPath . '.pdf' . '[0]');
				$pdf->setImageFormat('jpg');
			} catch (\Exception $e) {
				unlink($absPath);
				unlink($absPath . '.pdf');
				\OC_Log::write('core', $e->getmessage(), \OC_Log::ERROR);
				return false;
			}

			$image = new \OC_Image();
			$image->loadFromData($pdf);

			unlink($absPath);
			unlink($absPath . '.pdf');

			return $image->valid() ? $image : false;
		}

		private function initCmd() {
			$cmd = '';

			if(is_string(\OC_Config::getValue('preview_libreoffice_path', null))) {
				$cmd = \OC_Config::getValue('preview_libreoffice_path', null);
			}

			$whichLibreOffice = shell_exec('command -v libreoffice');
			if($cmd === '' && !empty($whichLibreOffice)) {
				$cmd = 'libreoffice';
			}

			$whichOpenOffice = shell_exec('command -v openoffice');
			if($cmd === '' && !empty($whichOpenOffice)) {
				$cmd = 'openoffice';
			}

			if($cmd === '') {
				$cmd = null;
			}

			$this->cmd = $cmd;
		}
	}

	//.doc, .dot
	class MSOfficeDoc extends Office {

		public function getMimeType() {
			return '/application\/msword/';
		}

	}

	\OC\Preview::registerProvider('OC\Preview\MSOfficeDoc');

	//.docm, .dotm, .xls(m), .xlt(m), .xla(m), .ppt(m), .pot(m), .pps(m), .ppa(m)
	class MSOffice2003 extends Office {

		public function getMimeType() {
			return '/application\/vnd.ms-.*/';
		}

	}

	\OC\Preview::registerProvider('OC\Preview\MSOffice2003');

	//.docx, .dotx, .xlsx, .xltx, .pptx, .potx, .ppsx
	class MSOffice2007 extends Office {

		public function getMimeType() {
			return '/application\/vnd.openxmlformats-officedocument.*/';
		}

	}

	\OC\Preview::registerProvider('OC\Preview\MSOffice2007');

	//.odt, .ott, .oth, .odm, .odg, .otg, .odp, .otp, .ods, .ots, .odc, .odf, .odb, .odi, .oxt
	class OpenDocument extends Office {

		public function getMimeType() {
			return '/application\/vnd.oasis.opendocument.*/';
		}

	}

	\OC\Preview::registerProvider('OC\Preview\OpenDocument');

	//.sxw, .stw, .sxc, .stc, .sxd, .std, .sxi, .sti, .sxg, .sxm
	class StarOffice extends Office {

		public function getMimeType() {
			return '/application\/vnd.sun.xml.*/';
		}

	}

	\OC\Preview::registerProvider('OC\Preview\StarOffice');
}
