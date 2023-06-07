<?php
/**
 * Copyright 2021-2023 Michael Moore - stuporglue@gmail.com
 * Licensed under the MIT License
 */
class Fastback {
	/*
	 * Usage: 
	 * Initialize Fastback, then override whatever you want to.
	 * Then call run.
	 *
	 * $fb = new Fastback();
	 * $fb->sitetitle = "Moore Family Gallery!";
	 * $fb->user['Michael'] = 'Mypassw0rd!;
	 * $fb->run();
	 */ 

	/*
	 * Settings!
	 *
	 * Don't touch these here, change them in your index.php file. 
	 */

	/*
	 * Debug mode or no?
	 */
	var $debug = 0;									// Are we debugging

	/* 
	 * User Experience
	 */
	var $sitetitle = "Fastback Photo Gallery";		// Title
	var $basemap = "L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href=\"https://www.openstreetmap.org/copyright\">OpenStreetMap</a> contributors'})";
	var $user = array();							// Dictionary of username => password. eg array('Michae' => 'michaelpassword123', 'Ryan' => '12345')
	var $canflag = array();							// List of users who can flag photos. eg. array('Michael','Caroline');
	var $_thumbsize = "256x256";					// Thumbnail size. Must be square.


	/*
	 * Locations
	 */
	var $photobase = __DIR__ . '/../';				// File path to full sized photos, Optional, will use current directory as default
	var $fastback_log = __DIR__ . '/cache/fastback.log'; // Where should fastabck log things. Nothing should get logged if debug is not true
	var $filecache = __DIR__ . '/cache/';			/* Folder path to cache directory. sqlite and thumbnails will be stored here. 
													   Optional, will create a cache folder in the current directory as the default
													   $filecache doesn't have to be web accessable
													*/
	var $sqlitefile = __DIR__ . '/fastback.sqlite';	// Path to .sqlite file, Optional, defaults to fastback/fastback.sqlite
	var $csvfile = __DIR__ . '/cache/fastback.csv';	// Path to .csv file, Optional, will use $this->filecache/fastback.sqlite by default
	var $siteurl;									/* Fastback will try to figure out the site url. If it's getting it wrong you can override it.
													*/

	/*
	 * Data processing
	 */

	var $photodirregex = '';						/* Use '' (empty string) for all photos, regardless of structure.
														Use this regex to only consider media in YYYY/MM/DD directories
													   $fb->photodirregex = './[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/'; 
													*/
	var $ignore_tag  = array('iMovie','FaceTime');	// Tags to ignore from photos.
	var $sortorder = 'DESC';						// Sort order of the photos for the csv (ASC or DESC)
	var $maybe_meme_level = 1;						/* Which level of maybe_meme should we filter at? The higher the number the more 
														likely it is a meme/junk image. Values can be any integer. Current code
														ends up assigning values between about -2 and +2.
													*/
	var $cronjobs = array(							// These are the cron jobs we will try to run, in the order we try to complete them.
		'find_new_files',							// If you don't want them all to run, for example if you don't want to generate thumbnails, then you could change this.
		'make_csv',
		'process_exif',
		'get_exif',
		'remove_deleted',
		'clear_locks',
		'make_thumbs',							    
		'make_streamable',							    
	);

	var $_crontimeout = 120;						/* How long to let cron run for in seconds. External calls don't count, so for thumbs and exif wall time may be longer
													   If this is to short some cron jobs may not record any finished work. See also $_process_limit and $_upsert_limit.
													*/
	var $_cron_min_interval = 62;					// A completed cron will run again occastionally to see if anything is new. This is how long it should wait between runs, when completed.	
	var $_concurrent_cronjobs;						/* How many concurrent cron jobs should we run? These take up fcgi processes. 
													   We don't want to use all processes as it will make the server unresponsive.
													   We will set it to CEIL(nproc/4) in cron() to allow some parallell processing.
													 */

	/*
	 * ffmpeg -i photobase/$original_file $this->_ffmpeg_streamable -threads $this->_ffmpeg_streamable_threads cache/$streamable_output_file
	 * Defatul command derived from examples here: https://gist.github.com/jaydenseric/220c785d6289bcfd7366
	 */
	var $_ffmpeg_streamable = ' -c:v libx264 -pix_fmt yuv420p -profile:v baseline -level 3.0 -crf 22 -maxrate 2M -bufsize 4M -preset medium -vf "scale=\'min(1024,iw)\':-2" -c:a aac -strict experimental -movflags +faststart';
	var $_ffmpeg_streamable_threads = 'auto';		// auto uses 0 (optimal) for CLI, 1 for web requests
	var $_process_limit = 100;						// How many records should we process at once?
	var $_upsert_limit = 10000;						// Max number of SQL statements to do per upsert

	/*
	 * Processing tools
	 */
	var $_vipsthumbnail;							// Path to vips binary
	var $_ffmpeg;									// Path to ffmpeg binary
	var $_gzip;										// Generate gzipped csv
	var $_convert;									// Path to ImageMagick binary
	var $_jpegoptim;								// Path to jpegoptim

	/*
	 * Internal variables, not reall meant to be messed with
	 */
	var $_sql;										// The sqlite object
	var $_sql_counter = 0;							// How many sql_connect calls do we have? Every function can sql_connect and sql_disconnect without closing the handle on someone else.
	var $_sqlite_timeout = 60;						// Wait timeout for a db connection. Value in seconds.
				

