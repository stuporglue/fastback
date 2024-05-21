Fastback.prototype.Modules.photos = class {

	constructor(id,fb) {
		this.fb = fb;
		this.id = id;
		this.module_type = 'photos';
	}

	util_generate_row_block(p){
		var vidclass = '';
		if ( p.alt !== null ) {
			vidclass = ' alt';
		}
		return '<div class="tn' + vidclass + '"><img data-photoid="' + p.id + '" src="' + encodeURI(this.fb.fastbackurl + '?thumbnail=' + p.mfile) + '" onerror="this.onerror=null;this.src=\'fastback/img/picture.webp\';"/></div>';
	}

	ui_show_fullsized(photo,showalt) {

		var imgsrc = showalt ? photo.alt : photo.mfile;

		var basename = imgsrc.replace(/.*\//,'');
		var directlink = encodeURI(this.fb.fastbackurl + '?download=' + imgsrc);

		// File type not found, proxy a jpg instead
		var supported_type = (this.fb.browser_supported_file_types.indexOf(imgsrc.replace(/.*\./,'').toLowerCase()) != -1);
		if ( !supported_type ) {
			directlink = encodeURI(this.fb.fastbackurl + '?proxy=' + imgsrc );	
		}
		var share_uri = encodeURI(this.fb.fastbackurl + '?file=' + imgsrc + '&share=' + md5('./' + photo.file));

		var imghtml = '<img src="' + directlink + '"/>';
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
	}
}
