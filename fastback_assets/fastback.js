/**
 * The fastback main class should only be responsible for loading the data from csv,
 * displaying it and handling the hyperlist. This includes clicking thumbnails to view the fullsize
 *
 * In other words, the core Fastback experience.
 */
class Fastback {

	/**
	 * Load the data and set up event handlers
	 */
	constructor(args) {
		this.setProps();

		jQuery.extend(this,args);
		$.get(this.cacheurl + 'fastback.csv', this._load_and_parse_data.bind(this)).then(this._setup_app.bind(this));
	}

	_load_and_parse_data(data) {
		var res = Papa.parse(data.trim());
		this.photos = res.data.map(function(r){
			return {
				'file': r[0],
				'isvideo': Boolean(parseInt(r[1])),
				'date': new Date(r[2].replaceAll('-','/')),
				'type': 'media',
				// Our csv is in x,y,z (lon,lat,elevation), but leaflet wants (lat,lon,elevation) so we swap lat/lon here.
				'coordinates': (isNaN(parseFloat(r[3])) ? null : [parseFloat(r[4]),parseFloat(r[3]),parseFloat(r[5])]),
				'dateorig': r[2],
				'tags': [],
			};
		});

		this.photos = this.add_date_blocks(this.photos);

		if ( this.photos.length === 0 ) {
			console.log("No photos loaded. Maybe it's a fresh install?");
			jQuery('#photos').html("No photos found. Maybe this is a fresh install? If not, check file permissions and the database!");
			return false;
		}

		// Browsers can only support an object so big, so we can only use so many rows.
		// Calculate the new max zoom
		this.maxzoom = Math.ceil(Math.sqrt(fastback.hyperlist_container.width() * fastback.photos.length / HyperList.getMaxBrowserHeight()));

		// Make sure our new cols doesn't go over the max zoom
		this.cols = Math.max(this.maxzoom, this.cols);

		this.hyperlist_container.addClass('up' + this.cols);
	}

	_setup_app() {
		this.orig_photos = this.photos;
		this.hyperlist_init();
		this.load_nav();

		jQuery('#speedslide').on('input',this.speed_slide.bind(this));
		jQuery('#zoom').on('change',this.zoom_change.bind(this));
		jQuery('#photos').on('click','.tn',this.handle_thumb_click.bind(this));

		/* Nav action handlers stuff */
		jQuery('#thumbright').on('click',this.handle_thumb_next.bind(this));
		jQuery('#thumbleft').on('click',this.handle_thumb_prev.bind(this));
		jQuery('#thumbclose').on('click',this.hide_thumb.bind(this));
		jQuery(document).on('keydown',this.keydown_handler.bind(this));
		// Touch stuff
		jQuery('#thumb').hammer({recognizers: [ 
			[Hammer.Swipe,{ direction: Hammer.DIRECTION_ALL }],
		]}).on('swiperight swipeup swipeleft', this.handle_thumb_swipe.bind(this));

		jQuery('#hyperlist_wrap').on('mouseenter','.tn',this.handle_tn_mouseover.bind(this));

		if ( this.features.map ){
			this.map = new Fastback.map(this);
		}

		if ( this.features.tagging ) {
			this.tagging = new Fastback.tagging(this);
		}

		if ( this.features.datejump ) {
			this.datejump = new Fastback.datejump(this);
		}

		if ( this.features.onthisday ) {
			this.onthisday = new Fastback.onthisday(this);
		}
	}

