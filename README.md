Fastback
========

A fast way to look back at photos. Work in progress supporting over 180,000 photos.

Prerequisites
-------------

* Linux PHP server
* Photos must be stored in YYYY/MM/DD directories
* PHP-CLI
* Writable cache directory
* vipsthumbnail installed
* Sqlite3 support
* find

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

