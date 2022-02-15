Fastback
========

A fast way to look back at photos. Work in progress supporting over 180,000 photos.

Prerequisites
-------------

* Photos must be stored in YYYY/MM/DD directories
* Linux PHP server
* PHP-CLI
* Sqlite3 support
* Writable cache directory
* vipsthumbnail installed
* find
* ffmpeg
* jpegoptim

CLI Commands
--------
index.php [command] [debug]

Commands:
	* handlenew (default) - Brings the database from whatever state it is in, up to date. This is the command you usually want to run.
		- load_cache
		- make_thumbs
		- get_time
		- geo_geo
		- make_json
		- make_geojson
	* db_test - Just tests if the database exists or can be created, and if it can be written to.
	* reset_cache – Truncate the database. It will need to be repopulated. Does not touch files.
	* load_cache – Finds all new files in the library and make cache entries for them. Does not generate new thumbnails.
	* make_thumbs – Generates thumbnails for all entries found in the cache.
	* get_exif – Read needed exif info into the database. Must happen before gettime or getgeo
	* get_time – Uses exif data or file creation or modified time to find the files's sort time
	* get_geo – Uses exif data to find the media geolocation info so it can be shown on a map
	* make_json - Regenerates the cached .json file based on the cache database. Doesn't touch or look at files.
	* make_geojson - Regenerates the cached .geojson file based on the cache database. Doesn't touch or look at files.
	* full_reset - Runs `resetcache` first and then runs handlenew
	
	All commands can have the word debug after them, which will disable the pretty print, and be more verbose.


Database 
--------

fastbackmeta
	key (varchar 20) - the meta key
	value (varchar 255) - the value for the meta key

fastback
	file (text)
	exif (text)
	isvideo (bool)
	flagged (bool)
	nullgeom (bool)
	mtime (int)
	sorttime (date)
	thumbnail (text)
	_util (text)
	geom (POINT Z geometry, 4326) - requires spatialite


Cache files
-----------
* fasback.sqlite - The sqlite database with the list of files and associated data
* fastback.json.gz - A cache of the json for the photos
* fastback.lock - A lock file to help us open the sqlite database safely with the ability to wait a bit if it's in use.


$_GET options
-------------

* ?debug=true - Enables debug output and avoids the cache
* ?flag=filepath - Flags a file (marks file and hides it in JSON output during next time json is compiled)
* ?get=photojson - Returns the json of all the files
* ?get=geojson - Returns a geojson file with bounding box and xyz coordinates of photos which have geolocation info
* ?test=true  - Runs the PHP test function. Useful for testing things. Empty by default

Exif data
---------

The following tags are used, in this order, to find a timestamp for the file. A found timestmap will not override the date of the YYYY/MM/DD folder struture.
 - DateTimeOriginal
 - CreateDate
 - CreationDate
 - DateCreated
 - TrackCreateDate
 - MediaCreateDate
 - GPSDateTime
 - ModifyDate
 - MediaModifyDate
 - TrackModifyDate
 - FileCreateDate
 - FileModifyDate

The following tags are used to find location info

 - GPSPosition
 - GPSCoordinates
 - These four values together
 	+ GPSLatitudeRef
 	+ GPSLatitude
 	+ GPSLongitudeRef
 	+ GPSLongitude
 
 	Additionally, if the altitude tag is present, the altitude is recorded. Otherwise it is set to 0
 	+ GPSAltitude

License
-------
This project is under the MIT License. 

It uses code from many other projects under various Open Source licenses, including: 
 * hyperlist 
 * PapaParse 
 * jQuery (jQuery, jQuery-ui, jquery-hammer)
 * Leaflet (Leaflet.js, Leaflet MarkerCluster)
 * hammer.js

Many thanks to the maintainers and developers of these projects for making this possible.