	/**
	 * Our default properties.
	 */
	setProps() {
		this.cacheurl = "./";
		this.photourl = "./";
		this.fastbackurl = "./";
		this.photos = [];
		this.cols = 5;
		this.palette = [ '#eedfd1', '#52a162', '#23403b', '#f3a14b', '#ec6c3e', '#d0464e', '#963755' ];
		this.hyperlist_container = jQuery('#photos');
		this.active_filters = {};
		this.dirty_filters = false;

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
			this.browser_supported_file_types.push('heic');
			this.browser_supported_file_types.push('mov');
			this.browser_supported_file_types.push('mpg');
		}
	}

	/**
	 * Initiate the hyperlist
	 */
	hyperlist_init() {
		var self = this;

		// Find our stylesheet
		/*
		var stylesheet;
		for(var s = 0;s<document.styleSheets.length;s++){
			if ( document.styleSheets[s].href.match(/.*\/fastback_assets\/fastback.css$/) !== null ) {
				stylesheet = document.styleSheets[s];
			}
		}

		if ( stylesheet === undefined ) {
			throw new Error("Couldn't find fastback stylesheet.");
		}
		*/

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
		jQuery('#photos').trigger('fastback_load_nav');
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
	zoom_change(e) {
		this.cols = Math.max(this.maxzoom, parseInt(e.target.value));

		this.hyperlist_container.removeClass('up1 up2 up3 up4 up5 up6 up7 up8 up9 up10'.replace('up' + this.cols,' '));
		this.hyperlist_container.addClass('up' + this.cols);
		this.refresh_layout();
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

			if ( photos[i].dateorig === undefined ) {
				continue;
			}

			cur_date = photos[i].dateorig.replace(/(....-..).*/,"$1");

			if ( cur_date != prev_date ) {
				photos.splice(i,0,{
					'type': 'dateblock',
					'printdate': cur_date,
					'date': photos[i]['date']
				});
			}

			photos[i].id = i;

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
		var files_in_row = {};
		var html = this
			.photos
			.slice(slice_from,slice_to)
			.map(function(p){

				if ( p['type'] == 'media' ) {
					files_in_row[p['id']] = p['file'];
					if ( p['isvideo'] ) {
						vidclass = ' vid';
					} else {
						vidclass = '';
					}
					return '<div class="tn' + vidclass + '" data-file="' + p['file'] + '" data-tags="' + p['tags'].join(' ') + '"><img data-dateorig="' + p['dateorig']+ '" data-photoid="' + p['id'] + '" src="' + encodeURI(self.cacheurl + p['file']) + '.webp"/></div>';
				} else if ( p['type'] == 'dateblock' ) {
					date = p['date'];
					// I feel like this is kind of clever. I take the Year-Month, eg. 2021-12, parse it to an int like 202112 and then take the mod of the palette length to get a fixed random color for each date.
					var cellhtml = '<div class="tn nolink" style="background-color: ' + self.palette[parseInt(p['printdate'].replace('-','')) % self.palette.length] + ';">';
					cellhtml += '<div class="faketable">';
					cellhtml += '<div class="faketablecell">' + date.getDate() +  '</div>';
					cellhtml += '<div class="faketablecell">' + date.toLocaleDateString(navigator.languages[0],{month:'long'}) + '</div>';
					cellhtml += '<div class="faketablecell">' + date.getFullYear()  + '</div>';
					cellhtml += '</div>';
					cellhtml += '</div>';
					return cellhtml;
				}
			})
			.join("");

		var e = jQuery.parseHTML('<div class="photorow">' + html + '</div>');


		jQuery('#photos').trigger('fastback_generate_row', files_in_row );

		return e[0];
	}

	/**
	 * Only apply filters to data strctures, no redraws.
	 */
	apply_filters() {
		if ( this.dirty_filters ) {
			this.photos = this.orig_photos.filter(function(item) { return item.type === 'media'; });

			for(var filter in this.active_filters) {
				this.active_filters[filter]();
			}

			this.photos = this.add_date_blocks(this.photos);
			jQuery('#photos').trigger('fastback_dirty_filters',this);
			this.dirty_filters = false;
		}
	}

	/*
	 * Refresh the page layout, including accounting for changed row nums or page resize.
	 *
	 * Called manually and by hyperlist
	 */
	refresh_layout() {

		this.apply_filters();

		// Browsers can only support an object so big, so we can only use so many rows.
		// Calculate the new max zoom
		this.maxzoom = Math.ceil(Math.sqrt(this.hyperlist_container.width() * this.photos.length / this.hyperlist._maxElementHeight));

		// Make sure our new cols doesn't go over the max zoom
		this.cols = Math.max(this.maxzoom, this.cols);

		// Set the slider size
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
		var totalheight = Math.ceil(fastback.photos.length / fastback.cols) * fastback.hyperlist_config.itemHeight
		jQuery('#speedslide').val(this.hyperlist_container[0].scrollTop / totalheight * 100);
		jQuery('#photos').trigger('fastback_after_render',self);
	}

	handle_thumb_click(e) {

		if ( e === undefined ) {
			console.log("No click event. Skipping");
			return;
		}

		var divwrap = jQuery(e.target).closest('div.tn');
		var img = divwrap.find('img');

		var imghtml;

		var photoid = img.data('photoid');
		var imgsrc = this.photos[photoid]['file'];
		var basename = imgsrc.replace(/.*\//,'');
			var fullsize = this.photourl + imgsrc;

			// File type not found, proxy a jpg instead
			var supported_type = (this.browser_supported_file_types.indexOf(fullsize.replace(/.*\./,'').toLowerCase()) != -1);
			if ( !supported_type ) {
				fullsize = this.fastbackurl + '?proxy=' + encodeURIComponent(fullsize);	
			}

			if (divwrap.hasClass('vid') && supported_type){
				imghtml = '<video controls poster="' + img.attr('src') + '"><source src="' + fullsize + '#t=0.0001">Your browser does not support this video format.</video>';
			} else {
				imghtml = '<img src="' + fullsize +'"/>';
			}
			jQuery('#thumbcontent').html(imghtml);

			jQuery('#thumbdownload').html(`<h2><a class="download" href="${fullsize}" download>${basename}</a></h2>`);
			jQuery('#thumbflag').html(`<a class="flag" onclick="return fastback.sendbyajax(this)" href="${this.fastbackurl}?flag=${encodeURIComponent(imgsrc)}">Flag Image</a>`);
			jQuery('#thumbinfo').html(this.photos[photoid]['date']);

			var share_uri = jQuery('a.download')[0].href;
			jQuery('#share_fb').attr('href','https://facebook.com/sharer/sharer.php?u=' + encodeURIComponent(share_uri));
			jQuery('#share_email').attr('href','mailto:?subject=' + encodeURIComponent(basename) + '&body=' + encodeURIComponent(share_uri));
			if ( this.is_mobile_browser ) {
				jQuery('#share_whatsapp').attr('href','whatsapp://send?text=' + encodeURIComponent(basename) + '%20' + encodeURIComponent(share_uri));
			} else {
				jQuery('#share_whatsapp').attr('href','https://web.whatsapp.com/send?text=' + encodeURIComponent(basename) + '%20' + encodeURIComponent(share_uri));
			}

		jQuery('#thumb').data('curphoto',photoid);
		jQuery('#thumb').show();
	}

	handle_thumb_next(e) {
		var photoid = jQuery('#thumb').data('curphoto');

		while(true) {
			photoid++;

			if ( photoid === this.photos.length ) {
				return false;
			}

			if ( this.photos[photoid].type === 'media' ) {
				var nextm = jQuery('div.tn img[data-photoid="' + photoid + '"]');

				if (nextm.length > 0) {
					nextm.trigger('click'); 
					return true;
				} else {
					return false;
				}
			}
		}
	}

	handle_thumb_prev(e) {
		var photoid = jQuery('#thumb').data('curphoto');

		while(true) {
			photoid--;

			if ( photoid < 0 ) {
				return false;
			}

			if ( this.photos[photoid].type === 'media' ) {
				var prevm = jQuery('div.tn img[data-photoid="' + photoid + '"]');

				if (prevm.length > 0) {
					prevm.trigger('click'); 
					return true;
				} else {
					return false;
				}
			}
		}
	}

	hide_thumb() {
		jQuery('#thumb').hide();
	}

	keydown_handler(e) {
		switch(e.key){
			case 'Escape':
				this.hide_thumb();
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
		}
	}

	/**
	 * Click a link but do it with ajax
	 */
	sendbyajax(link) {
		var thelink = link;
		jQuery.get(thelink.href).then(function(){
			jQuery(thelink).hide();
		});
		return false;
	}

	/**
	 * Go to photo id
	 */
	go_to_photo_id(id) {
		this._go_to_photo('id',id);
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

		// Get the row number now
		var rownum = parseInt(first / this.cols)

		// Set the scrollTop
		this.hyperlist_container.prop('scrollTop',(rownum * this.hyperlist_config.itemHeight));

		this.refresh_layout();
	}

	/*
	 * Interact with map on mouse over
	 */
	handle_tn_mouseover(e){
		var tn = jQuery(e.target).closest('.tn');
		jQuery('#photos').trigger('fastback_tn_mouseover',tn);
	}

	// https://stackoverflow.com/questions/11381673/detecting-a-mobile-browser
	is_mobile_browser() {
		let check = false;
		(function(a){if(/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino|android|ipad|playbook|silk/i.test(a)||/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0,4))) check = true;})(navigator.userAgent||navigator.vendor||window.opera);
		this.is_mobile_browser = check;
		return check;
	}
}

