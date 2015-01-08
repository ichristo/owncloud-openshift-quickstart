<?php
/**
 * Copyright (c) 2014 Andreas Fischer <bantu@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC;

/**
 * Helper class for large files on 32-bit platforms.
 */
class LargeFileHelper {
	/**
	* pow(2, 53) as a base-10 string.
	* @var string
	*/
	const POW_2_53 = '9007199254740992';

	/**
	* pow(2, 53) - 1 as a base-10 string.
	* @var string
	*/
	const POW_2_53_MINUS_1 = '9007199254740991';

	/**
	* @brief Checks whether our assumptions hold on the PHP platform we are on.
	*
	* @throws \RunTimeException if our assumptions do not hold on the current
	*                           PHP platform.
	*/
	public function __construct() {
		$pow_2_53 = floatval(self::POW_2_53_MINUS_1) + 1.0;
		if ($this->formatUnsignedInteger($pow_2_53) !== self::POW_2_53) {
			throw new \RunTimeException(
				'This class assumes floats to be double precision or "better".'
			);
		}
	}

	/**
	* @brief Formats a signed integer or float as an unsigned integer base-10
	*        string. Passed strings will be checked for being base-10.
	*
	* @param int|float|string $number Number containing unsigned integer data
	*
	* @throws \UnexpectedValueException if $number is not a float, not an int
	*                                   and not a base-10 string.
	*
	* @return string Unsigned integer base-10 string
	*/
	public function formatUnsignedInteger($number) {
		if (is_float($number)) {
			// Undo the effect of the php.ini setting 'precision'.
			return number_format($number, 0, '', '');
		} else if (is_string($number) && ctype_digit($number)) {
			return $number;
		} else if (is_int($number)) {
			// Interpret signed integer as unsigned integer.
			return sprintf('%u', $number);
		} else {
			throw new \UnexpectedValueException(
				'Expected int, float or base-10 string'
			);
		}
	}

	/**
	* @brief Tries to get the size of a file via various workarounds that
	*        even work for large files on 32-bit platforms.
	*
	* @param string $filename Path to the file.
	*
	* @return null|int|float Number of bytes as number (float or int) or
	*                        null on failure.
	*/
	public function getFileSize($filename) {
		$fileSize = $this->getFileSizeViaCurl($filename);
		if (!is_null($fileSize)) {
			return $fileSize;
		}
		$fileSize = $this->getFileSizeViaCOM($filename);
		if (!is_null($fileSize)) {
			return $fileSize;
		}
		$fileSize = $this->getFileSizeViaExec($filename);
		if (!is_null($fileSize)) {
			return $fileSize;
		}
		return $this->getFileSizeNative($filename);
	}

	/**
	* @brief Tries to get the size of a file via a CURL HEAD request.
	*
	* @param string $filename Path to the file.
	*
	* @return null|int|float Number of bytes as number (float or int) or
	*                        null on failure.
	*/
	public function getFileSizeViaCurl($filename) {
		if (function_exists('curl_init')) {
			$fencoded = rawurlencode($filename);
			$ch = curl_init("file://$fencoded");
			curl_setopt($ch, CURLOPT_NOBODY, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADER, true);
			$data = curl_exec($ch);
			curl_close($ch);
			if ($data !== false) {
				$matches = array();
				preg_match('/Content-Length: (\d+)/', $data, $matches);
				if (isset($matches[1])) {
					return 0 + $matches[1];
				}
			}
		}
		return null;
	}

	/**
	* @brief Tries to get the size of a file via the Windows DOM extension.
	*
	* @param string $filename Path to the file.
	*
	* @return null|int|float Number of bytes as number (float or int) or
	*                        null on failure.
	*/
	public function getFileSizeViaCOM($filename) {
		if (class_exists('COM')) {
			$fsobj = new \COM("Scripting.FileSystemObject");
			$file = $fsobj->GetFile($filename);
			return 0 + $file->Size;
		}
		return null;
	}

	/**
	* @brief Tries to get the size of a file via an exec() call.
	*
	* @param string $filename Path to the file.
	*
	* @return null|int|float Number of bytes as number (float or int) or
	*                        null on failure.
	*/
	public function getFileSizeViaExec($filename) {
		if (\OC_Helper::is_function_enabled('exec')) {
			$os = strtolower(php_uname('s'));
			$arg = escapeshellarg($filename);
			$result = null;
			if (strpos($os, 'linux') !== false) {
				$result = $this->exec("stat -c %s $arg");
			} else if (strpos($os, 'bsd') !== false || strpos($os, 'darwin') !== false) {
				$result = $this->exec("stat -f %z $arg");
			} else if (strpos($os, 'win') !== false) {
				$result = $this->exec("for %F in ($arg) do @echo %~zF");
				if (is_null($result)) {
					// PowerShell
					$result = $this->exec("(Get-Item $arg).length");
				}
			}
			return $result;
		}
		return null;
	}

	/**
	* @brief Gets the size of a file via a filesize() call and converts
	*        negative signed int to positive float. As the result of filesize()
	*        will wrap around after a file size of 2^32 bytes = 4 GiB, this
	*        should only be used as a last resort.
	*
	* @param string $filename Path to the file.
	*
	* @return int|float Number of bytes as number (float or int).
	*/
	public function getFileSizeNative($filename) {
		$result = filesize($filename);
		if ($result < 0) {
			// For file sizes between 2 GiB and 4 GiB, filesize() will return a
			// negative int, as the PHP data type int is signed. Interpret the
			// returned int as an unsigned integer and put it into a float.
			return (float) sprintf('%u', $result);
		}
		return $result;
	}

	protected function exec($cmd) {
		$result = trim(exec($cmd));
		return ctype_digit($result) ? 0 + $result : null;
	}
}
