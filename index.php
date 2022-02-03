<?php
/**
 * See fastback.ini.sample for settings
 */
declare(ticks=1);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class fastback {

	// Folder path to cache directory. sqlite and thumbnails will be stored here
	// Optional, will create a cache folder in the currend directory as the default
	var $filecache;

	// URL path to cache directory. 
	// Optional, will use current web path + cache as default
	var $cacheurl;

	// File path to full sized photos
	// Optional, will use current directory as default
	var $photobase;

	// URL path to full sized photos
	// Optional, will use current web path as default
	var $photourl;

	var $datelabels = 'month';

	// Max number of thumbnails to reserve per child process for thumbnail processing
	var $process_limit = 100;

	// Max number of SQL statements to do per upsert
	var $upsert_limit = 100000;

	// Max number of child processes
	var $nproc = 1;

	// Are we debugging
	var $debug = false;

	// Is the db locked by us?
	var $db_lock;

	var $supported_photo_types = array(
		// Photo formats
		'png',
		'jpg',
		'heic',
		'jpeg',
		'jp2',
		'bmp',
		'gif',
		'tif',
		'heic',
	);

	var $supported_video_types = array(
		// Video formats
		'dv',
		'3gp',
		'avi',
		'm4v',
		'mov',
		'mp4',
		'mpeg',
		'mpg',
		'ogg',
		'vob'
	);

	var $meta =array();

	var $sql;

	var $sortorder = 'DESC';

	var $spindex = 0;


	/**
	 * Kick it off
	 */
	function __construct(){

		$this->nproc = trim(shell_exec('nproc'));

		$this->filecache = __DIR__ . '/cache/';
		$this->cacheurl = dirname($_SERVER['SCRIPT_NAME']) . '/cache/';
		$this->photobase = __DIR__ . '/';
		$this->photourl = dirname($_SERVER['SCRIPT_NAME']) . '/';
		$this->staticurl = dirname($_SERVER['SCRIPT_NAME']) . '/';

		if ( !empty($_GET['debug']) && $_GET['debug'] === 'true' ) {
			$this->debug = true;
		}

		if ( file_exists(__DIR__ . '/fastback.ini') ) {
			$settings = parse_ini_file(__DIR__ . '/fastback.ini');
			foreach($settings as $k => $v) {
				$this->$k = $v;
			}
		}

		// Ensure single trailing slashes
		$this->filecache = rtrim($this->filecache,'/') . '/';
		$this->cacheurl = rtrim($this->cacheurl,'/') . '/';
		$this->photobase = rtrim($this->photobase,'/') . '/';
		$this->photourl = rtrim($this->photourl,'/') . '/';
		$this->staticurl = rtrim($this->staticurl,'/') . '/';
		$this->sortorder = ($this->sortorder == 'ASC' ? 'ASC' : 'DESC');

		// Hard work should be done via cli
		if (php_sapi_name() === 'cli') {

			global $argv,$argc;

			if ( !isset($argv) || count($argv) < 2 ) {
				$argv[1] = "default";
			}

			$tasks = array();
			switch($argv[1]) {
			case 'dbtest':
				$tasks = array('db_test');
			case 'resetcache':
				$tasks = array('reset_db_cache');
				break;
			case 'loadcache':
				$tasks = array('load_db_cache');
				break;
			case 'makethumbs':
				$tasks = array('make_thumbnails');
				break;
			case 'fakethumbs':
				$tasks = array('fake_thumbnails');
				break;
			case 'gettime':
				$tasks = array('get_times');
				break;
			case 'getgeo':
				$tasks = array('get_geo');
				break;
			case 'makejson':
				$tasks = array('makejson');
				break;
			case 'makegeojson':
				$tasks = array('makegeojson');
				break;
			case 'fullreset':
				$tasks = array('reset_db_cache','load_db_cache','make_thumbnails','get_times','get_geo','makejson','makegeojson');
				break;
			case 'help':
				$tasks = array('print_cli_usage');
				break;
			case 'default':
				$tasks = array('load_db_cache','make_thumbnails','get_times','get_geo','makejson','makegeojson');
				break;
			default:
				$tasks = array('print_cli_usage');
			}

			pcntl_signal(SIGINT, function(){
				print("\e[?25h"); // Show the cursor
				exit();
			});


			print("\e[?25l"); // Hide the cursor

			print("You're using fastback photo gallery\n");
			print("For help,run \"php ./index.php help\" on the command line");

			print("\n");
			print("\n");
			print("\n");
			print("\e[1A"); // Go up a line
			print("\e[1000D"); // Go to start of line
			for($i = 0; $i < count($tasks); $i++){
				print(" " . str_pad("Working on task " . ($i + 1) . " of " . count($tasks) . " ({$tasks[$i]})",100));
				print("\e[1000D"); // Go to start of line
				$this->{$tasks[$i]}(); // Every task should leave the cursor where it found it
				print("*" . str_pad("Completed task " . ($i + 1) . " of " . count($tasks) . " ({$tasks[$i]})",100));
				$this->print_status_line("",true); // Wipe the intermediate progress line
				print("\n");
				print("\e[1000D"); // Go to start of line
			}

			print("\e[?25l"); // Show cursor

		} else {
			$this->makeoutput();
		}
	}


	/**
	 * Initialize the database
	 */
	public function setup_db() {
		$q_create_meta = "Create TABLE IF NOT EXISTS fastbackmeta ( key VARCHAR(20) PRIMARY KEY, value VARCHAR(255))";
		$res = $this->sql->query($q_create_meta);

		$q_create_files = "CREATE TABLE IF NOT EXISTS fastback ( file TEXT PRIMARY KEY, isvideo BOOL, flagged BOOL, nullgeom BOOL, mtime INTEGER, sorttime DATETIME, thumbnail TEXT, _util TEXT)";
		$res = $this->sql->query($q_create_files);

		$this->sql->loadExtension('mod_spatialite.so');

		$this->sql->query("SELECT InitSpatialMetaData(1);");

		$add_geom = "SELECT AddGeometryColumn('fastback','geom',4326,'POINT',3,0)"; // 3 -> X,Y,Z, 0 -> allow null
		$res = $this->sql->query($add_geom);
	}

	/**
	 * Test if the DB is set up
	 */
	public function db_test() {
		$this->sql_connect();
		$this->sql->query("INSERT INTO fastback (file) VALUES ('test entry')");
		$this->sql->query("DELETE FROM fastback WHERE file='test entry'");
		$this->sql_disconnect();
		print "Test done. If no errors shown, then all is (probably!) well\n";
	}


	/**
	 * Reset the cache
	 */
	public function reset_db_cache() {
		global $argv;
		$this->sql_connect();

		$this->sql->query("DELETE FROM fastback");
		$this->sql->query('UPDATE fastbackmeta SET value="19000101" WHERE key="lastmod"');

		$this->sql_disconnect();
	}

	/**
	 * Get all modified files into the db cache
	 */
	public function load_db_cache() {
		global $argv;

		if ( !file_exists($this->filecache) ) {
			mkdir($this->filecache,0700,TRUE);
		}

		$this->sql_connect();

		$lastmod = '19000101';
		if ( !empty($this->meta['lastmod']) ){
			$lastmod = $this->meta['lastmod'];
		}

		chdir($this->photobase);
		$filetypes = implode('\|',array_merge($this->supported_photo_types, $this->supported_video_types));
		$cmd = 'find . -type f -regextype sed -iregex  "./[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/.*\(' . $filetypes . '\)$" -newerat ' . $lastmod;
		$modified_files_str = `$cmd`;

		if (  is_null($modified_files_str) || strlen(trim($modified_files_str)) === 0) {
			return;
		}

		$modified_files = explode("\n",$modified_files_str);
		$modified_files = array_filter($modified_files);

		$today = date('Ymd');
		$multi_insert = "INSERT INTO fastback (file,mtime,sorttime,isvideo) VALUES ";
		$multi_insert_tail = " ON CONFLICT(file) DO UPDATE SET isvideo=";
		$collect_photo = array();
		$collect_video = array();
		$togo = count($modified_files);
		$total = $togo;
		$this->print_status_line(intval(100*($total - $togo) / $total) . "% : Inserted " . ($total - $togo) . " of $total found files");
		foreach($modified_files as $k => $one_file){
			$mtime = filemtime($one_file);
			$pathinfo = pathinfo($one_file);

			if ( empty($pathinfo['extension']) ) {
				error_log(print_r($pathinfo,TRUE));
				var_dump($one_file);
				die("No file extension. Weird.");
				continue;
			}

			if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {
				$collect_video[] = "('" . SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($mtime) . "','" . SQLite3::escapeString(preg_replace('|.*([0-9]{4})/([0-9]{2})/([0-9]{2})/.*|','\1-\2-\3',$one_file)) . "',1)";
			} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_photo_types) ) {
				$collect_photo[] = "('" . SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($mtime) . "','" . SQLite3::escapeString(preg_replace('|.*([0-9]{4})/([0-9]{2})/([0-9]{2})/.*|','\1-\2-\3',$one_file)) . "',0)";
			} else {
				error_log("Don't know what to do with " . print_r($pathinfo,true));
			}

			if ( count($collect_photo) >= $this->upsert_limit) {
				$sql = $multi_insert . implode(",",$collect_photo) . $multi_insert_tail . '0';
				$this->sql->query($sql);
				$collect_photo = array();
				$togo -= $this->upsert_limit;
				$this->print_status_line(intval(100*($total - $togo) / $total) . "% : Inserted " . ($total - $togo) . " of $total found files");
			}

			if ( count($collect_video) >= $this->upsert_limit) {
				$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
				$this->sql->query($sql);
				$collect_video = array();
				$togo -= $this->upsert_limit;
				$this->print_status_line(intval(100*($total - $togo) / $total) . "% : Inserted " . ($total - $togo) . " of $total found files");
			}
		}

		if ( count($collect_photo) > 0 ) {
			$sql = $multi_insert . implode(",",$collect_photo) . $multi_insert_tail . '0';
			$this->sql->query($sql);
			$togo -= count($collect_photo);
			$this->print_status_line(intval(100 * ($total - $togo) / $total) . "% : Inserted " . ($total - $togo) . " of $total found files");
			$collect_photo = array();
		}

		if ( count($collect_video) > 0 ) {
			$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
			$this->sql->query($sql);
			$togo -= count($collect_video);
			$this->print_status_line(intval(100*($total - $togo) / $total) . "% : Inserted " . ($total - $togo) . " of $total found files");
			$collect_video = array();
		}

		$this->sql->query("INSERT INTO fastbackmeta (key,value) values ('lastmod',".date('Ymd').") ON CONFLICT(key) DO UPDATE SET value=".date('Ymd'));
		$this->sql_disconnect();
	}

	/**
	 * Just tell the DB that all the thumbs are made, even if they're not.
	 */
	public function fake_thumbnails() {
		$this->sql_connect();
		$this->sql->query('UPDATE fastback SET thumbnail=file || ".jpg" WHERE thumbnail IS NULL OR thumbnail LIKE "RESERVED%"');
		$this->sql_disconnect();
	}

	/**
	 * Build thumbnails in parallel
	 *
	 * This is the parent process
	 */
	public function make_thumbnails() {

		$this->sql_connect();

		$this->sql->query("UPDATE fastback SET thumbnail=NULL WHERE thumbnail LIKE 'RESERVED%'");

		$this->sql_disconnect();

		// Make the children
		$children = array();
		for ($i = 0;$i < $this->nproc; $i++){
			switch($pid = pcntl_fork()){
			case -1:
				die("Forking failed");
				break;
			case 0:
				// This is a child
				$this->_make_thumbnails($i);
				exit();
				break;
			default:
				$children[] = $pid;
				// This is the parent
			}
		}

		$this->sql_connect();
		$total = $this->sql->querySingle("SELECT COUNT(*) FROM fastback WHERE thumbnail IS NULL AND flagged IS NOT TRUE",);
		$start = time();
		$this->sql_disconnect();

		// Reap the children
		while(count($children) > 0){
			foreach($children as $key => $child){
				$res = pcntl_waitpid($child, $status, WNOHANG);
				if($res == -1 || $res > 0) {
					unset($children[$key]);
				}
			}
			$this->sql_connect();
			$togo = $this->sql->querySingle("SELECT COUNT(*) FROM fastback WHERE thumbnail IS NULL AND flagged IS NOT TRUE",);
			$this->sql_disconnect();


			if ( $total == 0 ) {
				$percent = 100;
			} else {
				$percent = intval(100*($total - $togo) / $total);
			}
			
			$processed = ($total - $togo);

			if ( $processed === 0 ) {
				$finish_string = "";
			} else {
				$seconds_left = intval((time() - $start)/$processed * $togo);
				$minutes_left = intval($seconds_left / 60);
				$seconds_left = $seconds_left % 60;
				$hours_left = intval($minutes_left / 60);
				$minutes_left = $minutes_left % 60;


				$finish_string = " ETA " . str_pad($hours_left,2,'0',STR_PAD_LEFT) . ':' . str_pad($minutes_left,2,'0',STR_PAD_LEFT) . ':' . str_pad($seconds_left,2,'0',STR_PAD_LEFT);
			}

			$this->print_status_line("$percent% : Generated $processed of $total thumbnails.$finish_string");
			sleep(1);
		}
	}

	private function _make_thumbnails($childno = "Unknown") {

		do {
			$queue = array();
			$this->sql_connect();
			$res = $this->sql->query("UPDATE fastback SET thumbnail='RESERVED-" . getmypid() . "' WHERE flagged IS NOT TRUE AND thumbnail IS NULL AND file != '' LIMIT " . $this->process_limit);
			$q_queue = "SELECT file FROM fastback WHERE thumbnail='RESERVED-" . getmypid() . "'";
			$res = $this->sql->query($q_queue);
			while($row = $res->fetchArray(SQLITE3_ASSOC)){
				$queue[] = $row['file'];
			}
			$this->sql_disconnect();

			if ( count($queue) === 0 ) {
				exit();
			}

			$made_thumbs = array();
			$flag_these = array();
			while($file = array_pop($queue)){

				$thumbnailfile = $this->filecache . '/' . ltrim($file,'./') . '.jpg';

				// Make it if needed
				if ( !file_exists($thumbnailfile) ) {
					$dirname = dirname($thumbnailfile);
					if (!file_exists($dirname) ){
						@mkdir($dirname,0700,TRUE);
					}

					$shellfile = escapeshellarg($file);
					$shellthumb = escapeshellarg($thumbnailfile);
					$pathinfo = pathinfo($file);

					if (in_array(strtolower($pathinfo['extension']),$this->supported_photo_types)){
						$cmd = "vipsthumbnail --size=120x120 --output=$shellthumb --smartcrop=attention $shellfile";
						$res = `$cmd`;
					} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {

						$tmpthumb = $this->filecache . 'tmpthumb_' . getmypid() . '.jpg';
						$tmpshellthumb = escapeshellarg($tmpthumb);

						$cmd = "ffmpeg -y -ss 10 -i $shellfile -vframes 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
						$res = `$cmd`;

						if ( !file_exists($tmpthumb)) {
							$cmd = "ffmpeg -y -ss 2 -i $shellfile -vframes 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
							$res = `$cmd`;
						}

						if ( !file_exists($tmpthumb)) {
							$cmd = "ffmpeg -y -ss 00:00:00 -i $shellfile -frames:v 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
							$res = `$cmd`;
						}

						if ( file_exists($tmpthumb) ) {
							$cmd = "vipsthumbnail --size=120x120 --output=$shellthumb --smartcrop=attention $tmpshellthumb";
							$res = `$cmd`;
							unlink($tmpthumb);
						}

					} else {
						error_log("What do I do with ");
						error_log(print_r($pathinfo,TRUE));
					}

					if ( file_exists( $thumbnailfile ) ) {
						$cmd = "jpegoptim --strip-all --strip-exif --strip-iptc $shellthumb";
						$res = `$cmd`;
					} 
				}

				// If we've got the file, we're good
				if ( file_exists($thumbnailfile) ) {
					$made_thumbs[$file] = $thumbnailfile;
				} else {
					$flag_these[] = $file;
				}
			}

			if (count($made_thumbs) > 0){
				$this->sql_connect();
				$update_q = "UPDATE fastback SET thumbnail=CASE \n";
				foreach($made_thumbs as $file => $thumb){
					$update_q .= " WHEN file='" . SQLite3::escapeString($file) . "' THEN '" . SQLite3::escapeString($thumb) . "'\n";
				}
				$update_q .= " ELSE thumbnail END
					WHERE thumbnail='RESERVED-" . getmypid() . "'";
				$this->sql->query($update_q);
				$this->sql_disconnect();
			}

			if ( count($flag_these) > 0) {
				$this->sql_connect();
				$update_q = "UPDATE fastback SET flagged=1 WHERE file IN (";

				$escaped = array();
				foreach($flag_these as $file){
					$escaped[] = '"' . SQLite3::escapeString($file) . '"';
				}

				$update_q .= implode(",",$escaped);

				$update_q .= ")";

				$this->sql->query($update_q);
				$this->sql_disconnect();
			}

		} while (count($made_thumbs) > 0);

		@unlink($this->filecache . '/fastback.json');
	}

	private function sql_connect($try_no = 1){

		if ( !file_exists($this->filecache . '/fastback.sqlite') ) {
			$this->sql = new SQLite3($this->filecache . '/fastback.sqlite');
			$this->setup_db();
			$this->sql->close();
		}

		if (php_sapi_name() === 'cli') {
			$this->db_lock = fopen($this->filecache . '/fastback.lock','w');
			if( flock($this->db_lock,LOCK_EX)){
				$this->sql = new SQLite3($this->filecache . '/fastback.sqlite');
				$this->sql->loadExtension('mod_spatialite.so');
			} else {
				throw new Exception("Couldn't lock db");
			}
		} else {
			$this->sql = new SQLite3($this->filecache .'/fastback.sqlite');
			$this->sql->loadExtension('mod_spatialite.so');
		}

		if (empty($this->meta)){
			$this->load_meta();
		}
	}

	private function sql_disconnect(){
		$this->sql->close();
		if (!empty($this->db_lock) ) {
			flock($this->db_lock,LOCK_UN);
			fclose($this->db_lock);
		}
		unset($this->sql);
	}

	// One file with various output options, depending on the $_GET flags
	public function makeoutput() {
		if (!empty($_GET['get']) && $_GET['get'] == 'photojson'){
			$this->sendjson();
		} else if (!empty($_GET['get']) && $_GET['get'] == 'geojson'){
			$this->sendgeojson();
		} else if (!empty($_GET['get']) && $_GET['get'] == 'js') {
			$this->makejs();
		} else if (!empty($_GET['flag'])) {
			$this->flag_photo();
		} else if (!empty($_GET['test'])) {
			$this->test();
		} else if (!empty($_GET['proxy'])) {
			$this->proxy();
		} else {
			$this->makehtml();
		}
	}

	/**
	 * Make the json cache file
	 */
	public function makejson(){
		ob_start();
		@unlink($this->filecache . '/fastback.json.gz');
		$this->sendjson();
		ob_end_clean();
	}

	/**
	 * Make the geosjon cache file
	 */

	public function makegeojson() {
		ob_start();
		@unlink($this->filecache . '/fastback.geojson.gz');
		$this->sendgeojson();
		ob_end_clean();
	}


	/**
	 * Generate or send a cached version of the photo json
	 */
	public function sendjson() {
		$json = array(
			'tags' => array(),
		);

		$this->sql_connect();
		$cf = $this->filecache . '/fastback.json.gz';
		@header("Cache-Control: \"max-age=1209600, public");
		@header("Content-Type: application/json");
		@header("Content-Encoding: gzip");
		if (file_exists($cf)) {
			header('Content-Length: ' . filesize($cf));
			readfile($cf);
			exit();
		}

		$total = $this->sql->querySingle("SELECT 
			count(file)
			FROM fastback 
			WHERE 
			thumbnail IS NOT NULL 
			AND thumbnail NOT LIKE 'RESERVE%' 
			AND flagged IS NOT TRUE 
			AND sorttime NOT LIKE '% 00:00:01' 
			AND sorttime IS NOT NULL 
			ORDER BY sorttime " . $this->sortorder . ",file");


		$res = $this->sql->query("SELECT 
			file,
			DATETIME(sorttime) AS sorttime,
			isvideo 
			FROM fastback 
			WHERE 
			thumbnail IS NOT NULL 
			AND thumbnail NOT LIKE 'RESERVE%' 
			AND flagged IS NOT TRUE 
			AND sorttime NOT LIKE '% 00:00:01' 
			AND sorttime IS NOT NULL 
			ORDER BY sorttime " . $this->sortorder . ",file");

		$last_date = NULL;
		$last_year = NULL;
		$idx = 0;
		while($row = $res->fetchArray(SQLITE3_ASSOC)){

			if ( is_null($row['sorttime'])){
				ob_start();
				var_dump($row);
				$err = ob_get_clean();
				error_log($err);
				continue;
			}

			preg_match('|((....)-(..)-(..)) ..:..:..|',$row['sorttime'],$curdates);

			if ( count($curdates) < 2 ) {
				var_dump($row);
				continue;
			}

			$new_date = $curdates[1];

			$showdatelabel = false;
			if ( $this->datelabels === 'all' ) {
				$showdatelabel = true;
			} else if ( $last_date != $new_date ) {

				if ( $this->datelabels == 'day' ) {
					$showdatelabel = true;
				} else if ( $this->datelabels == 'month' ) {
					$last = explode('-',$last_date);
					$new = explode('-',$new_date);

					if ( $last[0] != $new[0] || $last[1] != $new[1] ) {
						$showdatelabel = true;
					}
				} else if ( $this->datelabels == 'year' ) {
					$last = explode('-');
					$new = explode('-');

					if ( $last[0] != $new[0] ) {
						$showdatelabel = true;
					}
				}

				$last_date = $new_date;
			}

			$base = basename($row['file']);
			$json['tags'][] = '<div class="tn' . ($showdatelabel ? ' dlabel' : '') .  ( $row['isvideo'] ? ' vid' : '') . '" ' . ($showdatelabel ? ' data-dlabel="'.preg_replace('| .*|','',$new_date).'"' : '') . ' data-d="' . $row['sorttime'] . '" id=p' . $idx . '><img loading=lazy src="' . htmlentities(substr($row['file'],2)) . '.jpg" alt="' . $base . '"></div>';
			$idx++;

			if ( $total - $idx == 0) {
				$percent = 100;
			} else {
				$percent = intval(100*($idx / $total));
			}

			$this->print_status_line("$percent% : Adding record $idx of $total to the json cache");
		}

		$this->sql_disconnect();

		$str = json_encode($json,JSON_PRETTY_PRINT);
		@file_put_contents('compress.zlib://' . $cf,$str);
		print($str);
	}

	public function sendgeojson() {
		$geojson = array(
			'type' => 'FeatureCollection',
			'bbox' => Array(null,null,null,null,null,null),
			'features' => array()
		);

		$this->sql_connect();
		$cf = $this->filecache . '/fastback.geojson.gz';

		@header("Cache-Control: \"max-age=1209600, public");
		@header("Content-Type: application/geojson");
		@header("Content-Encoding: gzip");
		if (file_exists($cf)) {
			header('Content-Length: ' . filesize($cf));
			readfile($cf);
			exit();
		}

		$total = $this->sql->querySingle("SELECT 
			count(file)
			FROM fastback 
			WHERE 
			thumbnail IS NOT NULL 
			AND thumbnail NOT LIKE 'RESERVE%' 
			AND flagged IS NOT TRUE 
			AND sorttime NOT LIKE '% 00:00:01' 
			AND sorttime IS NOT NULL 
			ORDER BY sorttime " . $this->sortorder . ",file");

		$res = $this->sql->query("SELECT 
			file,
			DATETIME(sorttime) AS sorttime,
			X(geom) AS x,
			Y(geom) AS y,
			Z(geom) AS z
			FROM fastback 
			WHERE 
			thumbnail IS NOT NULL 
			AND thumbnail NOT LIKE 'RESERVE%' 
			AND flagged IS NOT TRUE 
			AND sorttime NOT LIKE '% 00:00:01' 
			AND sorttime IS NOT NULL 
			ORDER BY sorttime " . $this->sortorder . ",file");

		$idx = 0;
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if (is_null($row['sorttime'])) {
				continue;
			}

			preg_match('|((....)-(..)-(..)) ..:..:..|',$row['sorttime'],$curdates);

			if ( count($curdates) < 2 ) {
				continue;
			}

			$idx++;

			if ( is_null($row['x']) ) {
				continue;
			}

			$feature = array(
				'type' => 'Feature',
				'geometry' => array(
					'type' => 'Point',
					'coordinates' => null
				),
				'properties' => array(
					'idx' => $idx - 1,
					'file' => $row['file']
				)
			);

			if ( !is_null($row['x']) ) {
				$feature['geometry']['coordinates'] = array(
						$row['y'],
						$row['x'],
						$row['z']
				);


				// long
				if ( is_null($geojson['bbox'][0]) || $row['x'] < $geojson['bbox'][0] ) {
					$geojson['bbox'][0] = $row['x'];
				}

				if ( is_null($geojson['bbox'][3]) || $row['x'] > $geojson['bbox'][3] ) {
					$geojson['bbox'][3] = $row['x'];
				}

				// lat
				if ( is_null($geojson['bbox'][1]) || $row['y'] < $geojson['bbox'][1] ) {
					$geojson['bbox'][1] = $row['y'];
				}

				if ( is_null($geojson['bbox'][4]) || $row['y'] > $geojson['bbox'][4] ) {
					$geojson['bbox'][4] = $row['y'];
				}

				// elevation
				if ( is_null($geojson['bbox'][2]) || $row['z'] < $geojson['bbox'][2] ) {
					$geojson['bbox'][2] = $row['z'];
				}

				if ( is_null($geojson['bbox'][5]) || $row['z'] > $geojson['bbox'][5] ) {
					$geojson['bbox'][5] = $row['z'];
				}
			}

			$geojson['features'][] = $feature;


			if ( $total - $idx == 0) {
				$percent = 100;
			} else {
				$percent = intval(100*($idx / $total));
			}

			$this->print_status_line("$percent% : Adding record $idx of $total to the geojson cache");
		}

		$this->sql_disconnect();
		$str = json_encode($geojson,JSON_PRETTY_PRINT);
		@file_put_contents('compress.zlib://' . $cf,$str);
		print($str);
	}

	/**
	 * Generate the output html for the webpage, including any dynamicly generated CSS or HTML
	 */
	public function makehtml(){
		$html = '<!DOCTYPE html>
			<html lang="en">
			<head>
			<meta charset="UTF-8">
			<base href="'. $this->cacheurl . '/">
			<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
			<link rel="shortcut icon" href="' . $this->staticurl . '/fastback_assets/favicon.png' . ($this->debug ? '?ts=' . time() : '') . '"> 
			<link rel="apple-touch-icon" href="' . $this->staticurl . '/fastback_assets/favicon.png' . ($this->debug ? '?ts=' . time() : '') . '">
			<title>Moore Photos</title>
			<link rel="stylesheet" href="'. $this->staticurl .'/fastback_assets/jquery-ui-1.12.1/jquery-ui.min.css' . ($this->debug ? '?ts=' . time() : '') . '">
			<link rel="stylesheet" href="'. $this->staticurl .'/fastback_assets/fastback.css' . ($this->debug ? '?ts=' . time() : '') . '">
			<!-- Powered by https://github.com/stuporglue/fastback/ -->
			</head>
			<body>
			<div class="photos" id="photos"></div>
			<div id="resizer">
			<input type="range" min="1" max="10" value="5" class="slider" id="zoom">
			</div>
			<div id="notification"></div>
			<div id="thumb" data-ythreshold=150><div id="thumbcontent"></div><div id="thumbcontrols"></div><div id="thumbclose">🆇</div><div id="thumbleft" class="thumbctrl">LEFT</div><div id="thumbright" class="thumbctrl">RIGHT</div></div>
			<div id="calendaricon"><input readonly id="datepicker" type="text"></div>
			<div id="rewindicon"></div>
			<script src="'. $this->staticurl .'/fastback_assets/jquery.min.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>
<script src="'. $this->staticurl .'/fastback_assets/jquery-ui-1.12.1/jquery-ui.min.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>

<script src="'.$this->staticurl.'/fastback_assets/hammer.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>
<script src="'.$this->staticurl.'/fastback_assets/jquery.hammer.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>

<script src="'.$this->staticurl.'/fastback_assets/fastback.js' . ($this->debug ? '?ts=' . time() : '') . '"></script>
<script>
var FastbackBase = "' . $_SERVER['SCRIPT_NAME'] . '";
var FastbackBase = "' . $_SERVER['SCRIPT_NAME'] . '";
var fastback = new Fastback({
cacheurl: "' . $this->cacheurl . '",
	photourl: "' . $this->photourl . '",
	staticurl: "' . $this->staticurl . '",
	fastbackurl: "' . $_SERVER['SCRIPT_NAME'] . '",
	debug: ' . ($this->debug ? 'true' : 'false'). '
	});
	</script>

	</body>
</html>';
		print $html;
	}

	private function load_meta() {
		$q_getallmeta = "SELECT key,value FROM fastbackmeta";
		$res = $this->sql->query($q_getallmeta);
		$this->meta = array();
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$this->meta[$row['key']] = $row['value'];
		}
	}

	public function flag_photo(){
		$photo = $_GET['flag'];
		$this->sql_connect();
		$stmt = $this->sql->prepare("UPDATE fastback SET flagged=1 WHERE file=:file");
		$stmt->bindValue(':file',$_GET['flag']);
		$stmt->execute();
		$this->sql_disconnect();
		header("Content-Type: application/json");
		header("Cache-Control: no-cache");
		print json_encode(array('file_flagged' => $_GET['flag']));
	}

	public function test() {
	}

	public function proxy() {
		$file = $_GET['proxy'];

		if ( strpos($file,$this->photobase) !== 0 ) {
			die("Only photos in photobase can be proxied");
		}

		if ( !file_exists($file) ) {
			print $file . "\n";
			die("File doesn't exist");
		}

		$mime = mime_content_type($file);
		$mime = explode('/',$mime);

		if ( $mime[1] == 'x-tga' ) {
			$mime[0] = 'video';
			$mime[1] = 'mpeg2';
		}

		if ( $mime[0] == 'image' ) {
			header("Content-Type: image/jpeg");
			$cmd = 'convert ' . escapeshellarg($file) . ' JPG:-';
			passthru($cmd);
		} else if ($mime[0] == 'video' ) {
			header("Content-Type: image/jpeg");
			$cmd = "ffmpeg -ss 00:00:00 -i " . escapeshellarg($file) . " -frames:v 1 -f singlejpeg - ";
			passthru($cmd);
		} else {
			die("Unsupported file type");
		}
	}

	public function get_geo() {
		$this->_fork_em('_get_geo', "SELECT COUNT(*) FROM fastback WHERE geom IS NULL AND nullgeom IS NOT TRUE");
	}

	public function get_times() {
		$this->_fork_em('_get_times', "SELECT COUNT(*) FROM fastback WHERE LENGTH(sorttime) = 10 AND flagged IS NOT TRUE");
	}

	public function _fork_em($childfunc,$statussql) {

		$this->sql_connect();

		# Cancel all reservations if we're starting fresh
		$this->sql->query("UPDATE fastback SET _util=NULL WHERE _util LIKE 'RESERVED%'");

		$this->sql_disconnect();

		// Make the children
		$children = array();
		for ($i = 0;$i < $this->nproc; $i++){
			switch($pid = pcntl_fork()){
				case -1:
					die("Forking failed");
					break;
				case 0:
					// This is a child
					$this->$childfunc($i);
					exit();
					break;
				default:
					$children[] = $pid;
					// This is the parent
			}
		}

		$this->sql_connect();
		$total = $this->sql->querySingle($statussql);
		$start = time();
		$this->sql_disconnect();

		// Reap the children
		while(count($children) > 0){
			foreach($children as $key => $child){
				$res = pcntl_waitpid($child, $status, WNOHANG);
				if($res == -1 || $res > 0) {
					unset($children[$key]);
				}
			}
			$this->sql_connect();
			$togo = $this->sql->querySingle($statussql);
			$this->sql_disconnect();

			if ( $total == 0 ) {
				$percent = 100;
			} else {
				$percent = intval(100*($total - $togo) / $total);
			}

			$processed = ($total - $togo);

			if ( $processed === 0 ) {
				$finish_string = "";
			} else {
				$seconds_left = intval((time() - $start)/$processed * $togo);
				$minutes_left = intval($seconds_left / 60);
				$seconds_left = $seconds_left % 60;
				$hours_left = intval($minutes_left / 60);
				$minutes_left = $minutes_left % 60;

				$finish_string = " ETA " . str_pad($hours_left,2,'0',STR_PAD_LEFT) . ':' . str_pad($minutes_left,2,'0',STR_PAD_LEFT) . ':' . str_pad($seconds_left,2,'0',STR_PAD_LEFT);
			}

			$this->print_status_line("$percent% : Processed $processed of $total records.$finish_string");

			sleep(1);
		}
	}

	public function _get_times($childno = "Unknown") {

		$tags_to_consider = array(
			"-DateTimeOriginal",
			"-CreateDate",
			"-CreationDate",
			"-DateCreated",
			"-TrackCreateDate",
			"-MediaCreateDate",
			"-GPSDateTime",
			"-ModifyDate",
			"-MediaModifyDate",
			"-TrackModifyDate",
			"-FileModifyDate",
		);

		$tags = implode(' ',$tags_to_consider);

		do { 
			$updated_timestamps = array();

			$this->sql_connect();
			$this->sql->query('UPDATE fastback SET _util="RESERVED-' . getmypid() . '" WHERE LENGTH(sorttime) = 10 AND _util IS NULL AND file != "" AND flagged IS NOT TRUE ORDER BY file DESC LIMIT ' . $this->process_limit);
			$res = $this->sql->query('SELECT * FROM fastback WHERE _util="RESERVED-' . getmypid() . '"');

			$queue = array();

			while($row = $res->fetchArray(SQLITE3_ASSOC)){
				$queue[] = $row['file'];
			}
			$this->sql_disconnect();

			if ( count($queue) === 0 ) {
				exit();
			}

			while($file = array_pop($queue)) {
				$found = false;
					// Run with -lang en since it translates some values based on locale :-/
					$cmd = "exiftool -lang en -s $tags -extractEmbedded " . escapeshellarg($this->photobase . $file) . " 2>/dev/null";
					$fullres = trim(`$cmd`);
					if ( !empty($fullres) ) {
					$fullres = explode("\n",$fullres);
						foreach($fullres as $res) {
						if ( !empty($res) ) {

							if ( strpos($res,'0000:00:00 00:00:00') !== FALSE ) {
								continue;
							}

							$found = true;
							preg_match('/(\d{4})\D(\d{2})\D(\d{2}) (\d{2})\D(\d{2})\D(\d{2})[^ ]*/',trim($res),$matches);

							// If we find an embedded timestamp, rebuild the path it's supposed to be at. 
							// If it matches, update the time with the exif time. 
							// If it doesn't match, update the sorttime with midnight.
							$matchpath = "/$matches[1]/$matches[2]/$matches[3]/" . basename($file);

							if ( strpos($file,$matchpath) !== FALSE ) {
								$updated_timestamps[$file] = '"' . $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6] . '"';
							} else {
								$updated_timestamps[$file] = 'sorttime || " 00:00:00"';
							}
							break;
						}
						}
					}

				if ( !$found ){
					$updated_timestamps[$file] = 'sorttime || " 00:00:01"';
				}
			}

			if ( count($updated_timestamps) > 0 ) {
				$this->sql_connect();
				$update_q = "UPDATE fastback SET _util=NULL, sorttime=CASE \n";
				foreach($updated_timestamps as $file => $time){
					$update_q .= " WHEN file='" . SQLite3::escapeString($file) . "' THEN " . $time . "\n";
				}
				$update_q .= " ELSE sorttime END
					WHERE _util='RESERVED-" . getmypid() . "'";

				$this->sql->query($update_q);
				$this->sql_disconnect();
			}

		} while (count($updated_timestamps) > 0);

		$this->sql_disconnect();
	}

	public function _get_geo($childno = "Unknown") {

		$tags_to_consider = array(
			"-GPSPosition",
			"-GPSCoordinates",

			"-GPSLatitudeRef",
			"-GPSLatitude",
			"-GPSLongitudeRef",
			"-GPSLongitude",

			"-GPSAltitude",
			"-GPSAltitudeRef",
			"-FileName",
		);

		$tags = implode(' ',$tags_to_consider);

		do { 
			$updated_geoms = array();
			$no_geoms = array();

			$this->sql_connect();
			$this->sql->query('UPDATE fastback SET _util="RESERVED-' . getmypid() . '" WHERE geom IS NULL AND _util IS NULL AND file != "" AND nullgeom IS NOT TRUE ORDER BY file DESC LIMIT ' . $this->process_limit);
			$res = $this->sql->query('SELECT * FROM fastback WHERE _util="RESERVED-' . getmypid() . '"');

			$queue = array();

			while($row = $res->fetchArray(SQLITE3_ASSOC)){
				$queue[] = $row['file'];
			}
			$this->sql_disconnect();

			if ( count($queue) === 0 ) {
				exit();
			}

			while($file = array_pop($queue)) {
				$found = false;
					// Run with -lang en since it translates some values based on locale :-/
					$cmd = "exiftool -lang en -s -c '%.6f' $tags -extractEmbedded " . escapeshellarg($this->photobase . $file) . " 2>/dev/null";
					$fullres = `$cmd`;

					$xyz = array();

					if ( !empty($fullres) ) {
						$fullres = explode("\n",trim($fullres));
						$exif_tags = array();
						foreach($fullres as $line){
							$vals = preg_split('/ .*: /',$line,2);
							$exif_tags[$vals[0]] = $vals[1];
						}

						if ( array_key_exists('GPSPosition',$exif_tags) ) {
							// eg "38.741200 N, 90.642800 W"
							if ( preg_match('/([0-9.]+) (N|S), ([0-9.]+) (E|W)/',$exif_tags['GPSPosition'],$matches) ) {

								if ( count($matches) == 5) {

									if ( $matches[2] == 'S' ) {
										$matches[1] = $matches[1] * -1;
									}

									if ( $matches[4] == 'W' ) {
										$matches[3] = $matches[3] * -1;
									}

									$xyz[0] = $matches[1];
									$xyz[1] = $matches[3];
								}
							}
						}

						if ( count($xyz) === 0 && array_key_exists('GPSCoordinates',$exif_tags) ) {
							// eg "38.741200 N, 90.642800 W"
							if ( preg_match('/([0-9.]+) (N|S), ([0-9.]+) (E|W)/',$exif_tags['GPSCoordinates'],$matches) ) {

								if ( count($matches) == 5) {

									if ( $matches[2] == 'S' ) {
										$matches[1] = $matches[1] * -1;
									}

									if ( $matches[4] == 'W' ) {
										$matches[3] = $matches[3] * -1;
									}

									$xyz[0] = $matches[3];
									$xyz[1] = $matches[1];
								}
							}
						}

						if ( count($xyz) === 0 && 
							array_key_exists('GPSLatitudeRef',$exif_tags) && 
							array_key_exists('GPSLatitude',$exif_tags) && 
							array_key_exists('GPSLongitude',$exif_tags) && 
							array_key_exists('GPSLongitudeRef',$exif_tags) &&
							floatval($exif_tags['GPSLongitude']) == $exif_tags['GPSLongitude'] && 
							floatval($exif_tags['GPSLatitude']) == $exif_tags['GPSLatitude'] 
						) {

							if ( $exif_tags['GPSLatitudeRef'] == 'South' ) {
								$exif_tags['GPSLongitude'] = $exif_tags['GPSLongitude'] * -1;
							}

							if ( $exif_tags['GPSLongitudeRef'] == 'West' ) {
								$exif_tags['GPSLongitude'] = $exif_tags['GPSLongitude'] * -1;
							}

							$xyz[0] = $exif_tags['GPSLongitude'];
							$xyz[1] = $exif_tags['GPSLatitude'];
						}

						if ( count($xyz) === 2 && array_key_exists('GPSAltitude',$exif_tags) && floatval($exif_tags['GPSAltitude']) == $exif_tags['GPSAltitude']) {
							if ( preg_match('/([0-9.]+) m/',$exif_tags['GPSAltitude'],$matches ) ) {
								if ( array_key_exists('GPSAltitudeRef',$exif_tags) && $exif_tags['GPSAltitudeRef'] == 'Below Sea Level' ) {
									$xyz[2] = $matches[1] * -1;
								} else {
									$xyz[2] = $matches[1];
								}
							} else if ( $exif_tags['GPSAltitude'] == 'undef') {
								$xyz[2] = 0;
							} else {
								error_log(
									"New type of altitude value found: {$exif_tags['GPSAltitude']}"
								);
								print($file . "\n");
								var_dump($exif_tags);
								$xyz[2] = 0;
							}
						} else {
							$xyz[2] = 0;
						}
					}

					if ( count($xyz) === 3 ) {
						$updated_geoms[$file] = "MakePointZ($xyz[0],$xyz[1],$xyz[2], 4326)";
					} else {
						$no_geoms[] = $file;
					}
			}

			if ( count($updated_geoms) > 0 ) {
				$this->sql_connect();
				$update_q = "UPDATE fastback SET _util=NULL, geom=CASE \n";
				foreach($updated_geoms as $file => $geom){
					$update_q .= " WHEN file='" . SQLite3::escapeString($file) . "' THEN " . $geom. "\n";
				}
				$update_q .= " ELSE geom END
					WHERE _util='RESERVED-" . getmypid() . "'";

				$this->sql->query($update_q);
				$this->sql_disconnect();
			}

			if ( count($no_geoms) > 0 ) {
				$this->sql_connect();
				$update_q = "UPDATE fastback SET _util=NULL, nullgeom=1 WHERE file in (";

				$escaped = array();
				foreach($no_geoms as $file){
					$escaped[] = '"' . SQLite3::escapeString($file) . '"';
				}

				$update_q .= implode(",",$escaped);

				$update_q .= ")";

				$this->sql->query($update_q);
				$this->sql_disconnect();
			}

		} while (count($updated_geoms) > 0 || count($no_geoms) > 0);

		$this->sql_disconnect();
	}

	public function print_cli_usage() {
		print "index.php [command]

	Commands:
		* default- Brings the database from whatever state it is in, up to date. This is the command you usually want to run.
			- loadcache
			- makethumbs
			- gettime
			- geogeo
			- makejson
			- makegeojson
		* dbtest - Just tests if the database exists or can be created, and if it can be written to.
		* resetcache – Truncate the database. It will need to be repopulated. Does not touch files.
		* loadcache – Finds all new files in the library and make cache entries for them. Does not generate new thumbnails.
		* makethumbs – Generates thumbnails for all entries found in the cache.
		* gettime – Checks all files for timestamps, updates the database with the found times. Uses the exif data in the following order:
			- DateTimeOriginal
			- CreateDate
			- CreationDate
			- DateCreated
			- TrackCreateDate
			- MediaCreateDate
			- GPSDateTime
			- ModifyDate
			- MediaModifyDate
			- TrackModifyDate
			- FileModifyDate
		* getgeo – Checks the files for geolocation info, updates the database with the found locations. Uses the exif data in the following order:
			- GPSPosition
			- GPSCoordinates
			- These four values together
				+ GPSLatitudeRef
				+ GPSLatitude
				+ GPSLongitudeRef
				+ GPSLongitude

			  Additionally, if the altitude tag is present, the altitude is recorded. Otherwise it is set to 0
				+ GPSAltitude
		* makejson - Regenerates the cached .json file based on the cache database. Doesn't touch or look at files.
		* makegeojson - Regenerates the cached .geojson file based on the cache database. Doesn't touch or look at files.
		* fullreset - Runs `resetcache` first and then runs handlenew
		* fakethumbs – Tells the cache that all thumbs have been generated. Does not check if files exist, does not create them.
";
	}

	public function print_status_line($msg,$skip_spinner = false) {
		$spinners = array('\\','|','/','-');

		print("\e[1B"); // Go down a line
		print("\e[1000D"); // Go to start of line
		

		if ( !$skip_spinner ) {
			print($spinners[$this->spindex]); 
			$this->spindex++;
			if ($this->spindex == count($spinners)){
				$this->spindex = 0;
			}
		}

		print(" " . str_pad($msg,100));
		print("\e[1A"); // Go up a line
		print("\e[1000D"); // Go to start of line
	}

	// Calculate md5 of just the picture part
	// exiftool FILE -all= -o - | md5
}

new fastback();
