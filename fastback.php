<?php
declare(ticks = 1);

class Fastback {
	/*
	 * Settings!
	 *
	 * Usage
	 * $fb = new Fastback();
	 * $fb->sitetitle = "Moore Family Gallery!";
	 * $fb->user['Michael'] = 'Mypassw0rd!;
	 * $fb->run();
	 *
	 */ 
	var $debug = 1;									// Are we debugging
	var $sitetitle = "Fastback Photo Gallery";		// Title
	var $user = array();							// Dictionary of username => password 

	// Advanced usage
	var $photobase = __DIR__ . '/../';				// File path to full sized photos, Optional, will use current directory as default
	var $photourl;									// URL path to full sized photos, Optional, will use current web path as default
	// Should probably be customized if photobase is customized.
	// var $photodirregex = './[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/'; // Use '' (empty string) for all photos, regardless of structure.
	var $photodirregex = '';						// Use '' (empty string) for all photos, regardless of structure.
	var $ignore_tag  = array('iMovie','FaceTime');	// Tags to ignore from photos.
	var $sortorder = 'DESC';						// Sort order of the photos for the csv (ASC or DESC)
	var $canflag = array();							// List of users who can flag photos. eg. array('Michael','Caroline');
	var $filecache = __DIR__ . '/cache/';			// Folder path to cache directory. sqlite and thumbnails will be stored here. 
	// Optional, will create a cache folder in the current directory as the default
	// $filecache doesn't have to be web accessable
	var $precachethumbs = false;					// Should thumbnails be created for all photos in the cron task? Default is to generated them on the fly as needed.
	var $sqlitefile = __DIR__ . '/fastback.sqlite';	// Path to .sqlite file, Optional, defaults to fastback/fastback.sqlite
	var $csvfile = __DIR__ . '/cache/fastback.csv';	// Path to .csv file, Optional, will use fastback/cache/fastback.sqlite by default

	// Internal variables. These are also editable, but you probably don't need to.
	// Proceed with caution.
	var $_process_limit = 100;						// How many records should we process at once?
	var $_upsert_limit = 10000;						// Max number of SQL statements to do per upsert
	var $_sql;										// The sqlite object
	var $_sqlite_timeout = 60;						// Wait timeout for a db connection. Value in seconds.
	var $_vipsthumbnail;							// Path to vips binary
	var $_ffmpeg;									// Path to ffmpeg binary
	var $_jpegoptim;								// Path to jpegoptim
	var $_thumbsize = "256x256";					// Thumbnail size. Must be square.
	var $_crontimeout = 10;						// How long to let cron run for in seconds. External calls don't count, so for thumbs and exif wall time may be longer
	// If this is to short some cron jobs may not record any finished work. See also $_process_limit and $_upsert_limit.
	var $_concurrent_cronjobs;						// How many concurrent cron jobs should we run? These take up fcgi processes. 
	// We don't want to use all processes as it will make the server unresponsive.
	// We will set it to CEIL(nproc/4) in cron() to allow some parallell processing.

