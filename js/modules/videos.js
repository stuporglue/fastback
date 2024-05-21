Fastback.prototype.Modules.videos = class {

	constructor(id,fb) {
		this.fb = fb;
		this.id = id;
		this.module_type = 'videos';
	}

	util_generate_row_block(p){
		return '<div class="tn vid"><img data-photoid="' + p.id + '" src="' + encodeURI(this.fb.fastbackurl + '?thumbnail=' + p.mfile) + '" onerror="this.onerror=null;this.src=\'fastback/img/movie.webp\';"/></div>';
	}

	ui_show_fullsized(photo,showalt) {

		var imgsrc = showalt ? photo.alt : photo.mfile;

		var basename = imgsrc.replace(/.*\//,'');
		var directlink = encodeURI(this.fb.fastbackurl + '?proxy=' + imgsrc );	
		var share_uri = encodeURI(this.fb.fastbackurl + '?file=' + imgsrc + '&share=' + md5('./' + photo.file));

		// the onloadedmetadata script is to make very short videos (like iOS live photos) loop but longer videos do not loop
		var imghtml = '<video id="thevideo" controls loop ' + (showalt ? ' ' : ' poster="' + encodeURI(this.fb.fastbackurl + '?thumbnail=' +  imgsrc) + '"') + ' preload="auto" onloadedmetadata="this.duration > 5 ? this.removeAttribute(\'loop\') : false">';
		// Put the proxied link, this should be a transcoded mp4 version
		imghtml += '<source src="' + directlink + '#t=0.0001" type="video/mp4">';
		// Also include the original as a source...just in case it works
		imghtml += '<source src="' + encodeURI(this.fb.fastbackurl + '?download=' + imgsrc) + '#t=0.0001" type="video/' + imgsrc.replace(/.*\./,'').toLowerCase() + '">';
		imghtml += '<p>Your browser does not support this video format.</p></video>';

		jQuery('#thumbcontent').data('last_html',jQuery('#thumbcontent').html()); // Will keeping this here prevent images from unloading? 
		jQuery('#thumbcontent').html(imghtml);
		jQuery('#thumbinfo').html('<div id="infowrap">' + (showalt ? photo.alt : photo.file) + '</div>');
		jQuery('#thumbgeo').attr('data-coordinates', (photo.coordinates == null ? "" : photo.coordinates ));
		jQuery('#thumbalt').attr('data-alt',photo.alt || "");
		jQuery('#thumbalt').data('showing_alt',showalt)
		jQuery('#thumbflag').css('opacity',1);
		jQuery('#sharelink a').attr('href',share_uri);
		jQuery('#thumb').data('curphoto',photo.id);
		jQuery('#thumb').removeClass('disabled');

		setTimeout(function(){
			jQuery('#thevideo')[0].play();
		},500);
	}
}
