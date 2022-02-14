class Fastback {

	/**
	 * Load the data and set up event handlers
	 */
	constructor(args) {
		this.setProps();

		var self = this;
		jQuery.extend(this,args);
		$.get(this.cacheurl + 'fastback.csv', function(data) {
			self.photos = data.trim().split("\n").map(function(r){
				var r = r.split("|");
				return {
					'file': r[0],
					'isvideo': Boolean(parseInt(r[1])),
					'date': new Date(r[2]),
					'type': 'media',
					'dateorig': r[2] 
				};
			});


			self.photos = self.add_date_blocks(self.photos);

			// self.photos = self.photos.slice(0,100);

			// Browsers can only support an object so big, so we can only use so many rows.
			// Calculate the new max zoom
			self.maxzoom = Math.ceil(Math.sqrt(fastback.hyperlist_container.width() * fastback.photos.length / HyperList.getMaxBrowserHeight()));

			// Make sure our new cols doesn't go over the max zoom
			self.cols = Math.max(self.maxzoom, self.cols);

			self.hyperlist_container.addClass('up' + self.cols);
		}).then(function(){
			self.orig_photos = self.photos;
			self.hyperlist_init();
			self.load_nav();
			jQuery('#zoom').on('change',self.zoom_change.bind(self));
			jQuery('#photos').on('click','.tn',self.handle_thumb_click.bind(self));

			/* Nav action handlers stuff */
			jQuery('#thumbright').on('click',self.handle_thumb_next.bind(self));
			jQuery('#thumbleft').on('click',self.handle_thumb_prev.bind(self));
			jQuery('#thumbclose').on('click',self.hide_thumb.bind(self));
			jQuery(document).on('keydown',self.keydown_handler.bind(self));
			// Touch stuff
			jQuery('#thumb').hammer({recognizers: [ 
				[Hammer.Swipe,{ direction: Hammer.DIRECTION_ALL }],
			]}).on('swiperight swipeup swipeleft', self.handle_thumb_swipe.bind(self));
		});
	}

	/**
	 * Our default properties.
	 */
	setProps() {
		this.cacheurl = "./";
		this.photourl = "./";
		this.staticurl = "./";
		this.fastbackurl = "./";
		this.photos = [];
		this.cols = 5;
		this.palette = [ '#eedfd1', '#52a162', '#23403b', '#f3a14b', '#ec6c3e', '#d0464e', '#963755' ];
		this.hyperlist_container = jQuery('#photos');

		// Browser type
		this.isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

		// Browser supported file types - will be shown directly. 
		// Anything not in this list will be proxied into a jpg
		this.browser_supported_file_types = [
			// videos
			'mp4','m4v', 'ogg', 'mov',
			// images
			'jpg','jpeg','gif','png' ];

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
		var stylesheet;
		for(var s = 0;s<document.styleSheets.length;s++){
			if ( document.styleSheets[s].href.match(/.*\/fastback_assets\/fastback.css$/) !== null ) {
				stylesheet = document.styleSheets[s];
			}
		}

		if ( stylesheet === undefined ) {
			throw new Error("Couldn't find fastback stylesheet.");
		}

		this.hyperlist_config = {
			height: window.innerHeight,
			itemHeight: (this.hyperlist_container.width() / this.cols),
			total: Math.ceil(this.photos.length / this.cols),
			scrollerTagName: 'div',
			fastback: this,
			generate: this.generate_row
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
			onSelect: function(date){

				if ( jQuery('#rewindicon').hasClass('active') ) {
					jQuery('#rewindicon').trigger('click');
				}

				jQuery('#rewindicon').removeClass('active');

				var targetdate = new Date(date + ' 00:00:00');

				// Find the first photo that is younger than our target photo
				var first = self.photos.findIndex(o => o['date'] <= targetdate);

				// If we don't find one, go all the way to the end
				if ( first === undefined || first === -1 ) {
					first = self.photos.length - 1;
				}

				// Get the row number now
				var rownum = parseInt(first / self.cols)

				// Set the scrollTop
				self.hyperlist_container.prop('scrollTop',(rownum * self.hyperlist_config.itemHeight));
			}
		});

		jQuery('#rewindicon').on('click',function(){
			var icon = jQuery('#rewindicon');

			if ( icon.hasClass('active') ) {
				icon.removeClass('active');
				self.photos = self.orig_photos;
			} else {
				jQuery('#rewindicon').addClass('active');
				var d = new Date();
				var datepart = ((d.getMonth() + 1) + "").padStart(2,"0") + '-' + (d.getDate() + "").padStart(2,"0")
				var re = new RegExp('^....-' + datepart + ' ');
				self.photos = fastback.photos.filter( function(p){ return p.type === 'media' && p.dateorig.match(re);} );
				self.photos = self.add_date_blocks(self.photos);
			}

			self.refresh_layout();
			self.hyperlist_container.prop('scrollTop',0);
		});

		jQuery('#globeicon').on('click',function(){
			if ( jQuery('body').hasClass('map') ) {
				jQuery('body').removeClass('map');
			} else {
				jQuery('body').addClass('map');
				// TODO: Add map
				// self.refresh_map();
			}
			self.refresh_layout();
		});
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

	/*
	 * Refresh the page layout, including accounting for changed row nums or page resize.
	 *
	 * Called manually and by hyperlist
	 */
	refresh_layout() {
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
	 * Make a single row with however many photos we need.
	 *
	 * Called by Hyperlist
	 *
	 * @row - Which row to use. 
	 */
	generate_row(row) {
		var self = this;
		var slice_from = (row * this.fastback.cols);
		var slice_to = (row * this.fastback.cols) + this.fastback.cols;
		var vidclass = '';
		var date;
		var html = this
					.fastback
					.photos
					.slice(slice_from,slice_to)
					.map(function(p){

						if ( p['type'] == 'media' ) {
							if ( p['isvideo'] ) {
								vidclass = ' vid';
							} else {
								vidclass = '';
							}
							return '<div class="tn' + vidclass + '"><img data-photoid="' + p['id'] + '" src="' + encodeURI(self.fastback.cacheurl + p['file']) + '.jpg"/></div>';
						} else if ( p['type'] == 'dateblock' ) {
							date = new Date(p['printdate'] + '-01');
							// I feel like this is kind of clever. I take the Year-Month, eg. 2021-12, parse it to an int like 202112 and then take the mod of the palette length to get a fixed random color for each date.
							var cellhtml = '<div class="tn nolink" style="background-color: ' + self.fastback.palette[parseInt(p['printdate'].replace('-','')) % self.fastback.palette.length] + ';">';
							cellhtml += '<div class="faketable">';
							cellhtml += '<div class="faketablecell">' + date.toLocaleDateString(navigator.languages[0],{month:'long'}) + '</div>';
							cellhtml += '<div class="faketablecell">' + date.getFullYear()  + '</div>';
							cellhtml += '</div>';
							cellhtml += '</div>';
							return cellhtml;
						}
					})
					.join("");
		var e = jQuery.parseHTML('<div class="photorow">' + html + '</div>');
		return e[0];
	}

	handle_thumb_click(e) {
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

		var ctrlhtml = '<h2>' + basename + '</h2>';
		ctrlhtml += '<p><a class="download" href="' + fullsize + '" download>' + basename + '</a>';
		ctrlhtml += '<br>';
		ctrlhtml += '<a class="flag" onclick="return fastback.sendbyajax(this)" href=\"' + this.fastbackurl + '?flag=' + encodeURIComponent(imgsrc) + '\">Flag Image</a>';
		ctrlhtml += '</p>';
		jQuery('#thumbcontent').html(imghtml);
		jQuery('#thumbcontrols').html(ctrlhtml);
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
		jQuery('#thumbcontent').html("");
		jQuery('#thumbcontrols').html("");
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

	add_date_blocks(photos) {
		var prev_date = null;
		var cur_date;
		for(var i = 0; i<photos.length; i++){
			cur_date = photos[i]['dateorig'].replace(/(....-..).*/,"$1");

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

	handle_thumb_swipe(e) {
		if ( e.type == 'swiperight' ) {
			this.handle_thumb_next();
		} else if ( e.type == 'swipeleft' ) {
			this.handle_thumb_prev();
		} else if ( e.type == 'swipeup' ) {
			this.hide_thumb();
		}
	}
}
