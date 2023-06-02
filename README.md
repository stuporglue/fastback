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

Disclaimers and Decisions
-------------------------

I'm making this for myself and my family. 

Fastback is designed to be simple and for use by a small, trusted group. The default 
settings leave the config file and sqlite files accessable which may give users
information about your server or the users and passwords you have configured. 

Please feel free to submit bug reports and feature requests.

Requirements
-------------

* Linux PHP server
* Sqlite3 support
* find (command line tool)
* ffmpeg (command line tool)

Strongly Recommended
--------------------

* PHP-CLI
* Writable cache directory
* vipsthumbnail installed
* jpegoptim (optional, for smaller thumbs)

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
 * Visit your site. Every page load will process more files. 

Advanced Instructions
---------------------
* See fastback.ini.sample and configure as you see fit
* Run ```index.php help``` from the command line to process files all at once

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
* Handle processing through index.php page loads
* Loading page
* Prettier pwa offline page and more testing of pwa offline page.