/**
 * This class lets the user jump to any date in the photo collection
 */
Fastback.datejump = class {

	constructor(args) {
		jQuery('#widgets').append('<div id="calendaricon"><input readonly id="datepicker" type="text"></div>');

		var self = this;
		this.fastback = args;

		// first date (in tags list) -- The default is Descending view, so this should be the greatest date
		var fd = this.fastback.photos[0]['date'];
		var ld = this.fastback.photos[this.fastback.photos.length - 1]['date'];

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
	}


	/**
	 * Handle the datepicker change
	 */
	handle_datepicker_change(date){
		var targetdate = new Date(date.replaceAll('-','/') + ' 23:59:59'); // Use the very end of day so that our findIndex works later

		jQuery('#photos').trigger('fastback_datepicker_change',targetdate);

		// Find the first photo that is younger than our target photo
		var first = this.fastback.photos.findIndex(o => o['date'] <= targetdate);

		// If we don't find one, go all the way to the end
		if ( first === undefined || first === -1 ) {
			first = this.fastback.photos.length - 1;
		}

		// Get the row number now
		var rownum = parseInt(first / this.fastback.cols)

		// Set the scrollTop
		this.fastback.hyperlist_container.prop('scrollTop',(rownum * this.fastback.hyperlist_config.itemHeight));

		this.fastback.refresh_layout();
	}
}

