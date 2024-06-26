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

	/**
	 * Process object metadata. If other heavy object processing is needed, this could be a good place.
	 */
	public function process_meta() {
		die("process_meta should be overridden");
	}

	/**
	 * If any additional processing is needed before publishing, do it here. This is called just before the CSV is generated. 
	 * It will be used, for example, for linking live and static photos by their content_identifier fields
	 *
	 * Where process_meta should in theory only get called once per row, prep_for_csv could see
	 * rows getting processed every time a CSV is generated
	 *
	 */
	public function prep_for_csv() {
		die("prep_for_csv should be be overridden");
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

			$this->fb->sql_update_case_when($this->id,"UPDATE fastback SET _util=NULL, thumbnail=CASE", $made_thumbs, "ELSE thumbnail END", TRUE);

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

	/**
	 * Send the full-sized webview version.
	 */
	public function send_web_view($file){
		die("send_web_view should be overridden");
	}

	/**
	 * Send the download version.
	 */
	public function send_download($file){
		$this->fb->util_readfile($this->path . '/' . $file,'download');
	}

	public function send_share($file){
		$this->fb->util_readfile($this->path . '/' . $file);
	}

	public function remove_deleted(){
		chdir($this->path);
		$this->fb->log("Module {$this->id} checking for files now missing from $this->path\n");

		$count = $this->fb->sql_query_single("SELECT COUNT(*) AS c FROM fastback WHERE module={$this->id}");
		$this->fb->log("Checking for missing files: Found {$count} files in the database");

		$this->fb->sql_connect();
		$q = "SELECT file FROM fastback WHERE module={$this->id}";
		$res = $this->fb->_sql->query($q);

		$this->fb->_sql->query("BEGIN");
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if ( !file_exists($row['file'])){
				$this->fb->log("{$row['file']} NOT FOUND! Removing!\n");	
				$this->fb->_sql->query("DELETE FROM fastback WHERE file='" . SQLite3::escapeString($row['file']) . "' AND module={$this->id}");
			}
		}
		$this->fb->_sql->query("COMMIT");
		$this->fb->sql_query_single("UPDATE modules SET lastmod=NULL WHERE id={$this->id}");
		$this->fb->sql_disconnect();
	}

	/**
	 * Standard way to find new files. Modules can override
	 */
	public function find_new_files(){

			chdir($this->path);

			$lastmod = $this->lastmod;

			if ( !is_numeric($lastmod) ) {
				$lastmod = 0;
			}

			$filetypes = implode('\|',$this->supported_types);
			$cmd = 'find -L . -type f -regextype sed -iregex  "' . $this->file_regex . '.*\(' . $filetypes . '\)$" -newerat "@' . $lastmod . '"';

			$modified_files_str = `$cmd`;

			if (!is_null($modified_files_str) && strlen(trim($modified_files_str)) > 0) {
				$modified_files = explode("\n",$modified_files_str);
				$modified_files = array_filter($modified_files);

				$today = date('Ymd');
				$multi_insert = "INSERT INTO fastback (file,module,mtime,share_key) VALUES ";
				$multi_insert_tail = " ON CONFLICT(file) DO UPDATE SET file=file";
				$collect_file = array();
				foreach($modified_files as $k => $one_file){
					$mtime = filemtime($one_file);
					$pathinfo = pathinfo($one_file);

					if ( empty($pathinfo['extension']) ) {
						$this->fb->log(print_r($pathinfo,TRUE));
						continue;
					}

					if ( in_array(strtolower($pathinfo['extension']),$this->supported_types) ) {
						$collect_file[] = "('" .  SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($this->id) . "','" .  SQLite3::escapeString($mtime) .  "','" . md5($one_file) . "')";
					} else {
						$this->fb->log("Don't know what to do with " . print_r($pathinfo,true));
					}

					if ( count($collect_file) >= $this->fb->_upsert_limit) {
						$sql = $multi_insert . implode(",",$collect_file) . $multi_insert_tail;
						$this->fb->sql_query_single($sql);
						$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we found files, we need to make csv
						$this->cron->sql_update_cron_status('find_new_files');
						$collect_file = array();
					}
				}

				if ( count($collect_file) > 0 ) {
					$sql = $multi_insert . implode(",",$collect_file) . $multi_insert_tail;
					$this->fb->sql_query_single($sql);
					$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we found files, we need to make csv
					$this->cron->sql_update_cron_status('find_new_files');
					$collect_file = array();
				}
			}

			$maxtime = $this->fb->sql_query_single("SELECT MAX(mtime) AS maxtime FROM fastback WHERE module={$this->id}");
			$this->fb->sql_query_single("UPDATE modules SET lastmod=$maxtime WHERE id={$this->id}");
			$this->lastmod = $maxtime;
	}
}
