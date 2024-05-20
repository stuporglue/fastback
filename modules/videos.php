<?php

require_once(__DIR__ . '/module.php');
require_once(__DIR__ . '/exif.php');

class Fastback_Videos extends Fastback_Module {

	use Fastback_Exif;

	var $module_type_short = 'v';	// For JavaScript handling
	var $module_type = 'videos';	// For the database

	var $_vipsthumbnail; // tool to make thumbs

	/*
	* ffmpeg -i photobase/$original_file $this->_ffmpeg_webversion -threads $this->_ffmpeg_webversion_threads cache/$webversion_output_file
	* Defatul command derived from examples here: https://gist.github.com/jaydenseric/220c785d6289bcfd7366
	*/
	var $_ffmpeg;
	var $_ffmpeg_webversion = ' -c:v libx264 -pix_fmt yuv420p -profile:v baseline -level 3.0 -crf 22 -maxrate 2M -bufsize 4M -preset medium -vf "scale=\'min(1024,iw)\':-2" -c:a aac -strict experimental -movflags +faststart';
	var $_ffmpeg_webversion_threads = 'auto';		// auto uses 0 (optimal) for CLI, 1 for web requests

	var $supported_types = array( // Video formats that we will search for
		'dv',
		'3gp',
		'avi',
		'm4v',
		'mov',
		'mp4',
		'mpeg',
		'mpg',
		'ogg',
		'vob',
		'webm',
	);

	/**
	 * Make a webversion copy of the file under consideration. Does the reencoding with ffmpeg
	 */
	public function make_a_webversion($file,$videothumb) {

		if ( file_exists($this->fb->filecache . $videothumb) ) {
			return true;
		}

		if ( !file_exists($this->path . '/' . $file) ) {
			return false;
		}

		if ( !isset($this->_ffmpeg) ) { $this->_ffmpeg = trim(`which ffmpeg`); }

		$shellfile = escapeshellarg($this->path . '/' . $file);
		$shellthumbvid = escapeshellarg($this->fb->filecache . $videothumb);

		// find . -regextype sed -iregex  ".*.\(mp4\|mov\|avi\|dv\|3gp\|mpeg\|mpg\|ogg\|vob\|webm\).webp" -delete
		// If we're running from CLI, then use as much processor as possible since the user has direct control over the process & utilization
		// Otherwise, run on 1 thread so we don't use more than what the PHP process was going ot use anyways.
		if ( $this->_ffmpeg_webversion_threads == 'auto' ) {
			$threads = 0;
		} else {
			$threads = $this->_ffmpeg_webversion_threads;
		}

		$cmd = $this->_ffmpeg . ' -i ' . $shellfile . ' ' . $this->_ffmpeg_webversion . ' -threads ' . $threads . ' ' . $shellthumbvid . ' 4>/dev/null; echo $?';
		$res = `$cmd`;
		$res = trim($res);

		if ( file_exists($this->fb->filecache . $videothumb) && $res == '0' ) {
			return true;
		} 

		return false;
	}

	/**
	 * Make a video thumbnail
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
		$tmpthumb = $this->fb->filecache . '/tmpthumb_' . getmypid() . '.webp';
		$shellthumbvid = escapeshellarg($this->fb->filecache . $thumbnailfile . '.mp4');
		$tmpshellthumb = escapeshellarg($tmpthumb);

		if ( !isset($this->_ffmpeg) ) { $this->_ffmpeg = trim(`which ffmpeg`); }

		if ( empty($this->_ffmpeg) ) {
			return false;
		}

		// https://superuser.com/questions/650291/how-to-get-video-duration-in-seconds
		// Should we make animated thumbs for short/live videos?

		$timestamps = array('10',2,'00:00:00');

		foreach($timestamps as $timestamp) {

			$cmd = "{$this->_ffmpeg} -y -ss $timestamp -i $shellfile -vframes 1 $tmpshellthumb 2> /dev/null; echo $?";
			$res = `$cmd`;
			$res = trim($res);

			if ( file_exists($tmpthumb) && filesize($tmpthumb) > 0 && $res == '0') {
				break;
			}
		}

		clearstatcache();

		if ( file_exists($tmpthumb) && filesize($tmpthumb) !== 0 && $res == '0') {

			if ( !isset($this->_vipsthumbnail) ) { $this->_vipsthumbnail = trim(`which vipsthumbnail`); }

			if ( !empty($this->_vipsthumbnail) ) {
				$cmd = "$this->_vipsthumbnail --size={$this->fb->_thumbsize} --output=$shellthumb --smartcrop=attention $tmpshellthumb";
				$res = `$cmd`;
				// unlink($tmpthumb);
			} else {
				@rename($tmpthumb,$this->fb->filecache . $thumbnailfile);
			}
		}

		if ( file_exists($this->fb->filecache . $thumbnailfile) ) {
			return $thumbnailfile;
		}

		return false;
	}

	public function prep_for_csv() {
		// Start by assuming anything unprocessed is valid
		print(__FILE__ . ":" . __LINE__ . ' -- ' .  microtime () );
		$this->fb->sql_query_single("UPDATE fastback SET csv_ready=1 WHERE csv_ready IS NULL AND module={$this->id}");
		print(__FILE__ . ":" . __LINE__ . ' -- ' .  microtime () );

		// Remove duplicates
		// REPEAT UNTIL NO MORE files found
		do {
		print(__FILE__ . ":" . __LINE__ . ' -- ' .  microtime () );
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
						RETURNING FILE
						");
		} while (!empty($res));

		print(__FILE__ . ":" . __LINE__ . ' -- ' .  microtime () );
		$this->fb->sql_query_single("
				UPDATE
				fastback
				SET csv_ready=-1
				WHERE
				file in (
				SELECT
				fb.file
				FROM
				fastback fb
				LEFT JOIN fastback orig ON (CONCAT(fb.module,':',fb.file)=orig.alt_content)
				WHERE
				fb.module={$this->id}
				AND fb.csv_ready=1
				AND fb.content_identifier IS NOT NULL
				AND fb.content_identifier<>-1
				AND orig.content_identifier IS NOT NULL
				AND orig.content_identifier<>-1
				)
				AND module={$this->id}
		");
		print(__FILE__ . ":" . __LINE__ . ' -- ' .  microtime () );
	}
}
