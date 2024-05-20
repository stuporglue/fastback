<?php

if ( php_sapi_name() !== 'cli' ) {
	return;
	exit();
}

require_once(__DIR__ . '/fastback.php');

class Fastback_Cron {
	var $_direct_cron_func_call = false;			// Set to true if a cron util function is called directly from the command line. May alter behavior of a function, such as find_new_files which will use a modified_since time of 0

	var $cronjobs = array(							// These are the cron jobs we will try to run, in the order we try to complete them.
		'find_new_files',							// If you don't want them all to run, for example if you don't want to generate thumbnails, then you could change this.
		'make_csv',
		'get_meta',
		'process_meta',
		'remove_deleted',
		'clear_locks',
		'make_thumbs',							    
		'make_webversion',							    
	);

	var $_crontimeout = 120;						// How long to let cron run for in seconds. External calls don't count, so for thumbs and exif wall time may be longer
	// If this is to short some cron jobs may not record any finished work. See also $_process_limit and $_upsert_limit.
	
	var $_gzip;										// Which gzip binary to use

	var $_cron_min_interval = 62;					// A completed cron will run again occastionally to see if anything is new. This is how long it should wait between runs, when completed.	
	var $_concurrent_cronjobs;						// How many concurrent cron jobs should we run? These take up fcgi processes. 
	// We don't want to use all processes as it will make the server unresponsive.
	// We will set it to CEIL(nproc/4) in cron() to allow some parallell processing.

	var $fb;


	public function __construct() {
		global $argv;
		ini_set('error_log','php://stderr');

		$this->fb = Fastback::getInstance();

		foreach($this->fb->modules as $module) {
			$module->cron = $this;
		}

		pcntl_async_signals(true);

		// setup signal handlers
		pcntl_signal(SIGINT, function(){
			$this->fb->log("Got SIGINT and now exiting");
			exit();
		});

		pcntl_signal(SIGTERM, function(){
			$this->fb->log("Got SIGTERM and now exiting");
			exit();
		});

		if ( isset($argv) ) {
			$debug_found = array_search('debug',$argv);
			if ( $debug_found !== FALSE ) {
				$this->fb->debug = true;
				array_splice($argv,$debug_found,1);
			}
		}

	}

	public function run() {
		global $argv;

		if ( count($argv) == 1 ) {
			$this->cron();
			return;
		}

		$allowed_actions = array('find_new_files','make_csv','process_meta','get_meta','make_thumbs','make_webversion','remove_deleted','clear_locks','status','debug_meme_score');

		if ( in_array($argv[1],$allowed_actions) ) {
			$this->fb->log("Running {$argv[1]}");
			$func = "cron_" . $argv[1];
			$this->_direct_cron_func_call = true;
			$this->$func();
		} else {
			print("You're using fastback photo gallery\n");
			print("Usage: ./cron.php [debug] [" . implode('|',$allowed_actions) . "]\n");
		}
	}