/**
 * This class adds support for an "On this day" type experience
 */ 
Fastback.onthisday = class {

	constructor(args) {
		this.fastback = args;

		jQuery('#widgets').append('<div id="rewindicon"></div>');

		jQuery('#rewindicon').on('click',this.handle_rewind_click.bind(this));

		if ( this.fastback.features.datejump ) {
			jQuery('#photos').on('fastback_datepicker_change',this.handle_fastback_datepicker_change.bind(this));
		}
	}

	handle_fastback_datepicker_change(e,targetdate) {
		if ( jQuery('#rewindicon').hasClass('active') ) {
			this.setup_new_rewind_date(targetdate);	
		}
	}

	/**
	 * For an optional date object, set up a new rewind view
	 */
	setup_new_rewind_date(date_to_use) {
		var self = this;
		var d = date_to_use || new Date();
		var datepart = ((d.getMonth() + 1) + "").padStart(2,"0") + '-' + (d.getDate() + "").padStart(2,"0")
		var re = new RegExp('^....-' + datepart + ' ');
		this.fastback.active_filters.rewind = function() {
			self.fastback.photos = self.fastback.photos.filter(function(p){ return p.dateorig.match(re);});
		};
		this.fastback.dirty_filters = true;
	}

	/**
	 * Handle the rewind icon click
	 */
	handle_rewind_click() {
		var icon = jQuery('#rewindicon');

		if ( icon.hasClass('active') ) {
			icon.removeClass('active');
			delete this.fastback.active_filters.rewind;
			this.fastback.dirty_filters = true;
		} else {
			jQuery('#rewindicon').addClass('active');
			this.rewind_date = new Date();
			this.setup_new_rewind_date();
		}

		this.fastback.refresh_layout();
		this.fastback.hyperlist_container.prop('scrollTop',0);
	}
}

/**
 * This class adds support for map interactions
 */
