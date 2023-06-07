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
	'fastback/css/jquery-ui.min.css',
	'fastback/css/leaflet.css',
	'fastback/css/MarkerCluster.Default.css',
	'fastback/css/MarkerCluster.css',
	'?csv=get',
	'?pwa=manifest',
	'?pwa=down',
];

self.addEventListener('install', event => {
	event.waitUntil((async () => {
		const cache = await caches.open(CACHE_NAME);
		cache.addAll(urls_to_cache);
	})());
});

self.addEventListener('fetch', event => {
	event.respondWith((async () => {
		const cache = await caches.open(CACHE_NAME);

		// Get the resource from the cache.
		const cachedResponse = await cache.match(event.request);
		if (cachedResponse) {
			return cachedResponse;
		} else {
			try {
				// If the resource was not in the cache, try the network.
				const fetchResponse = await fetch(event.request);

				// Save the resource in the cache and return it.

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
					cache.put(event.request, fetchResponse.clone());
				}
				return fetchResponse;
			} catch (e) {
				// The network failed.

				const cache = await caches.open(CACHE_NAME);
				const cachedResponse = await cache.match(OFFLINE_URL);
				return cachedResponse;
			}
		}
	})());
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