	/*
	 * These are internal variables you probably shouldn't try to change
	 */
	var $supported_photo_types = array( // Photo formats that we will search for
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

	var $supported_video_types = array( // Video formats that we will search for
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
	 * Configure all the settings.
	 * 
	 * Defaults should be sane. Other things can be overridden in index.php
	 */
	public function __construct() {
		$this->siteurl = $this->util_base_url();

		// This will only log if debug is enabled
		$this->log("Debug enabled");
	}

	/**
	 * Do a normal run. Handle either the cli and its args, or the http request and its arguments.
	 *
	 * This function exits.
	 */
	public function run() {
		// Ensure trailing slashes
		$this->photobase = rtrim($this->photobase,'/') . '/';
		$this->filecache = rtrim($this->filecache,'/') . '/';

		if ( !is_dir($this->filecache) ) {
			@mkdir($this->filecache,0750,TRUE);
			if ( !is_dir($this->filecache) ) {
				error_log("Fastback cache directory {$this->filecache} doesn't exist and can't be created. Please create it and give the web server write permission.");
				$this->log("Fastback cache directory {$this->filecache} doesn't exist and can't be created. Please create it and give the web server write permission.");
				die("Fastback setup error. See error log.");
			}
			touch($this->filecache . '/index.php');
		}

		// Someone changed filecache but not the csv file. Update it.
		if ( $this->filecache != __DIR__ . '/cache/' && $this->csvfile == __DIR__ . '/cache/fastback.csv' ) {
			$this->csvfile = $this->filecache . 'fastback.csv';
		}

		if ( $this->filecache != __DIR__ . '/cache/' && $this->fastback_log == __DIR__ . '/cache/fastback.log' ) {
			$this->fastback_log = $this->filecache . 'fastback.log';
		}

		// CLI stuff doesn't need auth
		if (php_sapi_name() === 'cli') {
			$this->util_handle_cli();
			exit();
		} else  {
			// Dothis after cli handling so that cli error log still goes to stdout
			ini_set('error_log', $this->fastback_log);
		}

		// PWA stuff doesn't need auth
		if ( !empty($_GET['pwa']) ) {
			$this->send_pwa();
			exit();
		} else if ( !empty($_GET['share']) ) {
			$this->send_share();
			exit();
		}

		// Handle auth
		if ( !empty($this->user) ) {
			if ( !$this->util_handle_auth() ) {
				exit();
			}
		}

		// Handle requests
		if (!empty($_GET['proxy'])) {
			$this->send_proxy();
			exit();
		} else if (!empty($_GET['download'])) {
			$this->send_download();
			exit();
		} else if (!empty($_GET['thumbnail'])) {
			$this->send_thumbnail();
			exit();
		} else if (!empty($_GET['flag'])) {
			$this->action_flag_photo();
			exit();
		} else if (!empty($_GET['csv'])) {
			$this->send_csv();
			exit();
		} else if (!empty($_GET['cron'])){
			$this->cron();
			exit();
		} else {
			// Default case!
			$this->send_html();
			exit();
		}
	}

	/**
	 * Log a message through error_log if debug is true
	 */
	private function log($msg) {
		if ( $this->debug ) {
			error_log($msg);
		}
	}

	/**
	 * Process args and run what we should
	 */
	private function util_handle_cli(){
			global $argv;

			pcntl_async_signals(true);

			// setup signal handlers
			pcntl_signal(SIGINT, function(){
				$this->log("Got SIGINT and now exiting");
				exit();
			});

			pcntl_signal(SIGTERM, function(){
				$this->log("Got SIGTERM and now exiting");
				exit();
			});

			if ( isset($argv) ) {
				$debug_found = array_search('debug',$argv);
				if ( $debug_found !== FALSE ) {
					$this->debug = true;
					array_splice($argv,$debug_found,1);
				}
			}

			if ( !isset($argv) || count($argv) == 1 ) {
				$this->cron();
				return;
			} 

			$allowed_actions = array('find_new_files','make_csv','process_exif','get_exif','make_thumbs','make_streamable','remove_deleted','clear_locks','status');

			if ( in_array($argv[1],$allowed_actions) ) {
				$this->log("Running {$argv[1]}");
				$func = "cron_" . $argv[1];
				$this->$func();
			} else {
				print("You're using fastback photo gallery\n");
				print("Usage: ./index.php [debug] [" . implode('|',$allowed_actions) . "]\n");
			}
	}

	/**
	 * Do everything around authentication. 
	 * 
	 * Print the login form and exit
	 * Handle auth and continue
	 * Acknowledge session and continue
	 * Handle log out
	 *
	 * Sets cookies and sends headers
	 */
	private function util_handle_auth() {
		session_set_cookie_params(["SameSite" => "Strict"]); //none, lax, strict
		session_start();

		// Log out, just end it and redirect
		if ( isset($_REQUEST['logout']) ) {
			session_destroy();
			print_r($_SERVER);
			header("Location: " . preg_replace('/\?.*/','',$_SERVER['REQUEST_URI']));
			setcookie("fastback","", time() - 3600); // Clear cookie
			return true;
		}

		// Already active session
		if ( array_key_exists('authed',$_SESSION) && $_SESSION['authed'] === true && !empty($_SESSION['user']) ) {
			if ( !array_key_exists($_SESSION['user'], $this->user ) ) {
				session_destroy();
				header("Location: " . $_SERVER['SCRIPT_URL']);
				setcookie("fastback","", time() - 3600); // Clear cookie
				return false;
			}

			return true;
		}

		// User doing a new login
		if ( isset($_POST['Username']) && isset($_POST['Password']) && isset($this->user[$_POST['Username']]) && $this->user[$_POST['Username']] == $_POST['Password'] ) {
			$_SESSION['authed'] = true;
			$_SESSION['user'] = $_POST['Username'];

			if ( isset($_POST['remember']) && $_POST['remember'] == 1 ) {
				// There will only ever be a small number of users (for fastback's intended use case) so we're just going to brute force finding this later.
				// Setting the username + hash of the ini file means that if the ini file changes, the user must log in again.
				// This will cover the case of the user's password being changed or something.
				$cookie_val = md5($_POST['Username'] . md5_file($_SERVER['SCRIPT_FILENAME']));
				setcookie("fastback",$cookie_val, array('expires' => time() + 30 * 24 * 60 * 60,'SameSite' => 'Strict')); // Cookie valid for 30 days
			}

			return true;
		}

		// Returning user with a cookie
		if ( !empty($_COOKIE['fastback']) ) {
			$script_md5 = md5_file($_SERVER['SCRIPT_FILENAME']);
			foreach($this->user as $username => $password) {
				$cookie_val = md5($username . $script_md5);
				if ( $cookie_val == $_COOKIE['fastback'] ) {
					// Refresh the cookie
					setcookie("fastback",$cookie_val, array('expires' => time() + 30 * 24 * 60 * 60,'SameSite' => 'Strict')); // Cookie valid for 30 days
					$_SESSION['authed'] = true;
					$_SESSION['user'] = $username;
					return true;
				}
			}
			// Cookie doesn't match. Delete it.
			setcookie("fastback","", time() - 3600); // Clear cookie
			// Don't return, fall through to form.
		}

		$html = '<!doctype html>
			<html lang="en">
			<head>
			<meta charset="utf-8">
			<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
			<title>' . htmlspecialchars($this->sitetitle) . '</title>
			<link rel="shortcut icon" href="fastback/img/favicon.png"> 
			<link rel="apple-touch-icon" href="fastback/img/favicon.png">
			<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
			<link rel="stylesheet" href="fastback/css/fastback.css?ts=' . ($this->debug ? 'debug' : filemtime(__DIR__ . '/css/fastback.css')) . '">
			</head>';

		$html .= '<body><div id="loginform"><h1>Log in to ' . htmlspecialchars($this->sitetitle) . '</h1>
			<form method="POST">
			<div class="inputline"><label for="Username">Username: </label><input id="Username" type="text" name="Username"></div>
			<div class="inputline"><label for="Password">Password: </label><input id="Password" type="password" name="Password"></div>
			<div class="inputline"><label for="Remember">Remember me: </label><input id="Remember" type="checkbox" name="remember" value="1"></div>
			<div class="inputline"><input type="Submit" name="Submit" value="Log In"></div>
			</form>
			</div>
			</body>
			</html>';

		print $html;
		return false;
	}

	/**
	* Check whether URL is HTTPS/HTTP
	* @return boolean [description]
	*
	* https://stackoverflow.com/questions/5100189/use-php-to-check-if-page-was-accessed-with-ssl
	*/
	private function util_base_url() {
		// Probably from a CLI context
		if ( empty($_SERVER['HTTP_HOST']) ) {
			return false;
		}

		$http = '';
		if (
			( ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| ( ! empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
			|| ( ! empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on')
			|| (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
			|| (isset($_SERVER['HTTP_X_FORWARDED_PORT']) && $_SERVER['HTTP_X_FORWARDED_PORT'] == 443)
			|| (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https')
		) {
			$http .= 'https://';
		} else {
			$http .= 'http://';
		}

		$therest = preg_replace('/\?.*/','',$_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']);

		if ( preg_match('/\.php$/',$therest) ) {
			$therest = dirname($therest);
		}

		$ret = str_replace('//','/',$therest . '/');
		$whole_url = $http . $ret;

		if ( filter_var($whole_url, FILTER_VALIDATE_URL) === FALSE ) {
			error_log("Unable to create valid URL: $whole_url");
			$this->log("Unable to create valid URL: $whole_url");
			die("Couldn't figure out server URL");
			return false;
		}
		return $whole_url;
	}

	/**
	 * For a short file name check if the file is a valid photo option
	 *
	 * @param $file A short file name to check the database for.
	 *
	 * @return A file name that is valid according to the database and which exists on disk.
	 *
	 * Dies on file not exist or not in database.
	 */
	private function util_file_is_ok($file) {	
		$file_safe = SQLite3::escapeString($file);
		$file_safe = $this->sql_query_single("SELECT file FROM fastback WHERE file='$file_safe'");
		if ( !$file_safe ) {
			http_response_code(404);
			$this->log("Someone tried to access file '''$file'''");
			die();
		}

		if ( !file_exists($this->photobase . $file_safe) ) {
			http_response_code(404);
			$this->log("Someone tried to access $file, which doesn't exist");
			die();
		}

		return $file_safe;
	}

	/**
	 * Make the csv cache file
	 *
	 * @param $print_if_not_write If we can't open the cache file, then send the csv directly.
	 *
	 * @note Using $print_if_not_write will cause this function to exit() after sending.
	 */
	private function util_make_csv($print_if_not_write = false){

		$this->sql_connect();
		$rows = $this->sql_query_single("SELECT COUNT(*) FROM fastback");

		// If we have no rows in the db, try to run the find_new_files cron
		if ( $rows === 0 ) {
			$this->cron_find_new_files();	
		}

		$q = "SELECT 
			file,
			isvideo,
			COALESCE(CAST(STRFTIME('%s',sorttime) AS INTEGER),mtime) AS filemtime,
			ROUND(lat,5) AS lat,
			ROUND(lon,5) AS lon,
			tags
			FROM fastback 
			WHERE 
			flagged IS NOT TRUE 
			AND (maybe_meme <= '" . SQLite3::escapeString($this->maybe_meme_level) . "' OR maybe_meme IS NULL) -- Only display non-memes. Threshold of 1 seems pretty ok
			ORDER BY filemtime " . $this->sortorder . ",file " . $this->sortorder;
		$res = $this->_sql->query($q);

		$printed = false;
		$fh = fopen($this->csvfile,'w');
		if ( $fh === false  && $print_if_not_write) {
			$printed = true;
			header("Content-type: text/plain");
			header("Content-Disposition: inline; filename=\"" . basename($this->csvfile) . "\"");
			header("Last-Modified: " . filemtime($this->csvfile));
			header('Cache-Control: max-age=86400');
			header('Etag: ' . md5_file($this->sqlitefile));
			$fh = fopen('php://output', 'w');
		}

		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if ( $row['isvideo'] == 0 ) {
				$row['isvideo'] = NULL;
			}
			fputcsv($fh,$row);
		}
		fclose($fh);
		$this->sql_disconnect();

		if ( $printed ) {
			exit();
		}

		if ( !isset($this->_gzip) ) { $this->_gzip= trim(`which gzip`); }

		if ( isset($this->_gzip) ) {
			$cmd = "$this->_gzip -k --best -f {$this->csvfile}";
			`$cmd`;
		} else if ( file_exists($this->csvfile . '.gz') ) {
			$this->log("Can't write new {$this->csvfile}.gz, but it exists. It may get served and show stale results");
		}

		return true;
	}

	private function util_readfile($file,$disposition= 'inline'){
		$mime = mime_content_type($file);
		if ( $mime == 'text/csv' ) {
			$mime = 'text/plain';
		}

		if ( $mime == 'application/gzip' && file_exists(str_replace('.gz','',$file)) ) {
			header("Content-Encoding: gzip");
			$mime = mime_content_type(str_replace('.gz','',$file));
		}

		header("Content-Type: $mime");
		header("Content-Disposition: $disposition; filename=\"" . basename($file) . "\"");
		header("Content-Length: ".filesize($file));
		header("Last-Modified: " . filemtime($file));
		header('Cache-Control: max-age=86400');
		header('Etag: ' . md5_file($file));

		if ( $disposition == 'download' ) {
			header("Content-Transfer-Encoding: Binary");
		}

		readfile($file);
	}

	/**
	 * Generate the HTML for the application
	 */
	private function send_html() {
		$html = '<!doctype html>
			<html lang="en">
			<head>
			<meta charset="utf-8">
			<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
			<title>' . htmlspecialchars($this->sitetitle) . '</title>
			<link rel="manifest" href="?pwa=manifest">	
			<link rel="shortcut icon" href="fastback/img/favicon.png"> 
			<link rel="apple-touch-icon" href="fastback/img/favicon.png">
			<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">';

		$html .= '<link rel="stylesheet" href="fastback/css/jquery-ui.min.css">
			<link rel="stylesheet" href="fastback/css/leaflet.css"/>
			<link rel="stylesheet" href="fastback/css/MarkerCluster.Default.css"/>
			<link rel="stylesheet" href="fastback/css/MarkerCluster.css"/>
			<link rel="stylesheet" href="fastback/css/fastback.css?ts=' . ($this->debug ? 'debug' : filemtime(__DIR__ . '/css/fastback.css')) . '">
			</head>';

		$html .= '<body class="photos">';
		$html .= '<div id="map"></div>';
		$html .= '<div id="hyperlist_wrap">';
		$html .= '<div id="photos"><div id="loadingbox"><div id="loadingmsg">...Loading...</div><div id="loadingprogress"></div></div></div>';
		$html .= '</div>';
		$html .= '<input id="speedslide" class="afterload" type="range" orient="vertical" min="0" max="100" value="0"/>';
		$html .= '<div id="resizer" class="afterload">';
		$html .= '<input type="range" min="1" max="10" value="5" class="slider" id="zoom">';
		$html .= '<div id="globeicon" class="disabled"></div>';
		$html .= '<div id="tagicon" class="disabled"></div>';
		$html .= '<div id="rewindicon"></div>';
		$html .= '<div id="calendaricon"><input readonly id="datepicker" type="text"></div>';
		$html .= '<div id="exiticon" class="' . (empty($this->user) ? 'disabled' : '') . '"></div>';
		$html .= '</div>';
		$html .= '<div id="thumb" class="disabled">
			<div id="thumbcontent"></div>
			<div id="thumbleft" class="thumbctrl">LEFT</div>
			<div id="thumbright" class="thumbctrl">RIGHT</div>
			<div id="thumbcontrols">
			<div id="thumbclose" class="fakelink">🆇</div>
			<div class="fakelink" id="thumbdownload">⬇️</div>
			<div class="fakelink" id="sharelink"><a href="#">🔗<form id="sharelinkcopy">><input/></form></a></div>
			<div class="fakelink disabled" id="webshare"><img src="fastback/img/share.png"></div>';
			if (!empty($this->canflag) && !empty($_SESSION['user']) && in_array($_SESSION['user'],$this->canflag)){
				$html .= '<div class="fakelink" id="thumbflag" data-file="#">🚩</div>';
			}
			$html .= '<div class="fakelink" id="thumbgeo" data-coordinates="">🌐</div>
			<div id="thumbinfo"></div>
			</div>';
		$html .= '</div>';

		$html .= '<div id="tagwindow" class="disabled">
			<div id="and_or_toggle">
			<div id="tagwindowclose">🆇</div>
			<div class="tagtooltoggle"><label>Tag Filter Status:</label>
			<div class="nowrap"><span class="" id="tagon">On</span><span class="active" id="tagoff">Off</span></div>
			</div>
			<div class="tagtooltoggle"><label>Show photos that match:</label>
			<div class="nowrap"><span class="active" id="tagor">ANY tag</span><span id="tagand">ALL tags</span></div>
			</div>
			</div>
			<div id="thetags"></div>
			</div>';

		$html .= '<script src="fastback/js/jquery.min.js"></script>';
		$html .= '<script src="fastback/js/hammer.js"></script>';
		// $html .= '<script src="fastback/js/leaflet.js"></script>';
		$html .= '<script src="fastback/js/leaflet-src.js"></script>';
		$html .= '<script src="fastback/js/jquery-ui.min.js"></script>';
		$html .= '<script src="fastback/js/hyperlist.js"></script>';
		$html .= '<script src="fastback/js/papaparse.min.js"></script>';
		$html .= '<script src="fastback/js/jquery.hammer.js"></script>';
		$html .= '<script src="fastback/js/leaflet.markercluster.js"></script>';
		$html .= '<script src="fastback/js/fastback.js?ts=' . ($this->debug ? 'debug' : filemtime(__DIR__ . '/js/fastback.js')) . '"></script>';
		$html .= '<script src="fastback/js/md5.js"></script>';

		$base_script = preg_replace(array('/.*\//','/\?.*/'),array('',''),$_SERVER['REQUEST_URI']);

		$csvmtime = "";
		if ( $this->debug ) {
			$csvmtime = 'debug';
		} else if( file_exists($this->csvfile) ) {
			$csvmtime = filemtime($this->csvfile);
		} else if ( file_exists($this->sqlitefile )) {
			$csvmtime = filemtime($this->sqlitefile);
		} else {
			$csvmtime = filemtime(__FILE__);
		}

		$html .= '<script>
			fastback = new Fastback({
				csvurl: "' . $this->siteurl . '?csv=get&ts=' . $csvmtime . '",
				fastbackurl: "' . $this->siteurl . $base_script . '",
				photocount: ' . $this->sql_query_single("SELECT COUNT(*) FROM fastback") . ',
				basemap: ' . $this->basemap . '
		});';

		$html .= 'if("serviceWorker" in navigator) {
			navigator.serviceWorker.register("?pwa=sw", { scope: "' . $this->siteurl . '" });
		}';

		$html .= '</script>';
		$html .= '</body></html>';

		print $html;
	}

	/**
	 * Send, creating if needed, the CSV file of all the photos
	 */
	private function send_csv() {
		if ( !file_exists($this->csvfile ) ) {
			$wrote = $this->util_make_csv(true);

			if ( !$wrote ) {
				header("HTTP/1.0 404 Not Found");
				print("CSV file not found");
				exit();
			}
		}

		// If server accepts gzip and we have the gzip file, then send it. 
		if ( strpos($_SERVER['HTTP_ACCEPT_ENCODING'],'gzip') !== FALSE && file_exists($this->csvfile . '.gz')) {
			$this->util_readfile($this->csvfile . '.gz');
		} else if ( file_exists($this->csvfile) ) {
			$this->util_readfile($this->csvfile);
		}
	}

	/**
	 * Handle publicly sharable link
	 */
	private function send_share() {
		if ( empty($_GET['share']) ) {
			return false;
		}
		$share = SQLite3::escapeString($_GET['share']);
		$file = $this->sql_query_single("SELECT file FROM fastback WHERE share_key='$share'");

		if ( !$file ) {
			http_response_code(404);
			$this->log("Someone tried to access a shared file with parameters " . print_r($_GET,true));
			die();
		}

		if ( !file_exists($this->photobase . $file) ) {
			http_response_code(404);
			$this->log("Someone tried to access $file, which doesn't exist");
			die();
		}

		if ( !empty($_GET['proxy']) ) {
			$_GET['proxy'] = $file;
			return $this->send_proxy();
		}

		$file = $this->photobase . $file;

		$this->util_readfile($file);
		exit();
	}

	/**
	 * Proxy a file type which is not supported by the browser.
	 */
	private function send_proxy() {
		if ( !($file = $this->util_file_is_ok($_GET['proxy']) ) ) {
			die();
		}

		$video = $this->sql_query_single("SELECT isvideo FROM fastback WHERE file='" . SQLite3::escapeString($file) . "'");

		if ( !$video) {
			if ( !isset($this->_convert) ) { $this->_convert = trim(`which convert`); }

			// We only try convert here because vips is just for thumbnails, and the only formats that GD supports are already supported by the browser.
			if ( !empty($this->_convert) ) {
				header("Content-Type: image/jpeg");
				header("Content-Disposition: inline; filename=\"" . basename($file) . ".jpg\"");
				$cmd = $this->_convert . ' ' . escapeshellarg($this->photobase . $file) . ' JPG:-';
				passthru($cmd);
				exit();
			} else {
				// Fallback to sending the original. Maybe they can figure out what to do with it.
				header("Location: ?download=$file");
				exit();
			}
		} else {
			if ( file_exists($this->filecache . $file . '.mp4') ) {
				header("Content-Type: video/mp4");
				header("Content-Disposition: inline; filename=\"" . basename($file) . ".mp4\"" );
				header("Content-Length: ".filesize($this->filecache . $file . '.mp4'));
				header("Last-Modified: " . filemtime($this->filecache . $file . '.mp4'));
				header('Cache-Control: max-age=86400');
				header('Etag: ' . md5_file($this->filecache . $file . '.mp4'));
				readfile($this->filecache . $file . '.mp4');
				exit();
			} else {
				header("Location: ?download=$file");
			}
		}
	}

	/**
	 * Download a specific file
	 *
	 * Dies if file not in database or not on disk
	 */
	private function send_download() {
		if ( !$file = $this->util_file_is_ok($_GET['download']) ) {
			die();
		}

		$file = $this->photobase . $file;
		$this->util_readfile($file,'download');
		exit();
	}

	/**
	 * Send a thumbnail for the requested file
	 *
	 * Dies if file not in database or not on disk
	 */
	private function send_thumbnail() {
		$thumbnailfile = $this->_make_a_thumb($_GET['thumbnail'],false,true);

		if ( empty($thumbnailfile) ) {
			$this->log("Couldn't find thumbnail for '''{$_GET['thumbnail']}''', sending full sized!!!");
			// I know this breaks video thumbs, but if a user is just getting set up I want something to still work for them
			// This at least gets them images for now
			$thumbnailfile = $this->photobase . $this->util_file_is_ok($_GET['thumbnail']);
		} else {
			$thumbnailfile = $this->filecache . $thumbnailfile;
		}

		$this->util_readfile($thumbnailfile);
		exit();
	}

	/**
	 * Send any of the various resources needed for Progressive Web App
	 *
	 * The purpose of the PWA is to provide client side caching and to run the cron task in the background.
	 */
	private function send_pwa() {
		if ( $_GET['pwa'] == 'manifest' ) {
			$base_url = $this->siteurl;
			$manifest = array(
				'id' => $base_url,
				'name' => 'Fastback',
				'short_name' => $this->sitetitle,
				'description' => 'Fastback Photo Gallery for ' . $this->sitetitle,
				'icons' => array(),
				'start_url' => $base_url,
				'display' => 'standalone',
				'theme_color' => '#8888ff',
				'background_color' => '#8888ff',
				'scope' => $base_url,
				'orientatin' => 'any',
			);


			$sizes = array( '49', '72', '96', '144', '168', '192', '256', '512');

			foreach($sizes as $size){
				$manifest['icons'][] = array(
						'src' => "fastback/img/icons/$size.png",
						'sizes' => "{$size}x{$size}",
						'type' => 'image/png',
						'purpose' => 'any maskable'
					);
			}

			header("Content-Type: application/manifest+json");
			header("Content-Disposition: inline; filename=\"manifest.json\"");
			print json_encode($manifest,JSON_UNESCAPED_SLASHES);
			exit();
		} else if ( $_GET['pwa'] == 'sw' ) {
			header("Content-Type: application/javascript; charset=UTF-8");
			header("Content-Transfer-Encoding: Binary");
			header("Content-Disposition: inline; filename=\"fastback-sw.js\"");
			$sw = file_get_contents(__DIR__ . '/js/fastback-sw.js');
			$sw = str_replace('SW_FASTBACK_BASEURL',$this->siteurl,$sw);
			$sw = str_replace('SW_FASTBACK_TS',filemtime(__DIR__ . '/js/fastback-sw.js'),$sw);
			print($sw);
			exit();
		} else if ( $_GET['pwa'] == 'down' ) {
			$html = '<!doctype html>
			<html lang="en">
			<head>
			<meta charset="utf-8">
			<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
			<title>' . htmlspecialchars($this->sitetitle) . '</title>
			<link rel="manifest" href="?pwa=manifest">	
			<link rel="shortcut icon" href="fastback/img/favicon.png"> 
			<link rel="apple-touch-icon" href="fastback/img/favicon.png">
			<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">';

		$html .= '<link rel="stylesheet" href="fastback/css/jquery-ui.min.css">
			<link rel="stylesheet" href="fastback/css/leaflet.css"/>
			<link rel="stylesheet" href="fastback/css/MarkerCluster.Default.css"/>
			<link rel="stylesheet" href="fastback/css/MarkerCluster.css"/>
			<link rel="stylesheet" href="fastback/css/fastback.css?ts=' . ($this->debug ? 'debug' : filemtime(__DIR__ . '/css/fastback.css')) . '">
			</head>';

			$html .= '<body><div id="offline"><p>If you\'re seeing this, then ' . $this->siteurl . ' isn\'t accessable. Are you offline?? </p><p>The site could also be down, blocked for some reason, or the site could be an IPV6 site and you\'re on an IPV4 only network.</p></div>';

			$html .= '<script>

				// Refresh again after 10 seconds, and hope the site is back up. 
				setTimeout(function(){
					window.location=window.location;
				},10000);

				if("serviceWorker" in navigator) {
					navigator.serviceWorker.register("' . $this->siteurl . '?pwa=sw", { scope: "' . $this->siteurl . '" });
				}';

			$html .= '</script>';
			$html .= '</body</html>';
			print($html);
		} else if ( $_GET['pwa'] == 'test' ) {
			header("Content-Type: application/json");
			header("Cache-Control: no-cache");
			print(json_encode(array('status' => 'OK')));
		}
	}
	
	/**
	 * Set the flag field in the database to 1 for a specified file. 
	 *
	 * Flagged files are hidden the next time make_csv is run
	 */
	private function action_flag_photo(){
		if (!empty($this->canflag) && !empty($_SESSION['user']) && in_array($_SESSION['user'],$this->canflag)){
			$file = SQLite3::escapeString($_GET['flag']);
			$row = $this->sql_query_single("UPDATE fastback SET flagged=1 WHERE file='$file' RETURNING file,flagged",true);
		} else {
			$row = array('error' => 'access denied');
			http_response_code(403);
		}

		header("Content-Type: application/json");
		header("Cache-Control: no-cache");
		print json_encode($row);
	}

	/**
	 * Connect to sqlite, setting $this->sql
	 */
	private function sql_connect(){
		$this->_sql_counter++;
		if ( isset($this->_sql) ) {
			return $this->_sql;
		}

		if ( !file_exists($this->sqlitefile) ) {
			try {
				$this->_sql = new SQLite3($this->sqlitefile);
				$this->_sql->busyTimeout($this->_sqlite_timeout * 1001);
				$this->sql_setup_db();
			} catch (Exception $e) {
				$this->log($e->getMessage());
				die("Fastback setup error. See error log.");
			}
		} else {
			$this->_sql = new SQLite3($this->sqlitefile);
			$this->_sql->busyTimeout($this->_sqlite_timeout * 1000);
		}
	}

	/**
	 * Initialize the database
	 */
	private function sql_setup_db() {
		$q_create_meta = "CREATE TABLE IF NOT EXISTS cron ( 
			job VARCHAR(255) PRIMARY KEY, 
			updated INTEGER,
			last_completed INTEGER DEFAULT NULL, -- Set to 1 once it has last_completed at least once
			due_to_run BOOL DEFAULT 1, -- Set to 0 each time it completes, and then cleared when the completion is stale
			owner TEXT, -- pid of the process running it
			meta TEXT
		)";
		$res = $this->_sql->query($q_create_meta);

		$q_create_files = "CREATE TABLE IF NOT EXISTS fastback ( 
			file TEXT PRIMARY KEY, 
			exif TEXT,
			isvideo BOOL, 
			streamable_made BOOL, 
			flagged BOOL, 
			mtime INTEGER, 
			sorttime DATETIME, 
			thumbnail TEXT, 
			lat DECIMAL(15,10),
			lon DECIMAL(15,10), 
			elev DECIMAL(15,10), 
			nullgeom BOOL,
			_util TEXT,
			maybe_meme INT,
			share_key VARCHAR(32),
			tags TEXT
		)";

		$res = $this->_sql->query($q_create_files);
	}

	/**
	 * Close the sqlite connection, if one exists.
	 *
	 * Log the last 5 error messages.
	 */
	private function sql_disconnect(){
		$this->_sql_counter--;

		if ($this->_sql_counter < 0) {
			$this->log("How did sql_counter go negative?");
			$this->_sql_counter = 0;
			return;
		} else if ($this->_sql_counter > 0) {
			// Don't disconnect yet.
			return;
		}

		if ( !isset($this->sql) ) {
			return;
		}

		$max = 5;
		while ( $err = $this->_sql->lastErrorMsg() && $max--) {
			if ( $err != "1" && $err != "not an error") {
				break;
			}
			$this->log("SQL error: $err");
		}
		$this->_sql->close();
		unset($this->sql);
	}

	private function sql_query_single($query,$entireRow = false) {
		$this->sql_connect();
		$res = $this->_sql->querySingle($query,$entireRow);
		$err = $this->_sql->lastErrorMsg();
		if ( $err != "1" && $err != "not an error") {
			$this->log("SQL error: $err");
			$this->log($query);
		}
		
		$this->sql_disconnect();
		return $res;
	}

	/**
	 * Do an upsert to reserve some items from the queue in a consistant way. 
	 * The queue is used on the cli when forking multiple processes to process a request.
	 * We will process media in the same order as we would put them into the CSV, this makes 
	 * it so someone who is simply scroling down has the highest liklihod of having thumbnails etc.
	 */
	private function sql_get_queue($where) {
		$this->sql_connect();
		$this->_sql->query("UPDATE fastback SET _util=NULL WHERE _util='RESERVED-" . getmypid() . "'");
		$query = "UPDATE fastback 
			SET _util='RESERVED-" . getmypid() . "' 
			WHERE 
				_util IS NULL
				AND file != \"\"
				AND flagged IS NOT TRUE 
				AND ($where) 
			ORDER BY 
			COALESCE(CAST(STRFTIME('%s',sorttime) AS INTEGER),mtime) {$this->sortorder},
			file {$this->sortorder} 
			LIMIT {$this->_process_limit}";
		$this->_sql->query($query);

		$err = $this->_sql->lastErrorMsg();
		if ( $err != "1" && $err != "not an error") {
			$this->log("SQL error: $err");
			$this->log($query);
		}

		$query = "SELECT * FROM fastback WHERE _util='RESERVED-" . getmypid() . "'";
		$res = $this->_sql->query($query);

		$queue = array();

		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$queue[$row['file']] = $row;
		}
		$this->sql_disconnect();

		return $queue;
	}

	/*
	 * Update a bunch of rows at once using a CASE WHEN statement
	 */
	private function sql_update_case_when($update_q,$ar,$else,$escape_val = False) {
		if ( empty($ar) ) {
			return;
		}

		foreach($ar as $file => $val){
			if ( $escape_val ) {
				$update_q .= " WHEN file='" . SQLite3::escapeString($file) . "' THEN '" . SQLite3::escapeString($val) . "'\n";
			} else {
				$update_q .= " WHEN file='" . SQLite3::escapeString($file) . "' THEN " . $val . "\n";
			}
		}
		$update_q .= " " . $else;
		$update_q .= " WHERE _util='RESERVED-" . getmypid() . "'";
		$this->sql_connect();
		$res = $this->sql_query_single($update_q);
		$this->sql_disconnect();
	}

	/**
	 * Do cron upserts
	 */
	private function sql_update_cron_status($job,$complete = false,$meta=false) {
		$the_time = time();
		$owner = ( $complete ? 'NULL' : "'" . getmypid() . "'");

		if ( $complete ) {
			$complete_val = $the_time;
			$due_to_run = 0; // Completed, then mark not due
		} else {
			$complete_val = $this->sql_query_single("SELECT last_completed FROM cron WHERE job='$job'");
			if ( empty($complete_val) ) {
				$complete_val = 'NULL';
			}
			$due_to_run = 1; // Until it is complete again, we'll keep trying
		}

		if ( $meta !== false ){
			$this->sql_query_single("INSERT INTO cron (job,updated,last_completed,due_to_run,owner,meta)
				values ('$job'," . $the_time . ",$complete_val,$due_to_run,'" . getmypid() . "','" . SQLite3::escapeString($meta) . "')
				ON CONFLICT(job) DO UPDATE SET updated=$the_time,last_completed=$complete_val,due_to_run=$due_to_run,owner=$owner,meta='" . SQLite3::escapeString($meta). "'");
		} else {
			$this->sql_query_single("INSERT INTO cron (job,updated,last_completed,due_to_run,owner)
				values ('$job',$the_time,$complete_val,$due_to_run,'" . getmypid() . "')
				ON CONFLICT(job) DO UPDATE SET updated=$the_time,last_completed=$complete_val,due_to_run=$due_to_run,owner=$owner");
		}

		if ( $complete ) {
			$this->log("Cron job $job was marked as complete");
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

		if ( !empty($_GET['cron']) && $_GET['cron'] == 'status' ) {
			return $this->cron_status();
		}

		/*
		 * Start a buffer and prep to run something in the background
		 * CLI doesn't get a time limit or a buffer
		 */
		if (php_sapi_name() !== 'cli') {
			ob_start();
			header("Connection: close");
			header("Content-Encoding: none");
			header("Content-Type: application/json");
			set_time_limit($this->_crontimeout);
		}

		register_shutdown_function(function(){
			$this->sql_query_single("UPDATE cron SET owner=NULL WHERE owner='" . getmypid() . "'");
		});

		if ( !isset($this->_concurrent_cronjobs) ) {
			$this->_concurrent_cronjobs = ceil(`nproc`/4);
		}

		$cron_status = array();

		// Everything can run at least every _cron_min_interval minutes. 
		$this->sql_query_single("UPDATE cron SET due_to_run=1 WHERE updated < " . (time() - $this->_cron_min_interval * 60)); 

		/*
		 * Get the current cron status
		 */
		$q_get_cron = "SELECT job,updated,last_completed,due_to_run,owner FROM cron";
		$res = $this->_sql->query($q_get_cron);
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
		$jobs_running = $this->sql_query_single("SELECT COUNT(*) FROM cron WHERE owner IS NOT NULL");
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

		if (php_sapi_name() !== 'cli') {

			$res = array(
				'queue' => $jobs_to_run,
				'status' => $this->cron_status(true)
			);
			print(json_encode($res));

			// http1 and non-fcgi implementations
			$old_val = ini_set('catch_workers_output','yes'); // Without this our error_log calls don't get sent to the error log. 
			$size = ob_get_length();
			header("Content-Length: $size");
			http_response_code(200);
			ob_end_flush();
			@ob_flush();
			flush();

			// http2
			@fastcgi_finish_request();
		}

		$this->cron_status();
		$this->log("Cron Queue is: " . implode(', ',$jobs_to_run));
		foreach($jobs_to_run as $job) {
			$this->log("Running job $job");
			$job = 'cron_' . $job;
			$this->$job();
			$this->log("Job complete!");
		}
	}

	/**
	 * Get all modified files into the db cache
	 *
	 * Because we are reading from the file system this must complete 100% or not at all. We don't have a good way to crawl only part of the fs at the moment.
	 */
	private function cron_find_new_files() {
		$this->sql_update_cron_status('find_new_files');

		$lastmod = '19000102';
		$res = $this->sql_query_single("SELECT meta FROM cron WHERE job='find_new_files'");
		if ( !empty($res) ) {
			$lastmod = date('Ymd',$res);
		}

		$origdir = getcwd();
		chdir($this->photobase);
		$filetypes = implode('\|',array_merge($this->supported_photo_types, $this->supported_video_types));
		$cmd = 'find -L . -type f -regextype sed -iregex  "' . $this->photodirregex . '.*\(' . $filetypes . '\)$" -newerat ' . $lastmod . ' | grep -v "./fastback/"';

		$modified_files_str = `$cmd`;

		if (  !is_null($modified_files_str) && strlen(trim($modified_files_str)) > 0) {
			$modified_files = explode("\n",$modified_files_str);
			$modified_files = array_filter($modified_files);

			$today = date('Ymd');
			$multi_insert = "INSERT INTO fastback (file,mtime,isvideo,share_key) VALUES ";
			$multi_insert_tail = " ON CONFLICT(file) DO UPDATE SET isvideo=";
			$collect_photo = array();
			$collect_video = array();
			foreach($modified_files as $k => $one_file){
				$mtime = filemtime($one_file);
				$pathinfo = pathinfo($one_file);

				if ( empty($pathinfo['extension']) ) {
					$this->log(print_r($pathinfo,TRUE));
					continue;
				}

				if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {
					$collect_video[] = "('" .  SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($mtime) .  "',1,'" . md5($one_file) . "')";
				} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_photo_types) ) {
					$collect_photo[] = "('" .  SQLite3::escapeString($one_file) . "','" .  SQLite3::escapeString($mtime) .  "',0,'" . md5($one_file) . "')";
				} else {
					$this->log("Don't know what to do with " . print_r($pathinfo,true));
				}

				if ( count($collect_photo) >= $this->_upsert_limit) {
					$sql = $multi_insert . implode(",",$collect_photo) . $multi_insert_tail . '0';
					$this->sql_query_single($sql);
					$this->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we found files, we need to make csv
					$this->sql_update_cron_status('find_new_files');
					$collect_photo = array();
				}

				if ( count($collect_video) >= $this->_upsert_limit) {
					$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
					$this->sql_query_single($sql);
					$this->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we found files, we need to make csv
					$this->sql_update_cron_status('find_new_files');
					$collect_video = array();
				}
			}

			if ( count($collect_photo) > 0 ) {
				$sql = $multi_insert . implode(",",$collect_photo) . $multi_insert_tail . '0';
				$this->sql_query_single($sql);
				$this->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we found files, we need to make csv
				$this->sql_update_cron_status('find_new_files');
				$collect_photo = array();
			}

			if ( count($collect_video) > 0 ) {
				$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
				$this->sql_query_single($sql);
				$this->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we found files, we need to make csv
				$this->sql_update_cron_status('find_new_files');
				$collect_video = array();
			}
		}

		$maxtime = $this->sql_query_single("SELECT MAX(mtime) AS maxtime FROM fastback");
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
		chdir($this->photobase);

		$count = $this->sql_query_single("SELECT COUNT(*) AS c FROM fastback");
		$this->log("Checking for missing files: Found {$count} files in the database");

		$this->sql_connect();
		$q = "SELECT file FROM fastback";
		$res = $this->_sql->query($q);
		$not_found = array();

		$this->_sql->query("BEGIN");
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if ( !file_exists($row['file'])){
				$this->_sql->query("DELETE FROM fastback WHERE file='" . SQLite3::escapeString($row['file']) . "'");
			}
		}
		$this->_sql->query("COMMIT");
		$this->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we removed files, we need a new csv
		$this->sql_disconnect();
		$this->sql_update_cron_status('remove_deleted',true);
	}

	/**
	 * This is the child function that gets forked to make thumbnails
	 *
	 */
	private function cron_make_thumbs() {
		$this->sql_update_cron_status('make_thumbs');
		do {
			$queue = $this->sql_get_queue("thumbnail IS NULL");

			$made_thumbs = array();
			$flag_these = array();

			foreach($queue as $file => $row) {
				$thumbnailfile = $this->_make_a_thumb($file,true);

				// If we've got the file, we're good
				if ( file_exists($this->filecache . $thumbnailfile) ) {
					$made_thumbs[$file] = $thumbnailfile;
				} else {
					$flag_these[] = $file;
				}
			}

			$this->sql_update_case_when("UPDATE fastback SET _util=NULL, thumbnail=CASE", $made_thumbs, "ELSE thumbnail END", TRUE);

			$flag_these = array_map('SQLite3::escapeString',$flag_these);
			$this->sql_query_single("UPDATE fastback SET flagged=1 WHERE file IN ('" . implode("','",$flag_these) . "')");
			$this->sql_update_cron_status('make_thumbs');

		} while (count($made_thumbs) > 0);

		$this->sql_update_cron_status('make_thumbs',true);
	}

	/**
	 * For a given file, make a thumbnail
	 *
	 * @param $print_if_not_write If we can't open the cache file, then send the csv directly.
	 *
	 * @note Using $print_if_not_write will cause this function to exit() after sending.
	 *
	 * @return the thumbnail file name or false
	 */
	private function _make_a_thumb($file,$skip_db_write=false,$print_if_not_write = false){
		// Find original file
		$thumbnailfile = $this->sql_query_single("SELECT thumbnail FROM fastback WHERE file='" . SQLite3::escapeString($file) . "'");
		if ( !empty($thumbnailfile) && file_exists($this->filecache . $thumbnailfile) ) {
			// If it exists, we're golden
			return $thumbnailfile;
		}

		$file = $this->util_file_is_ok($file);
		$print_to_stdout = false;

		$origdir = getcwd();
		$thumbnailfile = ltrim($file,'./') . '.webp';

		// Quick exit if thumb exists. Should be the default case
		if ( file_exists($this->filecache . $thumbnailfile) ) {
			if ( !$skip_db_write ) {
				$this->sql_query_single("UPDATE fastback SET thumbnail='" . SQLite3::escapeString($thumbnailfile) . "' WHERE file='" . SQLite3::escapeString($file) . "'");
			}
			return $thumbnailfile;
		}

		// Quick exit if cachedir doesn't exist. That means we can't cache.
		if ( !file_exists($this->filecache) ) {
			if ( $print_if_not_write ) {
				$print_to_stdout = true;
			} else {
				return false;
			}
		}

		// Cachedir might exist, make sure we have our subdir
		$dirname = dirname($this->filecache . $thumbnailfile);
		if (!file_exists($dirname) ){
			@mkdir($dirname,0750,TRUE);
			if ( !is_dir($dirname) ) {
				$this->log("Cache sub-dir doesn't exist and can't create it");
				if ( $print_if_not_write ) {
					$print_to_stdout = true;
				} else {
					return false;
				}
			} else {
				// When we make the dir, put an empty index.php file to prevent directory listings.
				touch($dirname . '/index.php');
			}
		}

		// The cache directory exists but the file does not. 

		// Find our tools
		if ( !isset($this->_vipsthumbnail) ) { $this->_vipsthumbnail = trim(`which vipsthumbnail`); }
		if ( !isset($this->_ffmpeg) ) { $this->_ffmpeg = trim(`which ffmpeg`); }
		if ( !isset($this->_jpegoptim) ) { $this->_jpegoptim = trim(`which jpegoptim`); }

		$pathinfo = pathinfo($file);

		if (in_array(strtolower($pathinfo['extension']),$this->supported_photo_types)){
			$res = $this->_make_image_thumb($file,$thumbnailfile,$print_to_stdout);

			if ( $res === false ) {
				$this->log("Unable to make a thumbnail for $file");
				return false;
			}

		} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {
			$res = $this->_make_video_thumb($file,$thumbnailfile,$print_to_stdout);

			if ( $res === false ) {
				$this->log("Unable to make a thumbnail for $file");
				return false;
			}
		} else {
			$this->log("What do I do with ");
			$this->log(print_r($pathinfo,TRUE));
			return false;
		}

		if ( !file_exists( $this->filecache . $thumbnailfile ) ) {
			return false;
		}

		if ( !empty($this->_jpegoptim) ) {
			$shellthumb = escapeshellarg($this->filecache . $thumbnailfile);
			$cmd = "jpegoptim --strip-all --strip-exif --strip-iptc $shellthumb";
			$res = `$cmd`;
		}

		if ( !$skip_db_write ) {
			$this->sql_query_single("UPDATE fastback SET thumbnail='" . SQLite3::escapeString($thumbnailfile) . "' WHERE file='" . SQLite3::escapeString($file) . "'");
		}

		return $thumbnailfile;
	}

	/**
	 * Make an image thumbnail
	 *
	 * @file The source file
	 * @thumbnailfile The destination file
	 * @print_to_stdout If true then we print headers and image and exit
	 */
	private function _make_image_thumb($file,$thumbnailfile,$print_to_stdout = false) {
		if ( file_exists($this->filecache . $thumbnailfile) ) {
			if ( $print_to_stdout ) {
				$this->util_readfile($this->filecache . $thumbnailfile);
				exit();
			}
			return $thumbnailfile;
		}

		$shellfile = escapeshellarg($this->photobase . $file);
		$shellthumb = escapeshellarg($this->filecache . $thumbnailfile);

		if ( !isset($this->_vipsthumbnail) ) { $this->_vipsthumbnail = trim(`which vipsthumbnail`); }
		if (!empty($this->_vipsthumbnail) ) {

			if ( $print_to_stdout ) {
				$shellthumb = '.webp';
			}

			$cmd = "{$this->_vipsthumbnail} --size={$this->_thumbsize} --output=$shellthumb --smartcrop=attention $shellfile 2>/dev/null";

			if ( $print_to_stdout ) {
				header("Content-Type: image/webp");
				header("Last-Modified: " . filemtime($this-> photobase . $file));
				header('Cache-Control: max-age=86400');
				header('Etag: ' . md5_file($this->photobase . $file));
				passthru($cmd);
				exit();
			} else {
				$res = `$cmd`;
			}

			if ( file_exists($this->filecache . $thumbnailfile) ) {
				return $thumbnailfile;
			}
		}

		if ( !isset($this->_convert) ) { $this->_convert = trim(`which convert`); }
		if ( !empty($this->_convert) ) {

			if ( $print_to_stdout ) {
				$shellthumb = 'webp:-';
			}

			$cmd = "{$this->_convert} -define jpeg:size={$this->_thumbsize} $shellfile  -thumbnail {$this->_thumbsize}^ -gravity center -extent $this->_thumbsize $shellthumb 2>/dev/null";
			$res = `$cmd`;

			if ( $print_to_stdout ) {
				header("Content-Type: image/webp");
				header("Last-Modified: " . filemtime($this->photobase . $file));
				header('Cache-Control: max-age=86400');
				header('Etag: ' . md5_file($this->photobase . $file));
				passthru($cmd);
				exit();
			} else {
				$res = `$cmd`;
			}

			if ( file_exists($thumbnailfile) ) {
				return $thumbnailfile;
			}
		}

		// looks like vips didn't work
		if (extension_loaded('gd') || function_exists('gd_info')) {
			try {
					$image_info = getimagesize($this->photobase . $file);
					switch($image_info[2]){
					case IMAGETYPE_JPEG:
						$img = imagecreatefromjpeg($this->photobase . $file);
						break;
					case IMAGETYPE_GIF:
						$img = imagecreatefromgif($this->photobase . $file);
						break;
					case IMAGETYPE_PNG:
						$img = imagecreatefrompng($this->photobase . $file);
						break;
					default:
						$img = FALSE;
					}   

					if ( $img ) {
						$thumbsize = preg_replace('/x.*/','',$this->_thumbsize);

						$width = $image_info[0];
						$height = $image_info[1];

						if ( $height > $width ) {
							$newwidth = $thumbsize;
							$newheight = floor($height / ($width / $thumbsize));
						} else if ( $width > $height ) {
							$newheight = $thumbsize;
							$newwidth = floor($width / ($height / $thumbsize));
						} else {
							$newwidth = $thumbsize;
							$newheight = $thumbsize;
						}

						$srcy = max(0,($height / 2 - $width / 2));
						$srcx = max(0,($width / 2 - $height / 2));

						$tmpimg = imagecreatetruecolor($thumbsize, $thumbsize);
						imagecopyresampled($tmpimg, $img, 0, 0, $srcx, $srcy, $newwidth, $newheight, $width, $height );
						if ( $print_to_stdout ) {
							header("Content-Type: image/webp");
							header("Last-Modified: " . filemtime($this->photobase . $file));
							header('Cache-Control: max-age=86400');
							header('Etag: ' . md5_file($this->photobase . $file));
							imagewebp($tmpimg);
						} else {
							imagewebp($tmpimg, $this->filecache . $thumbnailfile);
						}
					} else {
						$this->log("Tried GD, but image was not png/jpg/gif");
					}

					if(file_exists($this->filecache . $thumbnailfile)){
						return $thumbnailfile;
					}   
				} catch (Exception $e){
					$this->log("Caught exception while using GD to make thumbnail");
				}
		} else {
			$this->log("No GD here");
		}

		return false;
	}

	/**
	 * Make videos that are suitable for streaming. Thanks, ffmpeg!
	 */
	private function cron_make_streamable() {
		$this->sql_update_cron_status('make_streamable');
		do {
			$queue = $this->sql_get_queue("streamable_made IS NULL AND isvideo");

			foreach($queue as $file => $row) {
				// If we've got the file, we're good
				$outputfile = $file . '.mp4';
				$worked = $this->_make_video_streamable($file,$outputfile);

				$worked = $worked ? 1 : 0;

				$this->sql_query_single("UPDATE fastback SET streamable_made=$worked WHERE file='"  . SQLite3::escapeString($file) . "'");
			}

			$this->sql_update_cron_status('make_streamable');
		} while (!empty($queue));

		$this->sql_update_cron_status('make_streamable',true);
	}

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
		$shellthumb = escapeshellarg($this->filecache . $videothumb);
		$shellthumbvid = escapeshellarg($this->filecache . $videothumb);

		// find . -regextype sed -iregex  ".*.\(mp4\|mov\|avi\|dv\|3gp\|mpeg\|mpg\|ogg\|vob\|webm\).webp" -delete
		// If we're running from CLI, then use as much processor as possible since the user has direct control over the process & utilization
		// Otherwise, run on 1 thread so we don't use more than what the PHP process was going ot use anyways.
		if ( $this->_ffmpeg_streamable_threads == 'auto' ) {
			$threads = php_sapi_name() == 'cli' ? 0 : 1;
		} else {
			$threads = $this->_ffmpeg_streamable_threads;
		}
		$cmd = $this->_ffmpeg . ' -i ' . $shellfile . ' ' . $this->_ffmpeg_streamable . ' -threads ' . $threads . ' ' . $shellthumbvid . ' 2>/dev/null';
		$res = `$cmd`;

		if ( file_exists($this->filecache . $videothumb) ) {
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

			$timestamps = array('10',2,'00:00:00');

			foreach($timestamps as $timestamp) {

				$cmd = "{$this->_ffmpeg} -y -ss $timestamp -i $shellfile -vframes 1 $formatflags $tmpshellthumb 2> /dev/null";
				$res = `$cmd`;

				if ( $print_to_stdout && $res !== NULL) {
					header("Content-Type: image/webp");
					header("Last-Modified: " . filemtime($file));
					header('Cache-Control: max-age=86400');
					header('Etag: ' . md5_file($file));
					print($res); // Can't use passthru because we need to check if the command worked
					exit();
				}

				if ( file_exists($tmpthumb) && filesize($tmpthumb) > 0 ) {
					break;
				}
			}

			clearstatcache();

			if ( file_exists($tmpthumb) && filesize($tmpthumb) !== 0) {

				if ( !isset($this->_vipsthumbnail) ) { $this->_vipsthumbnail = trim(`which vipsthumbnail`); }

				if ( !empty($this->_vipsthumbnail) ) {
					$cmd = "$this->_vipsthumbnail --size={$this->_thumbsize} --output=$shellthumb --smartcrop=attention $tmpshellthumb";
					$res = `$cmd`;
					unlink($tmpthumb);
				} else {
					@rename($tmpthumb,$this->filecache . $thumbnailfile);
				}
			}

			if ( file_exists($this->filecache . $thumbnailfile) ) {
				return $thumbnailfile;
			}

			return false;
	}

	/**
	 * Try to give an image a score of how likely it is to be a meme, based on some factors that seemed relavant for my photos.
	 */
	private function _process_exif_meme($exif,$file) {
		$bad_filetypes = array('MacOS','WEBP');
		$bad_mimetypes = array('application/unknown','image/png');
		$maybe_meme = 0;

		// Bad filetype  or Mimetype
		// "FileType":"MacOS"
		// FileType WEBP
		// "MIMEType":"application\/unknown"
		if ( array_key_exists('FileType',$exif) && in_array($exif['FileType'],$bad_filetypes) ) {
			$maybe_meme += 1;
		}

		if ( array_key_exists('MIMEType',$exif) && in_array($exif['MIMEType'],$bad_mimetypes) ) {
			$maybe_meme += 1;
		} else if ( array_key_exists('MIMEType',$exif) && preg_match('/video/',$exif['MIMEType']) ) {
			// Most videos aren't memes
			$maybe_meme -= 1;
		}

		//  Error present
		// "Error":"File format error"
		if ( array_key_exists('Error',$exif) ) {
			$maybe_meme +=1 ;
		}

		// If there's no real exif info
		// Unsure how to detect and how to account for scanned images

		// IF the image is too small
		// "ImageWidth":"2592",
		// "ImageHeight":"1944",
		if ( array_key_exists('ImageHeight',$exif) && array_key_exists('ImageWidth',$exif) ) {
			if ( $exif['ImageHeight'] * $exif['ImageWidth'] <  804864 ) { // Less than 1024x768
				$maybe_meme += 1;
			}
		}

		$exif_keys = array_filter($exif,function($k){
			return strpos($k,"Exif") === 0;
		},ARRAY_FILTER_USE_KEY);
		if ( count($exif_keys) <= 4 ) {
			$maybe_meme += 1;
		}

		if ( count($exif_keys) === 1 ) {
			$maybe_meme -= 1;

		}

		// Having GPS is good
		if ( array_key_exists('GPSLatitude',$exif) ) {
			$maybe_meme -= 1;
		}

		// Having a camera name is good
		if ( array_key_exists('Model',$exif) ) {
			$maybe_meme -= 1;
		} else 

		// Not having a camera is extra bad in 2020s
		if ( preg_match('/^202[0-9]:/',$exif['FileModifyDate']) && !array_key_exists('Model',$exif) ) {
			$maybe_meme += 1;
		}

		// Scanners might put a comment in 
		if ( array_key_exists('Comment',$exif) ) {
			$maybe_meme -= 1;
		}

		// Scanners might put a comment in 
		if ( array_key_exists('UserComment',$exif) && $exif['UserComment'] == 'Screenshot' ) {
			$maybe_meme += 2;
		}

		if ( array_key_exists('Software',$exif) && $exif['Software'] == 'Instagram' ) {
			$maybe_meme += 1;
		}

		if ( array_key_exists('ThumbnailImage',$exif) ) {
			$maybe_meme -= 1;
		}

		if ( array_key_exists('ProfileDescriptionML',$exif) ) {
			$maybe_meme -= 1;
		}

		// Luminance seems to maybe be something in some of our photos that aren't memes?
		if ( array_key_exists('Luminance',$exif) ) {
			$maybe_meme -= 1;
		}

		if ( array_key_exists('TagsList',$exif) ) {
			$maybe_meme -= 1;
		}

		if ( array_key_exists('Subject',$exif) ) {
			$maybe_meme -= 1;
		}

		if ( array_key_exists('DeviceMfgDesc',$exif) ) {
			$maybe_meme -= 1;
		}

		return array('maybe_meme' => $maybe_meme);
	}

	/**
	 * Get exif data for files that don't have it.
	 */
	private function cron_get_exif() {
		$this->sql_update_cron_status('get_exif');
		$cmd = "exiftool -stay_open True  -@ -";
		$cmdargs = [];
		$cmdargs[] = "-lang"; // Lang to english
		$cmdargs[] = "en"; // Lang to english
		$cmdargs[] = "-a"; // Allow duplicate tags
		$cmdargs[] = "-s"; // Tag names instead of descriptions
		$cmdargs[] = "-c"; // Set format for GPS numbers
		$cmdargs[] = "%.5f"; // Set format for GPS numbers
		$cmdargs[] = "-extractEmbedded"; // get embedded data like geo data
		$cmdargs[] = "-e"; // Don't generate composite tags
		// TODO: Extract with -G1 and -json formats to capture everything?
		$cmdargs = implode("\n",$cmdargs);

		$descriptors = array(
			0 => array("pipe", "r"),  // STDIN
			1 => array("pipe", "w"),  // STDOUT
			2 => array("pipe", "w")   // STDERR
		);

		$proc = proc_open($cmd, $descriptors, $pipes,$this->photobase);

		register_shutdown_function(function() use ($proc,$pipes) {
				$this->log("About to close exif process");
				if (is_resource($pipes[0])) {
					fputs($pipes[0], "-stay_open\nFalse\n");
					fflush($pipes[0]);
					fclose($pipes[0]);
					fclose($pipes[1]);
					fclose($pipes[2]);
				}

				if ( is_resource($proc) ) {
					proc_close($proc);
				}
		});

		// Don't block on STDERR
		stream_set_blocking($pipes[1], 0);
		stream_set_blocking($pipes[2], 0);

		do {
			$queue = $this->sql_get_queue("exif IS NULL");

			$found_exif = array();

			foreach($queue as $file => $row) {
				$cur_exif = $this->_read_one_exif($file,$cmdargs,$proc,$pipes);
				$found_exif[$file] = json_encode($cur_exif,JSON_FORCE_OBJECT | JSON_PARTIAL_OUTPUT_ON_ERROR);
			}

			$this->sql_update_case_when("UPDATE fastback SET _util=NULL, exif=CASE",$found_exif,"ELSE exif END",True);
			$this->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'process_exif'"); // If we found exif, then we need to process it.
			$this->sql_update_cron_status('get_exif');

		} while (!empty($queue) );

		fputs($pipes[0], "-stay_open\nFalse\n");
		fflush($pipes[0]);
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);

		$this->sql_update_cron_status('get_exif',true);
	}

	/**
	 * Look for rows that haven't had their exif data processed and handle them.
	 */
	private function cron_process_exif() {
		$this->sql_update_cron_status('process_exif');
		do {
			$queue = $this->sql_get_queue("
			(

				-- Needs tags
				(tags IS NULL
				AND (
					exif GLOB '*\"Subject\"*'
				OR exif GLOB '*\"XPKeywords\"*'
				OR exif GLOB '*\"Categories\"*'
				OR exif GLOB '*\"TagsList\"*'
				OR exif GLOB '*\"LastKeywordXMP\"*'
				OR exif GLOB '*\"HierarchicalSubject\"*'
				OR exif GLOB '*\"CatalogSets\"*'
				OR exif GLOB '*\"Keywords\"*'
				)) 
				OR
				-- Needs Geo
				(
					lat IS NULL 
					AND lon IS NULL 
					AND elev IS NULL 
					AND nullgeom IS NOT TRUE 
					AND (exif->'GPSPosition' IS NOT NULL OR exif->'GPSLatitude' IS NOT NULL)
				)
				OR 
				-- Needs time
				(
					sorttime IS NULL
				)		
				OR
				-- Meme status is null
				(
				maybe_meme IS NULL
				)
			) AND (
				exif IS NOT NULL
				AND exif != \"\"
			)");

			$this->_sql->query("BEGIN DEFERRED");

			if ( count($queue) == 0 ) {
				$this->log("Empty queue");
			}

			foreach($queue as $row) {
				$exif = json_decode($row['exif'],true);

				if ( !is_array($exif) ) {
					ob_start();
					var_dump($row);
					$content = ob_get_contents();
					file_put_contents(__DIR__ . '/cache/row',$content);
					ob_end_clean();
					$this->log("Non array exif value found");
					$this->log($row['exif']);
					die("ASDF");
				}

				$tags = $this->_process_exif_tags($exif,$row['file']);
				$geo = $this->_process_exif_geo($exif,$row['file']);
				$time = $this->_process_exif_time($exif,$row['file']);
				$meme = $this->_process_exif_meme($exif,$row['file']);

				$found_vals = array_merge($tags,$geo,$time,$meme);
				$q = "UPDATE fastback SET ";
				foreach($found_vals as $k => $v){
					if ( is_null($v) ) {
						$q .= "$k=NULL, ";
					} else {
						$q .= "$k='" . SQLite3::escapeString($v). "', ";
					}
				}
				$q .= "file=file WHERE file='" . SQLite3::escapeString($row['file']) . "'";

				$this->_sql->query($q);
			}
			$this->_sql->query("COMMIT");
			$this->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we updated exif, we need a new csv
			$this->sql_update_cron_status('process_exif');

		} while ( count($queue) > 0 );
		$this->sql_update_cron_status('process_exif',true);
	}

	/**
	 * Clear all locks. These can happen if jobs timeout or something.
	 */
	private function cron_clear_locks() {
		$this->sql_update_cron_status('clear_locks');
		// Clear reserved things once in a while.  May cause some double processing but also makes it possible to reprocess things that didn't work the first time.
		$this->sql_query_single("UPDATE fastback SET _util=NULL WHERE _util LIKE 'RESERVED%'");
		// Also clear owner of any cron entries which have been idle for 3x the timeout period.
		$this->sql_query_single("UPDATE cron SET owner=NULL WHERE updated < " . (time() - (60 * $this->_crontimeout * 3)));
		$this->sql_update_cron_status('clear_locks',true);
	}

	/**
	 * Update the CSV file
	 */
	private function cron_make_csv(){
		$this->sql_update_cron_status('make_csv');
		// A change to the sqlite or this file could indicate the need for a new csv. 
		// With the cron jobs being busy in the sqlite file that's not completely accurate, but it's the best easy thing.

		if ( !file_exists($this->csvfile) || filemtime($this->sqlitefile) - filemtime($this->csvfile) > 0 || filemtime(__FILE__) -  filemtime($this->csvfile) > 0) {
			$wrote = $this->util_make_csv();
			if ( $wrote ) {
				$this->sql_update_cron_status('make_csv',true);
			}
		}
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

		$allowed_actions = array('find_new_files','make_csv','process_exif','get_exif','make_thumbs','make_streamable','remove_deleted','clear_locks','status');
		foreach($allowed_actions as $job){
			$cron_status[$job] = $template;
			$cron_status[$job]['job'] = $job;
		}

		$this->sql_connect();
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
		$res = $this->_sql->query($q_get_cron);

		if ( empty($res) ) {
			$cron_status['queue'] = array();
			header("Content-Type: application/json");
			print json_encode($cron_status);
		}

		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$cron_status[$row['job']] = array_merge($cron_status[$row['job']],$row);
		}
		foreach($cron_status as $job => $details){
			if ( !in_array($job,$this->cronjobs) ) {
				$cron_status[$job]['status'] = 'Disabled';
			}
		}

		// Calculate how much of each job is done.
		$total_rows = $this->sql_query_single("SELECT COUNT(*) FROM fastback");
		$exif_rows = $this->sql_query_single("SELECT COUNT(*) FROM fastback WHERE flagged or exif IS NOT NULL");
		$video_rows = $this->sql_query_single("SELECT COUNT(*) FROM fastback WHERE isvideo=1 AND flagged IS NULL");

		$cron_status['get_exif']['percent_complete'] = $total_rows > 0 ? round( $exif_rows / $total_rows,4) * 100 . '%' : '0%';
		$cron_status['make_thumbs']['percent_complete'] = $total_rows > 0 ? round($this->sql_query_single("SELECT COUNT(*) FROM FASTBACK WHERE flagged OR thumbnail IS NOT NULL") / $total_rows,4) * 100 . '%' : '0%';
		$cron_status['make_streamable']['percent_complete'] = $video_rows > 0 ? round($this->sql_query_single("SELECT COUNT(*) FROM FASTBACK WHERE streamable_made=1") / $video_rows,4) * 100 . '%' : '0%';
		$cron_status['process_exif']['percent_complete'] = $exif_rows > 0 ? round($this->sql_query_single("SELECT COUNT(*) FROM FASTBACK WHERE flagged OR sorttime IS NOT NULL") / $exif_rows,4) * 100 . '%' : '0%';

		$all_or_nothing = array('remove_deleted','find_new_files','make_csv','clear_locks','status');
		foreach($all_or_nothing as $job_name) {
			$cron_status[$job_name]['percent_complete'] = ($cron_status[$job_name]['last_completed'] == 'Task not complete' ? '0%' : '100%');
		}

		$this->sql_update_cron_status('status',true);

		if (!$return) {

			// Pretty print for cli
			if (php_sapi_name() == 'cli') {

				if ( count($cron_status) == 0 ) {
					print("No cron info found");
				}

				$cols = array();

				foreach($cron_status as $job => $status){
					foreach($status as $st => $val) {
						if ( empty($cols[$st]) ) {
							$cols[$st] = strlen($st);
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
							print(str_pad($status[$col],$len,' ',$dir) . ' | ');
						} else {
							print(str_pad('',$len) . ' | ');
						}
					}
					print("\n");
				}


			} else {
				header("Content-Type: application/json");
				print json_encode($cron_status, JSON_PRETTY_PRINT);
			}
		} else {
			return $cron_status;
		}
	}

	/**
	 * Read the exif info for one file. This includes the accompanying xmp file 
	 */
	private function _read_one_exif($file,$cmdargs,$proc,$pipes) {
		$files_and_sidecars = array($file);

		// For the requested file and any xmp sidecars...
		if ( file_exists($this->photobase . "$file.xmp") ) {
			$files_and_sidecars[] = $this->photobase . "$file.xmp";
		}

		$cur_exif = array();
		foreach($files_and_sidecars as $exiftarget) {

			// Run the exiftool command
			fputs($pipes[0],$cmdargs . "\n");
			fputs($pipes[0],$exiftarget. "\n");
			fputs($pipes[0],"-execute\n");
			fflush($pipes[0]);

			$end_of_exif = FALSE;

			// Collect all exiftool output
			$exifout = "";
			while(!$end_of_exif){
				// Handle stdout. Build a single chunk of text then split it since some tag output seems to get split in some cases
				$line = fgets($pipes[1]);	
				if ($line == FALSE ) {
					continue;
				}

				$exifout .= $line;

				if (preg_match('/.*{ready}$/',$line)){
					$end_of_exif = TRUE;

					$exifout = preg_replace('/\n\s*{ready}\s*/','',$exifout);
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
			$exifout = explode("\n",$exifout);

			// Process the output
			foreach($exifout as $line) {
				$line = trim($line);

				if ( preg_match('/^======== (.*)/',$line, $matches ) ) {

					if ($matches[1] != $exiftarget) {
						$this->log("Expected '$exiftarget', got '$matches[1]'");
						die("Somethings broken");
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
					// die("Quitting for fun");
				}
			}
		}

		// Drop any empty lines
		$cur_exif = array_filter($cur_exif);
		// Sort it for ease of use
		ksort($cur_exif);

		return $cur_exif;
	}

	/*
	 * Find people tags in exif data
	 */
	private function _process_exif_tags($exif,$file){
			$simple = array('Subject','XPKeywords','Keywords','RegionName','RegionPersonDisplayName','CatalogSets','HierarchicalSubject','LastKeywordXMP','TagsList');

			// Clean up values
			$people_found = array();
			foreach($simple as $exif_keyword) {
				if ( !empty($exif[$exif_keyword]) ) {
					$sub = str_replace(" 'who'",'',$exif[$exif_keyword]);
					$sub = str_replace("People|","",$sub);
					$sub = str_replace("People/","",$sub);
					$sub = str_replace('People\/',"",$sub);
					$sub = str_replace('(none)',"",$sub);
					$sub = str_replace('(people)',"",$sub);
					$sub = str_replace(';',",",$sub);
					$sub = str_replace('|',",",$sub);
					$sub = str_replace('"',"",$sub);
					$sub = str_replace("'","",$sub);
					$subsplit = explode(",",$sub);
					foreach($subsplit as $person) {
						$people_found[] = trim($person);
					}
				}
			}

			$people_found = array_unique($people_found);
			$people_found = array_filter($people_found);
			$people_found = array_diff($people_found,$this->ignore_tag);
			$ret = array('tags' => implode('|',$people_found));
			return $ret;
	}

	/**
	 * Find geo info in exif data
	 */
	private function _process_exif_geo($exif,$file) {
		$xyz = array('lat' => NULL, 'lon' => NULL, 'elev' => 0);
		if ( array_key_exists('GPSPosition',$exif) ) {
			// eg "38.741200 N, 90.642800 W"
			$xyz = $this->_parse_gps_line($exif['GPSPosition']);	
		}

		if ( $xyz['lat'] === NULL && array_key_exists('GPSCoordinates',$exif) ) {
			// eg "38.741200 N, 90.642800 W"
			$xyz = $this->_parse_gps_line($exif['GPSCoordinates']);	
		}

		if ( $xyz['lat'] === NULL && array_key_exists('GPSLatitude',$exif) && array_key_exists('GPSLongitude',$exif)) {
			$lonval = floatval($exif['GPSLongitude']);
			$latval = floatval($exif['GPSLatitude']);

			if ( preg_match("/^['.0-9]+\s+S/",$exif['GPSLatitude']) ||  $exif['GPSLatitudeRef'] == 'South' || $exif['GPSLatitudeRef'] == 'S') {
				$latval = $latval * -1;
			}

			if ( preg_match("/^['.0-9]+\s+W/",$exif['GPSLongitude']) ||  $exif['GPSLongitudeRef'] == 'West' || $exif['GPSLongitudeRef'] == 'W') {
				$lonval = $lonval * -1;
			}

			$xyz['lon'] = $lonval;
			$xyz['lat'] = $latval;
		}

		if ( $xyz['elev'] === 0 ) {
		   if ( array_key_exists('GPSAltitude',$exif) ) {
			if ( preg_match('/([0-9.]+) m/',$exif['GPSAltitude'],$matches ) ) {
				if ( array_key_exists('GPSAltitudeRef',$exif) && $exif['GPSAltitudeRef'] == 'Below Sea Level' ) {
					$xyz['elev'] = $matches[1] * -1;
				} else {
					$xyz['elev'] = $matches[1];
				}
			} else if ( $exif['GPSAltitude'] == 'undef') {
				$xyz['elev'] = 0;
			} else {
				$this->log("New type of altitude value found: {$exif['GPSAltitude']} in $file");
				$xyz['elev'] = 0;
			}
			} else {
				$xyz['elev'] = 0;
			}
		}

		if ( $xyz['lat'] === NULL ) {
			$xyz['nullgeom'] = 1;
		} else {
			$xyz['nullgeom'] = 0;
		}

		return $xyz;
	}

	/**
	 * For a single-line GPS record, parse out the lat/lon
	 */
	private function _parse_gps_line($line) {
		$xyz = array('lat' => NULL,'lon' => NULL,'elev' => 0);

		if ( trim($line) == '0.00000 N, 0.00000 E' ) {
			return $xyz;
		}

		// 22.97400 S, 43.18910 W, 6.707 m Above Sea Level
		preg_match('/\'?([0-9.]+)\'? (N|S), \'?([0-9.]+)\'? (E|W), \'?([0-9.]+)\'? m .*/',$line,$matches);
		if ( count($matches) == 6) {

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

			$xyz['lon'] = $matches[3];
			$xyz['lat'] = $matches[1];
			$xyz['elev'] = $matches[5];
		} else  {
		// eg "38.741200 N, 90.642800 W"
			preg_match('/\'?([0-9.]+)\'? (N|S), \'?([0-9.]+)\'? (E|W)/',$line,$matches);
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

				$xyz['lon'] = $matches[3];
				$xyz['lat'] = $matches[1];
			} else {
				$this->log("Couldn't parse >>$line<<");
			}
		}
		return $xyz;
	}

	/**
	 * Find file time in exif data
	 */
	private function _process_exif_time($exif,$file) {
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

		// If the user has put the file in a date directory (YYYY/MM/DD) then use that as the date
		// otherwise, fall back on meta data
		if ( preg_match('|.*\/([0-9]{4})/([0-9]{2})/([0-9]{2})/[^\/]*|',$file)) {
			$datepart = preg_replace('|.*\/([0-9]{4})/([0-9]{2})/([0-9]{2})/[^\/]*|','\1-\2-\3',$file);
		} else {
			$datepart = NULL;
		}

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
				return array('sorttime' => $datepart . " " . $matches[4] . ':' . $matches[5] . ':' . $matches[6]);
			} else {
				return array('sorttime' => $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6]);
			}
		}

		if ( !is_null($datepart ) ) {
			return array('sorttime' => $datepart . ' 00:00:00');
		}

		return array('sorttime' => NULL);;
	}
}
