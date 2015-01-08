<?php

/**
* ownCloud
*
* @author Thomas Tanghus
* @copyright 2011 Thomas Tanghus <thomas@tanghus.net>
*
* This library is free software; you can redistribute it and/or
* modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
* License as published by the Free Software Foundation; either
* version 3 of the License, or any later version.
*
* This library is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU AFFERO GENERAL PUBLIC LICENSE for more details.
*
* You should have received a copy of the GNU Affero General Public
* License along with this library.  If not, see <http://www.gnu.org/licenses/>.
*
*/
/**
 * Class for basic image manipulation
 */
class OC_Image {
	protected $resource = false; // tmp resource.
	protected $imageType = IMAGETYPE_PNG; // Default to png if file type isn't evident.
	protected $mimeType = "image/png"; // Default to png
	protected $bitDepth = 24;
	protected $filePath = null;

	private $fileInfo;

	/**
	* Get mime type for an image file.
	* @param string|null $filePath The path to a local image file.
	* @return string The mime type if the it could be determined, otherwise an empty string.
	*/
	static public function getMimeTypeForFile($filePath) {
		// exif_imagetype throws "read error!" if file is less than 12 byte
		if (filesize($filePath) > 11) {
			$imageType = exif_imagetype($filePath);
		} else {
			$imageType = false;
		}
		return $imageType ? image_type_to_mime_type($imageType) : '';
	}

	/**
	 * Constructor.
	 * @param resource|string $imageRef The path to a local file, a base64 encoded string or a resource created by
	 * an imagecreate* function.
	 * @return \OC_Image False on error
	 */
	public function __construct($imageRef = null) {
		//OC_Log::write('core',__METHOD__.'(): start', OC_Log::DEBUG);
		if(!extension_loaded('gd') || !function_exists('gd_info')) {
			OC_Log::write('core', __METHOD__.'(): GD module not installed', OC_Log::ERROR);
			return false;
		}

		if (\OC_Util::fileInfoLoaded()) {
			$this->fileInfo = new finfo(FILEINFO_MIME_TYPE);
		}

		if(!is_null($imageRef)) {
			$this->load($imageRef);
		}
	}

	/**
	* Determine whether the object contains an image resource.
	* @return bool
	*/
	public function valid() { // apparently you can't name a method 'empty'...
		return is_resource($this->resource);
	}

	/**
	* Returns the MIME type of the image or an empty string if no image is loaded.
	* @return string
	*/
	public function mimeType() {
		return $this->valid() ? $this->mimeType : '';
	}

	/**
	* Returns the width of the image or -1 if no image is loaded.
	* @return int
	*/
	public function width() {
		return $this->valid() ? imagesx($this->resource) : -1;
	}

	/**
	* Returns the height of the image or -1 if no image is loaded.
	* @return int
	*/
	public function height() {
		return $this->valid() ? imagesy($this->resource) : -1;
	}

	/**
	* Returns the width when the image orientation is top-left.
	* @return int
	*/
	public function widthTopLeft() {
		$o = $this->getOrientation();
		OC_Log::write('core', 'OC_Image->widthTopLeft() Orientation: '.$o, OC_Log::DEBUG);
		switch($o) {
			case -1:
			case 1:
			case 2: // Not tested
			case 3:
			case 4: // Not tested
				return $this->width();
			case 5: // Not tested
			case 6:
			case 7: // Not tested
			case 8:
				return $this->height();
		}
		return $this->width();
	}

	/**
	* Returns the height when the image orientation is top-left.
	* @return int
	*/
	public function heightTopLeft() {
		$o = $this->getOrientation();
		OC_Log::write('core', 'OC_Image->heightTopLeft() Orientation: '.$o, OC_Log::DEBUG);
		switch($o) {
			case -1:
			case 1:
			case 2: // Not tested
			case 3:
			case 4: // Not tested
				return $this->height();
			case 5: // Not tested
			case 6:
			case 7: // Not tested
			case 8:
				return $this->width();
		}
		return $this->height();
	}

	/**
	 * Outputs the image.
	 * @param string $mimeType
	 * @return bool
	 */
	public function show($mimeType=null) {
		if($mimeType === null) {
			$mimeType = $this->mimeType();
		}
		header('Content-Type: '.$mimeType);
		return $this->_output(null, $mimeType);
	}

	/**
	 * Saves the image.
	 * @param string $filePath
	 * @param string $mimeType
	 * @return bool
	 */

