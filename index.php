<?php

/**
 * INSTRUCTIONS: 
 *
 * 1. Copy this index file to your web directory.
 * 2. Set any setting overrides as needed
 * 3. Delete the print() and exit() lines immediately following this comment!
 *
 * A typical install would be organized like this: 
 *
 *     /var/www/html/my_photos				<-- Web root for this app
 *     /var/www/html/my_photos/fastback/	<-- Fastback install
 *     /var/www/html/my_photos/index.php	<-- Customized copy of this file
 *     /var/www/html/my_photos/2020/		<-- Photo directory
 *     /var/www/html/my_photos/2022/		<-- Photo directory
 */
print("This file shouldn't be used without being copied outside of this directory, and being configured.\n");
exit();




if ( file_exists('fastback') && is_dir('fastback') ) {
	set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . DIRECTORY_SEPARATOR . 'fastback');
}
require_once('fastback.php');
$fb = Fastback::getInstance();

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
// $fb->user['User'] = 'password';

// Give the user permission to flag photos
// $fb->canflag[] = 'Michael';

/*
 * Other Settings
 */

// Directory regex used to find photos (YYYY/MM/DD, or any 4-number/2-number/2-number directories
// $fb->photodirregex = './[0-9][0-9][0-9][0-9]/[0-9][0-9]/[0-9][0-9]/'; 

// Should photos be sorted by folder or by date? If photo exif dates are wrong (eg. scanned photos) then file might make more sense
// $fb->sort_order = 'date';
// $fb->sort_order = 'file'; 

// What types of image files should be accepted
// $fb->supported_photo_types[] = 'tiff'; // Eg add tiff support

// Or maybe we only want jpgs
// $fb->supported_photo_types = array('jpg','jpeg'); // Or just override the list completely

// Change to a different basemap
// Find free options (with certain terms of use) here: https://leaflet-extras.github.io/leaflet-providers/preview/
// $fb->basemap =  "L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
// 	attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
// })";

// Run Fastback with the setup above
$fb->run();
