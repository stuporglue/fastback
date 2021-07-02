var fastback;
var curwidthpercent = 20;
var prevscrolltop = 0;
var scrolltimer;
var notificationtimer;

$.getJSON('https://convenienturl.com/shared/big/Photos/fastback.php?get=photojson', function(json) {
	fastback = json;
}).then(function(){
	load_view();
	appendphotos();
});

// Append as many photos as needed to meet the page size
function appendphotos(addthismany) {
	var	html ='';
	var thumbs = jQuery('.photos img.thumbnail');
	var cursize = thumbs.length;
	var curmax = 0;
	if (cursize > 0) {
		curmax = thumbs.last().data('photoid');
	}

	var photostoadd = addthismany || ( getPageSize() - cursize );

	// TODO: Change to slice to avoid looping
	var endidx = Math.min(photostoadd + curmax,fastback.tags.length);
	var startidx = curmax + 1;
	html = fastback.tags.slice(startidx,endidx + 1).join("");
	jQuery('.photos').append(html);
}

// Prepend as many photos as needed to meet the page size
function prependphotos(addthismany) {
	var	html ='';
	var thumbs = jQuery('.photos img.thumbnail');
	var cursize = thumbs.length;
	var curmin = 0;
	if (cursize > 0) {
		curmin = thumbs.first().data('photoid');
	}

	var photostoadd = addthismany || (getPageSize() - cursize);

	var startidx = Math.max(curmin - photostoadd,0);
	var endidx = curmin - 1;

	html = fastback.tags.slice(startidx,endidx + 1).join("");
	jQuery('.photos').prepend(html);
}

jQuery('.slider').on('change',function(e){
	curwidthpercent = 100/e.target.value;
	document.styleSheets[0].insertRule('.photos .thumbnail { width: ' + curwidthpercent + '%; }', document.styleSheets[0].cssRules.length);
	appendphotos();
});

jQuery('.photos').on('scroll',handleScrollEnd);

function handleScrollEnd(){
	loadMore();
	handleNewCurrentPhoto();
}

function loadMore(){

	if ( scrolltimer === undefined ) {
		scrolltimer = setTimeout(function(){
			scrolltimer = undefined;	
		},50);
	} else {
		return;
	}

	var howfar = (document.body.clientHeight + jQuery('.photos').scrollTop()) / jQuery('.photos')[0].scrollHeight;

	var direction = 'unknown';

	if (howfar > prevscrolltop) {
		direction = 'down';
	} else if (howfar < prevscrolltop) {
		direction = 'up';
	}

	prevscrolltop = howfar;

	if ( howfar >= 0.6 && direction == 'down') {
		page_down();
	}
	if (howfar <= 0.3 && direction == 'up') {
		page_up();
	}
}

function page_down() {
	var curmin = jQuery('.photos img.thumbnail').first().data('photoid');
	var third = Math.round(getPageSize() / 3);

	var imgwide = getRowWidth();
	var leftover = third % imgwide;
	if (leftover !== 0) {
		third += imgwide - leftover;
	}

	jQuery('.photos .thumbnail').slice(0,third).remove();
	appendphotos();
}

function page_up() {
	var curmin = jQuery('.photos img.thumbnail').first().data('photoid');
	var third = Math.round(getPageSize() / 3) * 2;

	var imgwide = getRowWidth();
	var leftover = third % imgwide;
	if (leftover !== 0) {
		third -= leftover;
	}

	jQuery('.photos .thumbnail').slice(third).remove();
	prependphotos();
}


// Figure out how many images to load, including accounting for always making a full row.
function getPageSize() {
	var imgwide = getRowWidth();

	var pagesize = Math.pow(imgwide,2) * 6; //(square * 2 for height, load 3 screens worth at a time)

	var leftover = pagesize % imgwide;
	if ( leftover !== 0 ) {
		pagesize += (imgwide - leftover);
	}

	return pagesize;
}

function getRowWidth() {
	return Math.round(100/curwidthpercent);
}

