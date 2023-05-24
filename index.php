<?php
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . DIRECTORY_SEPARATOR . 'fastback');
require_once('fastback.php');
$fb = new FastbackOutput();
$fb->run();
