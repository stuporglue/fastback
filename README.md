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

Commands
--------

* php index.php reset
 - Will wipe SQLITE fastbaack table and re-find all photos
	- Does not delete thumbnails


Database 
--------

fastbackmeta
	key (varchar 20) - the meta key
	value (varchar 255) - the value for the meta key

fastback
	file (text)
	isvideo (bool)
	flagged (bool)
	mtime (int)
	sorttime (date)
	thumbnail (text)


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
* ?test=(anything) - Runs the PHP test function. Useful for testing things. Empty by default