Fastback.map = class {

	constructor(args) {

		this.fastback = args;

		jQuery('#hyperlist_wrap').before('<div id="map"></div>');

		jQuery('#widgets').append('<div id="globeicon"></div>');

		var self = this;
		this.has_map = false;

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

		// Check if any photos have geotagging
		jQuery(this.fastback.photos).each(function(p){
			if ( self.has_map || p.coordinates !== null ) {
				self.has_map = true;
				jQuery('#globeicon').addClass('enabled');
				return false;
			}
		});


		jQuery('#photos').on('fastback_dirty_filters',function(e){
			this.map_update_cluster();
		});

		jQuery('#photos').on('fastback_after_render',this.after_render.bind(this));


		jQuery('#globeicon').on('click',this.handle_globe_click.bind(this));
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
				spiderfyOnMaxZoom: false
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

		L.Control.MapFilter = L.Control.extend({
			onAdd: function(map) {
				var button = jQuery('<div id="mapfilter">????</div>');
				button.on('click',function(){
					self.toggle_map_filter();
				});
				return button[0];
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

	/**
	 * Update the clustermarker content
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

		this.fmap.lmap.fitBounds(this.fmap.clusterlayer.getBounds());
	}

	/**
	 * Take a photos array and build geojson from it
	 */
	build_geojson(photos) {
		photos = photos || this.fastback.photos;

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
						'id': i,
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

				// Elev
				bbox[2] = Math.min(bbox[2],photos[i].coordinates[2]);
				bbox[5] = Math.max(bbox[5],photos[i].coordinates[2]);
			}
		}

		geojson.bbox = bbox;

		return geojson;
	}


	/**
	 * Handle globe icon click
	 */
	handle_globe_click(action) {
		if ( jQuery('#globeicon').hasClass('active') || action == 'disable') {

			jQuery('body').removeClass('split');
			jQuery('body').removeClass('map');
			jQuery('#globeicon').removeClass('active');

		} else if ( !jQuery('#globeicon').hasClass('active') || action == 'enable') {

			if ( this.fastback.tagging !== undefined ) {
				this.fastback.tagging.toggle_tagging('disable');
			}

			jQuery('body').addClass('split');
			jQuery('body').addClass('map');
			jQuery('#globeicon').addClass('active');

			if ( this.fmap === undefined ) {
				this.map_init();
			} else {
				this.fmap.lmap.invalidateSize();
				this.fmap.lmap.fitBounds(this.fmap.clusterlayer.getBounds());
			}
		}
		this.fastback.refresh_layout();
	}

	mouseover_handler(e) {
		var photoid = jQuery(e.target).find('img').first().data('photoid');
		this.flash_map_for_id(photoid);
	}

	flash_map_for_id(target_id){

		if ( this.fmap === undefined ) {
			return;
		}

		var one_layer;
		var one_tn
		var self = this;

		this.fmap.flashlayer.eachLayer(function(l){
			if ( target_id == l.feature.properties.id ) {
				one_layer = l;
			}
		});

		one_tn = jQuery('img[data-photoid="' + target_id +'"]').closest('.tn').addClass('flash');

		if ( one_layer !== undefined ) {
			one_layer.setStyle(this.flashstyle_hover);
		}

		setTimeout(function(){
			one_tn.removeClass('flash');
			if ( one_layer !== undefined ) {
				one_layer.setStyle(self.flashstyle);
			}
		},500);
	}

	toggle_map_filter() {
		console.log("Map filter");

		var is_filtered = (this.map_filter === undefined || this.map_filter === false );

		if ( !is_filtered ) {
			this.fastback.active_filters.map = function(p){
				var mapbounds = self.fmap.lmap.getBounds()
				self.photos = self.photos.filter(function(p){
					// Reject any photos without geo
					if ( p.coordinates === null ) {
						return false;
					}
					// Reject any photos outside the bounds of the map
				});
			};
		} else {
			delete this.fastback.active_filters.map;
		}

		this.dirty_filters = true;
	}

	after_render() {
		if ( this.fmap === undefined ) {
			return;
		}

		var self = this;

		// Refresh the map highlight layer
		var rows = jQuery('.photorow:visible').toArray().filter(function(r){return jQuery(r).position().top < window.innerHeight;})
		var tnsar =	rows.map(function(f){ return jQuery(f).find('.tn img').toArray(); });
		var tns = jQuery.map(tnsar,function(f){return f;});
		var photos = tns.map(function(f){return self.fastback.photos[jQuery(f).data('photoid')];})
		var geojson = this.build_geojson(photos);
		this.fmap.flashlayer.clearLayers();
		this.fmap.flashlayer.addData(geojson);
	}
}

/** 
 * This class adds support for tagging, and for filtering by tags
 */
Fastback.tagging = class {

	constructor(args) {
		jQuery('#hyperlist_wrap').before(
			'<div id="tags">'
			+ '<h1>Add tags</h1>'
			+ '<p>Add as many tags as you want, separated by commas or spaces. Or click existing tags to add them to the list.</p>'
			+ '<form id="tagform">'
			+ '<input type="text" id="new_tags"/> '
			+ '<input type="submit" value="Add Tags"/>'
			+ '</form>'
			+ '<hr>'
			+ '<div id="taglist"></div>'
			+ '</div>'
		);

		jQuery('#widgets').append('<div id="tagicon"></div>');

		jQuery('#tagform').on('submit',this.handle_add_tags.bind(this));

		this.fastback = args;
		this.selected = new Set();
		this.shiftprevon = false;
		this.shiftprevoff = false;

		jQuery('#tagicon').on('click',this.toggle_tagging.bind(this));

		jQuery('#photos').on('fastback_generate_row',this.handle_generate_row.bind(this));
	}

	toggle_tagging(action) {
		if ( jQuery('#tagicon').hasClass('active') || action == 'disable' ) {

			jQuery('body').removeClass('tags');
			jQuery('body').removeClass('split');

			// disable
			jQuery('#tagicon').removeClass('active');
			jQuery('#photos').off('fastback_after_render',this.after_render_callback.bind(this));
			try {
				jQuery('#photos').selectable('destroy');
			} catch (e) {
				// do nothing;
			}
			this.selectable = new Set();
		} else if ( !jQuery('#tagicon').hasClass('active') || action == 'enable' ) {
			if ( this.fastback.map !== undefined ) {
				this.fastback.map.handle_globe_click('disable');
			}

			jQuery('#tagicon').addClass('active');
			jQuery('body').addClass('tags');
			jQuery('body').addClass('split');

			jQuery('#photos').on('fastback_after_render',this.after_render_callback.bind(this));
			this.setup_selectable();
			this.setup_tags_pane();
		}
		this.fastback.refresh_layout();
	}

	setup_tags_pane() {
		var self = this;
		$.get(this.fastback.fastbackurl + '?tags=1&get_tags=1').then(function(tags){
			var html = '';
			for(var i=0;i<tags.length;i++) {
				html += '<div class="tags">' + tags[i] + '</div>';
			}
			jQuery('#taglist').html(html);
		});
	}

	setup_selectable() {
		var self = this;

		jQuery('#photos').selectable({
			filter: '.tn',
			classes: {
				'ui-selecting': 'tagging_selecting',
				'ui-selected': 'tagging_selected'
			},
			selected: function(e, ui) {
				var pid = jQuery(ui.selected).find('img').data('photoid');
				self.selected.add(pid);
			},
			unselected: function(e, ui){
				var pid = jQuery(ui.unselected).find('img').data('photoid');
				self.selected.delete(pid);
			}
		});
	}

	after_render_callback(fb){
		this.setup_selectable();
		this.after_render_catchup();
	}

	after_render_catchup() {
		var self = this;
		jQuery('.tn').each(function(i,tn){
			var pid = jQuery(tn).find('img').data('photoid');
			if ( self.selected.has(pid) ) {
				jQuery(tn).addClass('tagging_selected ui-selected');
			}
		})
	}

	/**
	 * Form handling. Return false so we don't actually submit.
	 */
	handle_add_tags() {
		var self = this;

		if ( this.selected.length === 0 ) {
			return false;
		}

		var photos = [];
		this.selected.forEach(function(i){
			photos.push(self.fastback.photos[i].file);
		});

		$.post(this.fastback.fastbackurl + '?tags=1&new_tags=1',{
			'photos': photos,
			'tags': jQuery('#new_tags').val()
		}).then(function(results){
			console.log(results);
		});
	}

	handle_generate_row(e,files_in_row){

		if ( !jQuery('#tagicon').hasClass('active') ) {
			return false;
		}

		$.get(this.fastback.fastbackurl,{
			'tags' : 1,
			'file_tags': files_in_row
			}).then(function(res){
				console.log(res);
		});
	}
}
