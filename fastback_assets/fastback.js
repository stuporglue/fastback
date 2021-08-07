class Fastback {

	setProps() {

		/**
		* Note: any property can be set in the constructor
		*/

		// built-in properties
		this.minphotos = 300;
		this.minpages = 5;

		this.notificationtimer = undefined;
		this.curthumbs = [];

		this.disablehandlers = false;

		this.rowwidth = 5;

		this.debug = false;

		this.limitdates = true;

		// Properties required in constructor
		this.cacheurl = undefined;
		this.photourl = undefined;
		this.staticurl = undefined;
		this.fastbackurl = undefined;

		// Properties loaded from json
		this.years = undefined;
		this.yearsindex = undefined;
		this.tags = undefined;

		this.last_scroll_factors = undefined;
		this.last_scroll_timestamp = 0;
		this.scroll_time = 100;

		// Browser type
		this.isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

		// Browser supported file types - will be shown directly. 
		// Anything not in this list will be proxied into a jpg
		this.browser_supported_file_types = [ 
			// videos
			'mp4','m4v', 'ogg', 'mov',
			// images
		'jpg','jpeg','gif','png' ];
	}

	constructor(args) {
		this.setProps();
		var self = this;

		jQuery.extend(this,args);

		if ( this.debug ) {
			console.log("Fastback initialized with:");
			console.log(args);
		}

		if ( this.isSafari ) {
			this.browser_supported_file_types.push('mov');
			this.browser_supported_file_types.push('mpg');
		}

		$.getJSON(this.fastbackurl + '?get=photojson' + ( this.debug ? '&debug=true' : ''), function(json) {
			jQuery.extend(self,json);
		}).then(function(){
			self.load_nav();

			self.normalize_view();

			var photoswidth = jQuery('#photos')[0].offsetWidth - jQuery('#photos')[0].clientWidth;
			jQuery('#photos').focus();

			self.addListeners();
		});
	}

	addListeners() {
		// Click and change handlers
		jQuery('#zoom').on('change',this.zoomChange.bind(this));
		jQuery('#photos').on('click','.tn',this.handleThumbClick.bind(this));
		jQuery('#thumbright').on('click',this.handleThumbNext.bind(this));
		jQuery('#thumbleft').on('click',this.handleThumbPrev.bind(this));
		jQuery('#thumbclose').on('click',this.hideThumb.bind(this));

		// Key presses
		jQuery(document).on('keydown',this.keydownHandler.bind(this));

		// Scrolling
		jQuery('#photos').on('scroll',this.debounce_scroll.bind(this));

		// Touch stuff
		jQuery('#thumb').hammer({recognizers: [ 
			[Hammer.Swipe,{ direction: Hammer.DIRECTION_ALL }],
		]}).on('swiperight swipeup swipeleft', this.handleThumbSwipe.bind(this));

		//https://stackoverflow.com/questions/11183174/simplest-way-to-detect-a-pinch/11183333#11183333
		if ( !this.isSafari) {
			jQuery('#photos').on({
				touchstart: this.handlePhotoPinch.bind(this),
				touchmove: this.handlePhotoPinch.bind(this),
				touchend: this.handlePhotoPinch.bind(this)
			});
		}

		jQuery('#thumb').on({
			touchstart: this.handleThumbSwipe.bind(this),
			touchmove: this.handleThumbSwipe.bind(this),
			touchend: this.handleThumbSwipe.bind(this)
		});
	}

	handleThumbSwipe(e) {

		/*
		// https://stackoverflow.com/questions/2264072/detect-a-finger-swipe-through-javascript-on-the-iphone-and-android

		var xDown = null;                                                        
		var yDown = null;

		function getTouches(evt) {
		return evt.touches ||             // browser API
		evt.originalEvent.touches; // jQuery
		}                                                     

		function handleTouchStart(evt) {
		const firstTouch = getTouches(evt)[0];                                      
		xDown = firstTouch.clientX;                                      
		yDown = firstTouch.clientY;                                      
		};                                                

		function handleTouchMove(evt) {
		if ( ! xDown || ! yDown ) {
		return;
		}

		var xUp = evt.touches[0].clientX;                                    
		var yUp = evt.touches[0].clientY;

		var xDiff = xDown - xUp;
		var yDiff = yDown - yUp;

		if ( Math.abs( xDiff ) > Math.abs( yDiff ) ) {/*most significant
		if ( xDiff > 0 ) {
		//* right swipe 
		} else {
		// /* left swipe 
		}                       
		} else {
		if ( yDiff > 0 ) {
		// /* down swipe 
		} else { 
		// /* up swipe 
		}                                                                 
		}
		xDown = null;
		yDown = null;                                             
		};
		*/

		if ( e.type == 'swiperight' ) {
			this.handleThumbNext();
		} else if ( e.type == 'swipeleft' ) {
			this.handleThumbPrev();
		} else if ( e.type == 'swipeup' ) {
			this.hideThumb();
		}
	}

	handlePhotoPinch(e){

		if ( e.touches.length !== 2 ) {
			return;
		}

		var dist = Math.hypot(e.touches[0].pageX - e.touches[1].pageX, e.touches[0].pageY - e.touches[1].pageY);

		if ( e.type == 'touchstart') {

			// Create the pinch object and set initial distance
			this.handlePhotoPinch.pinch = {
				origZoom: this.rowwidth,
				origDist: dist,
				curDist: dist,
				distChange: 0
			};
		} else if ( e.type == 'touchmove' ) {

			this.handlePhotoPinch.pinch.curDist = dist;
			this.handlePhotoPinch.pinch.distChange = this.handlePhotoPinch.pinch.curDist / this.handlePhotoPinch.pinch.origDist;

			var slots;

			if ( this.handlePhotoPinch.pinch.distChange > 1 ) {
				slots = Math.floor(this.handlePhotoPinch.pinch.distChange);
				if ( this.debug ) {
					console.log("Zooming in " + slots + " slots");
				}
				this.rowwidth = this.handlePhotoPinch.pinch.origZoom - slots;
				if ( this.rowwidth < 1 ) {
					this.rowwidth = 1;
				}
			} else {
				slots = Math.floor(1/this.handlePhotoPinch.pinch.distChange);
				if ( this.debug ) {
					console.log("Zooming out " + slots + " slots");
				}
				this.rowwidth = this.handlePhotoPinch.pinch.origZoom + slots;
				if ( this.rowwidth > 10 ) {
					this.rowwidth = 10;
				}
			}

			this.zoomSizeChange(this.rowwidth);

			var center = {
				x: (e.touches[0].pageX + e.touches[1].pageX) / 2,
				y: (e.touches[0].pageY + e.touches[1].pageY) / 2
			};

			var focus = jQuery(document.elementFromPoint(center.x,center.y)).closest('div.tn').attr('id').replace('p','');
			if ( parseInt(focus) == focus) {
				this.normalize_view(focus);
			}
		} else if ( e.type == 'touchend' ) {
			this.zoomFinalizeSizeChange();
			this.handlePhotoPinch.pinch = undefined;
		}

		if ( isNaN(parseInt(this.rowwidth))) {
			if ( this.debug ) {
				console.log("Something happend in pinch and my rowwidth is NaN. Resetting to 5"); 
			}
			this.rowwidth = 5;
			this.normalize_view();
		}
	}

	// Append as many photos as needed to meet the page size

	load_nav() {
		// first date (in tags list) -- The default is Descending view, so this should be the greatest date
		var fd = fastback.tags[0].match(/data-d="([^"]+)/)[1].replace(/ .*/,'');

		// last date (in tags list)
		var ld = fastback.tags[fastback.tags.length - 1].match(/data-d="([^"]+)/)[1].replace(/ .*/,'');

		// If fd is not the greatest date, swap 'em
		if ( fd > ld ) {
			[fd,ld] = [ld,fd];
		}

		jQuery('#datepicker').datepicker({
			minDate: new Date(fd + new Date().toISOString().replace(/.*T/,'T')),
			maxDate: new Date(ld + new Date().toISOString().replace(/.*T/,'T')),
			changeYear: true,
			changeMonth: true, 
			yearRange: 'c-100:c+100',
			onSelect: this.gotodate.bind(this)
		});

		jQuery('#rewindicon').on('click',this.onthisdate.bind(this));
	}

	onthisdate() {
		jQuery('#photos').fadeOut(500);
		jQuery('#rewindicon').addClass('active');
		this.disablehandlers = true;
		this.last_scroll_factors = undefined;
		this.getYearPhotos();
		jQuery('#photos').fadeIn(500);
	}

	/**
	* Go to the photo closest to a specified date
	*/
	gotodate(date){
		jQuery('#rewindicon').removeClass('active');
		jQuery('#photos').fadeOut(500);

		if ( this.disablehandlers ) {
			this.disablehandlers = false;
			jQuery('#photos').html("");
			this.curthumbs = [];
		}

		console.log("Going to " + date);
		var targetdate = date.replace(/(..)\/(..)\/(....)/,"$3-$1-$2");

		// There's no guarantee that the requested date will have photos.
		// normalize_view arround either:
		//	1) The fist item with a matching date OR
		//	2) If we are in Ascending Date order, the last item with a date before the requested OR
		//	3) If we are in Descending DAte order, the last item with a date after the requested

		var first = 0;
		var last = fastback.tags.length - 1;

		var datere = new RegExp('data-d="([^ "]+)');

		// first date (in tags list)
		var fd = fastback.tags[first].match(datere)[1];

		// last date (in tags list)
		var ld = fastback.tags[last].match(datere)[1];

		var direction = 'desc';
		if ( fd < ld ) {
			direction = 'asc';
		}

		var foundidx;
		var curdate;
		var i = 0;
		for (i = 0;i < fastback.tags.length;i++){
			curdate = fastback.tags[i].match(datere)[1];
			if ( curdate === targetdate ) {
				break;
			} else if ( 
				(direction === 'desc' && curdate < targetdate )  ||
				( direction === 'asc' && curdate > targetdate ) 
			) {
				// too far!
				if ( i > 0 ) {
					i = i - 1;
					break;
				} else {
					i = 0;
					break;
				}
			}
		}

		this.normalize_view(i);
		this.showNotification(curdate,5000);
		jQuery('#photos').fadeIn(500);
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
				if ( this.debug ) {
					console.log("Stuck in binary_search_find_visible");
				}
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
			if (jQuery(this.curthumbs[mid]).length === 0 && this.debug) {
				console.log("Mid has no offset and is " + mid);	
			}
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
		if ( typeof v.offset === 'function' && this.debug ) {
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
		jQuery('#notification').html(html).show(500).addClass('new');

		if ( this.notificationtimer !== undefined ) {
			clearTimeout(this.notificationtimer);
		}

		if (timer !== undefined ) {
			this.notificationtimer = setTimeout(function(){
				jQuery('#notification').removeClass('new').hide(500);
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
		var fullsize = this.photourl + img.attr('src').replace(/.jpg$/,'');

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

		var ctrlhtml = '<h2>' + (divwrap.data('d') + '') + '</h2>';
		ctrlhtml += '<p><a class="download" href="' + this.photourl + img.attr('src').replace(/.jpg$/,'') + '" download>' + img.attr('alt') + '</a>';
		ctrlhtml += '<br>';
		ctrlhtml += '<a class="flag" onclick="return fastback.sendbyajax(this)" href=\"' + this.fastbackurl + '?flag=' + encodeURIComponent('./' + img.attr('src').replace(/.jpg$/,'')) + '\">Flag Image</a>';
		ctrlhtml += '</p>';
		jQuery('#thumbcontent').html(imghtml);
		jQuery('#thumbcontrols').html(ctrlhtml);
		jQuery('#thumb').data('curphoto',divwrap);
		jQuery('#thumb').show();
	}

	_handleThumbMove(prev_next) {
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
		this._handleThumbMove('next');
	}

	handleThumbPrev(){
		this._handleThumbMove('prev');
	}

	zoomSizeChange(newSize){
		this.rowwidth = newSize;

		jQuery('#photos').addClass('up' + this.rowwidth);
		var removeme = jQuery('#photos').attr('class').split(' ').filter(function(e){return e.match(/^up[0-9]/);});

		var dontremove = removeme.indexOf('up' + this.rowwidth);
		removeme.splice(dontremove,1);
		removeme.forEach(function(c){jQuery('#photos').removeClass(c);});
	}

	zoomFinalizeSizeChange() {
		this.normalize_view();

		var firstoffset = this.curthumbs.first().offset().top;
		for(var i = 1;i<this.curthumbs.length;i++){
			if (this.curthumbs.eq(i).offset().top !== firstoffset){
				this.rowwidth = i;
				break;
			}
		}
	}

	zoomChange(e){
		this.zoomSizeChange(e.target.value);
		this.zoomFinalizeSizeChange();
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
			cols = 1;
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

		// When showing all photos on today's date, don't do the scroll handlers
		if (this.disablehandlers) {
			return;
		}

		if ( e.timeStamp - this.last_scroll_timestamp < this.scroll_time ) {
			return;
		}

		this.normalize_view();
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

		// At this point, anchor is the thing to use, not first_visible

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
			Math.abs(this.last_scroll_factors.anchor - anchor) < mid_chunk 
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
			remove_from_end = jQuery('#photos .tn');
			movement = 'reload';
		} else 
			// Some overlap, new is slightly left - Delete some and slide
		if ( curmax > newmax ) {
			remove_from_end = jQuery('#p' + newmax).nextAll();
			movement = 'slide';
		}

		// No overlap, new is right - Delete all and refresh
		if ( curmax < newmin ) {
			remove_from_start = jQuery('#photos .tn');
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

		if ( this.debug ) {
			console.log(this.last_scroll_factors);
		}

		if ( movement === 'reload' ) {
			jQuery('#photos').fadeOut(500);
		}

		/*
		* Safari is the new IE. It always needs special treatment and I hate dealing with it.
		* https://stackoverflow.com/questions/9834143/jquery-keep-window-from-changing-scroll-position-while-prepending-items-to-a-l
		*/
		// Get the current scroll top
		var first_visible_photo = jQuery('#p' + anchor);
		var curOffset;
		if ( first_visible_photo.length > 0){
			curOffset = first_visible_photo.offset().top - $('#photos').scrollTop();
		}

		remove_from_start.remove();
		remove_from_end.remove();
		jQuery('#photos').prepend(prepend);
		jQuery('#photos').append(append);

		// Set the new scroll top
		if ( first_visible_photo.length > 0 && starting_with !== undefined ) {
			$('#photos').scrollTop(first_visible_photo.offset().top - curOffset);
		}

		if ( starting_with !== undefined ) {
			jQuery('#photos').scrollTop(jQuery('#photos').scrollTop() + jQuery('#p' + anchor).offset().top);
		}

		jQuery('#photos').fadeIn(500);

		this.curthumbs = jQuery('#photos .tn');

		this.normalizing = false;
	}

	getYearPhotos() {
		var re = new RegExp( ' data-d="....-' + ("0" + (new Date().getMonth() + 1)).slice(-2) + '-' + ("0" + new Date().getDate()).slice(-2) + ' ');
		var found = this.tags.filter(function(e){return e.match(re);}).join("");
		jQuery('#photos').html(found);
		this.curthumbs = jQuery('#photos .tn');
		jQuery('#photos').scrollTop(0);
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
