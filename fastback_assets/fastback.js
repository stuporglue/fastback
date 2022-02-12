class Fastback {

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
	}

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
					0: r[0],
					1: Boolean(r[1]),
					2: new Date(r[2]),
					'do': r[2] 
				};
			});
		}).then(function(){
			self.orig_photos = self.photos;
			self.hyperlist_init();
			self.load_nav();
			jQuery('#zoom').on('change',self.zoom_change.bind(self));
		});
	}

	/**
	 * Initiate the hyperlist
	 */
	hyperlist_init() {
		var self = this;
		this.hyperlist_container = jQuery('#photos');

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
		var fd = this.photos[0][2];
		var ld = this.photos[this.photos.length - 1][2];

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
				var first = self.photos.findIndex(o => o[2] <= targetdate);

				// If we don't find one, just start at photo 0
				if ( first === undefined ) {
					first = 0;
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
				self.photos = fastback.photos.filter(function(p){return p.do.match(re);});
			}

			self.refresh_layout();
			self.hyperlist_container.prop('scrollTop',0);
		});
	}

	/*
	 * Handle the slider changes.
	 */
	zoom_change(e) {
		this.cols = Math.max(this.maxzoom, parseInt(e.target.value));

		jQuery('#photos').removeClass('up1 up2 up3 up4 up5 up6 up7 up8 up9 up10'.replace('up' + this.cols,' '));
		jQuery('#photos').addClass('up' + this.cols);
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
		this.maxzoom = Math.ceil(Math.sqrt(fastback.hyperlist_container.width() * fastback.photos.length / fastback.hyperlist._maxElementHeight))

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
		var html = this.
					fastback.
					photos.
					slice(slice_from,slice_to).
					map(function(p){
						return '<div class="tn"><img src="' + encodeURI(self.fastback.cacheurl + p[0]) + '.jpg"/></div>';
					}).
					join("");
		var e = jQuery.parseHTML('<div class="photorow">' + html + '</div>');
		return e[0];
	}
}
