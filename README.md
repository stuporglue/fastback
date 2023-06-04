Fastback
========

Fastback is a tool for navigating a large home collection of photos. Large in 
this case means it works well with at least up to 200,000 photos. 

Core features: 

 * Sort thousands of photos by date
 * Navigate timeline quickly, both linearly (scrolling) and direct jumping (date picker)
 * Show all photos taken on today's date in previous years
 * Use map to find photos taken in a specific location
 * Use photo tags to see all photos of specific people

Additional features:
 * Mobile friendly
 * Convert non-web-friendly image formats on the fly (eg. HEIC)
 * Easy photo sharing
 * Password protection
 * Easy to set up (for a web app)
 * Reads exif data from xmp sidecar files

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
    - As a backup Fastback will try to use convert (ImageMagick, command line program) or 
    GD (PHP library)
* jpegoptim (optional, for smaller thumbs)
* gzip (for smaller csv over the wire)
* htaccess support (for more security of your cache files)

Installation and Setup
----------------------
 * Clone this repository into a directory on your web server.
```
cd /var/www/html/photos/
git clone https://github.com/stuporglue/fastback.git
```

 * Copy `fastback/index.php` up a level into `/var/www/html/photos`
```
cp fastabck/index.php .
```

 * If your photo directories are NOT in `/var/www/html/photos` then edit
 `index.php`. Set `$fb->photobase` to the path to your files. If your photos are 
 in the same directory as `index.php` you can skip this step.
 ```
 $fb->photobase = '/mount/bigdisk/photo_album/';
 ```

 * Visit your site at `https://yoursite.com/photos/`


First Run
---------
The first visit will attempt to create the cache directory and find all photos and videos.
It will also try to create an sqlite database file and CSV file of the photo info at 
`cache/fastback.csv`. 

If any of these can't be created, Fastback should log the error display a message on web page.

If they are created you should see your photos right away. 

As you use the site a background worker will run some tasks to extract metadata 
(photo creation time, coordinates, tags). As this data is processed the site will become
more and more useful. 


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



Configuration
-------------
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

// Change to a different basemap
// $fb->basemap =  "L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
// 	attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
// })";

$fb->run();
```

Usage
-----

### Basics
Once it is installed you should be able to just use the site. A fake cron job 
gets kicked off 30 seconds after every page load and runs every 5 minutes while
the page is open. 

The cron job finds new files, loads metadata from the files exif info and 
detects deleted photos. Cron jobs run for up to 120 seconds at a time and on
up to 1/4 of the cores on your server.

The first page load may take a minutes or two to load as a list of files is found. 

### Behind the scenes
Extracting exif data is a very slow process since every file must be read. Until
exif data has been read photos will sort based on the file modification time
instead of the time stored in the files metadata. The same is true for tag
filtering and geolocation. 

Thumbnails are generated on first access.

### Running cron on the command line
If you want to handle these processes more quickly, you can run `php index.php`
on the command line. This will run without timing out.

The make_thumbs cronjob is disabled by default. You can enable it in index.php 
(`$fb->cronjob[] = 'make_thumbs';`) and build all thumbs at the cost of disk space.

### Debugging
Set `$fb->debug = true;` and quite verbose logging will be sent to `$fb->filecache/fastback.log`. 


Troubleshooting
---------------
* My photos are out of order
    - Photos are temporarily sorted by their file modification time. Reading the exif
    data is a slow process. You can see the status of these tasks by visiting `https://yoursite.com/photos/cron=status`.

* The map feature and tag features aren't showing up on my website
    - These features are enabled automatically once coordinates (for the map) or tags (for the tags!) are found.
    If you have a photo that you think has such metadata but it isn't reflected on the site, please send me the photo
    so I can see how the exif data is structured. I have 200,000+ photos but I'm sure there are other exif formats
    that Fasback doesn't handle correctly yet. 

* The site freezes up without any thumbnails
    - By default thumbnails are generated and saved on-demand. This saves on disk space at the cost of performance.
    You can pre-generate all the thumbnails by enabling the make_thumbs cron job in your index.php file. 
    ```
    $fb->cronjobs[] = 'make_thumbs';
    ```
    You can also run the job manually from the command line. Running from the command line won't time out like
    the browser-based cron tasks do. You can run it like so: 
    ```
    >$ php index.php make_thumbs
    ```

* Some thumbnails can be created and some cannot. What's going on? 
    - If you use the command line to run `php index.php make_thumbs` the files and folders will be created
    with the permissions of the user you are logged in as. The thumbs created via the web site 
    will be crated with the permissions of the web server user. Try changing the permissions of the 
    cache folder after making thumbs on the command line. If your webserver runs as user `www-data`, something
    like this might work:
    ```
    chown -R www-data /path/to/cachedir
    chmod -R 750 /path/to/cachedir
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

Many thanks to the maintainers and developers of these projects for making this 
possible. I couldn't have done it without you.


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
