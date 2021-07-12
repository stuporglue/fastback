<?php

declare(ticks=1);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

class fastback {

	var $cache = "/shared/big/no_backup/web_cache/"; 
	var $photobase = "/shared/big/Photos/";
	var $db_lock_file = '/shared/big/no_backup/web_cache/fastback.lock';
	var $db_lock;

	var $supported_file_types = array(
		'png',
		'jpg',
		'heic',
		'jpeg',
		'bmp',
		'gif',
		'tif',
		'heic',
	);
	var $blankpng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+P+/HgAFhAJ/wlseKgAAAABJRU5ErkJggg==';

	var $meta =array();

	var $sql;

	var $cores = 4;



	/**
	 * Kick it off
	 */
	function __construct(){
		// Hard work should be done via cli
		if (php_sapi_name() === 'cli') {
			$this->load_db_cache();
			$this->make_thumbnails();

			// also regenerate the json
			ob_start();
			unlink($this->cache . '/fastback.json.gz');
			$this->streamjson();
			ob_end_clean();

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

		$q_create_files = "CREATE TABLE IF NOT EXISTS fastback ( file TEXT PRIMARY KEY, mtime INTEGER, sorttime DATE, thumbnail TEXT)";

		$res = $this->sql->query($q_create_files);
		//var_dump($res);
        
		$q_create_files = "CREATE TABLE IF NOT EXISTS flagged ( file TEXT PRIMARY KEY )";

		$res = $this->sql->query($q_create_files);
	}

	/**
	 * Get all modified files into the db cache
	 */
	public function load_db_cache() {
		$this->sql_connect();

		$lastmod = '19000101';
		if ( !empty($this->meta['lastmod']) ){
			$lastmod = $this->meta['lastmod'];
		}

		chdir($this->photobase);
		$filetypes = implode('\|',$this->supported_file_types);
		$cmd = 'find . -type f -regextype sed -iregex  "./[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/.*\(' . $filetypes . '\)$" -newermt ' . $lastmod;
		$modified_files_str = `$cmd`;
		//print "$cmd\n";
		$modified_files = explode("\n",$modified_files_str);

		print "Building cache for " . count($modified_files) . " files modified since $lastmod\n";
		flush();

		$today = date('Ymd');
		$multi_insert = "INSERT INTO fastback (file,mtime,sorttime) VALUES ";
		$multi_insert_tail = " ON CONFLICT(file) DO NOTHING";
		$collect = array();
		$maxtime = 0;
		$togo = count($modified_files);
		foreach($modified_files as $one_file){
			$mtime = filemtime($one_file);
			$collect[] = "('" . SQLite3::escapeString($one_file) . "','" . SQLite3::escapeString($mtime) . "','" . SQLite3::escapeString(preg_replace('|.*([0-9]{4})/([0-9]{2})/([0-9]{2})/.*|','\1-\2-\3',$one_file)) . "')";

			if ( count($collect) > 1000 ) {
				$sql = $multi_insert . implode(",",$collect) . $multi_insert_tail;	
				$this->sql->query($sql);
				$collect = array();
				$togo -= 1000;
				print "Upserted 1000, $togo left to go\n";
			}

			$mtime_date = date('Ymd',$mtime);
			if($mtime_date > $maxtime){
				$maxtime = $mtime_date;
			}
		}

		if ($maxtime > $today){
			$maxtime = $today;
		}

		$this->sql->query("INSERT INTO fastbackmeta (key,value) values ('lastmod',$maxtime) ON CONFLICT(key) DO UPDATE SET value=$maxtime");
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
			$res = $this->sql->querySingle("SELECT COUNT(*) FROM fastback WHERE thumbnail IS NULL",);
			print "PARENT: $res more to go\n";
			$this->sql_disconnect();
			sleep(1);
		}
	}

