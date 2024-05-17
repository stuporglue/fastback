<?php

class Fastback_Module {

	var $id;						// Assigned by FastBack
	var $fb;						// FB singleton
	var $module_type_short;			// For JavaScript handling
	var $module_type;				// For the database
	var $lastmod;					// Find all files modified since this date. 
	var $cron;						// Cron object, may be set by Cron

	// Standard objects
	var $path;					// Path for this module to search in.

	// External program(s)
	var $_jpegoptim;

	var $supported_types; 

	var $file_regex = './[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/'; 

	public function __construct($path = __DIR__ . '/../../') {
		$this->path = $path;
		$this->fb = Fastback::getInstance();

		$db_info = $this->fb->sql_query_single("INSERT INTO modules (path,module_type,lastmod) VALUES ('" . SQLite3::escapeString($this->path) . "', '" . SQLite3::escapeString($this->module_type) . "','0') ON CONFLICT(path,module_type) DO UPDATE SET id=id RETURNING id,path,lastmod,module_type",true);

		$this->id = $db_info['id'];
		$this->lastmod = $db_info['lastmod'];
		$this->fb->modules[$this->id] = $this;
	}

	public function get_meta() {
		die("get_meta should be overridden");
	}

	public function process_meta() {
		die("process_meta should be overridden");
	}

	/**
	 * Common method for make_thumbs. Modules can override if they want
	 */
	public function make_thumbs() {
		if ( !isset($this->_jpegoptim) ) { $this->_jpegoptim = trim(`which jpegoptim`); }

		do {
			$queue = $this->fb->sql_get_queue("thumbnail IS NULL AND module={$this->id}");

			$made_thumbs = array();
			$flag_these = array();

			foreach($queue as $file => $row) {
				// Modules should override make_a_thumb unless they override the whole make_thumbs
				$thumbnailfile = $this->make_a_thumb($file,true);

				// If we've got the file, we're good
				if ( file_exists($this->fb->filecache . $thumbnailfile) ) {
					$made_thumbs[$file] = $thumbnailfile;
				} else {
					$flag_these[] = $file;
				}
			}

			$this->fb->sql_update_case_when("UPDATE fastback SET _util=NULL, thumbnail=CASE", $made_thumbs, "ELSE thumbnail END", TRUE);

			$flag_these = array_map('SQLite3::escapeString',$flag_these);
			$extra_sql = "";

			if (!method_exists($this,'make_a_webversion')) {
				$extra_sql =", webversion_made=1";
			}

			$this->fb->sql_query_single("UPDATE fastback SET flagged=1 $extra_sql WHERE file IN ('" . implode("','",$flag_these) . "') AND module={$this->id}");
			$this->cron->sql_update_cron_status('make_thumbs');

		} while (count($made_thumbs) > 0);
	}

	/**
	 * For a given file, make a thumbnail
	 *
	 * @return the thumbnail file name or false
	 */
	public function make_a_thumb($file){
		// Find original file
		$thumbnailfile = $this->fb->sql_query_single("SELECT thumbnail FROM fastback WHERE file='" . SQLite3::escapeString($file) . "' AND module={$this->id}");
		if ( !empty($thumbnailfile) && file_exists($this->fb->filecache . $thumbnailfile) ) {
			// If it exists, we're golden
			return $thumbnailfile;
		}
		$this->fb->log("Making thumb for $file");

		// Verify that file is OK to use
		$file = $this->fb->util_file_is_ok($file,$this->id);

		// Make the relative path to the thumbnail, prefixing the module ID
		$thumbnailfile = './' . $this->id . '/' . ltrim($file,'./') . '.webp';

		// Quick exit if thumb exists. Just update the db and return it.
		if ( file_exists($this->fb->filecache . $thumbnailfile) ) {
			$this->fb->sql_query_single("UPDATE fastback SET thumbnail='" . SQLite3::escapeString($thumbnailfile) . "' WHERE file='" . SQLite3::escapeString($file) . "' AND module={$this->id}");
			return $thumbnailfile;
		} else {
			var_dump($thumbnailfile);
		}

		// Quick exit if cachedir doesn't exist. That means we can't cache.
		if ( !file_exists($this->fb->filecache) ) {
			return false;
		}

		// Cachedir might exist, make sure we have our subdir
		$dirname = dirname($this->fb->filecache . $thumbnailfile);
		if (!file_exists($dirname) ){
			@mkdir($dirname,0750,TRUE);
			if ( !is_dir($dirname) ) {
				$this->fb->log("Cache sub-dir doesn't exist and can't create it");
				return false;
			} else {
				// When we make the dir, put an empty index.php file to prevent directory listings.
				touch($dirname . '/index.php');
			}
		}

		// Find our tools
		$pathinfo = pathinfo($file);

		// Supported type, let's do it.
		if (in_array(strtolower($pathinfo['extension']),$this->supported_types)){
			$res = $this->make_one_thumb($file,$thumbnailfile);
			if ( $res === false ) {
				$this->fb->log("Unable to make a thumbnail for $file");
				return false;
			}
		} else {
			$this->fb->log("What do I do with ");
			$this->fb->log(print_r($pathinfo,TRUE));
			return false;
		}

		// How did this happen?
		if ( !file_exists( $this->fb->filecache . $thumbnailfile ) ) {
			return false;
		}

		// Optimize the thumbs
		if ( !empty($this->_jpegoptim) ) {
			$shellthumb = escapeshellarg($this->fb->filecache . $thumbnailfile);
			$cmd = "{$this->_jpegoptim} --strip-all --strip-exif --strip-iptc $shellthumb";
			$res = `$cmd`;
		}

		$this->fb->sql_query_single("UPDATE fastback SET thumbnail='" . SQLite3::escapeString($thumbnailfile) . "' WHERE file='" . SQLite3::escapeString($file) . "' AND module={$this->id}");

		return $thumbnailfile;
	}

	/**
	 * Convert content into something that can be displayed on the web, if needed
	 */
	public function make_webversion(){

		if (!method_exists($this,'make_a_webversion')) {
			return;
		}

		do {
			$queue = $this->fb->sql_get_queue("webversion_made IS NULL AND module={$this->id}");

			foreach($queue as $file => $row) {
				// If we've got the file, we're good
				$videothumb = './' . $this->id . '/' . ltrim($file,'./') . '.mp4';
				$worked = $this->make_a_webversion($file,$videothumb);

				$worked = $worked ? 1 : 0;

				$this->fb->sql_query_single("UPDATE fastback SET webversion_made=$worked WHERE file='"  . SQLite3::escapeString($file) . "' AND module={$this->id}");
			}

			$this->cron->sql_update_cron_status('make_webversion');
		} while (!empty($queue));
	}

	public function __toString() {
		return "Module ({$this->id}): {$this->module_type}, at {$this->path}";
	}
}
