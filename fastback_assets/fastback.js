class Fastback {
	// built-in properties
	pagesize = 300;

	minphotos = 500;
	minpages = 5;

	prevfirstvisible = 0;
	urltimer = 0;
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

			self.normalize_view();

			var photoswidth = jQuery('.photos')[0].offsetWidth - jQuery('.photos')[0].clientWidth;
			jQuery('.photos').css('width','calc(100% + ' + photoswidth + ')');
			jQuery('.photos').focus();
		});

		jQuery(document).ready(this.docReady);
		jQuery('.slider').on('change',this.sliderChange.bind(this));
		jQuery('.photos').on('scroll',this.debounce_scroll.bind(this));
		jQuery('.photos').on('click','.thumbnail',this.handleThumbClick.bind(this));
		jQuery('.scroller').on('mouseup','.nav',this.navClick.bind(this));
		jQuery('#thumbclose').on('click',this.hideThumb.bind(this));
		jQuery(document).on('keyup',this.keyupHandler.bind(this));
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

		return jQuery(this.curthumbs[min]).attr('id').replace('photo-','');
	}

	showThumb(imghtml,notificationhtml) {
		jQuery('#thumbcontent').html(imghtml);
		jQuery('#thumbcontrols').html(notificationhtml);
		jQuery('#thumb').show();
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
		// window.location.hash = '#photo-' + idx;
		var cur = jQuery('#photo-' + idx);
		var year = cur.data('date').substr(0,4);
		var activeyear = jQuery('.nav.active');
		if(activeyear.length > 0 && activeyear.first().data('year') != year){
			activeyear.removeClass('active');
		}
		jQuery('.nav[data-year=' + year + ']').addClass('active');
	}

	handleThumbClick(e){
		var divwrap = jQuery(e.target).closest('div.thumbnail');
		var img = divwrap.find('img');

		var imghtml;
		if (divwrap.data('isvideo') == 1) {
			imghtml = '<video controls><source src="' + fastback.originurl + img.attr('src').replace(/.jpg$/,'') + '">Your browser does not support this video format.</video>';
		} else {
			imghtml = '<img src="' + fastback.originurl + img.attr('src').replace(/.jpg$/,'') +'"/>';
		}


		var ctrlhtml = '<h2>' + divwrap.data('date') + '</h2>';
		ctrlhtml += '<p><a class="download" href="' + fastback.originurl + img.attr('src').replace(/.jpg$/,'') + '" download>' + img.attr('alt') + '</a>';
		ctrlhtml += '<br>';
		ctrlhtml += '<a class="flag" onclick="return fastback.sendbyajax(this)" href=\"' + fastback.originurl + 'fastback.php?flag=' + encodeURIComponent('./' + img.attr('src').replace(/.jpg$/,'')) + '\">Flag Image</a>';
		ctrlhtml += '</p>';
		this.showThumb(imghtml,ctrlhtml);
	}

	sliderChange(e){
		var rowwidth = e.target.value;
		var curwidthpercent = 100/rowwidth
		this.pagesize = Math.pow(rowwidth,2) * 2 * this.minpages; //(square * 2 for height, load 3 screens worth at a time)
		var leftover = this.pagesize % rowwidth;
		if ( leftover !== 0 ) {
			this.pagesize += (rowwidth - leftover);
		}

		document.styleSheets[0].insertRule('.photos .thumbnail { width: ' + curwidthpercent + 'vw; height: ' + curwidthpercent + 'vw; }', document.styleSheets[0].cssRules.length);
		this.appendphotos();

		var firstoffset = this.curthumbs.first().offset().top;
		for(var i = 1;i<this.curthumbs.length;i++){
			if (this.curthumbs.eq(i).offset().top !== firstoffset){
				this.rowwidth = i;
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
			nextvisible = jQuery('#photo-' + nextvisible);
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
			tmpmax = Math.floor(this.tags.length / this.rowwidth) * this.rowwidth - 1;
			newmin -= (newmax - tmpmax);
			newmax = tmpmax;

			if ( newmin < 0 ) {
				newmin = 0; // too few photos? I guess.
			}
		}

		// Now figure out if we have any to prepend or append.

		var prepend;
		var remove_from_end;
		var append;
		var remove_from_start;
		var curmin = -Infinity;
		var curmax = -Infinity;
		var movement;
		if ( this.curthumbs.length !== 0){
			curmin = parseInt(this.curthumbs.first().attr('id').replace('photo-',''));
			curmax = parseInt(this.curthumbs.last().attr('id').replace('photo-',''));
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
			remove_from_end = jQuery('.photos .thumbnail');
			movement = 'reload';
		} else 
		// Some overlap, new is slightly left - Delete some and slide
		if ( curmax > newmax ) {
			remove_from_end = jQuery('#photo-' + newmax).nextAll();
			movement = 'slide';
		}

		// No overlap, new is right - Delete all and refresh
		if ( curmax < newmin ) {
			remove_from_start = jQuery('.photos .thumbnail');
			movement = 'reload';
		} else 
		// Some overlap, new is slightly right - Delete some and slide
		if ( curmin < newmin ) {
			remove_from_start = jQuery('#photo-' + newmin).prevAll();
			movement = 'slide';
		}

		this.last_scroll_factors = {
			"-start": ( remove_from_start === undefined ? 0 : remove_from_start.length ),
			"+start": ( prepend === undefined ? 0 : prepend.length ),
			"+end": (append === undefined ? 0 : append.length ),
			"-end": ( remove_from_end === undefined ? 0 : remove_from_end.length ),
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

		if ( remove_from_start !== undefined ) {
			// removing from start needs to adjust height of spacer
			remove_from_start.remove();
		}

		if ( remove_from_end !== undefined ) {
			remove_from_end.remove();
		}

		if ( prepend !== undefined ) {
			// appending to start needs to adjust height of spacer
			jQuery('#photos').prepend(prepend);
		}

		if ( append !== undefined ) {
			jQuery('#photos').append(append);
		}

		if ( movement === 'reload' || (movement === 'slide' && starting_with !== undefined ) ) {
			window.location.hash = '#photo-' + anchor;
		}	

		jQuery('.photos').fadeIn(500);

		this.curthumbs = jQuery('.photos .thumbnail');

		this.normalizing = false;
	}

	getYearPhotos() {
		var re = new RegExp( ' data-date="....-' + ("0" + (new Date().getMonth() + 1)).slice(-2) + '-' + ("0" + new Date().getDate()).slice(-2) + '" ');
		var found = fastback.tags.filter(function(e){return e.match(re);}).join("");
		jQuery('#photos').html(found);
		this.curthumbs = jQuery('.photos .thumbnail');
	}

	keyupHandler(e) {
	     if (e.key === "Escape") { 
			this.hideThumb();
		}
	}
}

var fastback = new Fastback();
