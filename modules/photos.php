<?php

require_once(__DIR__ . '/module.php');
require_once(__DIR__ . '/exif.php');

class Fastback_Photos extends Fastback_Module {

	use Fastback_Exif;

	var $module_type_short = 'p';	// For JavaScript handling
	var $module_type = 'photos';	// For the database

	var $_vipsthumbnail; // tool to make thumbs

	var $supported_types = array(	// Photo formats that we will search for
		'png',
		'jpg',
		'heic',
		'jpeg',
		'jp2',
		'bmp',
		'gif',
		'tif',
		'tiff',
		'heic',
		'webp',
	);


	/**
	 * Make an image thumbnail
	 *
	 * @file The source file
	 * @thumbnailfile The destination file
	 */
	public function make_one_thumb($file,$thumbnailfile) {
		if ( file_exists($this->fb->filecache . $thumbnailfile) ) {
			return $thumbnailfile;
		}

		$shellfile = escapeshellarg($this->path . '/' . $file);
		$shellthumb = escapeshellarg($this->fb->filecache . $thumbnailfile);

		// Prefer vips as it makes smaller thumbnails and has smartcrop
		if ( !isset($this->_vipsthumbnail) ) { $this->_vipsthumbnail = trim(`which vipsthumbnail`); }
		if (!empty($this->_vipsthumbnail) ) {
			$cmd = "{$this->_vipsthumbnail} --size={$this->fb->_thumbsize} --output=$shellthumb --smartcrop=attention $shellfile 2>/dev/null";
			$res = `$cmd`;

			if ( file_exists($this->fb->filecache . $thumbnailfile) ) {
				return $thumbnailfile;
			}
			$this->fb->log(__FILE__ . ":" . __LINE__ . ' -- ' .  microtime () . "\n"); 
		}

		return false;
	}
}