	public function save($filePath=null, $mimeType=null) {
		if($mimeType === null) {
			$mimeType = $this->mimeType();
		}
		if($filePath === null && $this->filePath === null) {
			OC_Log::write('core', __METHOD__.'(): called with no path.', OC_Log::ERROR);
			return false;
		} elseif($filePath === null && $this->filePath !== null) {
			$filePath = $this->filePath;
		}
		return $this->_output($filePath, $mimeType);
	}

	/**
	 * Outputs/saves the image.
	 * @param string $filePath
	 * @param string $mimeType
	 * @return bool
	 * @throws Exception
	 */
	private function _output($filePath=null, $mimeType=null) {
		if($filePath) {
			if (!file_exists(dirname($filePath)))
				mkdir(dirname($filePath), 0777, true);
			if(!is_writable(dirname($filePath))) {
				OC_Log::write('core',
					__METHOD__.'(): Directory \''.dirname($filePath).'\' is not writable.',
					OC_Log::ERROR);
				return false;
			} elseif(is_writable(dirname($filePath)) && file_exists($filePath) && !is_writable($filePath)) {
				OC_Log::write('core', __METHOD__.'(): File \''.$filePath.'\' is not writable.', OC_Log::ERROR);
				return false;
			}
		}
		if (!$this->valid()) {
			return false;
		}

		$imageType = $this->imageType;
		if($mimeType !== null) {
			switch($mimeType) {
				case 'image/gif':
					$imageType = IMAGETYPE_GIF;
					break;
				case 'image/jpeg':
					$imageType = IMAGETYPE_JPEG;
					break;
				case 'image/png':
					$imageType = IMAGETYPE_PNG;
					break;
				case 'image/x-xbitmap':
					$imageType = IMAGETYPE_XBM;
					break;
				case 'image/bmp':
					$imageType = IMAGETYPE_BMP;
					break;
				default:
					throw new Exception('\OC_Image::_output(): "' . $mimeType . '" is not supported when forcing a specific output format');
			}
		}

		switch($imageType) {
			case IMAGETYPE_GIF:
				$retVal = imagegif($this->resource, $filePath);
				break;
			case IMAGETYPE_JPEG:
				$retVal = imagejpeg($this->resource, $filePath);
				break;
			case IMAGETYPE_PNG:
				$retVal = imagepng($this->resource, $filePath);
				break;
			case IMAGETYPE_XBM:
				if (function_exists('imagexbm')) {
					$retVal = imagexbm($this->resource, $filePath);
				} else {
					throw new Exception('\OC_Image::_output(): imagexbm() is not supported.');
				}

				break;
			case IMAGETYPE_WBMP:
				$retVal = imagewbmp($this->resource, $filePath);
				break;
			case IMAGETYPE_BMP:
				$retVal = imagebmp($this->resource, $filePath, $this->bitDepth);
				break;
			default:
				$retVal = imagepng($this->resource, $filePath);
		}
		return $retVal;
	}

	/**
	* Prints the image when called as $image().
	*/
	public function __invoke() {
		return $this->show();
	}

	/**
	* @return resource Returns the image resource in any.
	*/
	public function resource() {
		return $this->resource;
	}

	/**
	* @return string Returns the raw image data.
	*/
	function data() {
		ob_start();
		switch ($this->mimeType) {
			case "image/png":
				$res = imagepng($this->resource);
				break;
			case "image/jpeg":
				$res = imagejpeg($this->resource);
				break;
			case "image/gif":
				$res = imagegif($this->resource);
				break;
			default:
				$res = imagepng($this->resource);
				OC_Log::write('core', 'OC_Image->data. Couldn\'t guess mimetype, defaulting to png', OC_Log::INFO);
				break;
		}
		if (!$res) {
			OC_Log::write('core', 'OC_Image->data. Error getting image data.', OC_Log::ERROR);
		}
		return ob_get_clean();
	}

	/**
	 * @return string - base64 encoded, which is suitable for embedding in a VCard.
	 */
	function __toString() {
		return base64_encode($this->data());
	}

