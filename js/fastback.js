/**
 * Copyright 2021-2023 Michael Moore - stuporglue@gmail.com
 * Licensed under the MIT License
 */
Fastback = class Fastback {

	Modules() {
		// Classes will be populated here
	}

	/*
	 * Load the data and set up event handlers
	 */
	constructor(args) {
		this.modules = {};
		var progressbar = jQuery('#loadingprogress');
		this.setup_setProps();
		jQuery.extend(this,args);
		var self = this;
		this.paths = [];

		for( var m in this.modules ) {
			this.modules[m] = new this.Modules[this.modules[m]](m,this);
		}

		// Set up handlers
		jQuery('#speedslide').on('input',this.handle_speed_slide.bind(this));
		jQuery('#zoom').on('change',this.handle_zoom_change.bind(this));
		jQuery('#photos').on('click','.tn',this.handle_thumb_click.bind(this));
		jQuery('#thumbright').on('click',this.handle_thumb_next.bind(this));
		jQuery('#thumbleft').on('click',this.handle_thumb_prev.bind(this));
		jQuery('#tagon,#tagoff').on('click',this.handle_tag_state.bind(this));
		jQuery('#tagor,#tagand').on('click',this.handle_tag_state.bind(this));
		jQuery('#thetags').on('click','.onetag',this.handle_onetag_click.bind(this));
		jQuery(document).on('keydown',this.handle_keydown.bind(this));
		jQuery('#thumbgeo').on('click',this.handle_geoclick.bind(this));
		jQuery('#thumbflag').on('click',this.handle_flagphoto.bind(this));
		jQuery('#thumbalt').on('click',this.handle_altswap.bind(this));
		jQuery('#sharelink').on('click',this.handle_shareclick.bind(this));
		jQuery('#webshare').on('click',this.handle_shareclick.bind(this));
		jQuery('#thumbdownload').on('click',this.handle_send_download.bind(this));

		// Touch stuff
		jQuery('#thumb').hammer({recognizers: [ 
			[Hammer.Swipe,{ direction: Hammer.DIRECTION_ALL }],
		]}).on('swiperight swipeup swipeleft', this.handle_thumb_swipe.bind(this));

		// Map interations
		jQuery('#hyperlist_wrap').on('mouseenter','.tn',this.handle_tn_mouseover.bind(this));


		// Thumb buttons
		jQuery('#thumbclose').on('click',this.ui_hide_thumb.bind(this));

		if (typeof navigator.share === 'function') {
			jQuery('#webshare').removeClass('disabled');
		}

		progressbar.css('width','5%');

		// Do setTimeout so the screen has time to repaint
		setTimeout(this.setup_asyncCSV.bind(this),100);
	}

	/*
	 * Our default properties.
	 */
	setup_setProps() {
		this.fastbackurl = "./";
		this.csv_error_load = false;

		this.photos = [];
		this.tags = {};
		this.cols = 5;
		this.palette = [ '#eedfd1', '#52a162', '#23403b', '#f3a14b', '#ec6c3e', '#d0464e', '#963755' ];
		this.hyperlist_container = jQuery('#photos');
		this.active_filters = {};
		this.dirty_filters = false;
		this.active_tags = [];

		// Browser supported file types - will be shown directly. 
		// Anything not in this list will be proxied into a jpg
		this.browser_supported_file_types = [
			// videos
			'mp4','m4v', 'webm',
			// images
			'jpg','jpeg','gif','png','webp' ];

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

	/*
	 * Run the papaparse routine inside a setTimeout to try to get the browser to paint the progress bar
	 */
	setup_asyncCSV() {
		var has_geo = false;
		var has_tag = false;

		var progressbar = jQuery('#loadingprogress');
		var lastprogress = 0;
		var self = this;
		var curpath;

		Papa.parse(this.csvurl,{
			download:true,
			skipEmptyLines: true,
			// preview: 100,
			step: function(res,parser){

				if ( self.photos.length == 0 ) {
					progressbar.css('width','5%');
					parser.pause();
					// A brief pause here lets the progress bar work
					setTimeout(parser.resume,5);
				}

				if ( res.data.length < 5 ) {
					parser.abort();
					return;
				}

				if ( res.data[2] !== '' ) {
					has_geo = true;
				}
				if ( res.data[4] !== '' ) {
					has_tag = true;

					var tags = res.data[4].split('|');
					for(var j=0;j<tags.length;j++){
						self.tags[tags[j]] = self.tags[tags[j]] || 0;
						self.tags[tags[j]]++;
					}
				}

				var fileinfo = res.data[0].split(':');

				curpath = fileinfo[1].match(/.*\//)[0].replace(/\/$/,'');
				if ( !self.paths.includes(curpath) && curpath !== "") {
					self.paths.push(curpath);
				}

				self.photos.push({
					'type': 'media',
					'mfile': res.data[0],
					'module': fileinfo[0],
					'file': fileinfo[1],
					'path': res.data[0].match(/.*\//)[0].replace(/\/$/,''),
					'date': new Date(res.data[1] * 1000),
					'coordinates': (isNaN(parseFloat(res.data[3])) ? null : [parseFloat(res.data[3]),parseFloat(res.data[2])]),
					'tags': res.data[4] == "" ? [] : res.data[4].split('|'),
					'alt': res.data[5] == "" ? null : res.data[5]
				});

				// Only check progress every 1000 rows
				var prog_percent = self.photos.length / self.photocount;
				var curprog = prog_percent * 80;
				var rounded = Math.round(curprog,0);
				var modded = rounded % 5;

				if (rounded > lastprogress && modded === 0){
					progressbar.css('width',rounded + '%');
					lastprogress = rounded;
					parser.pause();
					// A brief pause here lets the progress bar work
					setTimeout(parser.resume,5);
				}
			},
			complete: function(res,file) {
				progressbar.css('width','85%');

				if ( has_tag ) {
					jQuery('#tagicon').removeClass('disabled');
				}

				if ( has_geo ) {
					jQuery('#globeicon').removeClass('disabled');
				}

				if ( self.photos.length == 0 ) {
					self.csv_error_load = true;
					// If we got an error we want to start cron right away
					// Cron should run on its own
					// setTimeout(self.util_cron.bind(self),1);
				} else {
					// Cron should run on its own
					// setTimeout(self.util_cron.bind(self),1000 * 30);
				}

				self.orig_photos = self.util_add_separator_blocks(self.photos);
				self.photos = self.orig_photos;

				// These three processes can run almost concurrently off of the main thread to allow screen repainting
				setTimeout(function(){
					self.setup_make_tags();
					progressbar.css('width','90%');
				},5);
				setTimeout(function(){
					self.setup_load_nav();
					progressbar.css('width','95%');
				},15);
				setTimeout(function(){
					self.setup_hyperlist_init();
					progressbar.css('width','100%');
				},20);
			}, error: function(err, file, inputElem, reason) {
				// Whatever error we get, we assume 
				self.csv_error_load = true;
				// Cron should run on its own
				// setTimeout(self.util_cron.bind(self),1);
			}
		});
	}

	/*
	 * Initiate the hyperlist
	 */
	setup_hyperlist_init() {
		var self = this;

		// Browsers can only support an object so big, so we can only use so many rows.
		// Calculate the new max zoom
		this.maxzoom = Math.ceil(Math.sqrt(this.hyperlist_container.width() * this.photos.length / HyperList.getMaxBrowserHeight()));

		// Make sure our new cols doesn't go over the max zoom
		this.cols = Math.max(this.maxzoom, this.cols);

		this.hyperlist_container.addClass('up' + this.cols);

		this.hyperlist_config = {
			height: window.innerHeight,
			itemHeight: (this.hyperlist_container.width() / this.cols),
			total: Math.ceil(this.photos.length / this.cols),
			scrollerTagName: 'div',
			fastback: this,
			generate: this.util_generate_row.bind(this),
			afterRender: this.util_after_render.bind(this)
		};

		this.hyperlist = HyperList.create(this.hyperlist_container[0], this.hyperlist_config);

		window.onresize = this.ui_refresh_layout.bind(this);
		window.ontouchmove = this.ui_refresh_layout.bind(this);

		this.hyperlist_container.addClass("container");

		this.ui_refresh_layout();
	}

	/*
	 * Load the nav items
	 *
	 * * Calendar / datepicker
	 * * Rewind / onthisday
	 */
	setup_load_nav() {
		var self = this;
		// first date (in tags list) -- The default is Descending view, so this should be the greatest date
		var fd,ld;
		if ( this.photos.length > 0 ) {
			fd = this.photos[0]['date'];
			ld = this.photos[this.photos.length - 1]['date'];
		} else {
			fd = new Date();
			ld = new Date();
		}

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

		var pathhtml = '';
		var cur_path;
		var cur_part_parts;
		var opt;
		var paths_seen = [];
		var cur_path_string;

		for(var i = 0; i < this.paths.length; i++ ) {
			cur_path = this.paths[i].split('/');
			cur_part_parts = [];
			while(cur_path.length > 0){
				opt = cur_path.shift();
				cur_part_parts.push(opt);
				cur_path_string = cur_part_parts.join('/');
				if ( paths_seen.indexOf(cur_path_string) !== -1 ) {
					continue;
				} else if ( this.paths.indexOf(cur_path_string) !== -1 ) {
					pathhtml += '<option value="' + cur_path_string + '">' + '&emsp;'.repeat(cur_part_parts.length - 1) + (cur_part_parts.length > 1 ? '&#x21B3; ' : '' ) + opt + '</option>';
				} else {
					pathhtml += '<option disabled>' + '&emsp;'.repeat(cur_part_parts.length - 1) + (cur_part_parts.length > 1 ? '&#x21B3; ' : '' ) + opt + '</option>';
				}
				paths_seen.push(cur_path_string);
			}
		}

		jQuery('#pathpicker').html(pathhtml);

		jQuery('#calendaricon').on('click',this.handle_datepicker_toggle.bind(this));
		jQuery('#pathpicker').on('change',this.handle_pathpicker_click.bind(this));
		jQuery('#rewindicon').on('click',this.handle_rewind_click.bind(this));
		jQuery('#globeicon').on('click',this.handle_globe_click.bind(this));
		jQuery('#exiticon').on('click',this.handle_exit_click.bind(this));
		jQuery('#tagicon,#tagwindowclose').on('click',this.handle_tag_click.bind(this));
		jQuery('.afterload').addClass('loaded');
	}

	/*
	 * Kick off the map. This may be slow, so it should only get called the first time the div is visible.
	 */
	setup_map() {
		var self = this;

		this.fmap = {
			'lmap':  L.map('map').setView([0,0], 2),
			'base_map':  this.basemap,
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
								self.ui_flash_map_for_id(l.feature.properties.id);
							}
						});
						return true;
					});
				}
			})
		}

		this.fmap.lmap.on({
			moveend: this.handle_map_zoom_move_end.bind(self)
		});

		L.Control.MapFilter = L.Control.extend({
			onAdd: function(map) {
				self.fmap.mapfilter_button = jQuery('<div id="mapfilter">🛰</div>');
				self.fmap.mapfilter_button.on('click', self.ui_toggle_map_filter.bind(self));
				return self.fmap.mapfilter_button[0];
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
					self.ui_flash_map_for_id(l.feature.properties.id);
				}
			});
		});

		// Handle click on individual markers
		this.fmap.clusterlayer.on('click',function(e){
			var id = e.layer.feature.properties.id
			self.ui_go_to_photo_id(id);
		});

		// Scroll to first, if we're all the way zoomed in
		// this.fmap.clusterlayer.on('clusterclick', function (e) {
		// 	if ( e.layer._map.getZoom() == e.layer._map.getMaxZoom() ) {
		// 		var id = e.layer.getAllChildMarkers()[0].feature.properties.id
		// 		self.go_to_photo_id(id);
		// 	}
		// });

		this.fmap.base_map.addTo(this.fmap.lmap);
		this.fmap.clusterlayer.addTo(this.fmap.lmap);
		this.fmap.flashlayer.addTo(this.fmap.lmap);
		L.control.mapfilter({ position: 'topleft' }).addTo(this.fmap.lmap);

		// These two make the map freeze for a while. We can use setTimeout at least during page load.
		setTimeout(function(){
			self.ui_update_cluster();
			self.util_after_render();
		},200);
	}

	/*
	 * Make the tags interface.
	 */
	setup_make_tags() {
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

	/*
	 * Handle the speed slide. The speed slide is an input with 100 increments so you can quickly get through a large photo set.
	 */
	handle_speed_slide(e) {
		var totalheight = jQuery('#photos > div').first().height();
		jQuery('#photos').first()[0].scrollTop = totalheight * (e.target.value / 100);
	}

	/*
	 * Handle the slider changes.
	 */
	handle_zoom_change() {
		var val = jQuery('#zoom').val();
		this.cols = Math.max(this.maxzoom, parseInt(val));
		this.ui_refresh_layout();
	}

	/*
	 * Download a specific file. Opens a new window with the downloaded image as the source. 
	 */
	handle_send_download(e) {
		var photoid = jQuery('#thumb').data('curphoto');
		var download = encodeURI(this.fastbackurl + '?download=' + this.orig_photos[photoid].mfile);
		window.open(download, '_blank').focus();
	}

	/*
	 * Flag the current photo
	 */
	handle_flagphoto(e) {
		var photoid = jQuery('#thumb').data('curphoto');
		var imgsrc = this.orig_photos[photoid].mfile;
		var url = encodeURI(this.fastbackurl + '?flag=' + imgsrc);
		$.get(url).then(function(){
			$('#thumbflag').animate({ opacity: 0.3 })
		});
	}

	/**
	 * Swap the static and live photos
	 */
	handle_altswap(e) {
		var photoid = jQuery('#thumb').data('curphoto');
		var new_is_alt = jQuery('#thumbalt').data('showing_alt') ? 0 : 1;
		this.ui_show_fullsized(photoid,new_is_alt);
	}

	/*
	 * Handle a click on the globe shown on the full-sized image view. 
	 * This initializes or opens the map page if needed then zooms to the photo's location
	 */
	handle_geoclick(e) {
		var self = this;
		if ( this.fmap === undefined ) {
			this._map_init_action_queue = this._map_init_action_queue || {};
			this._map_init_action_queue['geoclick'] = function(){
				this.handle_geoclick(e);
			}.bind(this);

			this.handle_globe_click();
			return;
		} else if ( !jQuery('body').hasClass('map') ) {
			this.handle_globe_click();
		}

		var points = $('#thumbgeo').data('coordinates').split(',');
		this.fmap.lmap.flyTo([points[1],points[0]], this.fmap.lmap.getMaxZoom());
	}

	/*
	 * Handle the two supported sharing options. 
	 * The sharelink icon copies the sharing link to the clipboard.
	 * The webshare icon opens a phone's sharing dialog. 
	 */
	handle_shareclick(e) {
		var photoid = jQuery('#thumb').data('curphoto');
		var orig = this.orig_photos[photoid];
		var fullsize = './' + orig.file;

		var share_uri = encodeURI(this.fastbackurl + '?file=' + this.orig_photos[photoid].mfile + '&share=' + md5(fullsize));
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

	/*
	 * Handle when a thumbnail is clicked.
	 */
	handle_thumb_click(e) {
		var photoid = jQuery(e.target).closest('div.tn').find('img').data('photoid');
		this.ui_show_fullsized(photoid);
	}

	/*
	 * Go to the next thumbnail. This is the handler for clicks, swipes and arrow keys
	 */
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
				this.ui_scroll_to_photo(photoid);
				this.ui_show_fullsized(found.id);				
				return true;
			}

			if ( photoid === this.orig_photos.length ) {
				return false;
			}
		}
	}

	/*
	 * Go to the previous thumbnail. This is the handler for clicks, swipes and arrow keys
	 */
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
				this.ui_scroll_to_photo(photoid);
				this.ui_show_fullsized(found.id);				
				return true;
			}

			if ( photoid === 0 ) {
				return false;
			}
		}
	}

	/*
	 * Handle key presses and turn them into actions
	 */
	handle_keydown(e) {
		switch(e.key){
			case 'Escape':
				this.ui_hide_thumb();
				this.ui_hide_tags();
				break;
			case 'ArrowRight':
				this.handle_thumb_next();
				break;
			case 'ArrowLeft':
				this.handle_thumb_prev();
				break;
		}
	}

	/*
	 * Swipe actions for photos
	 */
	handle_thumb_swipe(e) {
		if ( e.type == 'swiperight' ) {
			this.handle_thumb_next();
		} else if ( e.type == 'swipeleft' ) {
			this.handle_thumb_prev();
		} else if ( e.type == 'swipeup' ) {
			this.ui_hide_thumb();
			this.ui_hide_tags();
		}
	}

	/*
	 * Show or hide the calendar
	 */
	handle_datepicker_toggle(){
		if ( $('#ui-datepicker-div').is(':visible') ) {
			$('#datepicker').datepicker('hide');
		} else {
			$('#datepicker').datepicker('show');
		}
	}

	/*
	 * Handle the datepicker change
	 */
	handle_datepicker_change(date){
		var targetdate = new Date(date.replaceAll('-','/') + ' 23:59:59'); // Use the very end of day so that our findIndex works later

		if ( jQuery('#rewindicon').hasClass('active') ) {
			this.util_setup_new_rewind_date(targetdate);	
		}

		// Find the first photo that is younger than our target photo
		// var first = this.photos.findIndex(o => o['date'] <= targetdate);
		var first = this.photos.find(function(o){return o.date <= targetdate && o.type == 'media'})

		this.ui_scroll_to_photo(first.id);

		this.ui_refresh_layout();
	}

	/*
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
			this.util_setup_new_rewind_date();
		}

		this.ui_refresh_layout();
		this.hyperlist_container.prop('scrollTop',0);
	}

	/*
	 * Handle logout
	 */
	handle_exit_click() {
		window.location = this.fastbackurl + '?logout=true';
	}

	/*
	 * Handle tag click
	 */
	handle_tag_click() {
		this.ui_hide_thumb();
		if ( $('#tagwindow').hasClass('disabled') ) {

			this.tag_state = JSON.stringify({
				'tags' : this.active_tags,
				'andor': $('#tagand').hasClass('active'),
				'tagson': $('#tagon').hasClass('active')
			});

			$('#tagwindow').removeClass('disabled');
			return;
		} else {
			this.ui_hide_tags();
		}
	}
	
	/*
	 * Handle clicking the path picker
	 */
	handle_pathpicker_click(e) {
		var targetdir = e.target.value;

		var first = this.photos.find(function(o){return o.path == targetdir && o.type == 'media'})

		this.ui_scroll_to_photo(first.id);

		this.ui_refresh_layout();
	}

	/*
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
				setTimeout(this.setup_map.bind(this),100);
			} else {
				this.fmap.lmap.invalidateSize();

				if ( this._map_init_action_queue !== undefined && Object.keys(this._map_init_action_queue).length > 0 ) {
					this.util_map_action_queue();
				} else if ( this.fmap.clusterlayer.getBounds().isValid() ) {
					this.fmap.lmap.fitBounds(this.fmap.clusterlayer.getBounds());
				} else {
					this.fmap.lmap.setView([0,0],1);
				}
			}
		}
		this.ui_refresh_layout();
	}

	/*
	 * Interact with map on mouse over
	 */
	handle_tn_mouseover(e){
		var photoid = jQuery(e.target).closest('.tn').find('img').first().data('photoid');
		this.ui_flash_map_for_id(photoid);
	}

	/*
	 * Make the tag filtering controls look how they should
	 */
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

	/*
	 * Add or remove a tag from the tag filter list
	 */
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

	/*
	 * When the map is done moving, we might need to adjust filters
	 */
	handle_map_zoom_move_end(e) {
		this.handling_map_move_end = true;

		// Moving the map only dirties our filters if we're doing map based filtering.
		if ( this.active_filters.map !== undefined ) {
			this.dirty_filters = true;
			this.ui_refresh_layout();
		}

		delete this.handling_map_move_end;
	}

	/*
	 * Add colored date blocks to a photos array
	 */
	util_add_separator_blocks(photos) {
		var prev_date = null;
		var cur_date;

		var prev_path = null;
		var cur_path;

		for(var i = 0; i<photos.length; i++){
			if ( photos[i].type !== 'media' ) {
				continue;
			}

			cur_date = photos[i].date.getFullYear() + '-' + (photos[i].date.getMonth() + 1) + '-' + (photos[i].date.getDate());

			if ( cur_date != prev_date ) {
				photos.splice(i,0,{
					'type': 'dateblock',
					'printdate': cur_date,
					'date': photos[i]['date']
				});
			}

			prev_date = cur_date;

			if ( this.orig_photos == undefined ) {
				photos[i].id = i;
			}

		}

		return photos;
	}

	/*
	 * Make a single row with however many photos we need.
	 *
	 * Called by Hyperlist
	 *
	 * @row - Which row to use. 
	 */
	util_generate_row(row) {
		var self = this;
		var slice_from = (row * this.cols);
		var slice_to = (row * this.cols) + this.cols;
		var vidclass = '';
		var date;
		var errimg;
		var html = this.photos.slice(slice_from,slice_to).map(function(p){

			if ( p.type == 'dateblock' ) {
				date = p.date;
				// I feel like this is kind of clever. I take the Year-Month, eg. 2021-12, parse it to an int like 202112 and then take the mod of the palette length to get a fixed random color for each date.
				var cellhtml = '<div class="tn nolink" style="background-color: ' + self.palette[parseInt(p.printdate.replaceAll('-','')) % self.palette.length] + ';">';
				cellhtml += '<div class="faketable dateblock">';
				cellhtml += '<div class="faketablecell">' + date.getDate() +  '</div>';
				cellhtml += '<div class="faketablecell">' + date.toLocaleDateString(navigator.languages[0],{month:'long'}) + '</div>';
				cellhtml += '<div class="faketablecell">' + date.getFullYear()  + '</div>';
				cellhtml += '</div>';
				cellhtml += '</div>';
				return cellhtml;
			} else {
				return self.modules[p.module].util_generate_row_block(p);
			}
		}).join("");
		var e = jQuery.parseHTML('<div class="photorow">' + html + '</div>');
		return e[0];
	}

	/*
	 * For an optional date object, set up a new rewind view
	 */
	util_setup_new_rewind_date(date_to_use) {
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

	/*
	 * Run the cron job every 5 minutes. 
	 *
	 * The PHP timeout is set to 2 minutes (assuming ini_set can override it) so 5 minutes should give the server some breathing room.
	 */
	util_cron() {
		// var url = this.fastbackurl + '?cron=now';
		// var self = this;
		// $.get(url).then(function() {
		// 	setTimeout(self.util_cron.bind(self),1000 * 5 * 60);
		// });
	}

	/*
	 * In the case that we need the map to do something after it has been initialized
	 * we can set up a little callback function and add it to the queue.
	 */
	util_map_action_queue(e) {
		this._map_init_action_queue = this._map_init_action_queue || {};
		var keys = Object.keys(this._map_init_action_queue);
		for(var k = 0;k< keys.length;k++){
			this._map_init_action_queue[keys[k]]();
			delete this._map_init_action_queue[keys[k]];
		}
	}

	/*
	 * Take a photos array and build geojson from it
	 */
	util_build_geojson(photos) {
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

	/*
	 * Only apply filters to data strctures, no redraws.
	 */
	util_apply_filters() {
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

			this.photos = this.util_add_separator_blocks(this.photos);
			this.ui_update_cluster();
			this.dirty_filters = false;
		}
		delete this.applying_filters;
	}

	/*
	 * Called after a hyperlist chunk is rendered
	 */
	util_after_render() {
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
		var geojson = this.util_build_geojson(photos);
		this.fmap.flashlayer.clearLayers();
		this.fmap.flashlayer.addData(geojson);
	}

	/*
	 * For a given photo ID, show the thumb popup. 
	 *
	 * We assume that the correct ID has been found by now, even though photos may have scrolled or shifted in the background somehow
	 *
	 * @photoid the ID of the photo to show, the index of the photo in the csv array
	 * @showalt If set to 1, and the photo has a `alt` attribute, then the fullsize will show the alt media instead.
	 *
	 * Returns false if couldn't show thumb
	 */
	ui_show_fullsized(photoid,showalt) {
		var imghtml;
		var showalt = showalt || 0;
		var photo = this.orig_photos.find(function(p){return p.id == photoid && p.type == 'media';});
		if ( photo === undefined ) {
			return false;
		}
		var imgsrc = showalt ? photo.alt : photo.mfile;
		var fileinfo = imgsrc.split(':');

		this.modules[fileinfo[0]].ui_show_fullsized(photo,showalt);
	}

	/*
	 * Hide the fullsized image, pausing the video if applicable.
	 */
	ui_hide_thumb() {
		if ( jQuery('#thumb').hasClass('disabled') ) {
			return;
		}

		jQuery('#thumb').addClass('disabled');
		if ( jQuery('#thumb video').length > 0 ) {
			jQuery('#thumb video')[0].pause();
		}
	}

	/*
	 * For a given photo ID, scroll to it if it is in the current photos.
	 */
	ui_scroll_to_photo(photoid) {
		var photo_idx = this.photos.findIndex(function(p){return p.id == photoid && p.type == 'media';});

		if ( photo_idx === -1 ) {
			return false; // Couldn't find the requested photo
		}

		// Get the row number now
		var rownum = parseInt(photo_idx / this.cols)

		// Set the scrollTop
		this.hyperlist_container.prop('scrollTop',(rownum * this.hyperlist_config.itemHeight));
	}

	/*
	 * Go to photo id
	 */
	ui_go_to_photo_id(id) {
		setTimeout(function(){
			var first = this.photos.findIndex(o => o.id == id);

			// If we don't find one, go all the way to the end
			if ( first === undefined || first === -1 ) {
				first = this.photos.length - 1;
			}

			this.scroll_to_photo(first);
		}.bind(this),100);
		this.ui_show_fullsized(id);
	}

	/*
	 * Close tags interface, applying filter, if needed
	 */
	ui_hide_tags() {
		if ( $('#tagwindow').hasClass('disabled') ) {
			return;
		}

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
		setTimeout(this.ui_refresh_layout.bind(this),20);
	}

	/*
	 * For a given photo ID, flash the corresponding icon on the map
	 */
	ui_flash_map_for_id(target_id){
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
		this.ui_flash_square_for_id(target_id);
	}

	/*
	 * For a given photo ID, flash the thumbnail
	 */
	ui_flash_square_for_id(target_id) {
		var one_tn = jQuery('img[data-photoid="' + target_id +'"]').closest('.tn').addClass('flash');

		setTimeout(function(){
			one_tn.removeClass('flash');
		},500);
	}

	/*
	 * Switch map filtering off and on
	 */
	ui_toggle_map_filter() {
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
		this.ui_refresh_layout();
	}

	/*
	 * Update the markercluster content
	 */
	ui_update_cluster() {
		if ( this.fmap === undefined ) {
			return;
		}

		var self = this;
		// ui_update_cluster gets called from update and blocks. 
		// Separate it out so it can updae cluster on its own
		setTimeout(function(){
			var geojson = self.util_build_geojson();

			var gj = L.geoJson(geojson);

			self.fmap.clusterlayer.clearLayers()

			self.fmap.clusterlayer.addLayer(gj,{
				'chunkedLoading': true
			});

			// If we're handling a user inited map move we don't want to update zoom. Let the user go where they want.
			// if ( self.handling_map_move_end === undefined ) {
			// 	if ( self._map_init_action_queue !== undefined && Object.keys(self._map_init_action_queue).length > 0 ) {
			// 		self.util_map_action_queue();
			// 	} else if ( self.fmap.clusterlayer.getBounds().isValid() ) {
			// 		self.fmap.lmap.fitBounds(self.fmap.clusterlayer.getBounds());
			// 	} else {
			// 		self.fmap.lmap.setView([0,0],1);
			// 	}
			// }
		},50);
	}

	/*
	 * Refresh the page layout, including accounting for changed row nums or page resize.
	 *
	 * Called manually and by hyperlist
	 */
	ui_refresh_layout() {
		jQuery('body').css('height',window.innerHeight);
		this.util_apply_filters();

		// Browsers can only support an object so big, so we can only use so many rows.
		// Calculate the new max zoom
		this.maxzoom = Math.ceil(Math.sqrt(this.hyperlist_container.width() * this.photos.length / this.hyperlist._maxElementHeight));

		// Make sure our new cols doesn't go over the max zoom
		this.cols = Math.max(this.maxzoom, this.cols);

		if ( !this.hyperlist_container.hasClass('up' + this.cols) ) {
			this.hyperlist_container.removeClass('up1 up2 up3 up4 up5 up6 up7 up8 up9 up10'.replace('up' + this.cols,' '));
			this.hyperlist_container.addClass('up' + this.cols);
		}

		// Set the slider size
		var zoomval = jQuery('#zoom').val();
		jQuery('#resizer input').prop('min',this.maxzoom);

		// Update hyperlist config
		this.hyperlist_config.height = this.hyperlist_container.parent().height();
		this.hyperlist_config.itemHeight = (this.hyperlist_container.width() / this.cols);
		this.hyperlist_config.total = Math.ceil(this.photos.length / this.cols);


		// propagate changes
		this.hyperlist.refresh(this.hyperlist_container[0], this.hyperlist_config);

		if ( this.photos.length == 0 ) {
			jQuery('#photos > div').css('opacity',1);
			var html = '<div id="nophotos"><p>No photos are available for this view. Try changing your filters.</p>';
			if (this.csv_error_load) {
				html += '<p>A new install will need a few minutes to populate the database.</p>';
			}
			html += '</div>';
			jQuery('#photos > div').html(html);
		}
	}
}
