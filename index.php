<?php
if ( file_exists('fastback') && is_dir('fastback') ) {
	set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . DIRECTORY_SEPARATOR . 'fastback');
}
require_once('fastback.php');
$fb = new Fastback();

# Enable these for debugging!
# $fb->debug = true;
# ini_set("log_errors",1);
# ini_set("error_log",$fb->dir() . '/error.log');

/*
 * About the site
 */
// Change the site title
$fb->sitetitle = "Fastback Photo Gallery";

/*
 * Directories and Files
 */

// Specify where full sized photos are located. This can read-only.
// eg. $fb->photobase = '/mount/bigdisk/photo_album/';
$fb->photobase = __DIR__;

// Specify where the sqlite file is located. The file must be read-write.
// $fb->sqlitefile = '/mount/fastdisk/fastback_gallery.sqlite';

// Specify the cache directory. Technically the cache doesn't have to be writable, we can serve everything dynamically (other than the sqlite file)
// $fb->filecache= '/mount/fastdisk/cachedir';

// Specify where the csv file is saved. The location must be read-write if we're going to use it. It's much faster to have this than to not to.
// Defaults to $fb->filecache/fastback.csv
// $fb->csvfile = '/mount/fastdisk/cachedirfastback_cache.csv';

/*
 * User Permissions
 */

// Add a user account
// $fb->user['Michael'] = 'moore';

// Give the user permission to flag photos
// $fb->canflag[] = 'Michael';

/*
 * Other Settings
 */

// Directory regex used to find photos
// $fb->photodirregex = './[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/'; 

// Should photos be sorted by folder or by date? If photo exif dates are wrong (eg. scanned photos) then file might make more sense
// $fb->photo_order = 'date';
// $fb->photo_order = 'file'; 

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