	/**
	* (I'm open for suggestions on better method name ;)
	* Get the orientation based on EXIF data.
	* @return int The orientation or -1 if no EXIF data is available.
	*/
	public function getOrientation() {
		if(!is_callable('exif_read_data')) {
			OC_Log::write('core', 'OC_Image->fixOrientation() Exif module not enabled.', OC_Log::DEBUG);
			return -1;
		}
		if(!$this->valid()) {
			OC_Log::write('core', 'OC_Image->fixOrientation() No image loaded.', OC_Log::DEBUG);
			return -1;
		}
		if(is_null($this->filePath) || !is_readable($this->filePath)) {
			OC_Log::write('core', 'OC_Image->fixOrientation() No readable file path set.', OC_Log::DEBUG);
			return -1;
		}
		$exif = @exif_read_data($this->filePath, 'IFD0');
		if(!$exif) {
			return -1;
		}
		if(!isset($exif['Orientation'])) {
			return -1;
		}
		return $exif['Orientation'];
	}

	/**
	* (I'm open for suggestions on better method name ;)
	* Fixes orientation based on EXIF data.
	* @return bool.
	*/
	public function fixOrientation() {
		$o = $this->getOrientation();
		OC_Log::write('core', 'OC_Image->fixOrientation() Orientation: '.$o, OC_Log::DEBUG);
		$rotate = 0;
		switch($o) {
			case -1:
				return false; //Nothing to fix
			case 1:
				$rotate = 0;
				break;
			case 2: // Not tested
				$rotate = 0;
				break;
			case 3:
				$rotate = 180;
				break;
			case 4: // Not tested
				$rotate = 180;
				break;
			case 5: // Not tested
				$rotate = 90;
				break;
			case 6:
				//$rotate = 90;
				$rotate = 270;
				break;
			case 7: // Not tested
				$rotate = 270;
				break;
			case 8:
				$rotate = 90;
				break;
		}
		if($rotate) {
			$res = imagerotate($this->resource, $rotate, 0);
			if($res) {
				if(imagealphablending($res, true)) {
					if(imagesavealpha($res, true)) {
						imagedestroy($this->resource);
						$this->resource = $res;
						return true;
					} else {
						OC_Log::write('core', 'OC_Image->fixOrientation() Error during alphasaving.', OC_Log::DEBUG);
						return false;
					}
				} else {
					OC_Log::write('core', 'OC_Image->fixOrientation() Error during alphablending.', OC_Log::DEBUG);
					return false;
				}
			} else {
				OC_Log::write('core', 'OC_Image->fixOrientation() Error during oriention fixing.', OC_Log::DEBUG);
				return false;
			}
		}
		return false;
	}

	/**
	 * Loads an image from a local file, a base64 encoded string or a resource created by an imagecreate* function.
	 * @param resource|string $imageRef The path to a local file, a base64 encoded string or a resource created by an imagecreate* function or a file resource (file handle    ).
	 * @return resource|false An image resource or false on error
	 */
	public function load($imageRef) {
		if(is_resource($imageRef)) {
			if(get_resource_type($imageRef) == 'gd') {
				$this->resource = $imageRef;
				return $this->resource;
			} elseif(in_array(get_resource_type($imageRef), array('file', 'stream'))) {
				return $this->loadFromFileHandle($imageRef);
			}
		} elseif($this->loadFromBase64($imageRef) !== false) {
			return $this->resource;
		} elseif($this->loadFromFile($imageRef) !== false) {
			return $this->resource;
		} elseif($this->loadFromData($imageRef) !== false) {
			return $this->resource;
		} else {
			OC_Log::write('core', __METHOD__.'(): couldn\'t load anything. Giving up!', OC_Log::DEBUG);
			return false;
		}
	}

	/**
	* Loads an image from an open file handle.
	* It is the responsibility of the caller to position the pointer at the correct place and to close the handle again.
	* @param resource $handle
	* @return resource|false An image resource or false on error
	*/
	public function loadFromFileHandle($handle) {
		OC_Log::write('core', __METHOD__.'(): Trying', OC_Log::DEBUG);
		$contents = stream_get_contents($handle);
		if($this->loadFromData($contents)) {
			return $this->resource;
		}
	}