	/**
	 * Do cron upserts
	 */
	public function sql_update_cron_status($job,$complete = false,$meta=false) {
		$the_time = time();
		$owner = ( $complete ? 'NULL' : "'" . getmypid() . "'");

		if ( $complete ) {
			$complete_val = $the_time;
			$due_to_run = 0; // Completed, then mark not due
		} else {
			$complete_val = $this->fb->sql_query_single("SELECT last_completed FROM cron WHERE job='$job'");
			if ( empty($complete_val) ) {
				$complete_val = 'NULL';
			}
			$due_to_run = 1; // Until it is complete again, we'll keep trying
		}

		if ( $meta !== false ){
			$this->fb->sql_query_single("INSERT INTO cron (job,updated,last_completed,due_to_run,owner,meta)
				values ('$job'," . $the_time . ",$complete_val,$due_to_run,'" . getmypid() . "','" . SQLite3::escapeString($meta) . "')
				ON CONFLICT(job) DO UPDATE SET updated=$the_time,last_completed=$complete_val,due_to_run=$due_to_run,owner=$owner,meta='" . SQLite3::escapeString($meta). "'");
		} else {
			$this->fb->sql_query_single("INSERT INTO cron (job,updated,last_completed,due_to_run,owner)
				values ('$job',$the_time,$complete_val,$due_to_run,'" . getmypid() . "')
				ON CONFLICT(job) DO UPDATE SET updated=$the_time,last_completed=$complete_val,due_to_run=$due_to_run,owner=$owner");
		}

		if ( $complete ) {
			$this->fb->log("Cron job $job was marked as complete");
		}
	}

	/**
	 * Cron should do all the maintenance work as needed. 
	 * It should send JSON with the status so that a JS loop will know when to stop
	 *
	 * This should be called service worker.
	 *
	 * Tasks: 
	 *	* Find new files
	 *	* Get exif from files
	 *	* Get times, geo and tags from exif
	 *	* Flag memes
	 *	* Find and remove deleted files
	 *
	 * It could also be run from the command line.
	 */
	private function cron() {
		/*
		 * Start a buffer and prep to run something in the background
		 * CLI doesn't get a time limit or a buffer
		 */
		register_shutdown_function(function(){
			$this->fb->sql_query_single("UPDATE cron SET owner=NULL WHERE owner='" . getmypid() . "'");
		});

		if ( !isset($this->_concurrent_cronjobs) ) { $this->_concurrent_cronjobs = ceil(`nproc`/4); }

		$cron_status = array();

		// Everything can run at least every _cron_min_interval minutes. 
		$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE updated < " . (time() - $this->_cron_min_interval * 60)); 

		/*
		 * Get the current cron status
		 */
		$q_get_cron = "SELECT job,updated,last_completed,due_to_run,owner FROM cron";
		$res = $this->fb->_sql->query($q_get_cron);
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$cron_status[$row['job']] = $row;
		}

		foreach($this->cronjobs as $job) {
			if ( empty($cron_status[$job] )) {
				$cron_status[$job] = array(
					'job' => $job,
					'updated' => 0,
					'last_completed' => false,
					'due_to_run' => true,
					'owner' => NULL
				);
			}
		}

		$jobs_to_run = array();
		$jobs_running = $this->fb->sql_query_single("SELECT COUNT(*) FROM cron WHERE owner IS NOT NULL");
		if ( $jobs_running <  $this->_concurrent_cronjobs ) {
			foreach($this->cronjobs as $job) {

				if ( !empty($cron_status[$job]['owner']) ) {
					continue;
				}

				if ( !empty($cron_status[$job]['last_completed']) && !$cron_status[$job]['due_to_run'] ) {
					continue;
				}

				$jobs_to_run[] = $job;
			}
		}

		if ( array_key_exists('cron',$_GET) && in_array($_GET['cron'],$this->cronjobs) ){
			$jobs_to_run = array($_GET['cron']);
		}

		$this->cron_status();
		$this->fb->log("Cron Queue is: " . implode(', ',$jobs_to_run));
		foreach($jobs_to_run as $job) {
			$this->fb->log("Running job $job");
			$job = 'cron_' . $job;
			$this->$job();
			$this->fb->log("Job complete!");
		}
	}

	/**
	 * Get all modified files into the db cache
	 *
	 * Because we are reading from the file system this must complete 100% or not at all. We don't have a good way to crawl only part of the fs at the moment.
	 */
	private function cron_find_new_files() {
		$this->sql_update_cron_status('find_new_files');

		$origdir = getcwd();

		foreach($this->fb->modules as $module) {

			chdir($module->path);

			$lastmod = $module->lastmod;

			if ( $this->_direct_cron_func_call ) {
				$this->fb->log("Finding all files, not just newer than $lastmod, since we were called directly");
				$lastmod = 0;
			}

			$filetypes = implode('\|',$module->supported_types);
			$cmd = 'find -L . -type f -regextype sed -iregex  "' . $module->file_regex . '.*\(' . $filetypes . '\)$" -newerat "@' . $lastmod . '"';

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

					if ( in_array(strtolower($pathinfo['extension']),$module->supported_types) ) {
						$collect_file[] = "('" .  SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($module->id) . "','" .  SQLite3::escapeString($mtime) .  "','" . md5($one_file) . "')";
					} else {
						$this->fb->log("Don't know what to do with " . print_r($pathinfo,true));
					}

					if ( count($collect_file) >= $this->fb->_upsert_limit) {
						$sql = $multi_insert . implode(",",$collect_file) . $multi_insert_tail;
						$this->fb->sql_query_single($sql);
						$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we found files, we need to make csv
						$this->sql_update_cron_status('find_new_files');
						$collect_file = array();
					}
				}

				if ( count($collect_file) > 0 ) {
					$sql = $multi_insert . implode(",",$collect_file) . $multi_insert_tail;
					$this->fb->sql_query_single($sql);
					$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we found files, we need to make csv
					$this->sql_update_cron_status('find_new_files');
					$collect_file = array();
				}
			}
		}


