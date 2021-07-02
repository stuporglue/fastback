class Fastback {
	// built-in properties
	rowwidth = 5;
	pagesize = 300;
	curwidthpercent = 20;
	minphotos = 300;
	minpages = 4; // 1 before, active, 2 after
	prevfirstvisible = 0;
	scrolltimer = 0;
	urltimer = 0;
	notificationtimer;
	curthumbs = [];
	scrollock = false;
	
	// Properties loaded from json
	cachebase;
	index;
	years;
	yearsindex;
	tags;

	constructor() {
		var self = this;

		$.getJSON('fastback.php?get=photojson', function(json) {
			jQuery.extend(self,json);
		}).then(function(){
			self.load_view();
			self.appendphotos();
		});

		jQuery(document).ready(this.docReady);
		jQuery('.slider').on('change',this.sliderChange.bind(this));
		jQuery('.photos').on('scroll',this.handleScrollEnd.bind(this));
		jQuery('.photos').on('click','.thumbnail',this.handleThumbClick.bind(this));
		jQuery('.scroller').on('click','.nav',this.navClick.bind(this));
	}

	// Append as many photos as needed to meet the page size
	appendphotos(addthismany) {
		var	html ='';
		var cursize = this.curthumbs.length;
		var curmax = 0;
		if (cursize > 0) {
			curmax = this.curthumbs.last().data('photoid');
		}

		var photostoadd = addthismany || ( this.pagesize - cursize );

		// TODO: Change to slice to avoid looping
		var endidx = Math.min(photostoadd + curmax,this.tags.length);
		var startidx = curmax + 1;
		html = this.tags.slice(startidx,endidx + 1).join("");
		jQuery('.photos').append(html);

		this.curthumbs = jQuery('.photos img.thumbnail');
	}

	// Prepend as many photos as needed to meet the page size
	prependphotos(addthismany) {
		var	html ='';
		var cursize = this.curthumbs.length;
		var curmin = 0;
		if (cursize > 0) {
			curmin = this.curthumbs.first().data('photoid');
		}

		var photostoadd = addthismany || (this.pagesize - cursize);

		var startidx = Math.max(curmin - photostoadd,0);
		var endidx = curmin - 1;

		html = this.tags.slice(startidx,endidx + 1).join("");
		jQuery('.photos').prepend(html);

		this.curthumbs = jQuery('.photos img.thumbnail');
	}

	handleScrollEnd(){
		if ( !this.scrollock ) {
			this.loadMore();
			this.handleNewCurrentPhoto();
		}
	}

	loadMore(){
		var curfirst = this.binary_search_first_visible();
		var howfar = curfirst - this.prevfirstvisible;

		var howfarscroll = (document.body.clientHeight + jQuery('.photos').scrollTop()) / jQuery('.photos')[0].scrollHeight;

		/*
		if (howfar > prevscrolltop) {
			direction = 'down';
		} else if (howfar < prevscrolltop) {
			direction = 'up';
		}
		if ( howfar >= 0.6 && direction == 'down') {
			page_down();
		}
		if (howfar <= 0.3 && direction == 'up') {
			page_up();
		}
		*/

		if ( howfar > this.rowwidth || howfarscroll > .9) {
			console.log("Page down");
			this.page_down();
		} else if (howfar < this.rowwidth*-1 || howfarscroll < .1) {
			console.log("Page up");
			this.page_up();
		}

		this.prevfirstvisible = curfirst;
	}

	page_down() {
		// Leave 1 page before the first visible, delete everything before that
		var first_visible = this.binary_search_first_visible();
		var first_keep = first_visible - (this.pagesize / this.minpages);
		jQuery('#photo-' + first_keep).prevAll().remove();
		this.curthumbs = jQuery('.photos img.thumbnail');

		// Then append to hit numbers
		this.appendphotos();
	}

	page_up() {
		// Make sure there are 2 pages before first visible
		// delete anything after (minpages - 2 before visible + visible)
		var curmin = this.binary_search_first_visible();
		var newmax = curmin + (this.minpages - 2)*(this.pagesize/this.minpages); 
		jQuery('#photo-' + newmax).nextAll().remove();
		this.curthumbs = jQuery('.photos img.thumbnail');

		// Then prepend to hit numbers
		this.prependphotos();
	}

	load_view() {
		for(var i=0;i<this.years.length;i++){
			jQuery('.scroller').append('<div class="nav" data-year="' + this.years[i] + '"><div class="year">' + this.years[i] + '</div></div>');
		}
	}

	gotodate(date){
		this.scrollock = true;
		var idx = this.yearindex[date];

		// get the first item in the row. Should belong to the same year, probably.
		idx = Math.floor(idx/this.rowwidth) * this.rowwidth;

		var min = this.curthumbs.first().data('photoid');
		var max = this.curthumbs.last().data('photoid');
		var newmin = idx - this.pagesize / this.minpages;
		var newmax = newmin + this.pagesize;
		var toremove;

		if ( newmin > max ) {
			// Only scroling down
			/*
			 *	Old:         [**********************]
			 *	New:                                     [**********************]
			 */
			toremove = this.curthumbs;
			jQuery('.photos').append(this.tags.slice(newmin,newmin+this.rowwidth)); // Should be adding a row
			this.curthumbs = jQuery('.photos img.thumbnail');
			this.appendphotos(this.pagesize);
		} 

		if ( newmax < min ) {
			// Only scrolling up
			/*
			 *	Old:                            [**********************]
			 *	New: [**********************]
			 */
			toremove = this.curthumbs;
			// Got to prepend a row or it shifts
			var onerow = this.tags.slice(newmax-this.rowwidth+1,newmax+1).join("");
			jQuery('.photos').prepend(onerow);
			this.curthumbs = jQuery('.photos img.thumbnail');
			this.prependphotos(this.pagesize);
		}

		if ( newmin > min && newmin < max ) {
			// too many before - cut between old min and new min

			// Overlap with existing
			/*
			 *	Old:         [**********************]
			 *	New:                 [**********************]
			 */
			toremove = jQuery('#photo-' + newmin).prevAll();
			this.prependphotos(idx - newmin);
		} 

		if ( newmax < max && newmax > newmin ) {
			// too many after

			/*
			 *	Old:          [**********************]
			 *	New:   [**********************]
			 */
			// 2*(pagesize/minpages) - ship ahead to two screens
			toremove = jQuery('#photo-' + newmax).nextAll();
			this.appendphotos();
		} 

		jQuery('#photo-' + idx)[0].scrollIntoView();
		toremove.remove();
		this.curthumbs = jQuery('.photos img.thumbnail');
		// window.location.hash = '#photo-' + idx;
		var html = "<h2>" + date + "</h2>";
		this.showNotification(html);

		setTimeout(function(){
			this.scrollock = false;
		}.bind(this),500);
	}

	// Find the first photo which is visible
	// ie. which has a top > 0
	binary_search_first_visible(){
		var min = this.curthumbs.first().data('photoid');
		var max = this.curthumbs.last().data('photoid');

		var mid;
		var maxloops = 20;
		while(min != max && maxloops > 0 && document.readyState === 'complete') {
			// Only check the first item in rows
			mid = Math.floor(((max + min)/2)/this.rowwidth) * this.rowwidth;

			if (mid == min) {

				if (jQuery('#photo-' + mid).offset().top >= -0.25 ) {
					break;
				} else {
					min += 1;
					mid += 1;
				}
			} else if (mid < min) {
				mid = min;
			}

			// Allow for a little bit of slop since the scroll stop seems to be a bit fuzzy
			if (jQuery('#photo-' + mid).offset().top >= 0 ) {
				// Go left
				max = mid;
			} else {
				// Go right
				min = mid;
			}

			maxloops--;
		}

		return min;
	}

	showNotification(html){
		jQuery('#notification').html(html).addClass('new');
		if ( this.notificationtimer !== undefined ) {
			clearTimeout(this.notificationtimer);
		}
		this.notificationtimer = setTimeout(function(){
			jQuery('#notification').removeClass('new');
		},5000);
	}

	handleNewCurrentPhoto(){

		if ( (new Date().getTime() - this.scrolltimer) < 1000 ) {
			setTimeout(this.handleNewCurrentPhoto.bind(this),200);
			return;
		}
		this.scrolltimer = new Date().getTime();

		var idx = this.binary_search_first_visible();
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
		var clicked = jQuery(e.target);
		var filename = new String(clicked.data('orig')).substring(clicked.data('orig').lastIndexOf('/') + 1);
		var html = '<h2>' + clicked.data('date') + '</h2>';
		html += '<p><a href="' + clicked.data('orig') + '" download>' + filename + '</a></p>';
		this.showNotification(html);
	}

	sliderChange(e){
		this.rowwidth = e.target.value;
		this.curwidthpercent = 100/this.rowwidth
		this.pagesize = Math.pow(this.rowwidth,2) * 2 * this.minpages; //(square * 2 for height, load 3 screens worth at a time)
		var leftover = this.pagesize % this.rowwidth;
		if ( leftover !== 0 ) {
			this.pagesize += (this.rowwidth - leftover);
		}

		document.styleSheets[0].insertRule('.photos .thumbnail { width: ' + this.curwidthpercent + 'vw; height: ' + this.curwidthpercent + 'vw; }', document.styleSheets[0].cssRules.length);
		this.appendphotos();
	}

	navClick(e) {
		var year = jQuery(e.target).closest('.nav').data('year');
		this.gotodate(year);
	}
}

var fastback = new Fastback();