	/**
	* Loads an image from a local file.
	* @param bool|string $imagePath The path to a local file.
	* @return bool|resource An image resource or false on error
	*/
	public function loadFromFile($imagePath=false) {
		// exif_imagetype throws "read error!" if file is less than 12 byte
		if(!@is_file($imagePath) || !file_exists($imagePath) || filesize($imagePath) < 12 || !is_readable($imagePath)) {
			OC_Log::write('core', 'OC_Image->loadFromFile, couldn\'t load: ' . (string) urlencode($imagePath), OC_Log::DEBUG);
			return false;
		}
		$iType = exif_imagetype($imagePath);
		switch ($iType) {
			case IMAGETYPE_GIF:
				if (imagetypes() & IMG_GIF) {
					$this->resource = imagecreatefromgif($imagePath);
					// Preserve transparency
					imagealphablending($this->resource, true);
					imagesavealpha($this->resource, true);
				} else {
					OC_Log::write('core',
						'OC_Image->loadFromFile, GIF images not supported: '.$imagePath,
						OC_Log::DEBUG);
				}
				break;
			case IMAGETYPE_JPEG:
				if (imagetypes() & IMG_JPG) {
					$this->resource = imagecreatefromjpeg($imagePath);
				} else {
					OC_Log::write('core',
						'OC_Image->loadFromFile, JPG images not supported: '.$imagePath,
						OC_Log::DEBUG);
				}
				break;
			case IMAGETYPE_PNG:
				if (imagetypes() & IMG_PNG) {
					$this->resource = imagecreatefrompng($imagePath);
					// Preserve transparency
					imagealphablending($this->resource, true);
					imagesavealpha($this->resource, true);
				} else {
					OC_Log::write('core',
						'OC_Image->loadFromFile, PNG images not supported: '.$imagePath,
						OC_Log::DEBUG);
				}
				break;
			case IMAGETYPE_XBM:
				if (imagetypes() & IMG_XPM) {
					$this->resource = imagecreatefromxbm($imagePath);
				} else {
					OC_Log::write('core',
						'OC_Image->loadFromFile, XBM/XPM images not supported: '.$imagePath,
						OC_Log::DEBUG);
				}
				break;
			case IMAGETYPE_WBMP:
				if (imagetypes() & IMG_WBMP) {
					$this->resource = imagecreatefromwbmp($imagePath);
				} else {
					OC_Log::write('core',
						'OC_Image->loadFromFile, WBMP images not supported: '.$imagePath,
						OC_Log::DEBUG);
				}
				break;
			case IMAGETYPE_BMP:
					$this->resource = $this->imagecreatefrombmp($imagePath);
				break;
			/*
			case IMAGETYPE_TIFF_II: // (intel byte order)
				break;
			case IMAGETYPE_TIFF_MM: // (motorola byte order)
				break;
			case IMAGETYPE_JPC:
				break;
			case IMAGETYPE_JP2:
				break;
			case IMAGETYPE_JPX:
				break;
			case IMAGETYPE_JB2:
				break;
			case IMAGETYPE_SWC:
				break;
			case IMAGETYPE_IFF:
				break;
			case IMAGETYPE_ICO:
				break;
			case IMAGETYPE_SWF:
				break;
			case IMAGETYPE_PSD:
				break;
			*/
			default:

				// this is mostly file created from encrypted file
				$this->resource = imagecreatefromstring(\OC\Files\Filesystem::file_get_contents(\OC\Files\Filesystem::getLocalPath($imagePath)));
				$iType = IMAGETYPE_PNG;
				OC_Log::write('core', 'OC_Image->loadFromFile, Default', OC_Log::DEBUG);
				break;
		}
		if($this->valid()) {
			$this->imageType = $iType;
			$this->mimeType = image_type_to_mime_type($iType);
			$this->filePath = $imagePath;
		}
		return $this->resource;
	}

	/**
	* Loads an image from a string of data.
	* @param string $str A string of image data as read from a file.
	* @return bool|resource An image resource or false on error
	*/
	public function loadFromData($str) {
		if(is_resource($str)) {
			return false;
		}
		$this->resource = @imagecreatefromstring($str);
		if ($this->fileInfo) {
			$this->mimeType = $this->fileInfo->buffer($str);
		}
		if(is_resource($this->resource)) {
			imagealphablending($this->resource, false);
			imagesavealpha($this->resource, true);
		}

		if(!$this->resource) {
			OC_Log::write('core', 'OC_Image->loadFromData, couldn\'t load', OC_Log::DEBUG);
			return false;
		}
		return $this->resource;
	}

