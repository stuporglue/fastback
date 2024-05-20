<?php

require_once(__DIR__ . '/module.php');
require_once(__DIR__ . '/exif.php');

class Fastback_Photos extends Fastback_Module {

	use Fastback_Exif;

	var $module_type_short = 'p';	// For JavaScript handling
	var $module_type = 'photos';	// For the database

	var $_vipsthumbnail; // tool to make thumbs

	var $_convert; // Where is imagemagick

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


	public function prep_for_csv() {
		// Start by assuming anything unprocessed is valid
		$this->fb->sql_query_single("UPDATE fastback SET csv_ready=1 WHERE csv_ready IS NULL AND module={$this->id}");

		// Remove duplicates
		// DO THIS WHILE more rows returned
		do { 
		$res = $this->fb->sql_query_single("
				UPDATE 
				fastback 
				SET csv_ready=-1 
				WHERE 
				module={$this->id}
				AND file in 
					(
						SELECT MIN(file) AS file 
						FROM fastback 
						WHERE 
						csv_ready<>-1
						AND content_identifier IS NOT NULL 
						AND content_identifier<>-1 
						AND module={$this->id}
						GROUP BY module, content_identifier 
						HAVING COUNT(file) > 1
					)
				RETURNING file
				");

		} while (!empty($res));

		// Set alt values
		$this->fb->sql_query_single("
				UPDATE
				fastback
				SET
				alt_content=alty.alt_content
				FROM (
					SELECT
					fb.content_identifier,
					CONCAT(alt.module,':',MIN(alt.file)) AS alt_content
					FROM
					fastback fb
					LEFT JOIN fastback alt ON (fb.content_identifier=alt.content_identifier AND fb.module<>alt.module)
					WHERE
					fb.module=64
					AND fb.content_identifier IS NOT NULL
					AND fb.content_identifier<>-1
					AND fb.csv_ready<>-1
					AND alt.module IS NOT NULL
					GROUP BY alt.content_identifier) alty
				WHERE
				fastback.module=64
				AND fastback.content_identifier=alty.content_identifier
				AND fastback.alt_content IS NULL
		");
	}

	public function send_web_view($file) {
			if ( !isset($this->_convert) ) { $this->_convert = trim(`which convert`); }

			// Vips is just for thumbnails, try imagemagick
			if ( !empty($this->_convert) ) {
				$cmd = $this->_convert . ' ' . escapeshellarg($this->path . '/' . $file) . ' JPG:-';
				header("Content-Type: image/jpeg");
				header("Content-Disposition: inline; filename=\"" . basename($file) . ".jpg\"");
				passthru($cmd);
				exit();
			} else {
				die(__FILE__ . ":" . __LINE__ . ' -- ' .  microtime () ); 
				header("Location: ?download={$this->id}:" . preg_replace('^./','',$file));
			}
	}
}
