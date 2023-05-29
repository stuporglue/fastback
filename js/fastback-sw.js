const CACHE_NAME = 'fastback';
const OFFLINE_URL = '?pwa=down';

self.addEventListener('install', event => {
	event.waitUntil((async () => {
		const cache = await caches.open(CACHE_NAME);
		cache.addAll([
			'/',
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
			OFFLINE_URL,
			'?pwa=sw'
		]);
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
          cache.put(event.request, fetchResponse.clone());
          return fetchResponse;
        } catch (e) {
          // The network failed.
		  console.log("Fetch failed; returning offline page instead.", e);

          const cache = await caches.open(CACHE_NAME);
          const cachedResponse = await cache.match(OFFLINE_URL);
          return cachedResponse;
        }
    }
  })());
});
