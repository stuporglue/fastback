const CACHE_NAME = 'fastback';
const SW_VERSION = 'SW_FASTBACK_TS';
const BASEURL = 'SW_FASTBACK_BASEURL';
const OFFLINE_URL = '?pwa=down';

var urls_to_cache = [
	'fastback/js/jquery.min.js',
	'fastback/js/hammer.js',
	'fastback/js/leaflet-src.js',
	'fastback/js/jquery-ui.min.js',
	'fastback/js/hyperlist.js',
	'fastback/js/papaparse.min.js',
	'fastback/js/jquery.hammer.js',
	'fastback/js/leaflet.markercluster.js',
	'fastback/js/fastback.js',
	'fastback/js/md5.js',
	'fastback/css/fastback.css',
	'fastback/img/favicon.png',
	'fastback/img/loading.png',
	'fastback/img/calendar.png',
	'fastback/img/exit.png',
	'fastback/img/loading.png',
	'fastback/img/playbutton.png',
	'fastback/img/calendar.png',
	'fastback/img/exit.png',
	'fastback/img/favicon.png',
	'fastback/img/fb.png',
	'fastback/img/globe.png',
	'fastback/img/live.png',
	'fastback/img/movie.webp',
	'fastback/img/picture.webp',
	'fastback/img/rewind.png',
	'fastback/img/share.png',
	'fastback/img/tag.png',
	'fastback/img/tagging.png',
	'fastback/img/whatsapp.png',
	'fastback/css/jquery-ui.min.css',
	'fastback/css/leaflet.css',
	'fastback/css/MarkerCluster.Default.css',
	'fastback/css/MarkerCluster.css',
	'?csv=get',
	'?pwa=manifest',
	'?pwa=down',
];

/**
 * Check if cached API data is still valid
 * @param  {Object}  response The response object
 * @return {Boolean}          If true, cached data is valid
 */
var isValid = function (response) {
	if (!response) return false;
	var fetched = response.headers.get('sw-fetched-on');
	// Max valid time is 12 hours
	if (fetched && (parseFloat(fetched) + (1000 * 60 * 60 * 12)) > new Date().getTime()) return true;
	return false;
};

self.addEventListener('install', event => {
	event.waitUntil((async () => {
		const cache = await caches.open(CACHE_NAME);
		cache.addAll(urls_to_cache);
	})());
});



/**
 * https://gomakethings.com/how-to-set-an-expiration-date-for-items-in-a-service-worker-cache/
 */
self.addEventListener('fetch', event => {
	event.respondWith(
		cache.match(request).then(function (response) {
			
			if ( isValid(response) ) {
				return response;
			}

			return fetch(request).then(functin (response) {

				var cache_this = false;
				var cleaned_url = event.request.url.replace('SW_FASTBACK_BASEURL','').replace(/\?ts=[0-9]+/,'').replace(/&ts=[0-9]+/,'')
				if ( urls_to_cache.indexOf(event.request.url) !== -1 ) {
					cache_this = true;
				} else if ( urls_to_cache.indexOf(cleaned_url) !== -1 ) {
					cache_this = true;
				} else if ( event.request.url.indexOf('?thumbnail=') !== -1 ) {
					cache_this = true;
				} else if ( event.request.url.indexOf('?csv=get') !== -1 ) {
					cache_this = true;
				} else if ( event.request.url.indexOf('?download=') !== -1 ) {
					cache_this = true;
				} else if ( cleaned_url.indexOf('fastback/') === 1) {
					cache_this = true;
				} 

				if ( cache_this ) {
					var copy = response.clone();
					event.waitUntil(caches.open('api').then(function (cache) {
						var headers = new Headers(copy.headers);
						headers.append('sw-fetched-on', new Date().getTime());
						return copy.blob().then(function (body) {
							return cache.put(request, new Response(body, {
								status: copy.status,
								statusText: copy.statusText,
								headers: headers
							}));
						});
					}));
				}

				return response;
			}).catch(function (error) {
				return caches.match(request).then(function (response) {
					return response || caches.match(OFFLINE_URL);
				});
			});
		});
});

self.addEventListener('activate', event => {
  // delete any caches that aren't in expectedCaches
  // which will get rid of static-v1
  event.waitUntil( caches.keys().then(keys => Promise.all(
      keys.map(key => {
        if (!urls_to_cache.includes(key)) {
          return caches.delete(key);
        }
      })
    )).then(async () => {
	  const cache = await caches.open(CACHE_NAME);
	  cache.addAll(urls_to_cache);
    })
  );
});
