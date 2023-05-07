<?php

declare(ticks = 1);

class FastbackOutput {

	var $sitetitle = "Fastback Photo Gallery";

	// Folder path to cache directory. sqlite and thumbnails will be stored here
	// Optional, will create a cache folder in the currend directory as the default
	var $filecache;

	// Path to .sqlite file 
	// Optional, will use $filecache/fastback.sqlite 
	var $sqlitefile;

	// URL path to cache directory. 
	// Optional, will use current web path + cache as default
	var $cacheurl;

	// File path to full sized photos
	// Optional, will use current directory as default
	var $photobase;

	// URL path to full sized photos
	// Optional, will use current web path as default
	var $photourl;

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
		'webp',
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
		'vob',
		'webm',
	);

	var $meta =array();

	var $sql;

	var $sortorder = 'DESC';

	var $spindex = 0;

	function __construct(){

		$this->filecache = __DIR__ . '/cache/';
		$this->cacheurl = dirname($_SERVER['SCRIPT_NAME']) . '/cache/';
		$this->photobase = __DIR__ . '/';
		$this->photourl = dirname($_SERVER['SCRIPT_NAME']) . '/';
		$this->sitetitle = "Fastback Photo Gallery";
		$this->sortorder = ($this->sortorder == 'ASC' ? 'ASC' : 'DESC');
		$this->filestructure = 'datebased'; // Or all
		$this->nproc = `nproc`;

		if ( file_exists(__DIR__ . '/fastback.ini') ) {
			$settings = parse_ini_file(__DIR__ . '/fastback.ini');
			foreach($settings as $k => $v) {
				$this->$k = $v;
			}
		}

		if ( !is_dir($this->filecache) ) {
			$this->log("Fastback cache directory {$this->filecache} doesn't exist");
			die("Fastback setup error. See error log.");
		}

		// Ensure single trailing slashes
		$this->filecache = rtrim($this->filecache,'/') . '/';
		$this->cacheurl = rtrim($this->cacheurl,'/') . '/';
		$this->photobase = rtrim($this->photobase,'/') . '/';
		$this->photourl = rtrim($this->photourl,'/') . '/';

		if ( !isset($this->sqlitefile) ){
			$this->sqlitefile = $this->filecache . 'fastback.sqlite';
		}

		if (php_sapi_name() === 'cli') {
			$this->handle_cli();
		} else if (!empty($_GET['proxy'])) {
			$this->proxy();
		} else if (!empty($_GET['flag'])) {
			$this->flag_photo();
		} else {
			$this->make_html();
		}
	}

	function make_html() {

		$html = '<!doctype html>
			<html lang="en">
			<head>
			<meta charset="utf-8">
			<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
			<title>' . htmlspecialchars($this->sitetitle) . '</title>
			<link rel="shortcut icon" href="fastback_assets/favicon.png"> 
			<link rel="apple-touch-icon" href="fastback_assets/favicon.png">
			<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
			<link rel="stylesheet" href="fastback_assets/jquery-ui.min.css">
			<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" integrity="sha512-xodZBNTC5n17Xt2atTPuE1HxjVMSvLVW9ocqUKLsCC5CXdbqCmblAshOMAS6/keqq/sMZMZ19scR4PsZChSR7A==" crossorigin="" />
			<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.0.3/dist/MarkerCluster.Default.css"/>
			<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css"/>
			<link rel="stylesheet" href="fastback_assets/fastback.css">
			</head>';

		$html .= '<body class="photos">';
		$html .= '<div id="map"></div>';
		$html .= '<div id="hyperlist_wrap">';
		$html .= '<div id="photos"></div>';
		$html .= '</div>';
		$html .= '<input id="speedslide" type="range" orient="vertical" min="0" max="100" value="0"/>';
		$html .= '<div id="resizer">';
			$html .= '<input type="range" min="1" max="10" value="5" class="slider" id="zoom">';
			$html .= '<div id="globeicon"></div>';
			$html .= '<div id="rewindicon"></div>';
			$html .= '<div id="calendaricon"><input readonly id="datepicker" type="text"></div>';
		$html .= '</div>';
		$html .= '<div id="thumb">
					<div id="thumbcontent"></div>
					<div id="thumbleft" class="thumbctrl">LEFT</div>
					<div id="thumbright" class="thumbctrl">RIGHT</div>
					<div id="thumbcontrols">
						<div id="thumbclose">🆇</div>
						<div id="thumbdownload"></div>
						<div id="thumbflag"></div>
						<div id="thumbinfo"></div>
						<div id="socialbuttons">
							<!-- Sharingbutton Facebook -->
							<a id="share_fb" class="resp-sharing-button__link" href="about:blank" target="_blank" rel="noopener" aria-label="">
							<div class="resp-sharing-button resp-sharing-button--facebook resp-sharing-button--small"><div aria-hidden="true" class="resp-sharing-button__icon resp-sharing-button__icon--solid">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18.77 7.46H14.5v-1.9c0-.9.6-1.1 1-1.1h3V.5h-4.33C10.24.5 9.5 3.44 9.5 5.32v2.15h-3v4h3v12h5v-12h3.85l.42-4z"/></svg>
								</div>
							</div>
							</a>

							<!-- Sharingbutton E-Mail -->
							<a id="share_email" class="resp-sharing-button__link" href="about:blank" target="_blank" rel="noopener" aria-label="">
							<div class="resp-sharing-button resp-sharing-button--email resp-sharing-button--small"><div aria-hidden="true" class="resp-sharing-button__icon resp-sharing-button__icon--solid">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M22 4H2C.9 4 0 4.9 0 6v12c0 1.1.9 2 2 2h20c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zM7.25 14.43l-3.5 2c-.08.05-.17.07-.25.07-.17 0-.34-.1-.43-.25-.14-.24-.06-.55.18-.68l3.5-2c.24-.14.55-.06.68.18.14.24.06.55-.18.68zm4.75.07c-.1 0-.2-.03-.27-.08l-8.5-5.5c-.23-.15-.3-.46-.15-.7.15-.22.46-.3.7-.14L12 13.4l8.23-5.32c.23-.15.54-.08.7.15.14.23.07.54-.16.7l-8.5 5.5c-.08.04-.17.07-.27.07zm8.93 1.75c-.1.16-.26.25-.43.25-.08 0-.17-.02-.25-.07l-3.5-2c-.24-.13-.32-.44-.18-.68s.44-.32.68-.18l3.5 2c.24.13.32.44.18.68z"/></svg>
								</div>
							</div>
							</a>

							<!-- Sharingbutton WhatsApp -->
							<a id="share_whatsapp" class="resp-sharing-button__link" href="about:blank" target="_blank" rel="noopener" aria-label="">
							<div class="resp-sharing-button resp-sharing-button--whatsapp resp-sharing-button--small"><div aria-hidden="true" class="resp-sharing-button__icon resp-sharing-button__icon--solid">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20.1 3.9C17.9 1.7 15 .5 12 .5 5.8.5.7 5.6.7 11.9c0 2 .5 3.9 1.5 5.6L.6 23.4l6-1.6c1.6.9 3.5 1.3 5.4 1.3 6.3 0 11.4-5.1 11.4-11.4-.1-2.8-1.2-5.7-3.3-7.8zM12 21.4c-1.7 0-3.3-.5-4.8-1.3l-.4-.2-3.5 1 1-3.4L4 17c-1-1.5-1.4-3.2-1.4-5.1 0-5.2 4.2-9.4 9.4-9.4 2.5 0 4.9 1 6.7 2.8 1.8 1.8 2.8 4.2 2.8 6.7-.1 5.2-4.3 9.4-9.5 9.4zm5.1-7.1c-.3-.1-1.7-.9-1.9-1-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.1-.2.2-.3.2-.6.1s-1.2-.5-2.3-1.4c-.9-.8-1.4-1.7-1.6-2-.2-.3 0-.5.1-.6s.3-.3.4-.5c.2-.1.3-.3.4-.5.1-.2 0-.4 0-.5C10 9 9.3 7.6 9 7c-.1-.4-.4-.3-.5-.3h-.6s-.4.1-.7.3c-.3.3-1 1-1 2.4s1 2.8 1.1 3c.1.2 2 3.1 4.9 4.3.7.3 1.2.5 1.6.6.7.2 1.3.2 1.8.1.6-.1 1.7-.7 1.9-1.3.2-.7.2-1.2.2-1.3-.1-.3-.3-.4-.6-.5z"/></svg>
								</div>
							</div>
							</a>
						</div>
					</div>';
		$html .= '</div>';
		$html .= '<script src="fastback_assets/jquery.min.js"></script>';
		$html .= '<script src="fastback_assets/jquery-ui.min.js"></script>';
		$html .= '<script src="fastback_assets/hyperlist.js"></script>';
		$html .= '<script src="fastback_assets/hammer.js"></script>';
		$html .= '<script src="fastback_assets/papaparse.min.js"></script>';
		$html .= '<script src="fastback_assets/jquery.hammer.js"></script>';
		$html .= '<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js" integrity="sha512-XQoYMqMTK8LvdxXYG3nZ448hOEQiglfqkJs1NOQV44cWnUrBc8PkAOcXy20w0vlaXaVUearIOBhiXZ5V3ynxwA==" crossorigin=""></script>';
		$html .= '<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>';
		$html .= '<script src="fastback_assets/fastback.js"></script>';
		$html .= '<script>
			var FastbackBase = "' . $_SERVER['SCRIPT_NAME'] . '";
		var FastbackBase = "' . $_SERVER['SCRIPT_NAME'] . '";
		var fastback = new Fastback({
			cacheurl:    "' . $this->cacheurl . '",
			photourl:    "' . $this->photourl .'",
			fastbackurl: "' . $_SERVER['SCRIPT_NAME'] . '"
	});
			</script>';
		$html .= '</body></html>';

		print $html;
	}

	public function proxy() {
		$file = $_GET['proxy'];

		if ( strpos($file,'/../') !== 0 ) {
			$this->log("Someone tried to use /../ ($file) to get outside of the allowed directories");
		}

		if ( strpos($file,$this->photobase) !== 0 ) {
			$this->log("Someone tried to access $file, a photo outside of the photobase");
			die("");
		}

		if ( !file_exists($file) ) {
			$this->log("Someone tried to access $file, which doesn't exist");
			die("");
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

	private function sql_connect($try_no = 1){

		if ( !file_exists($this->sqlitefile) ) {
			$this->sql = new SQLite3($this->sqlitefile);
			$this->setup_db();
			$this->sql->close();
		}

		if (php_sapi_name() === 'cli') {
			$this->db_lock = fopen($this->sqlitefile . '.lock','w');
			if( flock($this->db_lock,LOCK_EX)){
				$this->sql = new SQLite3($this->sqlitefile);
			} else {
				throw new Exception("Couldn't lock db");
			}
		} else {
			$this->sql = new SQLite3($this->sqlitefile);
		}

		if (empty($this->meta)){
			$this->load_meta();
		}
	}

	/**
	 * Initialize the database
	 */
	public function setup_db() {
		$q_create_meta = "Create TABLE IF NOT EXISTS fastbackmeta ( key VARCHAR(20) PRIMARY KEY, value VARCHAR(255))";
		$res = $this->sql->query($q_create_meta);

		$q_create_files = "CREATE TABLE IF NOT EXISTS fastback ( 
			file TEXT PRIMARY KEY, 
			exif TEXT,
			isvideo BOOL, 
			flagged BOOL, 
			mtime INTEGER, 
			sorttime DATETIME, 
			thumbnail TEXT, 
			lat DECIMAL(15,10),
			lon DECIMAL(15,10), 
			elev DECIMAL(15,10), 
			nullgeom BOOL,
			_util TEXT
		)";

		$res = $this->sql->query($q_create_files);
	}

	private function sql_disconnect(){
		$this->sql->close();
		if (!empty($this->db_lock) ) {
			flock($this->db_lock,LOCK_UN);
			fclose($this->db_lock);
		}
		unset($this->sql);
	}

	private function load_meta() {
		$q_getallmeta = "SELECT key,value FROM fastbackmeta";
		$res = $this->sql->query($q_getallmeta);
		$this->meta = array();
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$this->meta[$row['key']] = $row['value'];
		}
	}

	private function signal_handler() {
		$this->end_all_children = true;
		if ( !empty($this->children) ) {
			$this->log("Got an exit command. Telling children and then exiting");
		} else {
			$this->log("No children. Exiting directly.");
			exit();
		}
	}


	private function handle_cli(){
			global $argv,$argc;

			pcntl_signal(SIGINT, array(&$this,'signal_handler'));
			pcntl_signal(SIGTERM, array(&$this,'signal_handler'));
			$this->end_all_children = false;
			$this->children = array();

			if ( !isset($argv) || count($argv) == 1 ) {
				$argv[1] = 'handle_new';
			} else {
				if ( $argv[1] == 'debug' ) {
					array_splice($argv,1,0,'handle_new');
				}

				if ( isset($argv[2]) && $argv[2] == 'debug' ) {
					$this->debug = true;
					$this->log("Debug enabled");
				}
			} 

			$this->log("Using $this->nproc processes for forks");
			$this->log("Using sqlite database " . $this->sqlitefile);

			$tasks = array();
			switch($argv[1]) {
			case 'db_test':
				$tasks = array('db_test');
			case 'reset_cache':
				$tasks = array('reset_cache');
				break;
			case 'reset_db':
				$tasks = array('reset_db');
				break;
			case 'load_cache':
				$tasks = array('load_cache');
				break;
			case 'clear_thumbs_db':
				$tasks = array('clear_thumbs_db');
				break;
			case 'make_thumbs':
				$tasks = array('make_thumbs');
				break;
			case 'get_exif':
				$tasks = array('get_exif');
				break;
			case 'clear_exif':
				$tasks = array('clear_exif');
				break;
			case 'get_time':
				$tasks = array('get_times');
				break;
			case 'get_geo':
				$tasks = array('get_geo');
				break;
			case 'make_csv':
				$tasks = array('make_csv');
				break;
			case 'full_reset':
				$tasks = array('reset_db','reset_cache','load_cache','make_thumbs','get_exif','get_times','get_geo','make_csv');
				break;
			case 'help':
				$tasks = array('help');
				break;
			case 'handle_new':
				$tasks = array('load_cache','make_thumbs','get_exif','get_times','get_geo','make_csv');
				break;
			default:
				$tasks = array('help');
			}

			pcntl_signal(SIGINT, function(){
				$this->print_if_no_debug("\e[?25h"); // Show the cursor
				exit();
			});


			$this->print_if_no_debug("\e[?25l"); // Hide the cursor

			print("You're using fastback photo gallery\n");
			print("For help,run \"php " . basename(__FILE__) . " help\" on the command line");

			print("\n");
			print("\n");
			print("\n");
			$this->print_if_no_debug("\e[1A"); // Go up a line
			$this->print_if_no_debug("\e[1000D"); // Go to start of line
			for($i = 0; $i < count($tasks); $i++){
				print(" " . str_pad("Working on task " . ($i + 1) . " of " . count($tasks) . " ({$tasks[$i]})",100));
				if ($this->debug ) { print("\n"); }
				$this->print_if_no_debug("\e[1000D"); // Go to start of line
				//
				$this->{$tasks[$i]}(); // Every task should leave the cursor where it found it

				$this->print_status_line("",true); // Wipe the intermediate progress line
				print("*" . str_pad("Completed task " . ($i + 1) . " of " . count($tasks) . " ({$tasks[$i]})",100));
				print("\n\n");
				$this->print_if_no_debug("\e[1000D"); // Go to start of line
			}

			$this->print_if_no_debug("\e[?25l"); // Show cursor


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
	public function reset_cache() {
		global $argv;
		$this->sql_connect();
		$this->sql->query('UPDATE fastbackmeta SET value="19000101" WHERE key="lastmod"');
		$this->sql_disconnect();
	}

	public function clear_thumbs_db() {
		global $argv;
		$this->sql_connect();
		$this->sql->query('UPDATE fastback SET thumbnail=NULL, flagged=NULL');
		$this->sql_disconnect();
	}

	/**
	 * Reset database
	 */
	public function reset_db() {
		global $argv;
		$this->sql_connect();
		$this->sql->query("DELETE FROM fastback");
		$this->sql_disconnect();
	}

	/**
	 * Get all modified files into the db cache
	 */
	public function load_cache() {
		global $argv;

		if ( !file_exists($this->filecache) ) {
			mkdir($this->filecache,0700,TRUE);
		}

		$this->sql_connect();

		$lastmod = '19000101';
		if ( !empty($this->meta['lastmod']) ){
			$lastmod = $this->meta['lastmod'];
		}

		$this->log("Changing to " . $this->photobase);
		chdir($this->photobase);
		$filetypes = implode('\|',array_merge($this->supported_photo_types, $this->supported_video_types));
		if ( $this->filestructure === 'datebased' ) {
			$cmd = 'find . -type f -regextype sed -iregex  "./[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/.*\(' . $filetypes . '\)$" -newerat ' . $lastmod;
		} else if ( $this->filestructure === 'all' ) {
			$cmd = 'find . -type f -regextype sed -iregex  ".*\(' . $filetypes . '\)$" -newerat ' . $lastmod;
		} else {
			die("I don't know what kind of file structure to look for");
		}

		$modified_files_str = `$cmd`;

		if (  is_null($modified_files_str) || strlen(trim($modified_files_str)) === 0) {
			return;
		}

		$modified_files = explode("\n",$modified_files_str);
		$modified_files = array_filter($modified_files);

		$today = date('Ymd');
		$multi_insert = "INSERT INTO fastback (file,mtime,isvideo) VALUES ";
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
				$this->log(print_r($pathinfo,TRUE));
				var_dump($one_file);
				die("No file extension. Weird.");
				continue;
			}

			if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {
				$collect_video[] = "('" .  SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($mtime) .  "',1)";
			} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_photo_types) ) {
				$collect_photo[] = "('" .  SQLite3::escapeString($one_file) . "','" .  SQLite3::escapeString($mtime) .  "',0)";
			} else {
				$this->log("Don't know what to do with " . print_r($pathinfo,true));
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

	private function _make_thumbs($childno = "Unknown") {
		do {

			$queue = $this->get_queue("flagged IS NOT TRUE AND thumbnail IS NULL AND file != ''");

			$made_thumbs = array();
			$flag_these = array();

			chdir($this->photobase);

			foreach($queue as $file => $row) {

				$thumbnailfile = $this->filecache . '/' . ltrim($file,'./') . '.webp';

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

						$tmpthumb = $this->filecache . 'tmpthumb_' . getmypid() . '.webp';
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

						clearstatcache();
						if ( file_exists($tmpthumb) && filesize($tmpthumb) !== 0) {
							$cmd = "vipsthumbnail --size=120x120 --output=$shellthumb --smartcrop=attention $tmpshellthumb";
							$res = `$cmd`;
							unlink($tmpthumb);
						}

					} else {
						$this->log("What do I do with ");
						$this->log(print_r($pathinfo,TRUE));
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

			$this->update_case_when("UPDATE fastback SET _util=NULL, thumbnail=CASE", $made_thumbs, "ELSE thumbnail END", TRUE);

			$this->update_files_in("UPDATE fastback SET flagged=1 WHERE file IN (",$flag_these);

		} while (count($made_thumbs) > 0);
	}

/**
	 * Make the csv cache file
	 */
	public function make_csv(){
		$this->sql_connect();
		$q = "SELECT 
			file,
			isvideo,
			DATETIME(sorttime) AS sorttime,
			lon,
			lat,
			elev
			FROM fastback 
			WHERE 
			thumbnail IS NOT NULL 
			AND thumbnail NOT LIKE 'RESERVE%' 
			AND flagged IS NOT TRUE 
			AND sorttime NOT LIKE '% 00:00:01' 
			AND DATETIME(sorttime) IS NOT NULL 
			ORDER BY sorttime " . $this->sortorder . ",file";
		$res = $this->sql->query($q);

		$fh = fopen($this->filecache . '/fastback.csv','w');
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			fputcsv($fh,$row);
		}
		fclose($fh);
		$this->sql_disconnect();
	}

/**
	 * Build thumbnails in parallel
	 *
	 * This is the parent process
	 */
	public function make_thumbs() {
		$this->_fork_em('_make_thumbs',"SELECT COUNT(*) FROM fastback WHERE thumbnail IS NULL AND flagged IS NOT TRUE");
	}

	public function get_exif() {
		$this->_fork_em('_get_exif', "SELECT COUNT(*) FROM fastback WHERE exif IS NULL");
	}

	public function clear_exif() {
		$clear_partials = 'UPDATE fastback SET exif=NULL';

		$this->sql_connect();
		$this->sql->query($clear_partials);
		$this->sql_disconnect();
	}

	public function get_geo() {
		$clear_partials = 'UPDATE fastback SET lat=NULL,lon=NULL,elev=NULL WHERE (lat IS NULL OR lon IS NULL OR elev IS NULL) AND (lat IS NOT NULL OR lon IS NOT NULL OR elev IS NOT NULL) AND nullgeom IS NOT TRUE';

		$this->sql_connect();
		$this->sql->query($clear_partials);
		$this->sql_disconnect();

		$this->_fork_em('_get_geo', "SELECT COUNT(*) FROM fastback WHERE lat IS NULL AND nullgeom IS NOT TRUE AND exif IS NOT NULL");

		$this->sql_connect();
		$this->sql->query($clear_partials);
		$this->sql_disconnect();
	}

	public function get_times() {
		$this->_fork_em('_get_times', "SELECT COUNT(*) FROM fastback WHERE sorttime IS NULL AND flagged IS NOT TRUE AND exif IS NOT NULL");
	}

	private function _fork_em($childfunc,$statussql) {

		$this->sql_connect();

		# Cancel all reservations if we're starting fresh
		$this->sql->query("UPDATE fastback SET _util=NULL WHERE _util LIKE 'RESERVED%'");
		$total = $this->sql->querySingle($statussql);

		if ( $total === 0 ) {
			$this->print_status_line("100% : Processed 0 of 0 records.");
			return;
		}

		$start = time();
		$this->sql_disconnect();

		$this->log("Forking into $this->nproc processes for $childfunc");

		// Make the children
		$this->children = array();
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
				$this->children[] = $pid;
				// This is the parent
			}
		}

		// Reap the children
		while(count($this->children) > 0){
			foreach($this->children as $key => $child){
				if ($this->end_all_children){
					posix_kill($child, SIGTERM);
				}

				$res = pcntl_waitpid($child, $status, WNOHANG);
				if($res == -1 || $res > 0) {
					unset($this->children[$key]);
				}
			}

			if ( $this->end_all_children ) {
				$this->log("It seems that all children have exited. Exiting.");
				exit();
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

	private function _get_times($childno = "Unknown") {

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
			"FileCreateDate",
			"FileModifyDate",
		);

		do {
			$updated_timestamps = array();
			$flagged = array();

			$queue = $this->get_queue("sorttime IS NULL AND _util IS NULL AND file != \"\" AND flagged IS NOT TRUE AND exif IS NOT NULL",10); 

			foreach($queue as $file => $row) {

				// If the user has put the file in a date directory (YYYY/MM/DD) then use that as the date
				// otherwise, fall back on meta data
				if ( preg_match('|.*([0-9]{4})/([0-9]{2})/([0-9]{2})/[^\/]*|',$file)) {
					$datepart = preg_replace('|.*([0-9]{4})/([0-9]{2})/([0-9]{2})/[^\/]*|','\1-\2-\3',$file);
				} else {
					$datepart = NULL;
				}

				$found = false;
				$exif = json_decode($row['exif'],TRUE);

				foreach($tags_to_consider as $tag) {
					// Tag not found
					if ( !array_key_exists($tag,$exif)) {
						continue;
					}

					// Invalid date
					if ( strpos($exif[$tag], '0000:00:00 00:00:00') !== FALSE ) {
						continue;
					}

					preg_match('/(\d{4})\D(\d{2})\D(\d{2}) (\d{2})\D(\d{2})\D(\d{2})[^ ]*/',trim($exif[$tag]),$matches);

					if ( count($matches) !== 7 ) {

						preg_match('/(\d{4})\D(\d{2})\D(\d{2})/',trim($exif[$tag]),$matches);

						if ( count($matches) !== 4 ) {
							$this->log("Coudln't regex a date from {$exif[$tag]}");
							continue;
						} else {
							$matches[4] = '00';
							$matches[5] = '00';
							$matches[6] = '00';
						}
					}

					// If the file was in a date folder, then use that date with the best datepart we have.
					if ( !is_null($datepart) ) {
						$updated_timestamps[$file] = "'" . $datepart . " " . $matches[4] . ':' . $matches[5] . ':' . $matches[6] . "'";
					} else {
						$updated_timestamps[$file] = "'" . $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6] . "'";
					}

					$found = true;
					break; // We found a timestamp we like, stop checking the others
				}

				if ( !$found ){
					// $updated_timestamps[$file] = 'sorttime || " 00:00:01"';
					die("Every file should have a FileModifyDate since that's not actually exif. $file");
				}
			}

			if ( count($updated_timestamps) > 0 ) {
				$this->update_case_when("UPDATE fastback SET _util=NULL, sorttime=CASE",$updated_timestamps,"ELSE sorttime END");
			}

		} while (count($updated_timestamps) > 0);

		$this->sql_disconnect();
	}

	private function _get_exif($childno = "Unknown") {
		// these are all the exif tags we might use right now
		$tags_to_consider = array(
			"-GPSPosition",
			"-GPSCoordinates",
			"-GPSLatitudeRef",
			"-GPSLatitude",
			"-GPSLongitudeRef",
			"-GPSLongitude",
			"-GPSAltitude",
			"-GPSAltitudeRef",
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
			"-FileName",
		);

		$cmd = "exiftool -stay_open True  -@ -";
		$cmdargs = $tags_to_consider;
		$cmdargs = [];
		$cmdargs[] = "-lang";
		$cmdargs[] = "en";
		$cmdargs[] = "-s";
		$cmdargs[] = "-c";
		$cmdargs[] = "'%.6f'";
		$cmdargs[] = "-extractEmbedded";

		$descriptors = array(
			0 => array("pipe", "r"),  // STDIN
			1 => array("pipe", "w"),  // STDOUT
			2 => array("pipe", "w")   // STDERR
		);

		$proc = proc_open($cmd, $descriptors, $pipes,$this->photobase);

		// Don't block on STDERR
		stream_set_blocking($pipes[1], 0);
		stream_set_blocking($pipes[2], 0);

		do {
			$queue = $this->get_queue("exif IS NULL",1,FALSE);

			$found_exif = array();

			foreach($queue as $file => $row) {
				fputs($pipes[0],implode("\n",$cmdargs) . "\n");
				fputs($pipes[0],$file . "\n");
				fputs($pipes[0],"-execute\n");
				fflush($pipes[0]);

				$cur_exif = array();
				$end_of_exif = FALSE;

				while(!$end_of_exif){
					// Handle stdout
					$line = fgets($pipes[1]);	
					if ($line !== FALSE ) { 
						$line = trim($line);

						if ( preg_match('/^======== (.*)/',$line, $matches ) ) {

							if ($matches[1] != $file) {
								$this->log("Expected '$file', got '$matches[1]'");
								die("Somethings broken");
							}

							$cur_exif = array();

							// Big updates for now to make it worth it
							if ( count($found_exif) == $this->process_limit ) {

								$escaped = array();
								foreach($found_exif as $file => $json){
									$escaped[] = "'" . SQLite3::escapeString($file) . "'";
								}
								$files_where = implode(",",$escaped);
							}
						} else if (preg_match('/^([^ ]+)\s*:\s*(.*)$/',$line,$matches)){
							$cur_exif[$matches[1]] = $matches[2];
						} else if ($line == '{ready}') {
							$end_of_exif = TRUE;
						} else if (preg_match('/ExifTool Version Number.*/',$line)){
							// do nothing
						} else if ($line === ''){
							// do nothing
						} else {
							$this->log("Don't know how to handle exif line '" . $line . "'");
							die("Quitting for fun");
						}
					}

					// Handle stderr
					$err = fgets($pipes[2]);
					if ( $err !== FALSE ) {
						$no_err = FALSE;
						$this->log($err);
					}

					if ($err === FALSE && $line === FALSE) {
						time_nanosleep(0,200);
					}
				}

				$found_exif[$file] = json_encode($cur_exif,JSON_FORCE_OBJECT);
			}

			$this->update_case_when("UPDATE fastback SET _util=NULL, exif=CASE",$found_exif,"ELSE exif END",True);

		} while (!empty($queue));

		fputs($pipes[0], "-stay_open\nFalse\n");
		fflush($pipes[0]);
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);
		exit();
	}

	private function _get_geo($childno = "Unknown") {

		do {
			$updated_geoms = array();
			$no_geoms = array();

			$queue = $this->get_queue("lat IS NULL AND lon IS NULL and elev IS NULL AND _util IS NULL AND file != \"\" AND nullgeom IS NOT TRUE AND exif IS NOT NULL",10);

			foreach($queue as $file => $row){
				$found = false;
				$exif = json_decode($row['exif'],TRUE);

				$xyz = array();

				if ( array_key_exists('GPSPosition',$exif) ) {
					// eg "38.741200 N, 90.642800 W"
					$xyz = $this->parse_gps_line($exif['GPSPosition']);	
				}

				if ( count($xyz) === 0 && array_key_exists('GPSCoordinates',$exif) ) {
					// eg "38.741200 N, 90.642800 W"
					$xyz = $this->parse_gps_line($exif['GPSCoordinates']);	
				}

				if ( count($xyz) === 0 && 
					array_key_exists('GPSLatitudeRef',$exif) && 
					array_key_exists('GPSLatitude',$exif) && 
					array_key_exists('GPSLongitude',$exif) && 
					array_key_exists('GPSLongitudeRef',$exif) &&
					floatval($exif['GPSLongitude']) == $exif['GPSLongitude'] && 
					floatval($exif['GPSLatitude']) == $exif['GPSLatitude'] 
				) {

					if ( $exif['GPSLatitudeRef'] == 'South' ) {
						$exif['GPSLongitude'] = $exif['GPSLongitude'] * -1;
					}

					if ( $exif['GPSLongitudeRef'] == 'West' ) {
						$exif['GPSLongitude'] = $exif['GPSLongitude'] * -1;
					}

					$xyz[0] = $exif['GPSLongitude'];
					$xyz[1] = $exif['GPSLatitude'];
				}

				if ( count($xyz) === 2 && array_key_exists('GPSAltitude',$exif) ) { //  && floatval($exif['GPSAltitude']) == $exif['GPSAltitude']) {
					if ( preg_match('/([0-9.]+) m/',$exif['GPSAltitude'],$matches ) ) {
						if ( array_key_exists('GPSAltitudeRef',$exif) && $exif['GPSAltitudeRef'] == 'Below Sea Level' ) {
							$xyz[2] = $matches[1] * -1;
						} else {
							$xyz[2] = $matches[1];
						}
					} else if ( $exif['GPSAltitude'] == 'undef') {
						$xyz[2] = 0;
					} else {
						$this->log("New type of altitude value found: {$exif['GPSAltitude']} in $file");
						$xyz[2] = 0;
					}
				} else {
					$xyz[2] = 0;
				}

				if ( count($xyz) === 3 ) {
					$updated_geoms[$file] = $xyz;
				} else {
					$no_geoms[] = $file;
				}
			}

			if ( !empty($updated_geoms) ) {

				$lons = array();
				$lats = array();
				$elevs = array();

				foreach($updated_geoms as $file => $xyz){
					$lons[$file] = $xyz[0];
					$lats[$file] = $xyz[1];
					$elevs[$file] = $xyz[2];
				}

				$this->update_case_when("UPDATE fastback SET lon=CASE",$lons,"ELSE lon END");
				$this->update_case_when("UPDATE fastback SET lat=CASE",$lats,"ELSE lat END");
				$this->update_case_when("UPDATE fastback SET _util=NULL, elev=CASE",$elevs,"ELSE elev END");
			}

			$this->update_files_in("UPDATE fastback SET _util=NULL, nullgeom=1 WHERE file in (",$no_geoms);

		} while (count($updated_geoms) > 0 || count($no_geoms) > 0);
	}

	public function parse_gps_line($line) {
		$xyz = array();
		// eg "38.741200 N, 90.642800 W"
		if ( preg_match('/\'?([0-9.]+)\'? (N|S), \'?([0-9.]+)\'? (E|W)/',$line,$matches) ) {
			if ( count($matches) == 5) {

				if ( $matches[2] == 'S' ) {
					$matches[1] = $matches[1] * -1;
				} else {
					$matches[1] = $matches[1] * 1;
				}

				if ( $matches[4] == 'W' ) {
					$matches[3] = $matches[3] * -1;
				} else {
					$matches[3] = $matches[3] * 1;
				}

				$xyz[0] = $matches[1];
				$xyz[1] = $matches[3];
			}
		} else {
			$this->log("Couldn't parse >>$line<<");
		}
		return $xyz;
	}

	public function help() {
		print "\n";
		print "index.php [command] [debug]

			Commands:
		* handle_new (default) - Brings the database from whatever state it is in, up to date. This is the command you usually want to run. It will run the following steps, in order:
			- load_cache
			- make_thumbs
			- get_exif
			- get_time
			- get_geo
			- make_csv
		* db_test - Just tests if the database exists or can be created, and if it can be written to.
		* reset_cache – Clears the lastmod timestamp from the fastbackmeta database, causing all files to be reconsidered
		* reset_db – Truncate the database. It will need to be repopulated. Does not touch files.
		* clear_thumbs_db – Makes the database think there are no thumbnails generated. If files exist they will not be re-created. Useful if you delete some thumbnails and want to regenerate them.
		* load_cache – Finds all new files in the library and make cache entries for them. Does not generate new thumbnails.
		* make_thumbs – Generates thumbnails for all entries found in the cache.
		* clear_exif – Sets exif field for all files to NULL
		* get_exif – Read needed exif info into the database. Must happen before gettime or getgeo
		* get_time – Uses exif data or file creation or modified time to find the files's sort time
		* get_geo – Uses exif data to find the media geolocation info so it can be shown on a map
		* make_csv - Regenerates the cached .json file based on the cache database. Doesn't touch or look at files.
		* full_reset - Runs reset_cache and reset_db first and then runs handle_new

			All commands can have the word debug after them, which will disable the pretty print, and be more verbose.
";
	}

	public function print_status_line($msg,$skip_spinner = false) {
		$spinners = array('\\','|','/','-');

		$this->print_if_no_debug("\e[1B"); // Go down a line
		$this->print_if_no_debug("\e[1000D"); // Go to start of line


		if ( !$skip_spinner && !$this->debug) {
			print($spinners[$this->spindex]); 
			$this->spindex++;
			if ($this->spindex == count($spinners)){
				$this->spindex = 0;
			}
		}

		print(" " . str_pad($msg,100));
		if ($this->debug ) { print("\n"); }

		$this->print_if_no_debug("\e[1A"); // Go up a line
		$this->print_if_no_debug("\e[1000D"); // Go to start of line
	}

	public function print_if_no_debug($msg) {
		if ( !$this->debug ) {
			print($msg);
		}
	}

	public function log($msg) {
		if ( $this->debug ) {
			error_log($msg);
		}
	}

	private function get_queue($where,$multiplier = 1, $exit_on_empty = TRUE) {
		$this->sql_connect();

		$query = "UPDATE fastback SET _util='RESERVED-" . getmypid() . "' WHERE _util IS NULL AND " . $where . " ORDER BY file DESC LIMIT " . ($this->process_limit * $multiplier);
		$this->sql->query($query);

		$query = "SELECT * FROM fastback WHERE _util='RESERVED-" . getmypid() . "'";
		$res = $this->sql->query($query);

		$queue = array();

		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$queue[$row['file']] = $row;
		}
		$this->sql_disconnect();

		if ( count($queue) === 0 && $exit_on_empty) {
			exit();
		}

		return $queue;
	}

	private function update_case_when($update_q,$ar,$else,$escape_val = False) {

		if ( empty($ar) ) {
			return;
		}

		$this->sql_connect();
		foreach($ar as $file => $val){
			if ( $escape_val ) {
				$update_q .= " WHEN file='" . SQLite3::escapeString($file) . "' THEN '" . SQLite3::escapeString($val) . "'\n";
			} else {
				$update_q .= " WHEN file='" . SQLite3::escapeString($file) . "' THEN " . $val . "\n";
			}
		}
		$update_q .= " " . $else;
		$update_q .= " WHERE _util='RESERVED-" . getmypid() . "'";
		$res = $this->sql->query($update_q);
		if ( $res == False ) {
			$this->log($update_q);
			$this->log($this->sql->lastErrorMsg());
		}
		$this->sql_disconnect();
	}

	private function update_files_in($update_q,$files) {

		if ( count($files) === 0 ) {
			return;
		}

		$this->sql_connect();

		$escaped = array();
		foreach($files as $file){
			$escaped[] = "'" . SQLite3::escapeString($file) . "'";
		}

		$update_q .= implode(",",$escaped);

		$update_q .= ")";

		$this->sql->query($update_q);
		$this->sql_disconnect();
	}
}

$fb = new FastbackOutput();
