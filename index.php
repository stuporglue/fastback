<?php

class FastbackOutput {

	function __construct(){

		$this->filecache = __DIR__ . '/cache/';
		$this->cacheurl = dirname($_SERVER['SCRIPT_NAME']) . '/cache/';
		$this->photobase = __DIR__ . '/';
		$this->photourl = dirname($_SERVER['SCRIPT_NAME']) . '/';
		$this->staticurl = dirname($_SERVER['SCRIPT_NAME']) . '/';

		if ( file_exists(__DIR__ . '/fastback.ini') ) {
			$settings = parse_ini_file(__DIR__ . '/fastback.ini');
			foreach($settings as $k => $v) {
				$this->$k = $v;
			}
		}

		if ( !is_dir($this->filecache) ) {
			error_log("Fastback cache directory {$this->filecache} doesn't exist");
			die("Fastback setup error. See error log.");
		}

		if ( (!empty($_GET['debug']) && $_GET['debug'] === 'true') ) {
			$this->debug = true;
		}

		// Ensure single trailing slashes
		$this->filecache = rtrim($this->filecache,'/') . '/';
		$this->cacheurl = rtrim($this->cacheurl,'/') . '/';
		$this->photobase = rtrim($this->photobase,'/') . '/';
		$this->photourl = rtrim($this->photourl,'/') . '/';
		$this->staticurl = rtrim($this->staticurl,'/') . '/';

		$this->makeoutput();
	}

	function makeoutput() {

		$html = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<title>Hyper List Demo</title>
<link rel="shortcut icon" href="fastback_assets/favicon.png"> 
<link rel="stylesheet" href="fastback_assets/jquery-ui-1.12.1/jquery-ui.min.css">
<link rel="stylesheet" href="fastback_assets/fastback.css">
</head>';

		$html .= '<body class="photos">';
		$html .= '<div id="map"></div>';
		$html .= '<div id="hyperlist_wrap">';
		$html .= '<div id="photos"></div>';
		$html .= '<div id="resizer">';
		$html .= '<input type="range" min="1" max="10" value="5" class="slider" id="zoom">';
		$html .= '</div>';
		$html .= '</div>';
		$html .= '<div id="calendaricon"><input readonly id="datepicker" type="text"></div>';
		$html .= '<script src="fastback_assets/jquery.min.js"></script>';
		$html .= '<script src="fastback_assets/jquery-ui-1.12.1/jquery-ui.min.js"></script>';
		$html .= '<script src="fastback_assets/hyperlist.js"></script>';
		$html .= '<script src="fastback_assets/fastback.js"></script>';
		$html .= '<script>
			var FastbackBase = "' . $_SERVER['SCRIPT_NAME'] . '";
			var FastbackBase = "' . $_SERVER['SCRIPT_NAME'] . '";
			var fastback = new Fastback({
				cacheurl:    "' . $this->cacheurl . '",
				photourl:    "' . $this->photourl .'",
				staticurl:   "' . $this->staticurl . '",
				fastbackurl: "' . $_SERVER['SCRIPT_NAME'] . '"
				});
			</script>';
		$html .= '</body></html>';

		print $html;
	}
}

$fb = new FastbackOutput();
