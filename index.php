<?php
if ( file_exists('fastback') && is_dir('fastback') ) {
	set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . DIRECTORY_SEPARATOR . 'fastback');
}
require_once('fastback.php');
$fb = new Fastback();
$fb->run();
