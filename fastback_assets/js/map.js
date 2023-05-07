var map = L.map('map').setView([0,0], 2);

//		convenienturl.com/photos/?get=geojson

var base_map = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
	maxZoom: 19,
	attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
});

base_map.addTo(map);

var geolayer;

$.getJSON("index.php?get=geojson", function(data) {
	geolayer = L.markerClusterGroup();
	geolayer.addLayer(L.geoJson(data));
	geolayer.addTo(map);
});