	/**
	* Loads an image from a base64 encoded string.
	* @param string $str A string base64 encoded string of image data.
	* @return bool|resource An image resource or false on error
	*/
	public function loadFromBase64($str) {
		if(!is_string($str)) {
			return false;
		}
		$data = base64_decode($str);
		if($data) { // try to load from string data
			$this->resource = @imagecreatefromstring($data);
			if ($this->fileInfo) {
				$this->mimeType = $this->fileInfo->buffer($data);
			}
			if(!$this->resource) {
				OC_Log::write('core', 'OC_Image->loadFromBase64, couldn\'t load', OC_Log::DEBUG);
				return false;
			}
			return $this->resource;
		} else {
			return false;
		}
	}

	/**
	 * Create a new image from file or URL
	 * @link http://www.programmierer-forum.de/function-imagecreatefrombmp-laeuft-mit-allen-bitraten-t143137.htm
	 * @version 1.00
	 * @param string $fileName <p>
	 * Path to the BMP image.
	 * </p>
	 * @return bool|resource an image resource identifier on success, <b>FALSE</b> on errors.
	 */
	private function imagecreatefrombmp($fileName) {
		if (!($fh = fopen($fileName, 'rb'))) {
			trigger_error('imagecreatefrombmp: Can not open ' . $fileName, E_USER_WARNING);
			return false;
		}
		// read file header
		$meta = unpack('vtype/Vfilesize/Vreserved/Voffset', fread($fh, 14));
		// check for bitmap
		if ($meta['type'] != 19778) {
			trigger_error('imagecreatefrombmp: ' . $fileName . ' is not a bitmap!', E_USER_WARNING);
			return false;
		}
		// read image header
		$meta += unpack('Vheadersize/Vwidth/Vheight/vplanes/vbits/Vcompression/Vimagesize/Vxres/Vyres/Vcolors/Vimportant', fread($fh, 40));
		// read additional 16bit header
		if ($meta['bits'] == 16) {
			$meta += unpack('VrMask/VgMask/VbMask', fread($fh, 12));
		}
		// set bytes and padding
		$meta['bytes'] = $meta['bits'] / 8;
		$this->bitDepth = $meta['bits']; //remember the bit depth for the imagebmp call
		$meta['decal'] = 4 - (4 * (($meta['width'] * $meta['bytes'] / 4)- floor($meta['width'] * $meta['bytes'] / 4)));
		if ($meta['decal'] == 4) {
			$meta['decal'] = 0;
		}
		// obtain imagesize
		if ($meta['imagesize'] < 1) {
			$meta['imagesize'] = $meta['filesize'] - $meta['offset'];
			// in rare cases filesize is equal to offset so we need to read physical size
			if ($meta['imagesize'] < 1) {
				$meta['imagesize'] = @filesize($fileName) - $meta['offset'];
				if ($meta['imagesize'] < 1) {
					trigger_error('imagecreatefrombmp: Can not obtain filesize of ' . $fileName . '!', E_USER_WARNING);
					return false;
				}
			}
		}
		// calculate colors
		$meta['colors'] = !$meta['colors'] ? pow(2, $meta['bits']) : $meta['colors'];
		// read color palette
		$palette = array();
		if ($meta['bits'] < 16) {
			$palette = unpack('l' . $meta['colors'], fread($fh, $meta['colors'] * 4));
			// in rare cases the color value is signed
			if ($palette[1] < 0) {
				foreach ($palette as $i => $color) {
					$palette[$i] = $color + 16777216;
				}
			}
		}
		// create gd image
		$im = imagecreatetruecolor($meta['width'], $meta['height']);
		$data = fread($fh, $meta['imagesize']);
		$p = 0;
		$vide = chr(0);
		$y = $meta['height'] - 1;
		$error = 'imagecreatefrombmp: ' . $fileName . ' has not enough data!';
		// loop through the image data beginning with the lower left corner
		while ($y >= 0) {
			$x = 0;
			while ($x < $meta['width']) {
				switch ($meta['bits']) {
					case 32:
					case 24:
						if (!($part = substr($data, $p, 3))) {
							trigger_error($error, E_USER_WARNING);
							return $im;
						}
						$color = unpack('V', $part . $vide);
						break;
					case 16:
						if (!($part = substr($data, $p, 2))) {
							trigger_error($error, E_USER_WARNING);
							return $im;
						}
						$color = unpack('v', $part);
						$color[1] = (($color[1] & 0xf800) >> 8) * 65536 + (($color[1] & 0x07e0) >> 3) * 256 + (($color[1] & 0x001f) << 3);
						break;
					case 8:
						$color = unpack('n', $vide . substr($data, $p, 1));
						$color[1] = $palette[ $color[1] + 1 ];
						break;
					case 4:
						$color = unpack('n', $vide . substr($data, floor($p), 1));
						$color[1] = ($p * 2) % 2 == 0 ? $color[1] >> 4 : $color[1] & 0x0F;
						$color[1] = $palette[ $color[1] + 1 ];
						break;
					case 1:
						$color = unpack('n', $vide . substr($data, floor($p), 1));
						switch (($p * 8) % 8) {
							case 0:
								$color[1] = $color[1] >> 7;
								break;
							case 1:
								$color[1] = ($color[1] & 0x40) >> 6;
								break;
							case 2:
								$color[1] = ($color[1] & 0x20) >> 5;
								break;
							case 3:
								$color[1] = ($color[1] & 0x10) >> 4;
								break;
							case 4:
								$color[1] = ($color[1] & 0x8) >> 3;
								break;
							case 5:
								$color[1] = ($color[1] & 0x4) >> 2;
								break;
							case 6:
								$color[1] = ($color[1] & 0x2) >> 1;
								break;
							case 7:
								$color[1] = ($color[1] & 0x1);
								break;
						}
						$color[1] = $palette[ $color[1] + 1 ];
						break;
					default:
						trigger_error('imagecreatefrombmp: '
							. $fileName . ' has ' . $meta['bits'] . ' bits and this is not supported!',
							E_USER_WARNING);
						return false;
				}
				imagesetpixel($im, $x, $y, $color[1]);
				$x++;
				$p += $meta['bytes'];
			}
			$y--;
			$p += $meta['decal'];
		}
		fclose($fh);
		return $im;
	}

