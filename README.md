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
 * Easy photo sharing
 * Password protection
 * Easy to set up (for a web app)

Requirements
-------------

* Linux PHP server
* Sqlite3 support
* find (command line tool)

Strongly Recommended
--------------------

* PHP-CLI
* exiftool (required for geo, tagging and date sorting support)
* ffmpeg (required for video thumbnails)
* Writable cache directory
* vipsthumbnail (for best image thumbnails)
    - As a backup Fastback will try to use convert (ImageMagick, command line program) or GD (PHP library)
* jpegoptim (optional, for smaller thumbs)
* gzip (for smaller csv over the wire)
* htaccess support (for more security of your cache files)

Simple Installation
-------------------
 * Put or link your full-sized photos into a directory which is served by your webserver. 
  - eg. /var/www/html/photos/ 
 * Clone this repository
```
cd /var/www/html/photos/
git clone https://github.com/stuporglue/fastback.git
```
 * Copy fastback/index.php into /var/www/html/photos
```
cp fastabck/index.php .
```
 * Make sure that the fastback is writable by the web server process. Eg. if you are using Apache an it runs as user www-data: 
```
chgrp -R www-data fastback
chmod g+w -R www-data fastback
chmod g+x fastback
```
 * Visit your site. 

Advanced Instructions
---------------------
* Clone fastback into a web server directory
```
cd /var/www/html/photos/
git clone https://github.com/stuporglue/fastback.git
```
 * Copy fastback/index.php into /var/www/html/photos
```
cp fastabck/index.php .
```
* Edit index.php and mdify the variables of the Fastback object.  

Settings
--------
Sometimes the best documentation is code. See fastback.php for 
all options. They are pretty well documented. Some options you might
want to set include: 

```
$fb = new Fastback();
// Change the site title
$fb->sitetitle = "Moore Family Gallery";

// Add a user account
$fb->user['Michael'] = 'moore';

// Give the user permission to flag photos
$fb->canflag[] = 'Michael';

// Specify where full sized photos are located. This can read-only.
$fb->photobase = '/mount/bigdisk/my_photos/'; 

// Specify where the sqlite file is located. The file must be read-write.
$fb->sqlitefile = '/mount/fastdisk/fastback_gallery.sqlite';

// Specify where the csv file is saved. The location must be read-write.
$fb->csvfile = '/mount/fastdisk/fastback_cache.csv';

// Directory regex used to find photos
$fb->photodirregex = './[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/'; 

// What types of image files should be accepted
$fb->supported_photo_types[] = 'tiff';

$fb->run();
```

License, Credits and Thanks
----------------------------
This project is under the [LICENSE](MIT License). 

It uses code from many other projects under various Open Source licenses, including: 
 * [https://github.com/tbranyen/hyperlist](hyperlist)
 * [PapaParse](https://www.papaparse.com/)
 * jQuery ([https://jquery.com/](jQuery), [https://jqueryui.com/](jQuery-ui))
 * Leaflet ([https://leafletjs.com/](Leaflet.js), [https://github.com/Leaflet/Leaflet.markercluster](Leaflet MarkerCluster))
 * [https://hammerjs.github.io/](hammer.js)

Many thanks to the maintainers and developers of these projects for making this possible. I couldn't have done it without you.

TODO
----
* Location search input 
* Don't collapse exif tags
* Prettier pwa offline page and more testing of pwa offline page.

Disclaimers and Decisions
-------------------------

I'm making this for myself and my family. 

Fastback is designed to be simple and for use by a small, trusted group. It is not meant
for big groups or heavy usage. It is optimized for functionality. 

Basic steps have been taken for security and performance, but it is not hardened or 
anything like that. 

Please feel free to submit bug reports and feature requests.