	/*
	 * These are internal variables you probably shouldn't try to change
	 */
	var $supported_photo_types = array( // Photo formats
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

	var $supported_video_types = array( // Video formats
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
		$this->photourl = $this->_base_url();

		if ( !is_dir($this->filecache) ) {
			@mkdir($this->filecache,0700,TRUE);
			if ( !is_dir($this->filecache) ) {
				$this->log("Fastback cache directory {$this->filecache} doesn't exist");
				die("Fastback setup error. See errors log.");
			}
		}

		// This will only log if debug is enabled
		$this->log("Debug enabled");
	}

	/**
	 * Do a normal run. Handle either the cli and its args, or the http request and its arguments.
	 *
	 * This function exits.
	 */
	public function run() {
		ini_set('error_log', $this->filecache . '/fastback.log'); 
		// CLI stuff doesn't need auth
		if (php_sapi_name() === 'cli') {
			$this->util_handle_cli();
			exit();
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
	public function log($msg) {
		if ( $this->debug ) {
			error_log($msg);
		}
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
			<link rel="stylesheet" href="fastback/css/fastback.css?ts=' . filemtime(__DIR__ . '/css/fastback.css') . '">
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
		$html .= '<div id="tagicon"></div>';
		$html .= '<div id="rewindicon"></div>';
		$html .= '<div id="calendaricon"><input readonly id="datepicker" type="text"></div>';
		$html .= '<div id="exiticon" class="' . (isset($this->user) ? '' : 'disabled') . '"></div>';
		$html .= '</div>';
		$html .= '<div id="thumb" class="disabled">
			<div id="thumbcontent"></div>
			<div id="thumbleft" class="thumbctrl">LEFT</div>
			<div id="thumbright" class="thumbctrl">RIGHT</div>
			<div id="thumbcontrols">
			<div id="thumbclose">🆇</div>
			<div class="fakelink" id="thumbdownload" href="#">⬇️</div>
			<div class="fakelink" id="sharelink"><a href="#">🔗<form id="sharelinkcopy">><input/></form></a></div>
			<div class="fakelink disabled" id="webshare"><img src="fastback/img/share.png"></div>
			<div class="fakelink ' . (!empty($this->canflag) && !in_array($_SESSION['user'],$this->canflag) ? 'disabled' : '') . '" id="thumbflag" data-file="#">🚩</div>
			<div class="fakelink" id="thumbgeo" data-coordinates="">🌐</div>
			<!-- div class="fakelink" id="sharefb"><img src="fastback/img/fb.png" /></div>
			<div class="fakelink" id="sharewhatsapp"><img src="fastback/img/whatsapp.png" /></div>
			<div class="fakelink" id="shareemail">✉️</div -->
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
		$html .= '<script src="fastback/js/fastback.js?ts=' . filemtime(__DIR__ . '/js/fastback.js') . '"></script>';
		$html .= '<script src="fastback/js/md5.js"></script>';

		$base_script = preg_replace(array('/.*\//','/\?.*/'),array('',''),$_SERVER['REQUEST_URI']);

		$html .= '<script>
			fastback = new Fastback({
			csvurl: "' . $this->_base_url() . '?csv=get&ts=' . filemtime($this->csvfile) . '",
				photourl:    "' . $this->photourl .'",
				fastbackurl: "' . $this->_base_url() . $base_script . '",
	});
	if("serviceWorker" in navigator) {
		navigator.serviceWorker.register("?pwa=sw", { scope: "' . $this->_base_url() . '" });
	}
			</script>';
		$html .= '</body></html>';

		print $html;
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
			header("Location: " . $_SERVER['SCRIPT_URL']);
			setcookie("fastback","", time() - 3600); // Clear cookie
			return true;
		}

		// Already active session
		if ( array_key_exists('authed',$_SESSION) && $_SESSION['authed'] === true ) {
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
			<link rel="stylesheet" href="fastback/css/fastback.css?ts=' . filemtime(__DIR__ . '/css/fastback.css') . '">
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
	 * Set the flag field in the database to 1 for a specified file. 
	 *
	 * Flagged files are hidden the next time make_csv is run
	 */
	public function action_flag_photo(){
		$this->sql_connect();
		$stmt = $this->_sql->prepare("UPDATE fastback SET flagged=1 WHERE file=:file");
		$stmt->bindValue(':file',$_GET['flag']);
		$stmt->execute();

		$stmt = $this->_sql->prepare("SELECT file,flagged FROM fastback WHERE file=:file");
		$stmt->bindValue(':file',$_GET['flag']);
		$res = $stmt->execute();
		$row = $res->fetchArray(SQLITE3_ASSOC);

		$this->sql_disconnect();
		header("Content-Type: application/json");
		header("Cache-Control: no-cache");
		print json_encode($row);
	}

	/**
	 * Connect to sqlite, setting $this->sql
	 */
	public function sql_connect($try_no = 1){
		if ( !file_exists($this->sqlitefile) ) {
			$this->_sql = new SQLite3($this->sqlitefile);
			$this->_sql->busyTimeout($this->_sqlite_timeout * 1001);
			$this->sql_setup_db();
		} else {
			$this->_sql = new SQLite3($this->sqlitefile);
			$this->_sql->busyTimeout($this->_sqlite_timeout * 1000);
		}
	}

	/**
	 * Initialize the database
	 */
	public function sql_setup_db() {
		$q_create_meta = "Create TABLE IF NOT EXISTS cron ( 
			job VARCHAR(255) PRIMARY KEY, 
			updated INTEGER,
			completed BOOL,
			owner TEXT,
			meta TEXT
		)";
		$res = $this->_sql->query($q_create_meta);

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
	public function sql_disconnect(){
		if ( !isset($this->sql) ) {
			return;
		}

		$max = 5;
		while ( $err = $this->_sql->lastErrorMsg() && $max--) {
			if ( $err == "1") {
				break;
			}
			$this->log("SQL error: $err");
		}

		@$this->_sql->query("COMMIT");
		$this->_sql->close();
		unset($this->sql);
	}

	/**
	 * Process args and run what we should
	 */
	private function util_handle_cli(){
			global $argv;

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

			$allowed_actions = array('find_new_files','get_exif','process_exif','make_thumbs','remove_deleted','clear_locks');

			if ( in_array($argv[1],$allowed_actions) ) {
				$func = "cron_" . $argv[1];
				$this->$func();
			} else {
				print("You're using fastback photo gallery\n");
				print("Usage: ./index.php [debug] [" . implode('|',$allowed_actions) . "]\n");
			}
	}

	/**
	 * Get all modified files into the db cache
	 *
	 * Because we are reading from the file system this must complete 100% or not at all. We don't have a good way to crawl only part of the fs at the moment.
	 */
	public function cron_find_new_files() {
		$this->sql_connect();
		$this->sql_update_cron_status('find_new_files');

		$lastmod = '19000102';
		$res = $this->_sql->querySingle("SELECT meta FROM cron WHERE job='find_new_files'");
		if ( !empty($res) ) {
			$lastmod = $res;
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
					$this->_sql->query($sql);
					$this->sql_update_cron_status('find_new_files');
					$collect_photo = array();
				}

				if ( count($collect_video) >= $this->_upsert_limit) {
					$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
					$this->_sql->query($sql);
					$this->sql_update_cron_status('find_new_files');
					$collect_video = array();
				}
			}

			if ( count($collect_photo) > 0 ) {
				$sql = $multi_insert . implode(",",$collect_photo) . $multi_insert_tail . '0';
				$this->_sql->query($sql);
				$this->sql_update_cron_status('find_new_files');
				$collect_photo = array();
			}

			if ( count($collect_video) > 0 ) {
				$sql = $multi_insert . implode(",",$collect_video) . $multi_insert_tail . '1';
				$this->_sql->query($sql);
				$this->sql_update_cron_status('find_new_files');
				$collect_video = array();
			}
		}

		$maxtime = $this->_sql->querySingle("SELECT MAX(mtime) AS maxtime FROM fastback");
		if ( $maxtime ) {
			// lastmod to see where to pick up from
			$this->sql_update_cron_status('find_new_files',true,$maxtime);
			$this->sql_disconnect();
		}

		chdir($origdir);
		return true;
	}

	/**
	 * This task will delete rows from the database for any files which were deleted from disk.
	 */
	public function cron_remove_deleted() {
		$this->sql_update_cron_status('remove_deleted');
		$filetypes = implode('\|',array_merge($this->supported_photo_types, $this->supported_video_types));
		chdir($this->photobase);
		$cmd = 'find -L . -type f -regextype sed -iregex  "' . $this->photodirregex . '.*\(' . $filetypes . '\)$" | grep -v "./fastback/"';

		$all_files = `$cmd`;
		$all_files = explode("\n",$all_files);
		$all_files = array_filter($all_files);

		$this->log("Checking for missing files: Found " . count($all_files) . " files on disk");

		$this->sql_connect();

		$count = $this->_sql->querySingle("SELECT COUNT(*) AS c FROM fastback");
		$this->log("Checking for missing files: Found {$count} files in the database");

		$q = "SELECT file FROM fastback";
		$res = $this->_sql->query($q);
		$not_found = array();
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if ( !in_array($row['file'],$all_files) ) {
				$not_found[] = $row['file'];
			}
		}

		$this->log("Checking for missing files: Removing " . count($not_found) . " files from the database which don't exist on disk");

		if ( count($not_found) >  0 ) {
			$not_found = array_map('SQLite3::escapeString',$not_found);
			$q = 'DELETE FROM fastback WHERE file IN ("' . implode('","',$not_found) . '")';
			$this->_sql->query($q);
		}

		$this->sql_update_cron_status('remove_deleted',true);

		$this->sql_disconnect();
	}

	/**
	 * This is the child function that gets forked to make thumbnails
	 *
	 */
	public function cron_make_thumbs() {
		$this->sql_update_cron_status('make_thumbs');
		do {

			$this->sql_disconnect();
			$queue = $this->sql_get_queue("flagged IS NOT TRUE AND thumbnail IS NULL AND file != ''");

			$made_thumbs = array();
			$flag_these = array();

			foreach($queue as $file => $row) {
				$thumbnailfile = $this->_make_a_thumb($file,true);

				// If we've got the file, we're good
				if ( file_exists($thumbnailfile) ) {
					$made_thumbs[$file] = $thumbnailfile;
				} else {
					$flag_these[] = $file;
				}
			}

			$this->sql_update_case_when("UPDATE fastback SET _util=NULL, thumbnail=CASE", $made_thumbs, "ELSE thumbnail END", TRUE);

			$this->sql_connect();
			$flag_these = array_map('SQLite3::escapeString',$flag_these);
			$this->_sql->query("UPDATE fastback SET flagged=1 WHERE file IN ('" . implode("','",$flag_these) . "')");
			$this->sql_update_cron_status('make_thumbs');
			$this->sql_disconnect();

		} while (count($made_thumbs) > 0);

		$this->sql_connect();
		$this->sql_update_cron_status('make_thumbs',true);
		$this->sql_disconnect();
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
		$file = $this->_file_is_ok($file);
		$print_to_stdout = false;

		$origdir = getcwd();
		chdir($this->photobase);

		$thumbnailfile = $this->filecache . '/' . ltrim($file,'./') . '.webp';

		// Quick exit if thumb exists. Should be the default case
		if ( file_exists($thumbnailfile) ) {
			chdir($origdir);
			return $thumbnailfile;
		}

		// Quick exit if cachedir doesn't exist. That means we can't cache.
		if ( !file_exists($this->filecache) ) {
			mkdir($this->filecache,0700,TRUE);
			if ( !is_dir($this->filecache) ) {
				$this->log("Cache dir doesn't exist and can't create it");

				if ( $print_if_not_write ) {
					$print_to_stdout = true;
				} else {
					chdir($origdir);
					return false;
				}
			}
		}

		// Cachedir might exist, but not be wriatable. 
		$dirname = dirname($thumbnailfile);
		if (!file_exists($dirname) ){
			@mkdir($dirname,0700,TRUE);
			if ( !is_dir($dirname) ) {
				$this->log("Cache sub-dir doesn't exist and can't create it");
				if ( $print_if_not_write ) {
					$print_to_stdout = true;
				} else {
					chdir($origdir);
					return false;
				}
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
				chdir($origdir);
				return false;
			}

		} else if ( in_array(strtolower($pathinfo['extension']),$this->supported_video_types) ) {

			$res = $this->_make_video_thumb($file,$thumbnailfile,$print_to_stdout);

			if ( $res === false ) {
				$this->log("Unable to make a thumbnail for $file");
				chdir($origdir);
				return false;
			}
		} else {
			$this->log("What do I do with ");
			$this->log(print_r($pathinfo,TRUE));
			chdir($origdir);
			return false;
		}

		if ( !file_exists( $thumbnailfile ) ) {
			chdir($origdir);
			return false;
		}

		if ( !empty($this->_jpegoptim) ) {
			$shellthumb = escapeshellarg($thumbnailfile);
			$cmd = "jpegoptim --strip-all --strip-exif --strip-iptc $shellthumb";
			$res = `$cmd`;
		}

		if ( !$skip_db_write ) {
			$this->sql_connect();
			$made_thumbs[$file] = $thumbnailfile;
			$this->sql_update_case_when("UPDATE fastback SET _util=NULL, thumbnail=CASE", $made_thumbs, "ELSE thumbnail END", TRUE);
			$this->sql_disconnect();
		}

		chdir($origdir);
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
		if ( file_exists($thumbnailfile) ) {
			if ( $print_to_stdout ) {
				header("Content-Type: image/webp");
				readfile($thumbnailfile);
				exit();
			}
			return $thumbnailfile;
		}

		$shellfile = escapeshellarg($file);
		$shellthumb = escapeshellarg($thumbnailfile);

		if ( !isset($this->_vipsthumbnail) ) { $this->_vipsthumbnail = trim(`which vipsthumbnail`); }
		if (!empty($this->_vipsthumbnail) ) {

			if ( $print_to_stdout ) {
				$shellthumb = '.webp';
			}

			$cmd = "{$this->_vipsthumbnail} --size={$this->_thumbsize} --output=$shellthumb --smartcrop=attention $shellfile 2>/dev/null";
			$res = `$cmd`;

			if ( $print_to_stdout ) {
				header("Content-Type: image/webp");
				print $res;
				exit();
			}

			if ( file_exists($thumbnailfile) ) {
				return $thumbnailfile;
			}
		}

		if ( !isset($this->convert) ) { $this->convert = trim(`which convert`); }
		if ( !empty($this->convert) ) {

			if ( $print_to_stdout ) {
				$shellthumb = 'webp:-';
			}

			$cmd = "{$this->convert} -define jpeg:size={$this->_thumbsize} $shellfile  -thumbnail {$this->_thumbsize}^ -gravity center -extent $this->_thumbsize $shellthumb 2>/dev/null";
			$res = `$cmd`;

			if ( $print_to_stdout ) {
				header("Content-Type: image/webp");
				print $res;
				exit();
			}

			if ( file_exists($thumbnailfile) ) {
				return $thumbnailfile;
			}
		}

		// looks like vips didn't work
		if (extension_loaded('gd') || function_exists('gd_info')) {
			try {
					$image_info = getimagesize($file);
					switch($image_info[2]){
					case IMAGETYPE_JPEG:
						$img = imagecreatefromjpeg($file);
						break;
					case IMAGETYPE_GIF:
						$img = imagecreatefromgif($file);
						break;
					case IMAGETYPE_PNG:
						$img = imagecreatefrompng($file);
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
							imagewebp($tmpimg);
						} else {
							imagewebp($tmpimg, $thumbnailfile);
						}
					} else {
						$this->log("Tried GD, but image was not png/jpg/gif");
					}

					if(file_exists($thumbnailfile)){
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
	 * Make a video thumbnail
	 *
	 * @file The source file
	 * @thumbnailfile The destination file
	 * @print_to_stdout If true then we print headers and image and exit
	 */
	private function _make_video_thumb($file,$thumbnailfile,$print_to_stdout) {
			if ( file_exists($thumbnailfile) ) {
				if ( $print_to_stdout ) {
					header("Content-Type: image/webp");
					readfile($thumbnailfile);
					exit();
				}
				return $thumbnailfile;
			}

			if ( !isset($this->_ffmpeg) ) { $this->_ffmpeg = trim(`which ffmpeg`); }

			$shellfile = escapeshellarg($file);
			$shellthumb = escapeshellarg($thumbnailfile);
			$tmpthumb = $this->filecache . 'tmpthumb_' . getmypid() . '.webp';
			$tmpshellthumb = escapeshellarg($tmpthumb);
			$formatflags = "";

			if ( !empty($this->_ffmpeg) ) {

				if ( $print_to_stdout ) {
					$tmpshellthumb = '-';
					$formatflags = ' -f image2 -c png ';
				}

				$cmd = "{$this->_ffmpeg} -y -ss 10 -i $shellfile -vframes 1 $formatflags $tmpshellthumb 2> /dev/null";
				$res = `$cmd`;

				if ( $print_to_stdout && $res !== NULL) {
					header("Content-Type: image/webp");
					print($res);
					exit();
				}

				if ( !file_exists($tmpthumb) || filesize($tmpthumb) == 0 ) {
					$cmd = "{$this->_ffmpeg} -y -ss 2 -i $shellfile -vframes 1 $formatflags $tmpshellthumb 2> /dev/null";
					$res = `$cmd`;

					if ( $print_to_stdout && $res !== NULL) {
						header("Content-Type: image/webp");
						print($res);
						exit();
					}
				}

				if ( !file_exists($tmpthumb) || filesize($tmpthumb) == 0 ) {
					$cmd = "{$this->_ffmpeg} -y -ss 00:00:00 -i $shellfile -frames:v 1 $formatflags $tmpshellthumb";
					$res = `$cmd`;
					if ( $print_to_stdout && $res !== NULL) {
						header("Content-Type: image/png");
						print($res);
						exit();
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
						@rename($tmpthumb,$thumbnailfile);
					}
				}
				if ( file_exists($thumbnailfile) ) {
					return $thumbnailfile;
				}
			}

			@copy(__DIR__ . '/image/movie.webp',$thumbnailfile);

			if ( file_exists($thumbnailfile) ) {
				if ( $print_to_stdout ) {
					header('Content-Type: image/webp');
					readfile($thumbnailfile);
					exit();
				} 

				return $thumbnailfile;
			}

			if ( $print_to_stdout ) {
				header('Content-Type: image/webp');
				readfile(__DIR__ . '/img/movie.webp');
				exit();
			}
	}

	/**
	 * Make the csv cache file
	 *
	 * @param $print_if_not_write If we can't open the cache file, then send the csv directly.
	 *
	 * @note Using $print_if_not_write will cause this function to exit() after sending.
	 */
	private function _make_csv($print_if_not_write = false){
		if ( !file_exists($this->filecache) ) {
			@mkdir($this->filecache,0700,TRUE);
		}

		$this->sql_connect();
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
			AND (maybe_meme <= 1 OR maybe_meme IS NULL) -- Only display non-memes. Threshold of 1 seems pretty ok
			ORDER BY filemtime " . $this->sortorder . ",file " . $this->sortorder;
		$res = $this->_sql->query($q);

		$printed = false;
		$fh = fopen($this->csvfile,'w');
		if ( $fh === false  && $print_if_not_write) {
			$printed = true;
			header("Content-type: text/plain");
			header("Content-Disposition: inline; filename=\"" . basename($this->csvfile) . "\"");
			header("Last-Modified: " . gmdate("D, d M Y H:i:s"));
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
		return true;
	}

	private function _process_exif_meme($exif,$file) {
		$bad_filetypes = array('MacOS','WEBP');
		$bad_mimetypes = array('application/unknown','image/png');
		$maybe_meme = 0;

		// Bad filetype  or Mimetype
		// "FileType":"MacOS"
		// FileType WEBP
		// "MIMEType":"application\/unknown"
		if ( in_array($exif['FileType'],$bad_filetypes) ) {
			$maybe_meme += 1;
		}

		if ( in_array($exif['MIMEType'],$bad_mimetypes) ) {
			$maybe_meme += 1;
		} else if ( preg_match('/video/',$exif['MIMEType']) ) {
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
	 * Do an upsert to reserve some items from the queue in a consistant way. 
	 * The queue is used on the cli when forking multiple processes to process a request.
	 */
	private function sql_get_queue($where) {
		$this->sql_connect();

		$this->_sql->query("UPDATE fastback SET _util=NULL WHERE _util='RESERVED-" . getmypid() . "'");
		$query = "UPDATE fastback SET _util='RESERVED-" . getmypid() . "' WHERE _util IS NULL AND (" . $where . ") ORDER BY file DESC LIMIT {$this->_process_limit}";
		$this->_sql->query($query);

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
	public function sql_update_case_when($update_q,$ar,$else,$escape_val = False) {
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
		$res = $this->_sql->query($update_q);
		if ( $res == False ) {
			$this->log($update_q);
			$this->log($this->_sql->lastErrorMsg());
		}
		$this->sql_disconnect();
	}

	/**
	 * Send, creating if needed, the CSV file of all the photos
	 */
	public function send_csv() {
		// Auto detect if CSV has gotten stale
		if ( !file_exists($this->csvfile) || filemtime($this->sqlitefile) > filemtime($this->csvfile) || filemtime(__FILE__) > filemtime($this->csvfile) ) {
			$wrote = $this->_make_csv(true);

			if ( !$wrote ) {
				header("HTTP/1.0 404 Not Found");
				print("CSV file not found");
				exit();
			}
		}

		ob_start("ob_gzhandler");
		header("Content-type: text/plain");
		header("Content-Disposition: inline; filename=\"" . basename($this->csvfile) . "\"");
		header("Last-Modified: " . filemtime($this->csvfile));
		readfile($this->csvfile);
		ob_end_flush();
	}

	/**
	 * Handle publicly sharable link
	 */
	public function send_share() {
		if ( empty($_GET['share']) ) {
			return false;
		}
		$this->sql_connect();
		$stmt = $this->_sql->prepare("SELECT file FROM fastback WHERE share_key=:share");
		$stmt->bindValue(":share",strtolower($_GET['share']));
		$res = $stmt->execute();
		$row = $res->fetchArray(SQLITE3_ASSOC);

		if ( $row === FALSE ) {
			http_response_code(404);
			$this->log("Someone tried to access a shared file with parameters " . print_r($_GET,true));
			die();
		} else {
			$file = $row['file'];
		}

		$this->sql_disconnect();

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

		$mime = mime_content_type($file);
		header("Content-Type: $mime");
		header("Content-Transfer-Encoding: Binary");
		header("Content-Length: ".filesize($file));
		header("Content-Disposition: inline; filename=\"" . basename($file) . "\"");
		readfile($file);
		exit();
	}

	/**
	 * Proxy a file type which is not supported by the browser.
	 */
	public function send_proxy() {
		if ( !$file = $this->_file_is_ok($_GET['proxy']) ) {
			die();
		}

		$file = $this->photobase . $file;

		$mime = mime_content_type($file);
		$mime = explode('/',$mime);

		if ( $mime[1] == 'x-tga' ) {
			$mime[0] = 'video';
			$mime[1] = 'mpeg2';
		}

		if ( $mime[0] == 'image' ) {
			header("Content-Type: image/jpeg");
			header("Content-Disposition: inline; filename=\"" . basename($file) . ".jpg\"");
			$cmd = 'convert ' . escapeshellarg($file) . ' JPG:-';
			passthru($cmd);
			exit();
		} else if ($mime[0] == 'video' ) {
			header("Content-Type: image/jpeg");
			header("Content-Disposition: inline; filename=\"" . basename($file) . ".jpg\"");
			$cmd = "ffmpeg -ss 00:00:00 -i " . escapeshellarg($file) . " -frames:v 1 -f singlejpeg - ";
			passthru($cmd);
			exit();
		} else {
			$mime = mime_content_type($file);
			header("Content-Type: $mime");
			header("Content-Transfer-Encoding: Binary");
			header("Content-Length: ".filesize($file));
			header("Content-Disposition: inline; filename=\"" . basename($file) . "\"");
			readfile($file);
			exit();
		}
	}

	/**
	 * Download a specific file
	 *
	 * Dies if file not in database or not on disk
	 */
	public function send_download() {
		if ( !$file = $this->_file_is_ok($_GET['download']) ) {
			die();
		}

		$file = $this->photobase . $file;

		$mime = mime_content_type($file);
		header("Content-Type: $mime");
		header("Content-Transfer-Encoding: Binary");
		header("Content-Length: ".filesize($file));
		header("Content-disposition: attachment; filename=\"" . basename($file) . "\"");
		readfile($file);
		exit();
	}

	/**
	 * Send a thumbnail for the requested file
	 *
	 * Dies if file not in database or not on disk
	 */
	public function send_thumbnail() {
		$thumbnailfile = $this->_make_a_thumb($_GET['thumbnail'],false,true);

		if ( $thumbnailfile === false ) {
			$this->log("Couldn't find thumbnail for '''{$_GET['thumbnail']}''', sending full sized!!!");
			// I know this breaks video thumbs, but if a user is just getting set up I want something to still work for them
			// This at least gets them images for now
			$thumbnailfile = $this->_file_is_ok($_GET['thumbnail']);
		}

		$mime = mime_content_type($thumbnailfile);
		header("Content-Type: $mime");
		header("Content-Transfer-Encoding: Binary");
		header("Content-Length: ".filesize($thumbnailfile));
		header("Content-Disposition: inline; filename=\"" . basename($thumbnailfile) . "\"");
		readfile($thumbnailfile);
		exit();
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
	private function _file_is_ok($file) {	
		$this->sql_connect();
		$stmt = $this->_sql->prepare("SELECT file FROM fastback WHERE file=:file");
		$stmt->bindValue(":file",$file);
		$res = $stmt->execute();
		$row = $res->fetchArray(SQLITE3_ASSOC);

		if ( $row === FALSE ) {
			http_response_code(404);
			$this->log("Someone tried to access file '''$file'''");
			die();
		} else {
			$file = $row['file'];
		}

		$this->sql_disconnect();

		if ( !file_exists($this->photobase . $file) ) {
			http_response_code(404);
			$this->log("Someone tried to access $file, which doesn't exist");
			die();
		}

		return $file;
	}

	/**
	 * Send any of the various resources needed for Progressive Web App
	 *
	 * The purpose of the PWA is to provide client side caching and to run the cron task in the background.
	 */
	public function send_pwa() {
		if ( $_GET['pwa'] == 'manifest' ) {
			$base_url = $this->_base_url();
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
			header("Content-Length: ".filesize(__DIR__ . '/js/fastback-sw.js'));
			header("Content-Disposition: inline; filename=\"fastback-sw.js\"");
			$sw = file_get_contents(__DIR__ . '/js/fastback-sw.js');
			$sw = str_replace('SW_FASTBACK_BASEURL',$this->_base_url(),$sw);
			$sw = str_replace('SW_FASTBACK_PHOTOURL',$this->photourl,$sw);
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
			<link rel="stylesheet" href="fastback/css/fastback.css?ts=' . filemtime(__DIR__ . '/css/fastback.css') . '">
			</head>';

			$html .= '<body>If you\'re seeing this, then ' . $this->_base_url() . ' is in accessable. Maybe it\'s down? You could also be offline, or the site could be an IPV6 site and you\'re on an IPV4 only network.';

			$html .= '<script>
				if("serviceWorker" in navigator) {
					navigator.serviceWorker.register("' . $this->_base_url() . '?pwa=sw", { scope: "' . $this->_base_url() . '" });
		}
		</script>';
			$html .= '</body</html>';
			print($html);
		} else if ( $_GET['pwa'] == 'test' ) {
			header("Content-Type: application/json");
			header("Cache-Control: no-cache");
			print(json_encode(array('status' => 'OK')));
		}
	}

	/**
	* Check whether URL is HTTPS/HTTP
	* @return boolean [description]
	*
	* https://stackoverflow.com/questions/5100189/use-php-to-check-if-page-was-accessed-with-ssl
	*/
	private function _base_url() {
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
			$this->log("Unable to create valid URL: $whole_url");
			die("Couldn't figure out server URL");
			return false;
		}
		return $whole_url;
	}

	/**
	 * Get exif data for files that don't have it.
	 */
	public function cron_get_exif() {
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
			$this->sql_connect();
			$this->sql_update_cron_status('get_exif');
			$this->sql_disconnect();

		} while (!empty($queue));

		fputs($pipes[0], "-stay_open\nFalse\n");
		fflush($pipes[0]);
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);

		$this->sql_connect();
		$this->sql_update_cron_status('get_exif',true);
		$this->sql_disconnect();
	}

	/**
	 * Look for rows that haven't had their exif data processed and handle them.
	 */
	public function cron_process_exif() {
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
				file != \"\"
				AND exif IS NOT NULL
				AND exif != \"\"
				AND flagged IS NOT TRUE
			)");

			$this->sql_connect();
			$this->_sql->query("BEGIN DEFERRED");

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

				$new_vals = array_merge($tags,$geo,$time,$meme);
				$new_vals = array_map('SQLite3::escapeString',$new_vals);

				if ( empty($new_vals['lat']) && !empty($geo['lat']) ) {
					$this->log("GEO ERROR IN {$row['file']}");
				}

				$file = SQLite3::escapeString($row['file']);
				$q = "UPDATE fastback SET ";
				foreach($new_vals as $k => $v){
					if ( empty($v) ) {
						$v = 'NULL';
					}
					$q .= str_replace("'NULL'","NULL"," $k='$v',");
				}
				$q .= "file=file WHERE file='" . SQLite3::escapeString($file) . "'";

				$this->log($q);

				$this->_sql->query($q);
			}
			die();
			$this->sql_update_cron_status('process_exif');
			@$this->_sql->query("COMMIT");
			$this->sql_disconnect();

		} while ( count($queue) > 0 );
		$this->sql_update_cron_status('process_exif',true);
	}

	/**
	 * Clear all locks. These can happen if jobs timeout or something.
	 */
	public function cron_clear_locks() {
		$this->sql_connect();
		$this->_sql->query("UPDATE fastback SET _util=NULL WHERE _util LIKE 'RESERVED%'");
		$this->sql_update_cron_status('clear_locks',true);
		$this->sql_disconnect();
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
		return $xyz;
	}

	/**
	 * For a single-line GPS record, parse out the lat/lon
	 */
	private function _parse_gps_line($line) {
		$xyz = array('lat' => NULL,'lon' => NULL,'elev' => 0);

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

	/**
	 * Do cron upserts
	 */
	private function sql_update_cron_status($job,$complete = false,$meta=false) {
		$do_disconnect = false;

		if ( !isset($this->sql) ) {
			$this->sql_connect();
			$do_disconnect = true;
		}

		$complete = ( $complete ? 1 : 0 );
		$owner = ( $complete ? 'NULL' : "'" . getmypid() . "'");

		if ( $meta !== false ){
			$this->_sql->query("INSERT INTO cron (job,updated,completed,owner,meta)
				values ('$job'," . time() . ",$complete,'" . getmypid() . "','" . SQLite3::escapeString($meta) . "')
				ON CONFLICT(job) DO UPDATE SET updated=".time().",completed=$complete,owner=$owner,meta='" . SQLite3::escapeString($meta). "'");
		} else {
			$this->_sql->query("INSERT INTO cron (job,updated,completed,owner)
				values ('$job'," . time() . ",$complete,'" . getmypid() . "')
				ON CONFLICT(job) DO UPDATE SET updated=".time().",completed=$complete,owner=$owner");
		}

		if ( $do_disconnect ) {
			$this->sql_disconnect();
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
	public function cron() {
		/*
		 * Start a buffer and prep to run something in the background
		 * CLI doesn't get a time limit or a buffer
		 */

		if (php_sapi_name() !== 'cli') {
			ob_start();
			header("Connection: close");
			header("Content-Encoding: none");
			header("Content-Type: text/plain");
			set_time_limit($this->_crontimeout);
		}

		register_shutdown_function(function(){
			$this->log("Shutting down!");
			$this->sql_connect();
			$this->_sql->query("UPDATE cron SET owner=NULL WHERE owner='" . getmypid() . "'");
		});

		if ( !isset($this->_concurrent_cronjobs) ) {
			$this->_concurrent_cronjobs = ceil(`nproc`/4);
		}

		$jobs = array( 'find_new_files', 'get_exif', 'process_exif', 'make_thumbs', 'remove_deleted', 'clear_locks');
		$cron_status = array();

		$this->sql_connect();
		$this->_sql->query("UPDATE cron SET completed=0 WHERE updated < " . time() - (60 * 60)); // Everything can run at least hourly. 

		/*
		 * Get the current cron status
		 */
		$q_get_cron = "SELECT job,updated,completed,owner FROM cron";
		$res = $this->_sql->query($q_get_cron);
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			$cron_status[$row['job']] = $row;
		}

		foreach($jobs as $job) {
			if ( empty($cron_status[$job] )) {
				$cron_status[$job] = array(
					'job' => $job,
					'updated' => 0,
					'completed' => false,
					'owner' => NULL
				);
			}
		}

		print_r($cron_status);

		/*
		 * Find the next job to do
		 */
		shuffle($cron_status);

		$job_to_run = FALSE;
		$jobs_running = $this->_sql->querySingle("SELECT COUNT(*) FROM cron WHERE owner IS NOT NULL");
		if ( $jobs_running <  $this->_concurrent_cronjobs ) {
			foreach($cron_status as $job) {
				if ( !empty($job['owner']) ) {
					continue;
				}

				if ( $job['completed'] && time() - $job['updated'] < 60*60 ) {
					continue;
				}

				$job_to_run = "cron_{$job['job']}";
				break;
			}
		}

		if ( in_array($_GET['cron'],$jobs) ){
			$job_to_run = 'cron_' . $_GET['cron'];
		}

		if ( $job_to_run !== FALSE) {
			print("About to run $job_to_run\n");
		}

		// http1 and non-fcgi implementations
		if (php_sapi_name() !== 'cli') {
			$old_val = ini_set('catch_workers_output','yes'); // Without this our error_log calls don't get sent to the error log. 
			var_dump(ini_get('catch_workers_output'));
			$size = ob_get_length();
			header("Content-Length: $size");
			http_response_code(200);
			ob_end_flush();
			@ob_flush();
			flush();
			@fastcgi_finish_request();
		}

		$this->log($job_to_run);
		$this->$job_to_run();
	}
}