	/**
	* Resizes the image preserving ratio.
	* @param integer $maxSize The maximum size of either the width or height.
	* @return bool
	*/
	public function resize($maxSize) {
		if(!$this->valid()) {
			OC_Log::write('core', __METHOD__.'(): No image loaded', OC_Log::ERROR);
			return false;
		}
		$widthOrig=imageSX($this->resource);
		$heightOrig=imageSY($this->resource);
		$ratioOrig = $widthOrig/$heightOrig;

		if ($ratioOrig > 1) {
			$newHeight = round($maxSize/$ratioOrig);
			$newWidth = $maxSize;
		} else {
			$newWidth = round($maxSize*$ratioOrig);
			$newHeight = $maxSize;
		}

		$this->preciseResize(round($newWidth), round($newHeight));
		return true;
	}

	/**
	 * @param int $width
	 * @param int $height
	 * @return bool
	 */
	public function preciseResize($width, $height) {
		if (!$this->valid()) {
			OC_Log::write('core', __METHOD__.'(): No image loaded', OC_Log::ERROR);
			return false;
		}
		$widthOrig=imageSX($this->resource);
		$heightOrig=imageSY($this->resource);
		$process = imagecreatetruecolor($width, $height);

		if ($process == false) {
			OC_Log::write('core', __METHOD__.'(): Error creating true color image', OC_Log::ERROR);
			imagedestroy($process);
			return false;
		}

		// preserve transparency
		if($this->imageType == IMAGETYPE_GIF or $this->imageType == IMAGETYPE_PNG) {
			imagecolortransparent($process, imagecolorallocatealpha($process, 0, 0, 0, 127));
			imagealphablending($process, false);
			imagesavealpha($process, true);
		}

		imagecopyresampled($process, $this->resource, 0, 0, 0, 0, $width, $height, $widthOrig, $heightOrig);
		if ($process == false) {
			OC_Log::write('core', __METHOD__.'(): Error resampling process image '.$width.'x'.$height, OC_Log::ERROR);
			imagedestroy($process);
			return false;
		}
		imagedestroy($this->resource);
		$this->resource = $process;
		return true;
	}

