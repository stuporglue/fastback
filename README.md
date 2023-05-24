Fastback
========

Fastback is a tool for navigating a large home collection of photos. Large in 
this case means it works well with at least up to 200,000 photos. 

Core features: 

 * Sort thousands of photos by date
 * Navigate timeline quickly, both linearly (scrolling) and direct jumping (date picker)
 * Show all photos taken on today's date in previous years
 * Use map to find photos taken in a specific location
 * Use photo tags to see all photos of specific people [NOT YET IMPLEMENTED]

Additional features:
 * Mobile friendly
 * Convert non-web-friendly image formats on the fly (eg. HEIC)
 * Sharing buttons for Facebook, WhatsApp and email
 * Basic password protection
 * Easy to set up (for a web app) [PARTIALLY IMPLEMENTED]

Disclaimers and Decisions
-------------------------

I'm making this for myself and my family. 

Fastback is designed to be simple and for use by a small, trusted group. The default 
settings leave the config file and sqlite files accessable which may give users
information about your server or the users and passwords you have configured. 

Please feel free to submit bug reports and feature requests.

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
		- make_csv
	* db_test - Just tests if the database exists or can be created, and if it can be written to.
	* reset_cache – Truncate the database. It will need to be repopulated. Does not touch files.
	* load_cache – Finds all new files in the library and make cache entries for them. Does not generate new thumbnails.
	* make_thumbs – Generates thumbnails for all entries found in the cache.
	* get_exif – Read needed exif info into the database. Must happen before gettime or getgeo
	* get_time – Uses exif data or file creation or modified time to find the files's sort time
	* get_geo – Uses exif data to find the media geolocation info so it can be shown on a map
	* make_csv - Regenerates the cached .csvfile based on the cache database. Doesn't touch or look at files.
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
* fastback.csv - A cache of the csv for the photos
* fastback.lock - A lock file to help us open the sqlite database safely with the ability to wait a bit if it's in use.


$_GET options
-------------

* ?flag=filepath - Flags a file (marks file and hides it in CSV output during next time csv is compiled)
* ?proxy=filepath - Proxies a file from an unsupported format to a browser supported format.
* ?download=filepath - Requests a file for download
* ?csv=1 - Sends the photo list csv file

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

Map Modes
---------

 * Default mode – The maps shows the locations of the photos in the photo pane. The two states are all photos or rewind (all photos on this date across the years).
 - In this mode there are two map layers. The cluster layer, showing all photos in the scroll, and a geojson layer with dots showing where the currently visible photos are on the map. 
 * Map Filter mode – In this mode, the photo pane is filtered according to what is visible on the map.
 - In this mode there are two map layers. The cluster layer, showing all photos in the scroll, and a geojson layer with dots showing where the currently visible photos are on the map. 

 * Hovering over or clicking a map cluster or marker should flash the photo (if visible) in the photo pane.
 * Hovering over a photo should flash the geojson marker on the map

License
-------
This project is under the [LICENSE](MIT License). 

It uses code from many other projects under various Open Source licenses, including: 
 * [https://github.com/tbranyen/hyperlist](hyperlist)
 * [PapaParse](https://www.papaparse.com/)
 * jQuery ([https://jquery.com/](jQuery), [https://jqueryui.com/](jQuery-ui))
 * Leaflet ([https://leafletjs.com/](Leaflet.js), [https://github.com/Leaflet/Leaflet.markercluster](Leaflet MarkerCluster))
 * [https://hammerjs.github.io/](hammer.js)

Many thanks to the maintainers and developers of these projects for making this possible.


TODO
----
* Location search input 
* Fix fullscreen android
* Better icon
* Face tag support
* Make it a progressive web app
* Make movies stop playing when closing thumb view