		$maxtime = $this->fb->sql_query_single("SELECT MAX(mtime) AS maxtime FROM fastback");
		if ( $maxtime ) {
			// lastmod to see where to pick up from
			$this->sql_update_cron_status('find_new_files',true,$maxtime);
		}
		chdir($origdir);
		return true;
	}

	/**
	 * This task will delete rows from the database for any files which were deleted from disk.
	 */
	private function cron_remove_deleted() {
		$this->sql_update_cron_status('remove_deleted');
		chdir($this->fb->photobase);
		$this->fb->log("Checking for files now missing from $this->fb->photobase\n");

		$count = $this->fb->sql_query_single("SELECT COUNT(*) AS c FROM fastback");
		$this->fb->log("Checking for missing files: Found {$count} files in the database");

		$this->fb->sql_connect();
		$q = "SELECT file FROM fastback";
		$res = $this->fb->_sql->query($q);

		$this->fb->_sql->query("BEGIN");
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if ( !file_exists($row['file'])){
				$this->fb->log("{$row['file']} NOT FOUND! Removing!\n");	
				$this->fb->_sql->query("DELETE FROM fastback WHERE file='" . SQLite3::escapeString($row['file']) . "'");
			}
		}
		$this->fb->_sql->query("COMMIT");
		$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we removed files, we need a new csv

		$this->sql_update_cron_status('find_new_files',false,-1); 
		$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'find_new_files'"); // If we delted files, maybe there are new ones too? 

		$this->fb->sql_disconnect();
		$this->sql_update_cron_status('remove_deleted',true);
	}

	/**
	 * This is the child function that gets forked to make thumbnails
	 *
	 */
	private function cron_make_thumbs() {
		$this->sql_update_cron_status('make_thumbs');

		foreach($this->fb->modules as $module) {
			$module->make_thumbs();
		}

		$this->sql_update_cron_status('make_thumbs',true);
	}


	/**
	 * Make content that are suitable for web. 
	 */
	private function cron_make_webversion() {
		$this->sql_update_cron_status('make_webversion');

		foreach($this->fb->modules as $module) {
			$module->make_webversion();
		}

		$this->sql_update_cron_status('make_webversion',true);
	}

	/**
	 * Get exif data for files that don't have it.
	 */
	private function cron_get_meta() {
		foreach($this->fb->modules as $module) {
			$module->get_meta();
		}
	}
	
	/**
	 * Look for rows that haven't had their exif data processed and handle them.
	 */
	private function cron_process_meta() {
		foreach($this->fb->modules as $module) {
			$module->process_meta();
		}
	}

	/**
	 * Clear all locks. These can happen if jobs timeout or something.
	 */
	private function cron_clear_locks() {
		$this->sql_update_cron_status('clear_locks');

		// Clear reserved things once in a while.  May cause some double processing but also makes it possible to reprocess things that didn't work the first time.
		$this->fb->sql_query_single("UPDATE fastback SET _util=NULL WHERE _util LIKE 'RESERVED%'");

		// Re-try exif data once in a while
		$this->fb->sql_query_single("UPDATE fastback SET exif=NULL WHERE exif LIKE '%\"Error\"%'");

		// Also clear owner of any cron entries which have been idle for 3x the timeout period.
		$this->fb->sql_query_single("UPDATE cron SET owner=NULL WHERE updated < " . (time() - (60 * $this->_crontimeout * 3)));
		$this->sql_update_cron_status('clear_locks',true);
	}

	/**
	 * Update the CSV file
	 */
	private function cron_make_csv(){
		$this->sql_update_cron_status('make_csv');
		// A change to the sqlite or this file could indicate the need for a new csv. 
		// With the cron jobs being busy in the sqlite file that's not completely accurate, but it's the best easy thing.

		if ( $this->fb->debug || !file_exists($this->fb->csvfile) || filemtime($this->fb->sqlitefile) - filemtime($this->fb->csvfile) > 0 || filemtime(__FILE__) -  filemtime($this->fb->csvfile) > 0) {

			foreach($this->fb->modules as $module) {
				$module->prep_for_csv();
			}

			$wrote = $this->util_make_csv();
			if ( $wrote ) {
				$this->sql_update_cron_status('make_csv',true);
			}
		}
	}

	/**
	 * Debug the meme scoring
	 */
	private function cron_debug_meme_score() {
		global $argv;
		$file = $argv[2];
		$file_safe = SQLite3::escapeString($file);
		$exif_json = $this->fb->sql_query_single("SELECT exif FROM fastback WHERE file='$file_safe'");
		$exif = json_decode($exif_json,true);
		$this->verbose = true;
		$ret = $this->_process_exif_meme($exif,$file,array());
		print("Final score is {$ret['maybe_meme']}\n");
	}

	/**
	 * Get the status of the cron jobs
	 */
	private function cron_status($return = false) {

		$cron_status = array();

		$template = array(
			'updated' => NULL,
			'last_completed' => 'Task not complete',
			'due_to_run' => 'Disabled',
			'status' => 'Pending first run',
			'percent_complete' => '0%',
		);

		$allowed_actions = array('find_new_files','make_csv','get_meta','process_meta','make_thumbs','make_webversion','remove_deleted','clear_locks','status');
		foreach($allowed_actions as $job){
			$cron_status[$job] = $template;
			$cron_status[$job]['job'] = $job;
		}

		$this->fb->sql_connect();
		$q_get_cron = "SELECT 
			job,
			datetime(updated,'unixepoch') AS updated,
			datetime(last_completed,'unixepoch') AS last_completed,
			CASE 
				WHEN owner IS NOT NULL THEN 'Currently Running'
				WHEN due_to_run THEN 'Queued to run' 
				ELSE 'Not queued' 
			END AS status 
			FROM cron";
		$res = $this->fb->_sql->query($q_get_cron);

		if ( empty($res) ) {
			$cron_status['queue'] = array();
			header("Content-Type: application/json");
			print json_encode($cron_status);
		}

		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if ( !array_key_exists($row['job'],$cron_status)) {
				continue;
			}
			$cron_status[$row['job']] = array_merge($cron_status[$row['job']],$row);
		}
		foreach($cron_status as $job => $details){
			if ( !in_array($job,$this->cronjobs) ) {
				$cron_status[$job]['status'] = 'Disabled';
			}
		}

		// Calculate how much of each job is done.
		$total_rows = $this->fb->sql_query_single("SELECT COUNT(*) FROM fastback");
		$exif_rows = $this->fb->sql_query_single("SELECT COUNT(*) FROM fastback WHERE flagged or exif IS NOT NULL");
		$webversion_rows = $this->fb->sql_query_single("SELECT COUNT(*) FROM fastback WHERE webversion_made=1 AND flagged IS NULL");

		$cron_status['get_meta']['percent_complete'] = $total_rows > 0 ? round( $exif_rows / $total_rows,4) * 100 . '%' : '0%';
		$cron_status['make_thumbs']['percent_complete'] = $total_rows > 0 ? round($this->fb->sql_query_single("SELECT COUNT(*) FROM FASTBACK WHERE flagged OR thumbnail IS NOT NULL") / $total_rows,4) * 100 . '%' : '0%';
		$cron_status['make_webversion']['percent_complete'] = $webversion_rows > 0 ? round($this->fb->sql_query_single("SELECT COUNT(*) FROM FASTBACK WHERE webversion_made=1") / $webversion_rows,4) * 100 . '%' : '0%';
		$cron_status['process_meta']['percent_complete'] = $exif_rows > 0 ? round($this->fb->sql_query_single("SELECT COUNT(*) FROM FASTBACK WHERE flagged OR sorttime IS NOT NULL") / $exif_rows,4) * 100 . '%' : '0%';

		$all_or_nothing = array('remove_deleted','find_new_files','make_csv','clear_locks','status');
		foreach($all_or_nothing as $job_name) {
			$cron_status[$job_name]['percent_complete'] = ($cron_status[$job_name]['last_completed'] == 'Task not complete' ? '0%' : '100%');
		}

		$this->sql_update_cron_status('status',true);

		if ($return) {
			return $cron_status;
		} else {
			// Pretty print for cli
			if ( count($cron_status) == 0 ) {
				print("No cron info found");
			}

			$cols = array();


			foreach($cron_status as $job => $status){
				foreach($status as $st => $val) {
					if ( empty($cols[$st]) ) {
						$cols[$st] = strlen($st);
					}
					if (is_null($val)) {
						$val = -1;
					}
					$cols[$st] = max($cols[$st],strlen($val));
				}
			}

			$col_order = array('job','status','updated','percent_complete','last_completed');
			foreach($col_order as $col) {
				$len = $cols[$col];
				print(str_pad($col,$len) . " | ");
			}
			print("\n");
			foreach($col_order as $col) {
				$len = $cols[$col];
				print(str_pad('-',$len,'-') . " | ");
			}
			print("\n");
			foreach($cron_status as $job => $status){
				foreach($col_order as $col) {
					$len = $cols[$col];
					if ( array_key_exists($col,$status) ) {
						$dir = STR_PAD_RIGHT;
						if ( $col == 'percent_complete' ) {
							$dir = STR_PAD_LEFT;
						}
						if ( is_null($status[$col]) ) {
							$status[$col] = "";
						}
						print(str_pad($status[$col],$len,' ',$dir) . ' | ');
					} else {
						print(str_pad('',$len) . ' | ');
					}
				}
				print("\n");
			}
		}
	}

	/**
	 * Make the csv cache file
	 *
	 * @param $print_if_not_write If we can't open the cache file, then send the csv directly.
	 *
	 * @note Using $print_if_not_write will cause this function to exit() after sending.
	 */
	public function util_make_csv(){
		$this->fb->sql_connect();

		$q = "SELECT 
			CONCAT(fb.module,':',fb.file) AS file,
			COALESCE(CAST(STRFTIME('%s',fb.sorttime) AS INTEGER),fb.mtime) AS filemtime,
			ROUND(fb.lat,5) AS lat,
			ROUND(fb.lon,5) AS lon,
			fb.tags AS tags,
			alt_content
			FROM fastback fb
			WHERE 
			fb.flagged IS NOT TRUE 
			AND csv_ready=1
			ORDER BY filemtime DESC,fb.file DESC";

		$res = $this->fb->_sql->query($q);

		$this->fb->log("Trying to write to CSV file {$this->fb->csvfile}\n");
		$fh = fopen($this->fb->csvfile,'w');

		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			fputcsv($fh,$row);
		}
		fclose($fh);
		$this->fb->sql_disconnect();

		if ( !isset($this->_gzip) ) { $this->_gzip= trim(`which gzip`); }

		if ( isset($this->_gzip) ) {
			$cmd = "{$this->_gzip} -k --best -f {$this->fb->csvfile}";
			`$cmd`;
		} else if ( file_exists($this->fb->csvfile . '.gz') ) {
			$this->fb->log("Can't write new {$this->fb->csvfile}.gz, but it exists. It may get served and show stale results");
		}

		return true;
	}
}
