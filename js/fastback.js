Fastback = class Fastback {
	/**
	 * Load the data and set up event handlers
	 */
	constructor(args) {
		this.setProps();
		jQuery.extend(this,args);
		var self = this;

		Papa.parse(args.csvurl,{
			download:true,
			skipEmptyLines: true,
			complete: function(res){

				self.photos = res.data.map(function(r){
					return {
						'file': r[0],
						'isvideo': r[1] == 1,
						'date': new Date(r[2] * 1000),
						'type': 'media',
						'coordinates': (isNaN(parseFloat(r[4])) ? null : [parseFloat(r[4]),parseFloat(r[3])]),
						'tags': r[5].split('|')
					};
				});

				var tmptags,j,i;

				self.orig_photos = self.add_date_blocks(self.photos);

				for(i = 0;i<self.orig_photos.length;i++){
					self.orig_photos[i].id = i;
				}

				self.photos = self.orig_photos;

				self.tags = {};
				for(i = 0; i < self.orig_photos.length; i++){
					if (self.orig_photos[i].type == 'media' && self.orig_photos[i].tags != '') {
						tmptags = self.orig_photos[i].tags;
						for(j = 0;j < tmptags.length; j++){
							self.tags[tmptags[j]] = self.tags[tmptags[j]] || 0;
							self.tags[tmptags[j]]++;
						}
					}
				}

				// Browsers can only support an object so big, so we can only use so many rows.
				// Calculate the new max zoom
				self.maxzoom = Math.ceil(Math.sqrt(self.hyperlist_container.width() * self.photos.length / HyperList.getMaxBrowserHeight()));

				// Make sure our new cols doesn't go over the max zoom
				self.cols = Math.max(self.maxzoom, self.cols);

				self.hyperlist_container.addClass('up' + self.cols);

				self.hyperlist_init();
				self.load_nav();
				self.make_tags();
			}
		});

		jQuery('#speedslide').on('input',this.speed_slide.bind(this));
		jQuery('#zoom').on('change',this.zoom_change.bind(this));
		jQuery('#photos').on('click','.tn',this.handle_thumb_click.bind(this));

		/* Nav action handlers stuff */
		jQuery('#thumbright').on('click',this.handle_thumb_next.bind(this));
		jQuery('#thumbleft').on('click',this.handle_thumb_prev.bind(this));
		jQuery('#thumbclose').on('click',this.hide_thumb.bind(this));
		jQuery('#tagon,#tagoff').on('click',this.handle_tag_state.bind(this));
		jQuery('#tagor,#tagand').on('click',this.handle_tag_state.bind(this));
		jQuery('#thetags').on('click','.onetag',this.handle_onetag_click.bind(this));

		jQuery(document).on('keydown',this.keydown_handler.bind(this));
		// Touch stuff
		jQuery('#thumb').hammer({recognizers: [ 
			[Hammer.Swipe,{ direction: Hammer.DIRECTION_ALL }],
		]}).on('swiperight swipeup swipeleft', this.handle_thumb_swipe.bind(this));

		// Map interations
		jQuery('#hyperlist_wrap').on('mouseenter','.tn',this.handle_tn_mouseover.bind(this));

		// Thumb buttons
		// jQuery('#thumbdownload a').on('click',this.sendbyajax.bind(this));
		jQuery('#thumbdownload').on('click',this.senddownload.bind(this));
		jQuery('#thumbflag').on('click',this.flagphoto.bind(this));
		jQuery('#thumbgeo').on('click',this.geoclick.bind(this));
		jQuery('#sharelink').on('click',this.shareclick.bind(this));
		jQuery('#webshare').on('click',this.shareclick.bind(this));

		if (typeof navigator.share === 'function') {
			jQuery('#webshare').removeClass('disabled');
		}
	}

	/**
	 * Our default properties.
	 */
	setProps() {
		this.photourl = "./";
		this.fastbackurl = "./";

		this.photos = [];
		this.cols = 5;
		this.palette = [ '#eedfd1', '#52a162', '#23403b', '#f3a14b', '#ec6c3e', '#d0464e', '#963755' ];
		this.hyperlist_container = jQuery('#photos');
		this.active_filters = {};
		this.dirty_filters = false;
		this.active_tags = [];

		// Browser type
		this.isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

		this.is_mobile_browser();

		// Browser supported file types - will be shown directly. 
		// Anything not in this list will be proxied into a jpg
		this.browser_supported_file_types = [
			// videos
			'mp4','m4v', 'ogg', 'mov', 'webm',
			// images
			'jpg','jpeg','gif','png','webp' ];

		if ( this.isSafari ) {
			// this.browser_supported_file_types.push('heic');
			this.browser_supported_file_types.push('mov');
			this.browser_supported_file_types.push('mpg');
		}

		this.flashstyle = {
			radius: 15,
			fillColor: "#ff7800",
			color: "#000672",
			weight: 1,
			opacity: 0.7,
			fillOpacity: 0
		};

		this.flashstyle_hover = {
			weight: 3,
			opacity: 1
		};
	}

	/**
	 * Initiate the hyperlist
	 */
	hyperlist_init() {
		var self = this;

		this.hyperlist_config = {
			height: window.innerHeight,
			itemHeight: (this.hyperlist_container.width() / this.cols),
			total: Math.ceil(this.photos.length / this.cols),
			scrollerTagName: 'div',
			fastback: this,
			generate: this.generate_row.bind(this),
			afterRender: this.after_render.bind(this)
		};

		this.hyperlist = HyperList.create(this.hyperlist_container[0], this.hyperlist_config);

		window.onresize = this.refresh_layout.bind(this);
		window.ontouchmove = this.refresh_layout.bind(this);

		this.hyperlist_container.addClass("container");

		this.refresh_layout();
	}

	/**
	 * Load the nav items
	 *
	 * * Calendar / datepicker
	 * * Rewind / onthisday
	 */
	load_nav() {
		var self = this;
		// first date (in tags list) -- The default is Descending view, so this should be the greatest date
		var fd = this.photos[0]['date'];
		var ld = this.photos[this.photos.length - 1]['date'];

		// If fd is not the greatest date, swap 'em
		if ( fd > ld ) {
			[fd,ld] = [ld,fd];
		}

		jQuery('#datepicker').datepicker({
			minDate: fd,
			maxDate: ld,
			changeYear: true,
			changeMonth: true, 
			yearRange: 'c-100:c+100',
			dateFormat: 'yy-mm-dd',
			onSelect: this.handle_datepicker_change.bind(this) 
		});

		jQuery('#calendaricon').on('click',this.handle_datepicker_toggle.bind(this));
		jQuery('#rewindicon').on('click',this.handle_rewind_click.bind(this));
		jQuery('#tagicon,#tagwindowclose').on('click',this.handle_tag_click.bind(this));
		jQuery('#globeicon').on('click',this.handle_globe_click.bind(this));
		jQuery('#exiticon').on('click',this.handle_exit_click.bind(this));
	}

	/*
	 * Handle the speed slide. The speed slide is an input with 100 increments so you can quickly get through a large photo set.
	 */
	speed_slide(e) {
		var totalheight = jQuery('#photos > div').first().height();
		jQuery('#photos').first()[0].scrollTop = totalheight * (e.target.value / 100);
	}

	/*
	 * Handle the slider changes.
	 */
	zoom_change() {
		var val = jQuery('#zoom').val();
		this.cols = Math.max(this.maxzoom, parseInt(val));

		this.hyperlist_container.removeClass('up1 up2 up3 up4 up5 up6 up7 up8 up9 up10'.replace('up' + this.cols,' '));
		this.hyperlist_container.addClass('up' + this.cols);
		this.refresh_layout();
	}

	senddownload(e) {
		var photoid = jQuery('#thumb').data('curphoto');
		var download = this.fastbackurl + '?download=' + encodeURIComponent(this.orig_photos[photoid]['file']);	
		window.open(download, '_blank').focus();
	}

	flagphoto(e) {
		var photoid = jQuery('#thumb').data('curphoto');
		var imgsrc = this.orig_photos[photoid]['file'];
		var url = this.fastbackurl + '?flag=' + encodeURIComponent(imgsrc);
		$.get(url).then(function(){
			$('#thumbflag').animate({ opacity: 0.3 })
		});
	}

	geoclick(e) {
		if ( !jQuery('body').hasClass('map') ) {
			this.handle_globe_click();
		}
		var points = $('#thumbgeo').data('coordinates').split(',');
		this.fmap.lmap.flyTo([points[1],points[0]], this.fmap.lmap.getMaxZoom());
	}

	shareclick(e) {
		var photoid = jQuery('#thumb').data('curphoto');
		var orig = this.orig_photos[photoid];
		var fullsize = orig.file;

		var share_uri = this.fastbackurl + '?file=' + encodeURIComponent(fullsize).replaceAll('%2F','/') + '&share=' + md5(fullsize);
		share_uri = new URL(share_uri,document.location).href;

		if ( !orig.isvideo ) {
			var supported_type = (this.browser_supported_file_types.indexOf(fullsize.replace(/.*\./,'').toLowerCase()) != -1);
			if ( !supported_type ) {
				share_uri += '&proxy=true';
			}
		}

		var basename = fullsize.replace(/.*\//,'');

			switch ( e.target.closest('.fakelink').id ) {
				case 'sharelink':
					jQuery('#sharelinkcopy input').val(share_uri);
					$('#sharelinkcopy input').select();
					var res = document.execCommand('copy');
					window.getSelection().removeAllRanges();
					$('#thumb').focus();
					$('#thumb').select();
					$('#sharelinkcopy input').blur();
					$('#sharelink').effect('highlight');
					return false;
				case 'webshare':
					//		https://web.dev/web-share/
					navigator.share({
						title: 'See this ' + (orig.isvideo ? 'video' : 'picture')  + ' from ' + document.title,
						text: 'Someone is sharing a ' + basename + ' from ' + document.title + ' with you',
						url: share_uri
					});
				break;
			}
	}

	/**
	 * Add colored date blocks to a photos array
	 */
	add_date_blocks(photos) {
		var prev_date = null;
		var cur_date;
		for(var i = 0; i<photos.length; i++){

			if ( photos[i].type !== 'media' ) {
				continue;
			}

			// cur_date = photos[i].date.getFullYear() + '-' + (photos[i].date.getMonth() + 1);
			cur_date = photos[i].date.getFullYear() + '-' + (photos[i].date.getMonth() + 1) + '-' + (photos[i].date.getDate());

			if ( cur_date != prev_date ) {
				photos.splice(i,0,{
					'type': 'dateblock',
					'printdate': cur_date,
					'date': photos[i]['date']
				});
			}
			prev_date = cur_date;
		}

		return photos;
	}

	/**
	 * Make a single row with however many photos we need.
	 *
	 * Called by Hyperlist
	 *
	 * @row - Which row to use. 
	 */
	generate_row(row) {
		var self = this;
		var slice_from = (row * this.cols);
		var slice_to = (row * this.cols) + this.cols;
		var vidclass = '';
		var date;
		var html = this.photos.slice(slice_from,slice_to).map(function(p){

			if ( p['type'] == 'media' ) {
				if ( p['isvideo'] ) {
					vidclass = ' vid';
				} else {
					vidclass = '';
				}
				return '<div class="tn' + vidclass + '"><img data-photoid="' + p['id'] + '" src="' + encodeURI(self.fastbackurl + '?thumbnail=' + p['file']) + '"/></div>';
			} else if ( p['type'] == 'dateblock' ) {
				date = p['date'];
				// I feel like this is kind of clever. I take the Year-Month, eg. 2021-12, parse it to an int like 202112 and then take the mod of the palette length to get a fixed random color for each date.
				var cellhtml = '<div class="tn nolink" style="background-color: ' + self.palette[parseInt(p['printdate'].replaceAll('-','')) % self.palette.length] + ';">';
				cellhtml += '<div class="faketable">';
				cellhtml += '<div class="faketablecell">' + date.getDate() +  '</div>';
				cellhtml += '<div class="faketablecell">' + date.toLocaleDateString(navigator.languages[0],{month:'long'}) + '</div>';
				cellhtml += '<div class="faketablecell">' + date.getFullYear()  + '</div>';
				cellhtml += '</div>';
				cellhtml += '</div>';
				return cellhtml;
			}
		}).join("");
		var e = jQuery.parseHTML('<div class="photorow">' + html + '</div>');
		return e[0];
	}

	/**
	 * Only apply filters to data strctures, no redraws.
	 */
	apply_filters() {

		// We never want to get stuck in an applying_filters loop
		if ( this.applying_filters === true ) {
			return;
		}

		this.applying_filters = true;
		if ( this.dirty_filters ) {
			var self = this;
			this.photos = this.orig_photos.filter(function(item) { return item.type === 'media'; });

			for(var filter in self.active_filters) {
				self.active_filters[filter]();
			}

			this.photos = this.add_date_blocks(this.photos);
			this.map_update_cluster();
			this.dirty_filters = false;
		}
		delete this.applying_filters;
	}

	/*
	 * Refresh the page layout, including accounting for changed row nums or page resize.
	 *
	 * Called manually and by hyperlist
	 */
	refresh_layout() {

		jQuery('body').css('height',window.innerHeight);

		this.apply_filters();

		// Browsers can only support an object so big, so we can only use so many rows.
		// Calculate the new max zoom
		this.maxzoom = Math.ceil(Math.sqrt(this.hyperlist_container.width() * this.photos.length / this.hyperlist._maxElementHeight));

		// Make sure our new cols doesn't go over the max zoom
		this.cols = Math.max(this.maxzoom, this.cols);

		// Set the slider size
		var zoomval = jQuery('#zoom').val();
		jQuery('#resizer input').prop('min',this.maxzoom);

		// Update hyperlist config
		this.hyperlist_config.height = this.hyperlist_container.parent().height();
		this.hyperlist_config.itemHeight = (this.hyperlist_container.width() / this.cols);
		this.hyperlist_config.total = Math.ceil(this.photos.length / this.cols);

		// propagate changes
		this.hyperlist.refresh(this.hyperlist_container[0], this.hyperlist_config);
	}

	/**
	 * Called after a hyperlist chunk is rendered
	 */
	after_render() {
		// Non-map render
		var totalheight = Math.ceil(this.photos.length / this.cols) * this.hyperlist_config.itemHeight
		jQuery('#speedslide').val(this.hyperlist_container[0].scrollTop / totalheight * 100);

		if ( this.fmap === undefined ) {
			// No map, no need.
			return;
		}

		var self = this;

		// Refresh the map flash layer
		var rows = jQuery('.photorow:visible').toArray().filter(function(r){return jQuery(r).position().top < window.innerHeight;})
		var tnsar =	rows.map(function(f){ return jQuery(f).find('.tn img').toArray(); });
		var tns = jQuery.map(tnsar,function(f){return f;});
		var photos = tns.map(function(f){return self.photos.find(function(p){return p.id == $(f).data('photoid');})});
		var geojson = this.build_geojson(photos);
		this.fmap.flashlayer.clearLayers();
		this.fmap.flashlayer.addData(geojson);
	}

	handle_thumb_click(e) {
		var photoid = jQuery(e.target).closest('div.tn').find('img').data('photoid');
		this.show_thumb_popup(photoid);
	}

	/**
	 * For a given photo ID, show the thumb popup. 
	 *
	 * We assume that the correct ID has been found by now, even though photos may have scrolled or shifted in the background somehow
	 *
	 * Returns false if couldn't show thumb
	 */
	show_thumb_popup(photoid) {
		var imghtml;
		var photo = this.orig_photos.find(function(p){return p.id == photoid && p.type == 'media';});
		if ( photo === undefined ) {
			return false;
		}
		var imgsrc = photo.file;
		var basename = imgsrc.replace(/.*\//,'');
			var fullsize = this.photourl + imgsrc;

			// File type not found, proxy a jpg instead
			var supported_type = (this.browser_supported_file_types.indexOf(fullsize.replace(/.*\./,'').toLowerCase()) != -1);
			if ( !supported_type ) {
				fullsize = this.fastbackurl + '?proxy=' + encodeURIComponent(imgsrc);	
			}
			var share_uri = this.fastbackurl + '?file=' + encodeURIComponent(imgsrc).replaceAll('%2F','/') + '&?share=' + md5(fullsize);

			if (photo.isvideo && supported_type){
				imghtml = '<video controls poster="' + encodeURI(this.fastbackurl + '?thumbnail=' +  imgsrc) + '"><source src="' + fullsize + '#t=0.0001">Your browser does not support this video format.</video>';
			} else {
				imghtml = '<img src="' + fullsize +'"/>';
			}
		jQuery('#thumbcontent').html(imghtml);
		jQuery('#thumbinfo').html('<div id="infowrap">' + photo['file'] + '</div>');
		jQuery('#thumbgeo').attr('data-coordinates',( photo.coordinates == null ? "" : photo.coordinates ));
		jQuery('#thumbflag').css('opacity',1);
		jQuery('#sharelink a').attr('href',share_uri);
		jQuery('#thumb').data('curphoto',photo.id);
		jQuery('#thumb').removeClass('disabled');

		return true;
	}

	handle_thumb_next(e) {
		var photoid = jQuery('#thumb').data('curphoto');

		var photo = this.orig_photos.find(function(p){return p.id == photoid && p.type == 'media';});

		if ( photo === undefined ) {
			return false;
		}

		photoid = photo.id;

		while(true) {
			photoid++;
			// Found the next photo that is in the photos array
			var found = this.photos.find(function(p){return p.id == photoid && p.type == 'media';});

			if ( found !== undefined ) {
				// Scroll to photo
				this.scroll_to_photo(photoid);
				this.show_thumb_popup(found.id);				
				return true;
			}

			if ( photoid === this.orig_photos.length ) {
				return false;
			}
		}
	}

	handle_thumb_prev(e) {
		var photoid = jQuery('#thumb').data('curphoto');

		var photo = this.orig_photos.find(function(p){return p.id == photoid && p.type == 'media';});

		if ( photo === undefined ) {
			return false;
		}

		photoid = photo.id;

		while(true) {
			photoid--;
			// Found the next photo that is in the photos array
			var found = this.photos.find(function(p){return p.id == photoid && p.type == 'media';});

			if ( found !== undefined ) {
				// Scroll to photo
				this.scroll_to_photo(photoid);
				this.show_thumb_popup(found.id);				
				return true;
			}

			if ( photoid === 0 ) {
				return false;
			}
		}
	}

	hide_thumb() {
		jQuery('#thumb').addClass('disabled');
		if ( jQuery('#thumb video').length > 0 ) {
			jQuery('#thumb video')[0].pause();
		}
	}

	keydown_handler(e) {
		switch(e.key){
			case 'Escape':
				this.hide_thumb();
				this.hide_tags();
				break;
			case 'ArrowRight':
				this.handle_thumb_next();
				break;
			case 'ArrowLeft':
				this.handle_thumb_prev();
				break;
		}
	}

	/**
	 * Swipe actions for photos
	 */
	handle_thumb_swipe(e) {
		if ( e.type == 'swiperight' ) {
			this.handle_thumb_next();
		} else if ( e.type == 'swipeleft' ) {
			this.handle_thumb_prev();
		} else if ( e.type == 'swipeup' ) {
			this.hide_thumb();
			this.hide_tags();
		}
	}

	/**
	 * Click a link but do it with ajax
	 */
	sendbyajax(link) {
		var thelink = link;

		if ( thelink.href === undefined && link.target instanceof Node ) {
			thelink = link.target;
		}

		jQuery.get(thelink.href).then(function(){
			jQuery(thelink).hide();
		});
		return false;
	}

	/**
	 * Kick off the map. This may be slow, so it should only get called the first time the div is visible.
	 */
	map_init() {
		var self = this;

		this.fmap = {
			'lmap':  L.map('map').setView([0,0], 2),
			'base_map':  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: 19,
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
			}),
			'clusterlayer': L.markerClusterGroup({
				spiderfyOnMaxZoom: true,
				maxClusterRadius: 40
			}),
			'flashlayer': L.geoJSON(null,{
				pointToLayer: function (feature, latlng) {
					return L.circleMarker(latlng, self.flashstyle);
				},
				onEachFeature: function (feature, layer) {
					layer.on('mouseover', function (e) {
						var pixel = self.fmap.lmap.latLngToContainerPoint(layer.getLatLng())
						self.fmap.flashlayer.eachLayer(function(l){
							var mypixel = self.fmap.lmap.latLngToContainerPoint(l.getLatLng())
							if (Math.abs(pixel.x - mypixel.x) <= 10 && Math.abs(pixel.y - mypixel.y) <= 10 ){
								self.flash_map_for_id(l.feature.properties.id);
							}
						});
						return true;
					});
				}
			})
		}

		this.fmap.lmap.on({
			'moveend': this._map_handle_zoom_move_end.bind(self)
		});

		L.Control.MapFilter = L.Control.extend({
			onAdd: function(map) {
				self.fmap.mapfilter_button = jQuery('<div id="mapfilter">🛰</div>');
				self.fmap.mapfilter_button.on('click',function(){
					self.toggle_map_filter();
				});
				return self.fmap.mapfilter_button[0];
			},

			onRemove: function(map) {
				// Nothing to do here
			}
		});

		L.control.mapfilter = function(opts) {
			return new L.Control.MapFilter(opts);
		}

		// We want to flash photos in the moused cluster
		this.fmap.clusterlayer.on('clustermouseover',function(e){
			var pixel = e.layerPoint;
			self.fmap.flashlayer.eachLayer(function(l){
				var mypixel = self.fmap.lmap.latLngToContainerPoint(l.getLatLng());
				if (Math.abs(pixel.x - mypixel.x) <= 10 && Math.abs(pixel.y - mypixel.y) <= 10 ){
					self.flash_map_for_id(l.feature.properties.id);
				}
			});
		});

		// Handle click on individual markers
		this.fmap.clusterlayer.on('click',function(e){
			var id = e.layer.feature.properties.id
			self.go_to_photo_id(id);
		});

		// Scroll to first, if we're all the way zoomed in
		this.fmap.clusterlayer.on('clusterclick', function (e) {
			if ( e.layer._map.getZoom() == e.layer._map.getMaxZoom() ) {
				var id = e.layer.getAllChildMarkers()[0].feature.properties.id
				self.go_to_photo_id(id);
			}
		});

		this.fmap.base_map.addTo(this.fmap.lmap);
		this.fmap.clusterlayer.addTo(this.fmap.lmap);
		this.fmap.flashlayer.addTo(this.fmap.lmap);
		L.control.mapfilter({ position: 'topleft' }).addTo(this.fmap.lmap);

		this.map_update_cluster();
		this.after_render();
	}

	_map_handle_zoom_move_end(e) {
		this.handling_map_move_end = true;

		// Moving the map only dirties our filters if we're doing map based filtering.
		if ( this.active_filters.map !== undefined ) {
			this.dirty_filters = true;
			this.refresh_layout();
		}

		delete this.handling_map_move_end;
	}

	/**
	 * Update the markercluster content
	 */
	map_update_cluster() {
		if ( this.fmap === undefined ) {
			return;
		}

		var geojson = this.build_geojson();

		var gj = L.geoJson(geojson);

		this.fmap.clusterlayer.clearLayers()
		this.fmap.clusterlayer.addLayer(gj,{
			'chunkedLoading': true
		});


		// If we're handling a user inited map move we don't want to update zoom. Let the user go where they want.
		if ( this.handling_map_move_end === undefined ) {
			if ( this.fmap.clusterlayer.getBounds().isValid() ) {
				this.fmap.lmap.fitBounds(this.fmap.clusterlayer.getBounds());
			} else {
				this.fmap.lmap.setView([0,0],1);
			}
		}
	}

	/**
	 * Take a photos array and build geojson from it
	 */
	build_geojson(photos) {
		photos = photos || this.photos;

		var bbox = [0,0,0,0,0,0];
		var geojson = {
			'type': 'FeatureCollection',
			'features': []
		};

		var feature = {};
		for(var i = 0;i<photos.length;i++){
			if (photos[i].coordinates !== undefined && photos[i].coordinates !== null && photos[i].type === 'media') {

				if ( photos[i].coordinates[0] == 0 && photos[i].coordinates[1] == 0 ) {
					continue;
				}

				feature = {
					'type': 'Feature',
					'geometry': {
						'type': 'Point',
						'coordinates' : photos[i].coordinates,
					},
					'properties': {
						'id': photos[i].id,
						'file': photos[i].file,
						'date': photos[i].date,
						'isvideo': photos[i].isvideo
					}
				};
				geojson.features.push(feature);

				// Lon
				bbox[0] = Math.min(bbox[0],photos[i].coordinates[0]);
				bbox[3] = Math.max(bbox[3],photos[i].coordinates[0]);

				// Lat
				bbox[1] = Math.min(bbox[1],photos[i].coordinates[1]);
				bbox[4] = Math.max(bbox[4],photos[i].coordinates[1]);
			}
		}

		geojson.bbox = bbox;

		return geojson;
	}


	/**
	 * For a given photo ID, scroll to it if it is in the current photos.
	 */
	scroll_to_photo(photoid) {
		var photo_idx = this.photos.findIndex(function(p){return p.id == photoid && p.type == 'media';});

		if ( photo_idx === -1 ) {
			return false; // Couldn't find the requested photo
		}

		// Get the row number now
		var rownum = parseInt(photo_idx / this.cols)

		// Set the scrollTop
		this.hyperlist_container.prop('scrollTop',(rownum * this.hyperlist_config.itemHeight));
	}

	/**
	 * Show or hide the calendar
	 */
	handle_datepicker_toggle(){

		if ( $('#ui-datepicker-div').is(':visible') ) {
			$('#datepicker').datepicker('hide');
		} else {
			$('#datepicker').datepicker('show');
		}

	}

	/**
	 * Handle the datepicker change
	 */
	handle_datepicker_change(date){
		var targetdate = new Date(date.replaceAll('-','/') + ' 23:59:59'); // Use the very end of day so that our findIndex works later

		if ( jQuery('#rewindicon').hasClass('active') ) {
			this.setup_new_rewind_date(targetdate);	
		}

		// Find the first photo that is younger than our target photo
		var first = this.photos.findIndex(o => o['date'] <= targetdate);

		this.scroll_to_photo(first);

		this.refresh_layout();
	}

	/**
	 * Go to photo id
	 */
	go_to_photo_id(id) {
		this._go_to_photo('id',id);
		this.flash_square_for_id(id);
		this.show_thumb_popup(id);
	}

	/**
	 * Find a photo based on a key name and value, and go to it
	 */
	_go_to_photo(key,val) {
		// Find the first photo that is younger than our target photo
		var first = this.photos.findIndex(o => o[key] == val);

		// If we don't find one, go all the way to the end
		if ( first === undefined || first === -1 ) {
			first = this.photos.length - 1;
		}

		this.scroll_to_photo(first);
	}

	/**
	 * Handle the rewind / memories icon click
	 */
	handle_rewind_click() {
		var icon = jQuery('#rewindicon');

		if ( icon.hasClass('active') ) {
			icon.removeClass('active');
			delete this.active_filters.rewind;
			this.dirty_filters = true;
		} else {
			icon.addClass('active');
			this.rewind_date = new Date();
			this.setup_new_rewind_date();
		}

		this.refresh_layout();
		this.hyperlist_container.prop('scrollTop',0);
	}

	/**
	 * For an optional date object, set up a new rewind view
	 */
	setup_new_rewind_date(date_to_use) {
		var self = this;
		var d = date_to_use || new Date();
		var month = d.getMonth();
		var date = d.getDate();
		this.active_filters.rewind = function() {
			self.photos = self.photos.filter(function(p){ 
				return p.date.getMonth() == month && p.date.getDate() == date;
			});
		};
		this.dirty_filters = true;
	}

	/**
	 * Handle logout
	 */
	handle_exit_click() {
		window.location = this.fastbackurl + '?logout=true';
	}

	/**
	 * Make the tags interface.
	 */
	make_tags() {
		var thetags = $('#thetags');
		var htmltag,t;
		var tagnames = [];

		for(t in this.tags) {
			tagnames.push(t);
		}

		tagnames.sort();

		for(var t = 0; t < tagnames.length; t++ ) {
			htmltag = tagnames[t].replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
				thetags.append('<div class="onetag" data-tag="' + tagnames[t] + '">' + htmltag + ' (' + this.tags[tagnames[t]] + ')</div>');
			}
	}

	/**
	 * Handle tag click
	 */
	handle_tag_click() {
		this.hide_thumb();
		if ( $('#tagwindow').hasClass('disabled') ) {

			this.tag_state = JSON.stringify({
				'tags' : this.active_tags,
				'andor': $('#tagand').hasClass('active'),
				'tagson': $('#tagon').hasClass('active')
			});

			$('#tagwindow').removeClass('disabled');
			return;
		} else {
			this.hide_tags();
		}
	}

	/**
	 * Close tags interface, applying filter, if needed
	 */
	hide_tags() {
		$('#tagwindow').addClass('disabled');

		var tagstate = JSON.stringify({
			'tags' : this.active_tags,
			'andor': $('#tagand').hasClass('active'),
			'tagson': $('#tagon').hasClass('active')
		});

		if ( $('#tagoff').hasClass('active') ) {
			delete this.active_filters.tags;
		} else if (tagstate === this.tag_state) {
			return; // no changed state, no dirty filters!
		} else {

			this.tag_and_or = $('#tagand').hasClass('active') ? 'and' : 'or';

			var self = this;

			this.active_filters.tags = function(){

				if ( self.active_tags.length == 0 ) {
					return;
				}

				self.photos = self.photos.filter(function(p){
					// Count matches
					var matches = p.tags.reduce(function(total,tag){
						if ( self.active_tags.indexOf(tag) === -1 ) {
							return total;
						} else {
							return total + 1;
						}
					},0);

					// See if we have enough matches
					if ( matches == self.active_tags.length && self.tag_and_or == 'and' ) {
						return true;
					} else if ( matches > 0 && self.tag_and_or == 'or' ) {
						return true;
					} else {
						return false;
					}
				});
			};
		}

		this.dirty_filters = true;
		this.refresh_layout();
	}

	/**
	 * Handle globe icon click
	 */
	handle_globe_click() {
		var icon = jQuery('#globeicon');

		if ( jQuery('body').hasClass('map') ) {
			icon.removeClass('active');
			jQuery('body').removeClass('map');
		} else {
			jQuery('body').addClass('map');
			icon.addClass('active');

			if ( this.fmap === undefined ) {
				this.map_init();
			} else {
				this.fmap.lmap.invalidateSize();
				if ( this.fmap.clusterlayer.getBounds().isValid() ) {
					this.fmap.lmap.fitBounds(this.fmap.clusterlayer.getBounds());
				} else {
					this.fmap.lmap.setView([0,0],1);
				}
			}
		}
		this.refresh_layout();
	}

	/*
	 * Interact with map on mouse over
	 */
	handle_tn_mouseover(e){
		var photoid = jQuery(e.target).closest('.tn').find('img').first().data('photoid');
		this.flash_map_for_id(photoid);
	}

	flash_map_for_id(target_id){

		if ( this.fmap === undefined ) {
			return;
		}

		var one_layer;
		var self = this;

		this.fmap.flashlayer.eachLayer(function(l){
			if ( target_id == l.feature.properties.id ) {
				one_layer = l;
			}
		});

		if ( one_layer !== undefined ) {
			one_layer.setStyle(this.flashstyle_hover);
		}

		setTimeout(function(){
			if ( one_layer !== undefined ) {
				one_layer.setStyle(self.flashstyle);
			}
		},500);
		this.flash_square_for_id(target_id);
	}

	flash_square_for_id(target_id) {
		var one_tn = jQuery('img[data-photoid="' + target_id +'"]').closest('.tn').addClass('flash');

		setTimeout(function(){
			one_tn.removeClass('flash');
		},500);
	}

	// https://stackoverflow.com/questions/11381673/detecting-a-mobile-browser
	is_mobile_browser() {
		let check = false;
		(function(a){if(/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino|android|ipad|playbook|silk/i.test(a)||/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0,4))) check = true;})(navigator.userAgent||navigator.vendor||window.opera);
		this.is_mobile_browser = check;
		return check;
	}

	toggle_map_filter() {

		var is_filtered = (this.active_filters.map !== undefined );

		if ( !is_filtered ) {
			this.fmap.mapfilter_button.addClass('active');

			var self = this;
			this.active_filters.map = function(p){
				var mapbounds = self.fmap.lmap.getBounds();
				self.photos = self.photos.filter(function(p){
					// Reject any photos without geo
					if ( p.coordinates === null ) {
						return false;
					}
					// Reject any photos outside the bounds of the map
					return mapbounds.contains([p.coordinates[1],p.coordinates[0]]);
				});
			};
		} else {
			this.fmap.mapfilter_button.removeClass('active');
			delete this.active_filters.map;
		}

		this.dirty_filters = true;
		this.refresh_layout();
	}

	handle_tag_state(e) {
		if ( e.target.id == 'tagoff' ) {
			$('#tagon').removeClass('active');
			$('#tagoff').addClass('active');
			$('#tagicon').removeClass('active');
		} else if ( e.target.id == 'tagon' ) {
			$('#tagon').addClass('active');
			$('#tagoff').removeClass('active');
			$('#tagicon').addClass('active');
		} else if ( e.target.id == 'tagand' ) {
			$('#tagand').addClass('active');
			$('#tagor').removeClass('active');
		} else if ( e.target.id == 'tagor' ) {
			$('#tagor').addClass('active');
			$('#tagand').removeClass('active');
		}
	}

	handle_onetag_click(e) {
		var tag = $(e.target).data('tag');
		var idx = this.active_tags.indexOf(tag);
		if (  idx === -1 ) {
			this.active_tags.push(tag);
			$(e.target).addClass('active');
		} else {
			this.active_tags.splice(idx,1);
			$(e.target).removeClass('active');
		}
	}
}
