L.Control.MapFilter = L.Control.extend({
    onAdd: function(map) {
		var button = jQuery('<div id="mapfilter">🛰</div>');
		button.on('click',function(){console.log("Clicky");});
        return button[0];
    },

    onRemove: function(map) {
        // Nothing to do here
    }
});

L.control.mapfilter = function(opts) {
    return new L.Control.MapFilter(opts);
}

L.control.mapfilter({ position: 'topleft' }).addTo(map);