	/**
	* Crops the image to the middle square. If the image is already square it just returns.
	* @param int $size maximum size for the result (optional)
	* @return bool for success or failure
	*/
	public function centerCrop($size=0) {
		if(!$this->valid()) {
			OC_Log::write('core', 'OC_Image->centerCrop, No image loaded', OC_Log::ERROR);
			return false;
		}
		$widthOrig=imageSX($this->resource);
		$heightOrig=imageSY($this->resource);
		if($widthOrig === $heightOrig and $size==0) {
			return true;
		}
		$ratioOrig = $widthOrig/$heightOrig;
		$width = $height = min($widthOrig, $heightOrig);

		if ($ratioOrig > 1) {
			$x = ($widthOrig/2) - ($width/2);
			$y = 0;
		} else {
			$y = ($heightOrig/2) - ($height/2);
			$x = 0;
		}
		if($size>0) {
			$targetWidth=$size;
			$targetHeight=$size;
		}else{
			$targetWidth=$width;
			$targetHeight=$height;
		}
		$process = imagecreatetruecolor($targetWidth, $targetHeight);
		if ($process == false) {
			OC_Log::write('core', 'OC_Image->centerCrop. Error creating true color image', OC_Log::ERROR);
			imagedestroy($process);
			return false;
		}

		// preserve transparency
		if($this->imageType == IMAGETYPE_GIF or $this->imageType == IMAGETYPE_PNG) {
			imagecolortransparent($process, imagecolorallocatealpha($process, 0, 0, 0, 127));
			imagealphablending($process, false);
			imagesavealpha($process, true);
		}

		imagecopyresampled($process, $this->resource, 0, 0, $x, $y, $targetWidth, $targetHeight, $width, $height);
		if ($process == false) {
			OC_Log::write('core',
				'OC_Image->centerCrop. Error resampling process image '.$width.'x'.$height,
				OC_Log::ERROR);
			imagedestroy($process);
			return false;
		}
		imagedestroy($this->resource);
		$this->resource = $process;
		return true;
	}

	/**
	* Crops the image from point $x$y with dimension $wx$h.
	* @param int $x Horizontal position
	* @param int $y Vertical position
	* @param int $w Width
	* @param int $h Height
	* @return bool for success or failure
	*/
	public function crop($x, $y, $w, $h) {
		if(!$this->valid()) {
			OC_Log::write('core', __METHOD__.'(): No image loaded', OC_Log::ERROR);
			return false;
		}
		$process = imagecreatetruecolor($w, $h);
		if ($process == false) {
			OC_Log::write('core', __METHOD__.'(): Error creating true color image', OC_Log::ERROR);
			imagedestroy($process);
			return false;
		}

		// preserve transparency
		if($this->imageType == IMAGETYPE_GIF or $this->imageType == IMAGETYPE_PNG) {
			imagecolortransparent($process, imagecolorallocatealpha($process, 0, 0, 0, 127));
			imagealphablending($process, false);
			imagesavealpha($process, true);
		}

		imagecopyresampled($process, $this->resource, 0, 0, $x, $y, $w, $h, $w, $h);
		if ($process == false) {
			OC_Log::write('core', __METHOD__.'(): Error resampling process image '.$w.'x'.$h, OC_Log::ERROR);
			imagedestroy($process);
			return false;
		}
		imagedestroy($this->resource);
		$this->resource = $process;
		return true;
	}

	/**
	 * Resizes the image to fit within a boundry while preserving ratio.
	 * @param integer $maxWidth
	 * @param integer $maxHeight
	 * @return bool
	 */
	public function fitIn($maxWidth, $maxHeight) {
		if(!$this->valid()) {
			OC_Log::write('core', __METHOD__.'(): No image loaded', OC_Log::ERROR);
			return false;
		}
		$widthOrig=imageSX($this->resource);
		$heightOrig=imageSY($this->resource);
		$ratio = $widthOrig/$heightOrig;

		$newWidth = min($maxWidth, $ratio*$maxHeight);
		$newHeight = min($maxHeight, $maxWidth/$ratio);

		$this->preciseResize(round($newWidth), round($newHeight));
		return true;
	}

	public function destroy() {
		if($this->valid()) {
			imagedestroy($this->resource);
		}
		$this->resource=null;
	}

