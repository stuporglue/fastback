<?php

require_once(__DIR__ . '/modules/photos.php');
require_once(__DIR__ . '/modules/videos.php');
require_once(__DIR__ . '/cron.php');

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
	 * Core functionality
	 * ------------------
	 * Maintaining the database
	 * Finding files
	 * Providing cron routines
	 * Handle HTTP requests & serving website
	 * Handle authentication
	 * Managing SQL connection
	 * Manage cache
	 * Relay requests to modules
	 * Register modules
	 * Find files
	 * Provide debugging facilities
	 */

	/*
	 * Module functionality - Each media type will have a module (photos, videos, PDFs, HTML, txt, DOCX, etc.)
	 * --------------------
	 *  Thumbnail files
	 *  Read metadata
	 *  Make Browser friendly version
	 */
	var $modules = array();

	/**
	 * This is going to be a singleton because of how we will add support for multiple file formats
	 */
	private static $_instance;
	protected function __construct() { }

	public static function getInstance() {
		$cls = static::class;
		if ( !isset(self::$_instance) ) {
			self::$_instance = new static();
		}

		return self::$_instance;
	}

	/*
	 * Settings!
	 *
	 * Don't touch these here, change them in your index.php file. 
	 */

	/*
	 * Debug mode or no?
	 */
	var $debug = 0;									// Are we debugging
	var $verbose = 0;								// Are we extra verbose

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
	var $fastback_log = __DIR__ . '/cache/fastback.log'; // Where should fastabck log things. Nothing should get logged if debug is not true
	var $filecache = __DIR__ . '/cache/';			// Folder path to cache directory. sqlite and thumbnails will be stored here. 
													// Optional, will create a cache folder in the current directory as the default
													// $filecache doesn't have to be web accessable
	var $sqlitefile = __DIR__ . '/fastback.sqlite';	// Path to .sqlite file, Optional, defaults to fastback/fastback.sqlite
	var $csvfile = __DIR__ . '/cache/fastback.csv';	// Path to .csv file, Optional, will use $this->filecache/fastback.sqlite by default
	var $siteurl;									// Fastback will try to figure out the site url. If it's getting it wrong you can override it.

	/*
	 * Data processing
	 */

	var $photodirregex = '';						// Use '' (empty string) for all photos, regardless of structure.
													// Use this regex to only consider media in YYYY/MM/DD directories
													// $fb->photodirregex = './[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/'
	var $ignore_tag  = array('iMovie','FaceTime');	// Tags to ignore from photos.
	var $maybe_meme_level = 1;						// Which level of maybe_meme should we filter at? The higher the number the more 
													// likely it is a meme/junk image. Values can be any integer. Current code
													// ends up assigning values between about -2 and +2.
	var $_process_limit = 100;						// How many records should we process at once?
	var $_upsert_limit = 10000;						// Max number of SQL statements to do per upsert

	/*
	 * Internal variables, not reall meant to be messed with
	 */
	var $_sql;										// The sqlite object
	var $_sql_counter = 0;							// How many sql_connect calls do we have? Every function can sql_connect and sql_disconnect without closing the handle on someone else.
	var $_sqlite_timeout = 60;						// Wait timeout for a db connection. Value in seconds.

	var $supported_types = array();

		/**
	 * Do a normal run. Handle either the cli and its args, or the http request and its arguments.
	 *
	 * This function exits.
	 */
	public function run() {
		$this->siteurl = $this->util_base_url();
		// Ensure trailing slashes
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

		// if (php_sapi_name() === 'cli') {
		// 	$this->util_handle_cli();
		// 	exit();
		// }

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
		} else {
			// Default case!
			$this->send_html();
			exit();
		}
	}

	/**
	 * Get the fastback dir
	 */
	public function dir(){
		return __DIR__;
	}

	/**
	 * Log the current config
	 */
	public function log_config(){

		$this->log("=========FASTBACK CONFIG======");

		// Which site
		$this->log("| Site Info");
		$this->log("|  sitetitle: $this->sitetitle");
		$this->log("|  siteurl: $this->siteurl");
		$this->log("|  Install directory: " . __DIR__);

		// Where's stuff stored
		$this->log("| Storage");
		$this->log("|  filecache: $this->filecache");
		$this->log("|   exists: " . (file_exists($this->filecache) ? 'TRUE' : 'FALSE'));
		$this->log("|   writable: " . (is_writable($this->filecache) ? 'TRUE' : 'FALSE'));
		$this->log("|  sqlitefile: $this->sqlitefile");
		$this->log("|   exists: " . (file_exists($this->sqlitefile) ? 'TRUE' : 'FALSE'));
		$this->log("|   writable: " . (is_writable($this->sqlitefile) ? 'TRUE' : 'FALSE'));
		$this->log("|   size: " . filesize($this->sqlitefile));
		$this->log("|  csvfile: $this->csvfile");
		$this->log("|   exists: " . (file_exists($this->csvfile) ? 'TRUE' : 'FALSE'));
		$this->log("|   writable: " . (is_writable($this->csvfile) ? 'TRUE' : 'FALSE'));
		$this->log("|   size: " . filesize($this->csvfile));

		$this->log("| Users");
		$this->log("|  user: " . implode(",",array_keys($this->user)));
		$this->log("|  canflag: " . implode(",",$this->canflag));

		// Debugging
		$this->log("| Debugging");
		$this->log("|  debug: $this->debug");
		$this->log("|  verbose: $this->verbose");
		$this->log("|  fastback_log: $this->fastback_log");
		$this->log("|  error_log: " . ini_get("error_log"));
		$this->log("|  PHP version: " . phpversion());

		// Configuration
		$this->log("| Configuration");
		$this->log("|  photodirregex: $this->photodirregex");
		$this->log("|  supported_video_types: " . implode(",",$this->supported_video_types));
		$this->log("|  basemap: $this->basemap");
		$this->log("|  ignore_tag: " . implode(",",$this->ignore_tag));
		$this->log("|  maybe_meme_level: $this->maybe_meme_level");

		if ( $this->verbose ) {
			if ( !isset($this->_concurrent_cronjobs) ) { $this->_concurrent_cronjobs = ceil(`nproc`/4); }
			if ( !isset($this->_vipsthumbnail) ) { $this->_vipsthumbnail = trim(`which vipsthumbnail`); }
			if ( !isset($this->_ffmpeg) ) { $this->_ffmpeg = trim(`which ffmpeg`); }
			if ( !isset($this->_jpegoptim) ) { $this->_jpegoptim = trim(`which jpegoptim`); }
			if ( !isset($this->_gzip) ) { $this->_gzip= trim(`which gzip`); }
			if ( !isset($this->_convert) ) { $this->_convert = trim(`which convert`); }


			$this->log("| Internals: settings");
			$this->log("|  _thumbsize: $this->_thumbsize");
			$this->log("|  _crontimeout: $this->_crontimeout");
			$this->log("|  _concurrent_cronjobs: $this->_concurrent_cronjobs");
			$this->log("|  _process_limit: $this->_process_limit");
			$this->log("|  _upsert_limit: $this->_upsert_limit");
			$this->log("|  _sqlite_timeout: $this->_sqlite_timeout");

			$this->log("| Internals: settings");
			$this->log("|  _sql_counter: $this->_sql_counter");
			$this->log("|  _direct_cron_func_call: $this->_direct_cron_func_call");

			$this->log("| Internals: external programs");
			$this->log("|  _vipsthumbnail: $this->_vipsthumbnail");
			$this->log("|  _ffmpeg: $this->_ffmpeg");
			$this->log("|  _ffmpeg_webversion: $this->_ffmpeg_webversion");
			$this->log("|  _ffmpeg_webversion_threads: $this->_ffmpeg_webversion_threads");
			$this->log("|  _gzip: $this->_gzip");
			$this->log("|  _convert: $this->_convert");
			$this->log("|  _jpegoptim: $this->_jpegoptim");

		}
		$this->log("==============================");
	}

	/**
	 * Log a message through error_log if debug is true
	 */
	public function log($msg) {
		if ( $this->debug ) {
			error_log($msg);
		}
	}

	public function log_verbose($msg){
		if ($this->verbose ) {
			error_log($msg);
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
	public function util_handle_auth() {
		session_set_cookie_params(["SameSite" => "Strict"]); //none, lax, strict
		session_start();

		// Log out, just end it and redirect
		if ( isset($_REQUEST['logout']) ) {
			session_destroy();
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
	public function util_base_url() {
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
	public function util_file_is_ok($file,$module) {	
		$file_safe = SQLite3::escapeString($file);
		$module_safe = SQLite3::escapeString($module);
		$file_safe = $this->sql_query_single("SELECT file FROM fastback WHERE file='$file_safe' AND module='$module_safe'");
		if ( !$file_safe ) {
			http_response_code(404);
			$this->log("Someone tried to access file '''$file'''");
			var_dump($file);
			var_dump($module);
			die(__FILE__ . ":" . __LINE__ . ' -- ' .  microtime () ); 
			die();
		}

		if ( !file_exists($this->modules[$module]->path . '/' . $file_safe) ) {
			http_response_code(404);
			$this->log("Someone tried to access $file, which doesn't exist");
			die();
		}

		return $file_safe;
	}

	public function util_readfile($file,$disposition= 'inline'){
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
	public function send_html() {
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
		$html .= '<div id="rewindicon" class="date"></div>';
		$html .= '<div id="calendaricon" class="date"><input readonly id="datepicker" type="text"></div>';
		$html .= '<div id="pathpickericon" class="date"><select id="pathpicker"></select></div>';
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
		$html .= '<div class="fakelink" id="thumbgeo" data-coordinates="">🌐</div>';
		$html .= '<div class="fakelink" id="thumbalt" data-showing_alt="0" data-alt=""><img src="fastback/img/alt.png"></div>';
		$html .= '<div id="thumbinfo"></div>';
		$html .= '</div>';
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
			$csvmtime = time();
		} else if( file_exists($this->csvfile) ) {
			$csvmtime = filemtime($this->csvfile);
		} else if ( file_exists($this->sqlitefile )) {
			$csvmtime = filemtime($this->sqlitefile);
		} else {
			$csvmtime = filemtime(__FILE__);
		}

		$query = "SELECT * FROM modules";
		$res = $this->_sql->query($query);
		$modules = array();
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$modules[$row['id']] = $row['module_type'];
		}

		$html .= '<script>
			fastback = new Fastback({
			csvurl: "?csv=get&ts=' . $csvmtime . '",
			modules: ' . json_encode($modules) . ',
			fastbackurl: "' . $this->siteurl . $base_script . '",
			photocount: ' . $this->sql_query_single("SELECT COUNT(*) FROM fastback") . ',
			basemap: ' . $this->basemap . ',
			sort_order: "date",
			debugger: "' . ($_SESSION['authed'] === true && $this->debug ? 'debug' : 'none') . '"
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
	public function send_csv() {
		if ( !file_exists($this->csvfile ) ) {
			$wrote = $this->util_make_csv();

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
	public function send_share() {
		if ( empty($_GET['share']) ) {
			return false;
		}
		$share = SQLite3::escapeString($_GET['share']);
		$row = $this->sql_query_single("SELECT module,file FROM fastback WHERE share_key='$share'",true);

		if ( !$row ) {
			http_response_code(404);
			$this->log("Someone tried to access a shared file with parameters " . print_r($_GET,true));
			die();
		}

		if ( !$file = $this->util_file_is_ok($row['file'],$row['module']) ) {
			http_response_code(404);
			$this->log("Someone tried to access $file, which doesn't exist");
			die();
		}

		if ( !array_key_exists($row['module'],$this->modules) ) {
			die("The requested module is not loaded");
		}

		$this->modules[$row['module']]->send_share($row['file']);
	}

	/**
	 * Proxy a file type which is not supported by the browser.
	 */
	public function send_proxy() {
		$mfile = explode(':',$_GET['proxy']);

		if ( !array_key_exists($mfile[0],$this->modules) ) {
			die("The requested module is not loaded");
		}

		$this->modules[$mfile[0]]->send_web_view('./' . $mfile[1]);
	}

	/**
	 * Download a specific file
	 *
	 * Dies if file not in database or not on disk
	 */
	public function send_download() {
		$mfile = explode(':',$_GET['download']);
		if ( !$file = $this->util_file_is_ok('./' . $mfile[1],$mfile[0]) ) {
			die();
		}

		if ( !array_key_exists($mfile[0],$this->modules) ) {
			die("The requested module is not loaded");
		}

		$this->modules[$mfile[0]]->send_download('./' . $mfile[1]);
	}

	/**
	 * Send a thumbnail for the requested file
	 *
	 * Dies if file not in database or not on disk
	 */
	public function send_thumbnail() {
		$tninfo = explode(':',$_GET['thumbnail']);

		$tnfile = './' . $tninfo[1];

		$thumbnailfile = $this->modules[$tninfo[0]]->make_a_thumb($tnfile);

		if ( empty($thumbnailfile) ) {
			$this->log("Couldn't find thumbnail for '''{$_GET['thumbnail']}''', sending full sized!!!");
			return false;
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
	public function send_pwa() {
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
	public function action_flag_photo(){
		if (!empty($this->canflag) && !empty($_SESSION['user']) && in_array($_SESSION['user'],$this->canflag)){
			$mfile = explode(':',$_GET['flag']);
			$file = SQLite3::escapeString('./' . $mfile[1]);
			$module = SQLite3::escapeString($mfile[0]);
			$row = $this->sql_query_single("UPDATE fastback SET flagged=1 WHERE file='$file' AND module='$module' RETURNING file,flagged",true);
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
	public function sql_connect(){
		$this->_sql_counter++;
		if ( isset($this->_sql) ) {
			return $this->_sql;
		}

		if ( !file_exists($this->sqlitefile) ) {
			try {
				$this->_sql = new SQLite3($this->sqlitefile);
				$this->_sql->busyTimeout($this->_sqlite_timeout * 1000);
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
	public function sql_setup_db() {
		$q_create_meta = "CREATE TABLE IF NOT EXISTS cron ( 
			job VARCHAR(255) PRIMARY KEY, 
			updated INTEGER,
			last_completed INTEGER DEFAULT NULL, -- Set to 1 once it has last_completed at least once
			due_to_run BOOL DEFAULT 1, -- Set to 0 each time it completes, and then cleared when the completion is stale
			owner TEXT -- pid of the process running it
		)";
		$res = $this->_sql->query($q_create_meta);

		$q_create_files = "CREATE TABLE IF NOT EXISTS fastback ( 
			module INTEGER,				-- which module does this row belong to?
			file TEXT PRIMARY KEY,  	-- which file is being referenced?
			thumbnail TEXT,				-- where is the thumbnail for this object?
			webversion_made BOOL,		-- has a web-friendly version of this been made? (eg. streamable video version)
			exif TEXT,					-- place to store the metadata before it is processed
			flagged BOOL,				-- has the user flagged this object to exclude it?
			mtime INTEGER,				-- what is the file's modification time (used until a sorttime is calcualted)
			sorttime DATETIME,			-- what time should be used to sort the object? (might come from exif or elsewhere)
			lat DECIMAL(15,10),			-- coordinates (latitude)
			lon DECIMAL(15,10),			-- coordiantes (longitude)
			elev DECIMAL(15,10),		-- coordinates (elevation)
			nullgeom BOOL,				-- handle weird geometry fields so we don't plot 0,0 coordinate objects
			maybe_meme INT,				-- meme rating score
			share_key VARCHAR(32),		-- secret key used to authenticate a sharing link
			tags TEXT,					-- comma separated list of tags for this object
			content_identifier TEXT,	-- media ID that connects two different representations of the same thing. eg. a live and still version of the same picture
			alt_content TEXT,			-- a reference to the other version of the content. Format: module_id:/file/path
			csv_ready BOOL,				-- is the media ready for CSV usage? (ready to show on the web?)
			_util TEXT					-- util field used by cron to mark rows as reserved for work
		)";

		$res = $this->_sql->query($q_create_files);

		$q_create_modules= "CREATE TABLE IF NOT EXISTS modules (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			path TEXT,
			lastmod TEXT,
			module_type TEXT,
			CONSTRAINT unique_module UNIQUE(path,module_type)
		)";

		$this->_sql->query("CREATE UNIQUE INDEX IF NOT EXISTS fb_file ON fastback (file)");
		$this->_sql->query("CREATE INDEX IF NOT EXISTS fb_content_identifier ON fastback (content_identifier)");
		$this->_sql->query("CREATE INDEX IF NOT EXISTS fb_module ON fastback (module)");
		$this->_sql->query("CREATE INDEX IF NOT EXISTS fb_maybe_meme ON fastback (maybe_meme)");
		$this->_sql->query("CREATE INDEX IF NOT EXISTS fb_csvsort ON fastback (COALESCE(CAST(STRFTIME('%s',sorttime) AS INTEGER),mtime),file)");
		$this->_sql->query("CREATE INDEX IF NOT EXISTS fb_csv_ready ON fastback (csv_ready)");
		$this->_sql->query("CREATE INDEX IF NOT EXISTS fb_alt ON fastback (alt_content)");
		$this->_sql->query("CREATE INDEX fb_alt_lookup ON fastback (CONCAT(module,':',file))");

		$res = $this->_sql->query($q_create_modules);

		$res = $this->_sql->query('PRAGMA journal_mode=WAL');
	}

	/**
	 * Close the sqlite connection, if one exists.
	 *
	 * Log the last 5 error messages.
	 */
	public function sql_disconnect(){
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

	public function sql_query_single($query,$entireRow = false) {
		$this->sql_connect();
		$res = $this->_sql->querySingle($query,$entireRow);
		$err = $this->_sql->lastErrorMsg();
		if ( $err != "1" && $err != "not an error") {
			$this->log("SQL error: $err");
			var_dump($err);
			debug_print_backtrace();
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
	public function sql_get_queue($where) {
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
			COALESCE(CAST(STRFTIME('%s',sorttime) AS INTEGER),mtime) DESC,
			file DESC
			LIMIT {$this->_process_limit}";

		$this->log_verbose($query);
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
	public function sql_update_case_when($module_id,$update_q,$ar,$else,$escape_val = False) {
		if ( empty($ar) ) {
			return;
		}

		foreach($ar as $file => $val){
			if ( $escape_val ) {
				$update_q .= " WHEN module='$module_id' AND file='" . SQLite3::escapeString($file) . "' THEN '" . SQLite3::escapeString($val) . "'\n";
			} else {
				$update_q .= " WHEN module='$module_id' AND file='" . SQLite3::escapeString($file) . "' THEN " . $val . "\n";
			}
		}
		$update_q .= " " . $else;
		$update_q .= " WHERE _util='RESERVED-" . getmypid() . "'";
		$this->sql_connect();
		$res = $this->sql_query_single($update_q);
		$this->sql_disconnect();
	}
}