	private function _make_thumbnails($childno = "Unknown") {
		echo "Child $childno pid is " . getmypid() . "\n";

		$made_some = false;
		do {
			$queue = array();
			$this->sql_connect();
			$res = $this->sql->query("UPDATE fastback SET thumbnail='RESERVED-" . getmypid() . "' WHERE thumbnail IS NULL AND FILE != '' LIMIT 1000");
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

				$thumbnailfile = $this->cache . ltrim($file,'./') . '.jpg';

				if ( !file_exists($thumbnailfile) ) {
					$dirname = dirname($thumbnailfile);
					if (!file_exists($dirname) ){
						@mkdir($dirname,0700,TRUE);
					}

					$shellfile = escapeshellarg($file);
					$shellthumb = escapeshellarg($thumbnailfile);
					$cmd = "vipsthumbnail --size=120x120 --output=$shellthumb --smartcrop=attention $shellfile";
					echo "\tChild $childno -- $cmd\n";
					$res = `$cmd`;
					$cmd = "jpegoptim --strip-all --strip-exif --strip-iptc $shellthumb";
					echo "\tChild $childno -- $cmd\n";
					$res = `$cmd`;
					$made_some = true;
				}
				// print "$childno";
				$made_thumbs[$file] = $thumbnailfile;
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

		if ($made_some) {
			@unlink($this->cache . 'fastback.json');
		}
	}

	private function sql_connect($try_no = 1){
		if (php_sapi_name() === 'cli') {
			$this->db_lock = fopen($this->db_lock_file,'w');
			if( flock($this->db_lock,LOCK_EX)){
				$this->sql = new SQLite3($this->cache .'fastback.sqlite');
			} else {
				throw new Exception("Couldn't lock db");
			}

			$this->setup_db();
		} else {
			// $this->sql = new SQLite3($this->cache .'fastback.sqlite',SQLITE3_OPEN_READONLY);
			$this->sql = new SQLite3($this->cache .'fastback.sqlite');
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

	public function makeoutput() {
		if (!empty($_GET['get']) && $_GET['get'] == 'photojson'){
			$this->streamjson();
		} else if (!empty($_GET['get']) && $_GET['get'] == 'js') {
			$this->makejs();
        } else if (!empty($_GET['flag'])) {
            $this->flag_photo();
		} else {
			$this->makehtml();
		}
	}

	public function streamjson() {
		$json = array(
			'yearmonthindex' => array(),
			'tags' => array(),
			);

		$this->sql_connect();
		$cf = $this->cache . '/fastback.json.gz';
		@header("Cache-Control: \"max-age=1209600, public");
		@header("Content-Type: application/json");
		@header("Content-Encoding: gzip");
		if (file_exists($cf)) {
			header('Content-Length: ' . filesize($cf));
			readfile($cf);
			exit();
		}

		$res = $this->sql->query("SELECT file,sorttime FROM fastback WHERE thumbnail IS NOT NULL AND thumbnail NOT LIKE 'RESERVE%' ORDER BY sorttime DESC");
		$last_date = NULL;
		$last_year = NULL;
		$idx = 0;
		while($row = $res->fetchArray(SQLITE3_ASSOC)){
			if ( $last_date != $row['sorttime'] ) {
				$last_date = $row['sorttime'];

				$this_year_month = substr($last_date,0,7);

				if (empty($json['yearmonthindex'][$this_year_month])) {
					$json['yearmonthindex'][$this_year_month] = $idx;
				}
			}
            $base = basename($row['file']);
			$json['tags'][] = '<img id="photo-' . $idx .'" data-date="' . $row['sorttime'] . '" class="thumbnail" src="' . htmlentities(substr($row['file'],2)) . '.jpg" " alt="' . $base . '" />';
			$idx++;
		}

		$this->sql_disconnect();

		$str = json_encode($json,JSON_PRETTY_PRINT);
		file_put_contents('compress.zlib://' . $cf,$str);
		print($str);
	}

	public function makehtml(){
		$dirname = dirname($_SERVER['REQUEST_URI']);
		$html = '<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
		<link rel="shortcut icon" href="'.$dirname.'/fastback.png"> 
		<link rel="apple-touch-icon" href="'.$dirname.'/fastback.png">
		<title>Moore Photos</title>
		<link rel="stylesheet" href="'.$dirname.'/fastback.css">
		<base href="'. $this->cache . '">
        <style>
            .photos .thumbnail,.body { background-image: url(\'' . $this->cache . 'fastback.png\'); };
        </style>
    </head>
	<body>
		<div class="photos" id="photos"><div id="photospacer"></div></div>
		<div class="scroller"></div>
		<div class="calendarpick"><div class="year"></div><div class="calendar">
			<div class="calendarrow">
				<div id="calpick-jan">Jan</div>
				<div id="calpick-feb">Feb</div>
				<div id="calpick-mar">Mar</div>
			</div>
			<div class="calendarrow">
				<div id="calpick-apr">Apr</div>
				<div id="calpick-may">May</div>
				<div id="calpick-jun">Jun</div>
			</div>
			<div class="calendarrow">
				<div id="calpick-jul">Jul</div>
				<div id="calpick-aug">Aug</div>
				<div id="calpick-sep">Sep</div>
			</div>
			<div class="calendarrow">
				<div id="calpick-oct">Oct</div>
				<div id="calpick-nov">Nov</div>
				<div id="calpick-dec">Dec</div>
			</div>
		</div></div>
		<div id="resizer">
			<input type="range" min="1" max="10" value="5" class="slider" id="myRange">
		</div>
		<div id="notification"></div>
		<div id="thumb"><div id="thumbcontent"></div><div id="thumbcontrols"></div><div id="thumbclose">🆇</div></div>
	</body>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
	<script src="'.$dirname.'/fastback.js"></script>
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
        $stmt = $this->sql->prepare("INSERT INTO flagged (file) VALUES (:file) ON CONFLICT(file) DO UPDATE SET file=file");
        $stmt->bindValue(':file',$_GET['flag']);
        $stmt->execute();
        $this->sql_disconnect();
		header("Content-Type: application/json");
		header("Cache-Control: no-cache");
        print json_encode(array('file_flagged' => $_GET['flag']));
    }
}

new fastback();
