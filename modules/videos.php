<?php

require_once(__DIR__ . '/module.php');
require_once(__DIR__ . '/exif.php');

class Fastback_Videos extends Fastback_Module {

	use Fastback_Exif;

	var $module_type_short = 'v';	// For JavaScript handling
	var $module_type = 'videos';	// For the database

	/*
	* ffmpeg -i photobase/$original_file $this->_ffmpeg_streamable -threads $this->_ffmpeg_streamable_threads cache/$streamable_output_file
	* Defatul command derived from examples here: https://gist.github.com/jaydenseric/220c785d6289bcfd7366
	*/
	var $_ffmpeg_streamable = ' -c:v libx264 -pix_fmt yuv420p -profile:v baseline -level 3.0 -crf 22 -maxrate 2M -bufsize 4M -preset medium -vf "scale=\'min(1024,iw)\':-2" -c:a aac -strict experimental -movflags +faststart';
	var $_ffmpeg_streamable_threads = 'auto';		// auto uses 0 (optimal) for CLI, 1 for web requests

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
	 * Make a streamable copy of the file under consideration. Does the reencoding with ffmpeg
	 */
	private function _make_video_streamable($file,$videothumb) {

		if ( file_exists($this->filecache . $videothumb) ) {
			return true;
		}

		if ( !file_exists($this->photobase . $file) ) {
			return false;
		}

		if ( !isset($this->_ffmpeg) ) { $this->_ffmpeg = trim(`which ffmpeg`); }

		$shellfile = escapeshellarg($this->photobase . $file);
		$shellthumbvid = escapeshellarg($this->filecache . $videothumb);

		// find . -regextype sed -iregex  ".*.\(mp4\|mov\|avi\|dv\|3gp\|mpeg\|mpg\|ogg\|vob\|webm\).webp" -delete
		// If we're running from CLI, then use as much processor as possible since the user has direct control over the process & utilization
		// Otherwise, run on 1 thread so we don't use more than what the PHP process was going ot use anyways.
		if ( $this->_ffmpeg_streamable_threads == 'auto' ) {
			$threads = 0;
		} else {
			$threads = $this->_ffmpeg_streamable_threads;
		}

		$cmd = $this->_ffmpeg . ' -i ' . $shellfile . ' ' . $this->_ffmpeg_streamable . ' -threads ' . $threads . ' ' . $shellthumbvid . ' 4>/dev/null; echo $?';
		$res = `$cmd`;
		$res = trim($res);

		if ( file_exists($this->filecache . $videothumb) && $res == '0' ) {
			return true;
		} 

		return false;
	}

	/**
	 * Make a video thumbnail
	 *
	 * @file The source file
	 * @thumbnailfile The destination file
	 * @print_to_stdout If true then we print headers and image and exit
	 */
	private function _make_video_thumb($file,$thumbnailfile,$print_to_stdout) {
		if ( file_exists($this->filecache . $thumbnailfile) ) {
			if ( $print_to_stdout ) {
				$this->util_readfile($this->filecache . $thumbnailfile);
				exit();
			}
			return $thumbnailfile;
		}

		if ( !isset($this->_ffmpeg) ) { $this->_ffmpeg = trim(`which ffmpeg`); }

		if ( empty($this->_ffmpeg) ) {
			return false;
		}

		$shellfile = escapeshellarg($this->photobase . $file);
		$shellthumb = escapeshellarg($this->filecache . $thumbnailfile);
		$tmpthumb = $this->filecache . '/tmpthumb_' . getmypid() . '.webp';
		$shellthumbvid = escapeshellarg($this->filecache . $thumbnailfile . '.mp4');
		$tmpshellthumb = escapeshellarg($tmpthumb);
		$formatflags = "";

		if ( $print_to_stdout ) {
			$tmpshellthumb = '-';
			$formatflags = ' -f image2 -c png ';
		}

		// https://superuser.com/questions/650291/how-to-get-video-duration-in-seconds
		// Should we make animated thumbs for short/live videos?

		// Get duration
		// approximte duration in seconds = ffmpeg -i ./03/30/IMG_1757.MOV 2>&1 | grep Duration | cut -d ' ' -f 4 | sed s/,// | sed 's@\..*@@g' | awk '{ split($1, A, ":"); split(A[3], B, "."); print 3600*A[1] + 60*A[2] + B[1] }'
		// If under 5 seconds, 
		//  If accompanying photo (on disk? in the db?) 
		//		Make boomerang
		//	Else
		//		Make normal at 0 		
		// Else
		//	Make thumb based on length:


		$timestamps = array('10',2,'00:00:00');

		foreach($timestamps as $timestamp) {

			$cmd = "{$this->_ffmpeg} -y -ss $timestamp -i $shellfile -vframes 1 $formatflags $tmpshellthumb 2> /dev/null; echo $?";
			$res = `$cmd`;
			$res = trim($res);

			if ( $print_to_stdout && $res == '0') {
				header("Content-Type: image/webp");
				header("Last-Modified: " . filemtime($file));
				header('Cache-Control: max-age=86400');
				header('Etag: ' . md5_file($file));
				readfile($file);
				exit();
			}

			if ( file_exists($tmpthumb) && filesize($tmpthumb) > 0 && $res == '0') {
				break;
			}
		}

		clearstatcache();

		if ( file_exists($tmpthumb) && filesize($tmpthumb) !== 0 && $res == '0') {

			if ( !isset($this->_vipsthumbnail) ) { $this->_vipsthumbnail = trim(`which vipsthumbnail`); }

			if ( !empty($this->_vipsthumbnail) ) {
				$cmd = "$this->_vipsthumbnail --size={$this->_thumbsize} --output=$shellthumb --smartcrop=attention $tmpshellthumb";
				$res = `$cmd`;
				// unlink($tmpthumb);
			} else {
				@rename($tmpthumb,$this->filecache . $thumbnailfile);
			}
		}

		if ( file_exists($this->filecache . $thumbnailfile) ) {
			return $thumbnailfile;
		}

		return false;
	}
}