jQuery(document).on('load',function(){
	prevscrolltop = jQuery('.photos').scrollTop() / document.body.clientHeight;
});

function load_view() {
	for(var i=0;i<fastback.years.length;i++){
		jQuery('.scroller').append('<div class="nav" data-year="' + fastback.years[i] + '"><div class="year">' + fastback.years[i] + '</div></div>');
	}

	jQuery('.scroller .nav').on('click',function(e){
		var year = jQuery(e.target).closest('.nav').data('year');
		gotodate(year);
	});
}

function gotodate(date){
	console.log("About to go to " + date);
	idx = fastback.yearindex[date];

	var curphotos = jQuery('.photos .thumbnail');
	var min = curphotos.first().data('photoid');
	var max = curphotos.last().data('photoid');
	var newmin = idx - getPageSize() / 3;
	var newmax = newmin + getPageSize();

	if ( newmin > max ){
		// Only scroling down
		toremove = jQuery('.photos .thumbnail');
		jQuery('.photos').append(fastback.tags[newmin]);
		appendphotos(getPageSize());
		jQuery('#photo-' + idx)[0].scrollIntoView();
		toremove.remove();
	} else if ( newmax < min ) {
		// Only scrolling up
		toremove = jQuery('.photos .thumbnail');
		// Got to prepend a row or it shifts
		var onerow = fastback.tags.slice(newmax-getRowWidth()+1,newmax+1).join("");
		jQuery('.photos').prepend(onerow);
		prependphotos(getPageSize());
		jQuery('#photo-' + idx)[0].scrollIntoView();
		toremove.remove();
	} else if (  ( newmin <= max && newmin >= min ) || (newmax >= min && newmax <= max) ) {
		// Overlap with existing
		if ( min < newmin ) {
			// too many before - cut between old min and new min
			jQuery('.photos .thumbnail').slice(0,(newmin - min)).remove();		
			prependphotos(idx - newmin);
		}
		if ( newmax > max ) {
			jQuery('.photos .thumbnail').slice(0 + 2*getPageSize()/3).remove();
			appendphotos();
		}

		jQuery('#photo-' + idx)[0].scrollIntoView();
	} else {
		console.log("Missed a case in gotodate shomehow");
	}

	window.location.hash = '#photo-' + idx;
	var html = "<h2>" + date + "</h2>";
	showNotification(html);
}

// Find the first photo which is visible
// ie. which has a top > 0
function binary_search_first_visible(){
	var min = jQuery('.photos .thumbnail').first().data('photoid');
	var max = jQuery('.photos .thumbnail').last().data('photoid');
	var img_per_row = getRowWidth();

	var mid;
	var maxloops = 20;
	while(min != max && maxloops > 0 && document.readyState === 'complete') {
		// Only check the first item in rows
		mid = Math.floor(((max + min)/2)/img_per_row) * img_per_row;

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
		if (jQuery('#photo-' + mid).offset().top >= -0.25 ) {
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

jQuery('.photos').on('click','.thumbnail',function(e){
	var clicked = jQuery(e.target);
	var filename = new String(clicked.data('orig')).substring(clicked.data('orig').lastIndexOf('/') + 1);
	var html = '<h2>' + clicked.data('date') + '</h2>';
	html += '<p><a href="' + clicked.data('orig') + '" download>' + filename + '</a></p>';
	showNotification(html);
});

function showNotification(html){

	jQuery('#notification').html(html).addClass('new');
	if ( notificationtimer !== undefined ) {
		clearTimeout(notificationtimer);
	}
	notificationtimer = setTimeout(function(){
		jQuery('#notification').removeClass('new');
	},5000);
}

function handleNewCurrentPhoto(){
	var idx = binary_search_first_visible();
	window.location.hash = '#photo-' + idx;
	var cur = jQuery('#photo-' + idx);
	var year = cur.data('date').substr(0,4);
	var activeyear = jQuery('.nav.active');
	if(activeyear.length > 0 && activeyear.first().data('year') != year){
		activeyear.removeClass('active');
	}
	jQuery('.nav[data-year=' + year + ']').addClass('active');
}