	public function __destruct() {
		$this->destroy();
	}
}
if ( ! function_exists( 'imagebmp') ) {
	/**
	 * Output a BMP image to either the browser or a file
	 * @link http://www.ugia.cn/wp-data/imagebmp.php
	 * @author legend <legendsky@hotmail.com>
	 * @link http://www.programmierer-forum.de/imagebmp-gute-funktion-gefunden-t143716.htm
	 * @author mgutt <marc@gutt.it>
	 * @version 1.00
	 * @param string $fileName [optional] <p>The path to save the file to.</p>
	 * @param int $bit [optional] <p>Bit depth, (default is 24).</p>
	 * @param int $compression [optional]
	 * @return bool <b>TRUE</b> on success or <b>FALSE</b> on failure.
	 */
	function imagebmp($im, $fileName='', $bit=24, $compression=0) {
		if (!in_array($bit, array(1, 4, 8, 16, 24, 32))) {
			$bit = 24;
		}
		else if ($bit == 32) {
			$bit = 24;
		}
		$bits = pow(2, $bit);
		imagetruecolortopalette($im, true, $bits);
		$width = imagesx($im);
		$height = imagesy($im);
		$colorsNum = imagecolorstotal($im);
		$rgbQuad = '';
		if ($bit <= 8) {
			for ($i = 0; $i < $colorsNum; $i++) {
				$colors = imagecolorsforindex($im, $i);
				$rgbQuad .= chr($colors['blue']) . chr($colors['green']) . chr($colors['red']) . "\0";
			}
			$bmpData = '';
			if ($compression == 0 || $bit < 8) {
				$compression = 0;
				$extra = '';
				$padding = 4 - ceil($width / (8 / $bit)) % 4;
				if ($padding % 4 != 0) {
					$extra = str_repeat("\0", $padding);
				}
				for ($j = $height - 1; $j >= 0; $j --) {
					$i = 0;
					while ($i < $width) {
						$bin = 0;
						$limit = $width - $i < 8 / $bit ? (8 / $bit - $width + $i) * $bit : 0;
						for ($k = 8 - $bit; $k >= $limit; $k -= $bit) {
							$index = imagecolorat($im, $i, $j);
							$bin |= $index << $k;
							$i++;
						}
						$bmpData .= chr($bin);
					}
					$bmpData .= $extra;
				}
			}
			// RLE8
			else if ($compression == 1 && $bit == 8) {
				for ($j = $height - 1; $j >= 0; $j--) {
					$lastIndex = "\0";
					$sameNum = 0;
					for ($i = 0; $i <= $width; $i++) {
						$index = imagecolorat($im, $i, $j);
						if ($index !== $lastIndex || $sameNum > 255) {
							if ($sameNum != 0) {
								$bmpData .= chr($sameNum) . chr($lastIndex);
							}
							$lastIndex = $index;
							$sameNum = 1;
						}
						else {
							$sameNum++;
						}
					}
					$bmpData .= "\0\0";
				}
				$bmpData .= "\0\1";
			}
			$sizeQuad = strlen($rgbQuad);
			$sizeData = strlen($bmpData);
		}
		else {
			$extra = '';
			$padding = 4 - ($width * ($bit / 8)) % 4;
			if ($padding % 4 != 0) {
				$extra = str_repeat("\0", $padding);
			}
			$bmpData = '';
			for ($j = $height - 1; $j >= 0; $j--) {
				for ($i = 0; $i < $width; $i++) {
					$index  = imagecolorat($im, $i, $j);
					$colors = imagecolorsforindex($im, $index);
					if ($bit == 16) {
						$bin = 0 << $bit;
						$bin |= ($colors['red'] >> 3) << 10;
						$bin |= ($colors['green'] >> 3) << 5;
						$bin |= $colors['blue'] >> 3;
						$bmpData .= pack("v", $bin);
					}
					else {
						$bmpData .= pack("c*", $colors['blue'], $colors['green'], $colors['red']);
					}
				}
				$bmpData .= $extra;
			}
			$sizeQuad = 0;
			$sizeData = strlen($bmpData);
			$colorsNum = 0;
		}
		$fileHeader = 'BM' . pack('V3', 54 + $sizeQuad + $sizeData, 0, 54 + $sizeQuad);
		$infoHeader = pack('V3v2V*', 0x28, $width, $height, 1, $bit, $compression, $sizeData, 0, 0, $colorsNum, 0);
		if ($fileName != '') {
			$fp = fopen($fileName, 'wb');
			fwrite($fp, $fileHeader . $infoHeader . $rgbQuad . $bmpData);
			fclose($fp);
			return true;
		}
		echo $fileHeader . $infoHeader. $rgbQuad . $bmpData;
		return true;
	}
}

if ( ! function_exists( 'exif_imagetype' ) ) {
	/**
	 * Workaround if exif_imagetype does not exist
	 * @link http://www.php.net/manual/en/function.exif-imagetype.php#80383
	 * @param string $fileName
	 * @return string|boolean
	 */
	function exif_imagetype ( $fileName ) {
		if ( ( $info = getimagesize( $fileName ) ) !== false ) {
			return $info[2];
		}
		return false;
	}
}
