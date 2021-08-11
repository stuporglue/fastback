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
	var $process_limit = 1000;

	// Max number of SQL statements to do per upsert
	var $upsert_limit = 10000;

	// Max number of child processes
	var $cores = 5;

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


	/**
	 * Kick it off
	 */
	function __construct(){

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

			if ( isset($argv) && count($argv) > 1 ) {

				if ( $argv[1] == 'reset' ) {
					$this->reset_db_cache();
				} else if ( $argv[1] == 'gettimes' ) {
					$this->get_times();
				}

			} else {
				$this->load_db_cache();
				$this->make_thumbnails();

				// also regenerate the json
				ob_start();
				@unlink($this->filecache . '/fastback.json.gz');
				$this->sendjson();
				ob_end_clean();
			}

		} else {
			$this->makeoutput();
		}
	}


	/**
	 * Initialize the database
	 */
	public function setup_db() {

		// TODO: Drop these creates in an exception handler
		$q_create_meta = "Create TABLE IF NOT EXISTS fastbackmeta ( key VARCHAR(20) PRIMARY KEY, value VARCHAR(255))";

		$res = $this->sql->query($q_create_meta);
		//var_dump($res);

		$q_create_files = "CREATE TABLE IF NOT EXISTS fastback ( file TEXT PRIMARY KEY, isvideo BOOL, flagged BOOL, mtime INTEGER, sorttime DATETIME, thumbnail TEXT, _util TEXT)";

		$res = $this->sql->query($q_create_files);
		//var_dump($res);

		$res = $this->sql->query($q_create_files);
	}

	/**
	 * Reset the cache
	 */
	public function reset_db_cache() {
		$this->sql_connect();

		if ( count($argv) > 1 && $argv[1] == 'reset' ) {
			$this->sql->query("DELETE FROM fastback");
			$this->sql->query("UPDATE fastbackmeta SET lastmod='19000101'");
		}

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
		print "Searching for files in " . getcwd() . "\n";
		$filetypes = implode('\|',array_merge($this->supported_photo_types, $this->supported_video_types));
		$cmd = 'find . -type f -regextype sed -iregex  "./[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/.*\(' . $filetypes . '\)$" -newerat ' . $lastmod;
		echo $cmd . "\n";
		$modified_files_str = `$cmd`;
		//print "$cmd\n";
		$modified_files = explode("\n",$modified_files_str);
		$modified_files = array_filter($modified_files);

		print "Building cache for " . count($modified_files) . " files modified since $lastmod\n";
		flush();

		$today = date('Ymd');
		$multi_insert = "INSERT INTO fastback (file,mtime,sorttime,isvideo) VALUES ";
		$multi_insert_tail = " ON CONFLICT(file) DO UPDATE SET isvideo=";
		$collect_photo = array();
		$collect_video = array();
		$togo = count($modified_files);
		foreach($modified_files as $k => $one_file){
			$mtime = filemtime($one_file);
			$pathinfo = pathinfo($one_file);

			if ( empty($pathinfo['extension']) ) {
				print_r($pathinfo);
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
				print "Upserted {$this->upsert_limit}, $togo left to go\n";
			}

			if ( count($collect_video) >= $this->upsert_limit) {
				$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
				$this->sql->query($sql);
				$collect_video = array();
				$togo -= $this->upsert_limit;
				print "Upserted {$this->upsert_limit}, $togo left to go\n";
			}
		}

		if ( count($collect_photo) > 0 ) {
			$sql = $multi_insert . implode(",",$collect_photo) . $multi_insert_tail . '0';
			$this->sql->query($sql);
			$togo -= count($collect_photo);
			$collect_photo = array();
			print "Upserted some, $togo left to go\n";
		}

		if ( count($collect_video) > 0 ) {
			$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
			$this->sql->query($sql);
			$togo -= count($collect_video);
			$collect_video = array();
			print "Upserted some, $togo left to go\n";
		}

		$this->sql->query("INSERT INTO fastbackmeta (key,value) values ('lastmod',".date('Ymd').") ON CONFLICT(key) DO UPDATE SET value=".date('Ymd'));
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
		for ($i = 0;$i < $this->cores; $i++){
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

		// Reap the children
		while(count($children) > 0){
			foreach($children as $key => $child){
				$res = pcntl_waitpid($child, $status, WNOHANG);
				if($res == -1 || $res > 0) {
					unset($children[$key]);
				}
			}
			$this->sql_connect();
			$res = $this->sql->querySingle("SELECT COUNT(*) FROM fastback WHERE thumbnail IS NULL AND flagged IS NOT TRUE",);
			print "PARENT: $res more to go\n";
			$this->sql_disconnect();
			sleep(1);
		}
	}

	private function _make_thumbnails($childno = "Unknown") {
		echo "Child $childno pid is " . getmypid() . "\n";

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

			echo "\nChild $childno (" . getmypid() . ") reserved " . count($queue) . " images\n";

			if ( count($queue) === 0 ) {
				print "Child $childno exiting\n";
				exit();
			}

			$made_thumbs = array();
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
						echo "\tChild $childno -- $cmd\n";
						$res = `$cmd`;
					} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {

						$tmpthumb = $this->filecache . 'tmpthumb_' . getmypid() . '.jpg';
						$tmpshellthumb = escapeshellarg($tmpthumb);

						$cmd = "ffmpeg -y -ss 10 -i $shellfile -vframes 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
						echo "\tChild $childno -- $cmd\n";
						$res = `$cmd`;

						if ( !file_exists($tmpthumb)) {
							$cmd = "ffmpeg -y -ss 2 -i $shellfile -vframes 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
							echo "\tChild $childno -- $cmd\n";
							$res = `$cmd`;
						}

						if ( !file_exists($tmpthumb)) {
							$cmd = "ffmpeg -y -ss 00:00:00 -i $shellfile -frames:v 1 $tmpshellthumb 2>&1 > /tmp/fastback.ffmpeg.log.$childno";
							echo "\tChild $childno -- $cmd\n";
							$res = `$cmd`;
						}

						if ( file_exists($tmpthumb) ) {
							$cmd = "vipsthumbnail --size=120x120 --output=$shellthumb --smartcrop=attention $tmpshellthumb";
							echo "\tChild $childno -- $cmd\n";
							$res = `$cmd`;
							unlink($tmpthumb);
						}

					} else {
						print "What do I do with ";
						print_r($pathinfo);
					}

					if ( file_exists( $thumbnailfile ) ) {
						$cmd = "jpegoptim --strip-all --strip-exif --strip-iptc $shellthumb";
						echo "\tChild $childno -- $cmd\n";
						$res = `$cmd`;
					} 
				}

				// If we've got the file, we're good
				if ( file_exists($thumbnailfile) ) {
					$made_thumbs[$file] = $thumbnailfile;
				}
			}
			print "Done with while loop\n";

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

		} while (count($made_thumbs) > 0);

		@unlink($this->filecache . '/fastback.json');
	}

	private function sql_connect($try_no = 1){
		if (php_sapi_name() === 'cli') {
			$this->db_lock = fopen($this->filecache . '/fastback.lock','w');
			if( flock($this->db_lock,LOCK_EX)){
				$this->sql = new SQLite3($this->filecache . '/fastback.sqlite');
			} else {
				throw new Exception("Couldn't lock db");
			}

			$this->setup_db();
		} else {
			$this->sql = new SQLite3($this->filecache .'/fastback.sqlite');
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

		$res = $this->sql->query("SELECT file,DATETIME(sorttime) AS sorttime,isvideo FROM fastback WHERE thumbnail IS NOT NULL AND thumbnail NOT LIKE 'RESERVE%' AND flagged IS NOT TRUE ORDER BY sorttime " . $this->sortorder . ",file");
		$last_date = NULL;
		$last_year = NULL;
		$idx = 0;
		while($row = $res->fetchArray(SQLITE3_ASSOC)){

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
		}

		$this->sql_disconnect();

		$str = json_encode($json,JSON_PRETTY_PRINT);
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
		//var_dump($res);
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

	public function get_times() {

		$this->sql_connect();

		$this->sql->query("UPDATE fastback SET _util=NULL WHERE _util LIKE 'RESERVED%'");

		$this->sql_disconnect();

		// Make the children
		$children = array();
		for ($i = 0;$i < $this->cores; $i++){
			switch($pid = pcntl_fork()){
				case -1:
					die("Forking failed");
					break;
				case 0:
					// This is a child
					$this->_get_times($i);
					exit();
					break;
				default:
					print "I made a kid $pid\n";
					$children[] = $pid;
					// This is the parent
			}
		}

		// Reap the children
		while(count($children) > 0){
			foreach($children as $key => $child){
				$res = pcntl_waitpid($child, $status, WNOHANG);
				if($res == -1 || $res > 0) {
					unset($children[$key]);
				}
			}
			$this->sql_connect();
			$res = $this->sql->querySingle("SELECT COUNT(*) FROM fastback WHERE LENGTH(sorttime) = 10 AND flagged IS NOT TRUE",);
			print "PARENT: $res more to go\n";
			$this->sql_disconnect();
			sleep(1);
		}
	}

	public function _get_times($childno = "Unknown") {
		echo "Child $childno pid is " . getmypid() . "\n";

$tags_to_consider = array(
							"DateTimeOriginal",
							"CreateDate",
							"CreationDate",
							"DateCreated",
							"TrackCreateDate",
							"MediaCreateDate",
							"GPSDateTime",
							"ModifyDate",
							"MediaModifyDate",
							"TrackModifyDate",
						);



		do { 
			$updated_timestamps = array();

			$this->sql_connect();
			$this->sql->query('UPDATE fastback SET _util="RESERVED-' . getmypid() . '" WHERE LENGTH(sorttime) = 10 AND _util IS NULL AND file != "" AND flagged IS NOT TRUE  LIMIT ' . $this->process_limit);
			$res = $this->sql->query('SELECT * FROM fastback WHERE _util="RESERVED-' . getmypid() . '"');

			$queue = array();

			while($row = $res->fetchArray(SQLITE3_ASSOC)){
				$queue[] = $row['file'];
			}
			$this->sql_disconnect();

			echo "\nChild $childno (" . getmypid() . ") reserved " . count($queue) . " images\n";

			if ( count($queue) === 0 ) {
				print "Child $childno exiting\n";
				exit();
			}

			$updates = array();

			while($file = array_pop($queue)) {
				$found = false;
				foreach($tags_to_consider as $maybetag){
					$cmd = "exiftool -$maybetag -extracEmbedded " . escapeshellarg($this->photobase . $file) . " 2>/dev/null | head -1";
					// echo "\tChild $childno -- $cmd\n";
					$res = `$cmd`;
					if ( !is_null($res) ) {
						$found = true;
						print "\tChild $childno Found timestamp using $maybetag in $file\n";
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

				if ( !$found ){
					print "\tChild $childno Found no timestamp using any tag in $file\n";
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
				print "Child $childno just checked in " . count($updated_timestamps) . " new timestamps\n";
			}

		} while (count($updated_timestamps) > 0);

		$this->sql_disconnect();
	}
}

new fastback();
