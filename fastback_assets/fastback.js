class Fastback {
	// built-in properties
	minphotos = 200;
	minpages = 5;

	notificationtimer;
	curthumbs = [];
	originurl = location.pathname.replace(/[^\/]+$/,'');

	disablehandlers = false;

	rowwidth = 5;

	// Properties loaded from json
	years;
	yearsindex;
	yearmonthindex;
	tags;

	last_scroll_factors;
	last_scroll_timestamp = 0;
	scroll_time = 50;

	constructor() {
		var self = this;



		$.getJSON(this.originurl + 'fastback.php?get=photojson', function(json) {
			jQuery.extend(self,json);
		}).then(function(){
			self.load_nav();

			// Set up dynamicly generated css
			var newcss = Object.keys(self.yearmonthindex).map(function(d){ return d.replace(/(....)-(..)/,'.y$1.m$2~.y$1.m$2:after');	}).join(',') + '{display:none;}';

			jQuery('body').append('<style>' + newcss + '</style>');

			self.normalize_view();

			var photoswidth = jQuery('.photos')[0].offsetWidth - jQuery('.photos')[0].clientWidth;
			jQuery('.photos').css('width','calc(100% + ' + photoswidth + ')');
			jQuery('.photos').focus();
		});

		jQuery(document).ready(this.docReady);
		jQuery('.slider').on('change',this.sliderChange.bind(this));
		jQuery('.photos').on('scroll',this.debounce_scroll.bind(this));
		jQuery('.photos').on('click','.tn',this.handleThumbClick.bind(this));
		jQuery('#thumbright').on('click',this.handleThumbNext.bind(this));
		jQuery('#thumbleft').on('click',this.handleThumbPrev.bind(this));
		jQuery('.scroller').on('mouseup','.nav',this.navClick.bind(this));
		jQuery('#thumbclose').on('click',this.hideThumb.bind(this));
		jQuery(document).on('keydown',this.keydownHandler.bind(this));
		/*
			jQuery('.photos').hammer({
				recognizers: [
					// RecognizerClass, [options], [recognizeWith, ...], [requireFailure, ...]
					[Hammer.Pinch, { enable: true }]
					// ,[Hammer.Swipe,{ direction: Hammer.DIRECTION_HORIZONTAL }]
				]
			}).on('pinchout pinchin', this.pinchhandler.bind(this));
		*/
	}

	pinchhandler(e) {
		this.pinchhandler.debounce = this.pinchhandler.debounce || 300;
		var last_ts = this.pinchhandler.last_ts || 0;
		this.pinchhandler.last_ts = e.timeStamp;

		if ( e.timeStamp - last_ts <  this.pinchhandler.debounce ) {
			console.log("Too soon. Skipping this pinch");
			return;
		}

		var mr = jQuery('#myRange');
		var cur = mr.val();
		if ( e.type == 'pinchout' ) {
			console.log("Using " + e.type + " with staritng value of " + cur + ". New val should be " + Math.max(cur-1,1) + "(pinchout)");
			mr.val(Math.max(cur - 1,1)).trigger('change');
		} else if ( e.type == 'pinchin' ) {
			console.log("Using " + e.type + " with staritng value of " + cur + ". New val should be " + Math.min(cur+1,10) + "(pinchin)");
			mr.val(Math.min(cur + 1,10)).trigger('change');
		}

		this.pinchhandler.debounce = 1;
		this.pinchhandler.last_ts = this.pinchhandler.last_ts + this.pinchhandler.debounce;
	}

	// Append as many photos as needed to meet the page size

	load_nav() {
		var keys = Object.keys(fastback.yearmonthindex).sort().reverse();
		var html = '<div class="nav" data-year="onthisdate"><div class="year">Today</div></div>';

		var y;
		var m;
		var lastyear = "";
		var months = {'01':'Jan','02':'Feb','03':'Mar','04':'Apr','05':'May','06':'Jun','07':'Jul','08':'Aug','09':'Sep','10':'Oct','11':'Nov','12':'Dec'};
		for(var k=0;k<keys.length;k++){
			y = keys[k].substr(0,4);
			m = keys[k].substr(5,2);

			if ( y !== lastyear ) {
				if ( k !== 0 ) {
					html += '</div></div>';
				}

				html += '<div class="nav" data-year="' + y + '"><div class="year">' + y + '</div>';
			}

			// html += '<div class="month" data-month="' + keys[k] + '">' + months[m] + '</div>';

			lastyear = y;
		}

		html += '</div></div>';

		jQuery('.scroller').append(html);
	}

	gotodate(date){
		// Find the first date for the year indicated
		var idx = this.yearmonthindex[Object.keys(this.yearmonthindex).filter(function(k){return k.substr(0,4)==date;}).sort().reverse()[0]];
		this.normalize_view(idx);
		this.showNotification(date,5000);
	}

	/**
	* Find the first photo which is visible, ie. which has a top > 0
	*/
	binary_search_find_visible(){

		if ( this.curthumbs.length === 0 ) {
			return false;
		}

		var min = 0; // parseInt(this.curthumbs.first().attr('id').replace('photo-',''));
		var max = this.curthumbs.length - 1; // parseInt(this.curthumbs.last().attr('id').replace('photo-',''));

		var mid;
		var maxloops = 20;

		var old;

		while(min != max && maxloops > 0 && document.readyState === 'complete') {
			// Only check the first item in rows
			mid = Math.floor(((max + min)/2)/this.rowwidth) * this.rowwidth;

			if ( (min + ',' + mid + ',' + max) == old ) {
				// We're getting stuck in some conditions, not sure where yet
				console.log("Stuck in binary_search_find_visible");
			}
			old = min + ',' + mid + ',' + max;

			if (mid == min) {
				if (jQuery(this.curthumbs[mid]).offset().top >= 0) {
					break;
				} else {
					// If we're off page and equal, jump forward one row
					min += this.rowwidth;
					mid += this.rowwidth;
				}
			} else if (mid < min) {
				mid = min;
			}

			// Allow for a little bit of slop since the scroll stop seems to be a bit fuzzy
			if (jQuery(this.curthumbs[mid]).offset().top >= 0) {
				// Go left
				max = mid;
			} else {
				// Go right
				min = mid;
			}

			maxloops--;
		}

		var v = jQuery(this.curthumbs[min]).attr('id').replace('p','');
		if ( typeof v.offset === 'function' ) {
			console.log("Undefined offset");
		}
		return v;
	}

	hideThumb() {
		jQuery('#thumb').hide();
		jQuery('#thumbcontent').html("");
		jQuery('#thumbcontrols').html("");
	}

	showNotification(html,timer){
		jQuery('#notification').html(html).addClass('new');

		if ( this.notificationtimer !== undefined ) {
			clearTimeout(this.notificationtimer);
		}

		if (timer !== undefined ) {
			this.notificationtimer = setTimeout(function(){
				jQuery('#notification').removeClass('new');
			},timer);
		}
	}

	handleNewCurrentPhoto(){

		if ( (new Date().getTime() - this.scrolltimer) < 1000 ) {
			setTimeout(this.handleNewCurrentPhoto.bind(this),200);
			return;
		}
		this.scrolltimer = new Date().getTime();

		var idx = this.binary_search_find_visible();
		// window.location.hash = '#p' + idx;
		var cur = jQuery('#p' + idx);
		var year = cur.data('d').substr(0,4);
		var activeyear = jQuery('.nav.active');
		if(activeyear.length > 0 && activeyear.first().data('year') != year){
			activeyear.removeClass('active');
		}
		jQuery('.nav[data-year=' + year + ']').addClass('active');
	}

	handleThumbClick(e){
		var divwrap = jQuery(e.target).closest('div.tn');
		var img = divwrap.find('img');

		var imghtml;
		if (divwrap.hasClass('vid')){
			imghtml = '<video controls><source src="' + fastback.originurl + img.attr('src').replace(/.jpg$/,'') + '">Your browser does not support this video format.</video>';
		} else {
			imghtml = '<img src="' + fastback.originurl + img.attr('src').replace(/.jpg$/,'') +'"/>';
		}


		var ctrlhtml = '<h2>' + (divwrap.data('d') + '') + '</h2>';
		ctrlhtml += '<p><a class="download" href="' + fastback.originurl + img.attr('src').replace(/.jpg$/,'') + '" download>' + img.attr('alt') + '</a>';
		ctrlhtml += '<br>';
		ctrlhtml += '<a class="flag" onclick="return fastback.sendbyajax(this)" href=\"' + fastback.originurl + 'fastback.php?flag=' + encodeURIComponent('./' + img.attr('src').replace(/.jpg$/,'')) + '\">Flag Image</a>';
		ctrlhtml += '</p>';
		jQuery('#thumbcontent').html(imghtml);
		jQuery('#thumbcontrols').html(ctrlhtml);
		jQuery('#thumb').data('curphoto',divwrap);
		jQuery('#thumb').show();
	}

	_hanleThumbMove(prev_next) {
		var t = jQuery('#thumb');

		if(!t.is(':visible')){
			return;
		}

		var p = jQuery('#photos');
		var cur = t.data('curphoto');
		var newp;

		if ( prev_next == 'prev' ) {
			newp = cur.prev();	
		} else {
			newp = cur.next();
		}

		if ( newp.length === 0 ) {
			return;
		}

		var vert = newp.position().top;

		if ( vert < 0 || vert > p.height() ) {
			p.animate({
				scrollTop: p.scrollTop() + vert
			}, 2000);
		}

		newp.trigger('click');
	}

	handleThumbNext(){
		this._hanleThumbMove('next');
	}

	handleThumbPrev(){
		this._hanleThumbMove('prev');
	}

	sliderChange(e){
		fastback.rowwidth = e.target.value;
		var curwidthpercent = 100/fastback.rowwidth

		document.styleSheets[0].insertRule('.photos .tn{ width: ' + curwidthpercent + 'vw; height: ' + curwidthpercent + 'vw; }', document.styleSheets[0].cssRules.length);
		fastback.normalize_view();

		var firstoffset = fastback.curthumbs.first().offset().top;
		for(var i = 1;i<fastback.curthumbs.length;i++){
			if (fastback.curthumbs.eq(i).offset().top !== firstoffset){
				fastback.rowwidth = i;
				break;
			}
		}
	}

	navClick(e) {
		var year = jQuery(e.target).closest('.nav').data('year');
		jQuery('#photos').fadeOut(500);

		if ( parseInt(year) == year ) {
			if ( this.disablehandlers ) {
				this.disablehandlers = false;
				jQuery('#photos').html("");
				this.curthumbs = [];
			}
			this.gotodate(year);
		} else if (year == 'onthisdate') {
			this.disablehandlers = true;
			this.getYearPhotos();
		}
		jQuery('#photos').fadeIn(500);
	}

	sendbyajax(link) {
		var thelink = link;
		jQuery.get(thelink.href).then(function(){
			jQuery(thelink).hide();
		});
		return false;
	}

	/*
	* Figure out how many photos are visible rightnow
	*/
	photos_on_screen() {
		// Start with the expected
		var cols = this.rowwidth;

		var nextvisible = this.binary_search_find_visible();
		// But check reality if we have photos loaded
		if ( nextvisible !== false ) {
			nextvisible = jQuery('#p' + nextvisible);

			if ( nextvisible.length === 0 ) {
				// probably scrolling quickly and picked up on a photo that was just about to be removed
				return false;
			}

			var offset = nextvisible.offset().top;
			var cols = 1;
			while ( nextvisible.next().offset().top == offset ) {
				cols++;
				nextvisible = nextvisible.next();
			}
		}

		var rows = Math.ceil(window.innerHeight / (window.innerWidth / this.rowwidth));
		var count = rows * cols;
		return count;
	}

	debounce_scroll(e) {

		if (this.disablehandlers) {
			return;
		}

		if ( e.timeStamp - this.last_scroll_timestamp > this.scroll_time ) {
			this.normalize_view();
		}

		this.last_scroll_timestamp = e.timeStamp;
	}

	/**
	* For a given position, normalize the photos around it. 
	*
	* At the end of the funciton we should have
	*
	* max(this.minphotos,this.minpages*this.photos_on_screen()) photos loaded with the current screen in the middle of this.minpages
	*/
	normalize_view(starting_with) {

		// Don't run multiple at once
		if (this.normalizing === true) {
			return;
		}

		this.normalizing = true;

		if ( starting_with instanceof jQuery.Event ) {
			starting_with = undefined;
		}

		/**
		* Use the specified index
		* OR
		* the first visible
		* OR 
		* 0
		*/
		var first_visible = this.binary_search_find_visible();

		// How many photos are we needing?
		var mid_chunk = this.photos_on_screen();
		if ( mid_chunk === false ) {
			// couldn't get a midchunk, let's bounce.
			this.normalizing = false;
			return false;
		}
		var total_photocount = Math.max(this.minphotos,mid_chunk*this.minpages);

		var orig_anchor = first_visible || 0;

		// Can't || this in there since 0 == false
		if ( parseInt(starting_with) > -1 ) {
			orig_anchor = parseInt(starting_with);
		}

		// Move anchor to the start of the row
		var anchor = Math.floor(orig_anchor / this.rowwidth) * this.rowwidth;

		// Same as last time, bail
		if ( 
			this.last_scroll_factors !== undefined && 
			this.last_scroll_factors.first_visible == first_visible && 
			this.last_scroll_factors.total_photocount == total_photocount && 
			this.last_scroll_factors.mid_chunk == mid_chunk &&
			this.last_scroll_factors.anchor == anchor
		) {
			this.normalizing = false;
			return;
		}

		if ( 
			this.last_scroll_factors !== undefined && 
			Math.abs(this.last_scroll_factors.anchor - first_visible) < mid_chunk 
		) {
			// console.log("Not scrolling till we get at least a page to load");
			// return;
		}

		// Get 1/3, then find the number of rows to add before. 
		var before = Math.floor(Math.ceil(total_photocount / 3) / this.rowwidth) * this.rowwidth;
		var after = before * 2;

		var newmin = anchor - before; 

		if ( newmin < 0 ) {
			after += Math.abs(newmin);
			newmin = 0;
		}

		var newmax = Math.floor((anchor + after) / this.rowwidth) * this.rowwidth - 1;

		if ( newmax >= this.tags.length ) {
			var tmpmax = Math.floor(this.tags.length / this.rowwidth) * this.rowwidth - 1;
			newmin -= (newmax - tmpmax);
			newmax = tmpmax;

			if ( newmin < 0 ) {
				newmin = 0; // too few photos? I guess.
			}
		}

		// Now figure out if we have any to prepend or append.

		var prepend = jQuery();
		var remove_from_end = jQuery();
		var append = jQuery();
		var remove_from_start = jQuery();
		var curmin = -Infinity;
		var curmax = -Infinity;
		var movement;
		if ( this.curthumbs.length !== 0) {
			curmin = parseInt(this.curthumbs.first().attr('id').replace('p',''));
			curmax = parseInt(this.curthumbs.last().attr('id').replace('p',''));
		}

		/*
		* What to add!
		*/
		// No overlap, new is left - Prepend
		if ( newmax < curmin) {
			// put before 
			prepend = this.tags.slice(newmin,newmax + 1);
		} else 
			// Some overlap, new is slightly left - Prepend
		if ( newmin < curmin ) {
			prepend = this.tags.slice(newmin, curmin);	
		}


		// No overlap, new is right - Append
		if ( newmin > curmax ) {
			// put before 
			append = this.tags.slice(newmin,newmax + 1);
		} else 
			// Some overlap, new is slightly right - Append
		if ( newmax > curmax ) {
			append = this.tags.slice(curmax + 1,newmax + 1);
		}

		/*
		* What to remove!
		*/

		// No overlap, new is left - Delete all and refresh
		if ( newmax < curmin ) {
			remove_from_end = jQuery('.photos .tn');
			movement = 'reload';
		} else 
			// Some overlap, new is slightly left - Delete some and slide
		if ( curmax > newmax ) {
			remove_from_end = jQuery('#p' + newmax).nextAll();
			movement = 'slide';
		}

		// No overlap, new is right - Delete all and refresh
		if ( curmax < newmin ) {
			remove_from_start = jQuery('.photos .tn');
			movement = 'reload';
		} else 
			// Some overlap, new is slightly right - Delete some and slide
		if ( curmin < newmin ) {
			remove_from_start = jQuery('#p' + newmin).prevAll();
			movement = 'slide';
		}

		this.last_scroll_factors = {
			"-start": remove_from_start.length + ' (' + (remove_from_start.length % this.rowwidth) + ')',
			"+start": prepend.length  + ' (' + (prepend.length % this.rowwidth) + ')',
			"+end": append.length + ' (' + (append.length % this.rowwidth) + ')',
			"-end": remove_from_end.length + ' (' + (remove_from_end.length % this.rowwidth) + ')',
			"movement": movement,
			'first_visible': first_visible,
			'total_photocount': total_photocount,
			'mid_chunk': mid_chunk,
			'anchor': anchor,
			'curmin': curmin,
			'curmax': curmax,
			'newmin': newmin,
			'newmax': newmax
		};

		console.log(this.last_scroll_factors);

		if ( movement === 'reload' ) {
			jQuery('.photos').fadeOut(500);
		}

		remove_from_start.remove();
		remove_from_end.remove();
		jQuery('#photos').prepend(prepend);
		jQuery('#photos').append(append);

		// if ( movement === 'reload' || (movement === 'slide' && starting_with !== undefined ) ) {
		// 	window.location.hash = '#p' + anchor;
		// }	

		jQuery('.photos').fadeIn(500);

		this.curthumbs = jQuery('.photos .tn');

		this.normalizing = false;
	}

	getYearPhotos() {
		var re = new RegExp( ' data-d=....-' + ("0" + (new Date().getMonth() + 1)).slice(-2) + '-' + ("0" + new Date().getDate()).slice(-2) + ' ');
		var found = fastback.tags.filter(function(e){return e.match(re);}).join("");
		jQuery('#photos').html(found);
		this.curthumbs = jQuery('.photos .tn');
	}

	keydownHandler(e) {
		switch(e.key){
			case 'Escape':
			this.hideThumb();
			break;
			case 'ArrowRight':
			this.handleThumbNext();
			break;
			case 'ArrowLeft':
			this.handleThumbPrev();
			break;
		}
	}
}

var fastback = new Fastback();
