<?php
if ( file_exists('fastback') && is_dir('fastback') ) {
	set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . DIRECTORY_SEPARATOR . 'fastback');
}
require_once('fastback.php');
$fb = new Fastback();
// Change the site title
// $fb->sitetitle = "Moore Family Gallery";

// Add a user account
// $fb->user['Michael'] = 'moore';

// Give the user permission to flag photos
// $fb->canflag[] = 'Michael';

// Specify where full sized photos are located. This can read-only.
// $fb->photobase = '/mount/bigdisk/my_photos/'; 

// Specify where the sqlite file is located. The file must be read-write.
// $fb->sqlitefile = '/mount/fastdisk/fastback_gallery.sqlite';

// Specify where the csv file is saved. The location must be read-write.
// $fb->csvfile = '/mount/fastdisk/fastback_cache.csv';

// Directory regex used to find photos
// $fb->photodirregex = './[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/'; 

// What types of image files should be accepted
// $fb->supported_photo_types[] = 'tiff';

// Or maybe we only want jpgs
// $fb->supported_photo_types = array('jpg','jpeg');

// Change to a different basemap
// Find free options (with certain terms of use) here: https://leaflet-extras.github.io/leaflet-providers/preview/
// $fb->basemap =  "L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
// 	attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
// })";

$fb->run();
