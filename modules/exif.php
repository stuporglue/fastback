<?php

trait Fastback_Exif {

	/**
	 * Read the exif info for one file. This includes the accompanying xmp file 
	 */
	public function _read_one_exif($file,$cmdargs,$proc,$pipes) {
		$files_and_sidecars = array($file);

		// For the requested file and any xmp sidecars...
		if ( file_exists($this->path . "$file.xmp") ) {
			$files_and_sidecars[] = $this->path . "$file.xmp";
		}

		$cur_exif = array();
		foreach($files_and_sidecars as $exiftarget) {

			// Run the exiftool command
			fputs($pipes[0],$cmdargs . "\n");
			fputs($pipes[0],$exiftarget. "\n");
			fputs($pipes[0],"-execute\n");
			fflush($pipes[0]);

			$end_of_exif = FALSE;

			// Collect all exiftool output
			$exifout = "";
			while(!$end_of_exif){
				// Handle stdout. Build a single chunk of text then split it since some tag output seems to get split in some cases
				$line = fgets($pipes[1]);	
				if ($line == FALSE ) {
					continue;
				}

				$exifout .= $line;

				if (preg_match('/.*{ready}$/',$line)){
					$end_of_exif = TRUE;

					$exifout = preg_replace('/\n\s*{ready}\s*/','',$exifout);
				}

				// Handle stderr
				$err = fgets($pipes[2]);
				if ( $err !== FALSE ) {
					$no_err = FALSE;
					$this->fb->log($err);
				}

				if ($err === FALSE && $line === FALSE) {
					time_nanosleep(0,200);
				}
			}
			$exifout = explode("\n",$exifout);

			// Process the output
			foreach($exifout as $line) {
				$line = trim($line);

				if ( preg_match('/^======== (.*)/',$line, $matches ) ) {

					if ($matches[1] != $exiftarget) {
						$this->fb->log("Expected '$exiftarget', got '$matches[1]'");
						die("Somethings broken");
					}
				} else if (preg_match('/^([^ ]+)\s*:\s*(.*)$/',$line,$matches)){
					$cur_exif[$matches[1]] = $matches[2];
				} else if ($line == '{ready}') {
					$end_of_exif = TRUE;
				} else if (preg_match('/ExifTool Version Number.*/',$line)){
					// do nothing
				} else if ($line === ''){
					// do nothing
				} else {
					$this->fb->log("Don't know how to handle exif line '" . $line . "'");
					// die("Quitting for fun");
				}
			}
		}

		// Drop any empty lines
		$cur_exif = array_filter($cur_exif);
		// Sort it for ease of use
		ksort($cur_exif);

		return $cur_exif;
	}

	/*
	 * Find people tags in exif data
	 */
	public function _process_exif_tags($exif,$file,$row){
		$simple = array('Subject','XPKeywords','Keywords','RegionName','RegionPersonDisplayName','CatalogSets','HierarchicalSubject','LastKeywordXMP','TagsList');

		// Clean up values
		$people_found = array();
		foreach($simple as $exif_keyword) {
			if ( !empty($exif[$exif_keyword]) ) {
				$sub = str_replace(" 'who'",'',$exif[$exif_keyword]);
				$sub = str_replace("People|","",$sub);
				$sub = str_replace("People/","",$sub);
				$sub = str_replace('People\/',"",$sub);
				$sub = str_replace('(none)',"",$sub);
				$sub = str_replace('(people)',"",$sub);
				$sub = str_replace(';',",",$sub);
				$sub = str_replace('|',",",$sub);
				$sub = str_replace('"',"",$sub);
				$sub = str_replace("'","",$sub);
				$subsplit = explode(",",$sub);
				foreach($subsplit as $person) {
					$people_found[] = trim($person);
				}
			}
		}

		$people_found = array_unique($people_found);
		$people_found = array_filter($people_found);
		$people_found = array_diff($people_found,$this->fb->ignore_tag);
		$ret = array('tags' => implode('|',$people_found));
		return $ret;
	}

	/**
	 * Find geo info in exif data
	 */
	public function _process_exif_geo($exif,$file,$row) {
		$xyz = array('lat' => NULL, 'lon' => NULL, 'elev' => 0);
		if ( array_key_exists('GPSPosition',$exif) ) {
			// eg "38.741200 N, 90.642800 W"
			$xyz = $this->_parse_gps_line($exif['GPSPosition']);	
		}

		if ( $xyz['lat'] === NULL && array_key_exists('GPSCoordinates',$exif) ) {
			// eg "38.741200 N, 90.642800 W"
			$xyz = $this->_parse_gps_line($exif['GPSCoordinates']);	
		}

		if ( $xyz['lat'] === NULL && array_key_exists('GPSLatitude',$exif) && array_key_exists('GPSLongitude',$exif)) {
			$lonval = floatval($exif['GPSLongitude']);
			$latval = floatval($exif['GPSLatitude']);

			if ( preg_match("/^['.0-9]+\s+S/",$exif['GPSLatitude']) ||  $exif['GPSLatitudeRef'] == 'South' || $exif['GPSLatitudeRef'] == 'S') {
				$latval = $latval * -1;
			}

			if ( preg_match("/^['.0-9]+\s+W/",$exif['GPSLongitude']) ||  $exif['GPSLongitudeRef'] == 'West' || $exif['GPSLongitudeRef'] == 'W') {
				$lonval = $lonval * -1;
			}

			$xyz['lon'] = $lonval;
			$xyz['lat'] = $latval;
		}

		if ( $xyz['elev'] === 0 ) {
			if ( array_key_exists('GPSAltitude',$exif) ) {
				if ( preg_match('/([0-9.]+) m/',$exif['GPSAltitude'],$matches ) ) {
					if ( array_key_exists('GPSAltitudeRef',$exif) && $exif['GPSAltitudeRef'] == 'Below Sea Level' ) {
						$xyz['elev'] = $matches[1] * -1;
					} else {
						$xyz['elev'] = $matches[1];
					}
				} else if ( $exif['GPSAltitude'] == 'undef') {
					$xyz['elev'] = 0;
				} else {
					$this->fb->log("New type of altitude value found: {$exif['GPSAltitude']} in $file");
					$xyz['elev'] = 0;
				}
			} else {
				$xyz['elev'] = 0;
			}
		}

		if ( $xyz['lat'] === NULL ) {
			$xyz['nullgeom'] = 1;
		} else {
			$xyz['nullgeom'] = 0;
		}

		return $xyz;
	}

	/**
	 * For a single-line GPS record, parse out the lat/lon
	 */
	public function _parse_gps_line($line) {
		$xyz = array('lat' => NULL,'lon' => NULL,'elev' => 0);

		if ( trim($line) == '0.00000 N, 0.00000 E' ) {
			return $xyz;
		}

		// 22.97400 S, 43.18910 W, 6.707 m Above Sea Level
		preg_match('/\'?([0-9.]+)\'? (N|S), \'?([0-9.]+)\'? (E|W), \'?([0-9.]+)\'? m .*/',$line,$matches);
		if ( count($matches) == 6) {

			if ( $matches[2] == 'S' ) {
				$matches[1] = $matches[1] * -1;
			} else {
				$matches[1] = $matches[1] * 1;
			}

			if ( $matches[4] == 'W' ) {
				$matches[3] = $matches[3] * -1;
			} else {
				$matches[3] = $matches[3] * 1;
			}

			$xyz['lon'] = $matches[3];
			$xyz['lat'] = $matches[1];
			$xyz['elev'] = $matches[5];
		} else  {
			// eg "38.741200 N, 90.642800 W"
			preg_match('/\'?([0-9.]+)\'? (N|S), \'?([0-9.]+)\'? (E|W)/',$line,$matches);
			if ( count($matches) == 5) {

				if ( $matches[2] == 'S' ) {
					$matches[1] = $matches[1] * -1;
				} else {
					$matches[1] = $matches[1] * 1;
				}

				if ( $matches[4] == 'W' ) {
					$matches[3] = $matches[3] * -1;
				} else {
					$matches[3] = $matches[3] * 1;
				}

				$xyz['lon'] = $matches[3];
				$xyz['lat'] = $matches[1];
			} else {
				$this->fb->log("Couldn't parse >>$line<<");
			}
		}
		return $xyz;
	}

	/**
	 * Find file time in exif data
	 *
	 * The tag to use will be considered in the order of $tags_to_consider
	 */
	public function _process_exif_time($exif,$file,$row) {
		$tags_to_consider = array(
			"DateTimeOriginal",
			"CreateDate",
			"CreationDate",
			"DateCreated",
			"TrackCreateDate",
			"MediaCreateDate",
			"GPSDateTime",
			"ModifyDate",
			"MediaModifyDate",
			"TrackModifyDate",
			"FileCreateDate",
			"FileModifyDate",
		);

		// If the user has put the file in a date directory (YYYY/MM/DD) then use that as the date
		// otherwise, fall back on meta data
		if ( preg_match('|.*\/([0-9]{4})/([0-9]{2})/([0-9]{2})/[^\/]*|',$file)) {
			$datepart = preg_replace('|.*\/([0-9]{4})/([0-9]{2})/([0-9]{2})/[^\/]*|','\1-\2-\3',$file);
		} else {
			$datepart = NULL;
		}

		foreach($tags_to_consider as $tag) {
			// Tag not found
			if ( !array_key_exists($tag,$exif)) {
				continue;
			}

			// Invalid date
			if ( strpos($exif[$tag], '0000:00:00 00:00:00') !== FALSE ) {
				continue;
			}

			preg_match('/(\d{4})\D(\d{2})\D(\d{2}) (\d{2})\D(\d{2})\D(\d{2})[^ ]*/',trim($exif[$tag]),$matches);

			if ( count($matches) !== 7 ) {

				preg_match('/(\d{4})\D(\d{2})\D(\d{2})/',trim($exif[$tag]),$matches);

				if ( count($matches) !== 4 ) {
					$this->fb->log("Coudln't regex a date from {$exif[$tag]}");
					continue;
				} else {
					$matches[4] = '00';
					$matches[5] = '00';
					$matches[6] = '00';
				}
			}

			// If the file was in a date folder, then use that date with the best datepart we have.
			if ( !is_null($datepart) ) {
				return array('sorttime' => $datepart . " " . $matches[4] . ':' . $matches[5] . ':' . $matches[6]);
			} else {
				return array('sorttime' => $matches[1] . '-' . $matches[2] . '-' . $matches[3] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6]);
			}
		}

		if ( !is_null($datepart ) ) {
			return array('sorttime' => $datepart . ' 00:00:00');
		}

		return array('sorttime' => NULL);;
	}

	/**
	 * Try to give an image a score of how likely it is to be a meme, based on some factors that seemed relavant for my photos.
	 */
	public function _process_exif_meme($exif,$file,$row) {
		$bad_filetypes = array('MacOS','WEBP');
		$bad_mimetypes = array('application/unknown','image/png');
		$maybe_meme = 0;

		// Bad filetype  or Mimetype
		// "FileType":"MacOS"
		// FileType WEBP
		// "MIMEType":"application\/unknown"
		if ( array_key_exists('FileType',$exif) && in_array($exif['FileType'],$bad_filetypes) ) {
			$maybe_meme += 1;
			$this->fb->log_verbose("Has FileType but it's bad (+1)\n");
		}

		if ( array_key_exists('MIMEType',$exif) && in_array($exif['MIMEType'],$bad_mimetypes) ) {
			$maybe_meme += 1;
			$this->fb->log_verbose("Has MIMEType but it's bad (+1)\n");
		} else if ( array_key_exists('MIMEType',$exif) && preg_match('/video/',$exif['MIMEType']) ) {
			// Most videos aren't memes
			$maybe_meme -= 1;
			$this->fb->log_verbose("Most videos aren't memes (-1)\n");
		}

		//  Error present
		// "Error":"File format error"
		if ( array_key_exists('Error',$exif) ) {
			$maybe_meme +=1 ;
			$this->fb->log_verbose("File format error (+1)\n");
		}

		// If there's no real exif info
		// Unsure how to detect and how to account for scanned images

		// IF the image is too small
		// "ImageWidth":"2592",
		// "ImageHeight":"1944",
		if ( array_key_exists('ImageHeight',$exif) && array_key_exists('ImageWidth',$exif) ) {
			if ( $exif['ImageHeight'] * $exif['ImageWidth'] <  804864 ) { // Less than 1024x768
				$maybe_meme += 1;
				$this->fb->log_verbose("ImageHeight * ImageWidth is small (+1)\n");
			}
		}

		$exif_keys = array_filter($exif,function($k){
			return strpos($k,"Exif") === 0;
		},ARRAY_FILTER_USE_KEY);
		if ( count($exif_keys) <= 4 ) {
			$maybe_meme += 1;
			$this->fb->log_verbose("Very few exif keys (+1) \n");
		}

		if ( count($exif_keys) === 1 ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Exactly 1 exif key (-1) \n");
		}

		// Having GPS is good
		if ( array_key_exists('GPSLatitude',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Has GPS (-1) \n");
		}

		// Having a camera name is good
		if ( array_key_exists('Model',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Has camera model (-1) \n");
		} else 

			// Having no FileModifyDate is sketchy
			if ( !array_key_exists('FileModifyDate',$exif) ) {
				$maybe_meme += 1;
				$this->fb->log_verbose("Has no FileModifyDate (+1) \n");
			} else 

				// Not having a camera is extra bad in 2020s
				if ( preg_match('/^202[0-9]:/',$exif['FileModifyDate']) && !array_key_exists('Model',$exif) ) {
					$maybe_meme += 1;
					$this->fb->log_verbose("Has no camera model AND is newer than 2020 (+1) \n");
				}

		// Scanners might put a comment in 
		if ( array_key_exists('Comment',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Scanner software likes to add comments (-1) \n");
		}

		// Scanners might put a comment in 
		if ( array_key_exists('UserComment',$exif) && $exif['UserComment'] == 'Screenshot' ) {
			$maybe_meme += 2;
			$this->fb->log_verbose("UserComment with Screenshot is probably a screenshot (+2) \n");
		}

		if ( array_key_exists('Software',$exif) && $exif['Software'] == 'Instagram' ) {
			$maybe_meme += 1;
			$this->fb->log_verbose("From Insta? Probably a meme (+1) \n");
		}

		if ( array_key_exists('ThumbnailImage',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Has a built-in Thumbnail (-1) \n");
		}

		if ( array_key_exists('ProfileDescriptionML',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Has ProfileDescriptionML (-1) \n");
		}

		// Luminance seems to maybe be something in some of our photos that aren't memes?
		if ( array_key_exists('Luminance',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Has Luminance (-1) \n");
		}

		if ( array_key_exists('TagsList',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Has TagsList (-1) \n");
		}

		if ( array_key_exists('Subject',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Has Subject (-1) \n");
		}

		if ( array_key_exists('DeviceMfgDesc',$exif) ) {
			$maybe_meme -= 1;
			$this->fb->log_verbose("Has DeviceMfgDesc (-1) \n");
		}

		return array('maybe_meme' => $maybe_meme);
	}

	/**
	 * Try to associate a live file with the other piece of media.
	 *
	 * Currently only tested with Apple photos which use the ContentIdentifier exif tag.
	 */

	public function _process_exif_live($exif,$file,$row) {
		if ( array_key_exists('ContentIdentifier',$exif) ) {
			return array('content_identifier' => $exif['ContentIdentifier']);
		}

		return array('content_identifier' => '-1');
	}

	/**
	 * For photos and videos our "get_meta" can use exif
	 */
	public function get_meta() {
		$this->cron->sql_update_cron_status('get_meta');

		$cmd = "exiftool -stay_open True  -@ -";
		$cmdargs = [];
		$cmdargs[] = "-lang"; // Lang to english
		$cmdargs[] = "en"; // Lang to english
		$cmdargs[] = "-a"; // Allow duplicate tags
		$cmdargs[] = "-s"; // Tag names instead of descriptions
		$cmdargs[] = "-c"; // Set format for GPS numbers
		$cmdargs[] = "%.5f"; // Set format for GPS numbers
		$cmdargs[] = "-extractEmbedded"; // get embedded data like geo data
		$cmdargs[] = "-e"; // Don't generate composite tags
		// TODO: Extract with -G1 and -json formats to capture everything?
		$cmdargs = implode("\n",$cmdargs);

		$descriptors = array(
			0 => array("pipe", "r"),  // STDIN
			1 => array("pipe", "w"),  // STDOUT
			2 => array("pipe", "w")   // STDERR
		);

		$proc = proc_open($cmd, $descriptors, $pipes,$this->path);

		register_shutdown_function(function() use ($proc,$pipes) {
			$this->fb->log("About to close exif process");
			if (is_resource($pipes[0])) {
				fputs($pipes[0], "-stay_open\nFalse\n");
				fflush($pipes[0]);
				fclose($pipes[0]);
				fclose($pipes[1]);
				fclose($pipes[2]);
			}

			if ( is_resource($proc) ) {
				proc_close($proc);
			}
		});

		// Don't block on STDERR
		stream_set_blocking($pipes[1], 0);
		stream_set_blocking($pipes[2], 0);

		do {
			$queue = $this->fb->sql_get_queue("exif IS NULL AND module={$this->id}");

			$found_exif = array();

			foreach($queue as $file => $row) {
				$this->fb->log("Reading exif for $file");
				$cur_exif = $this->_read_one_exif($file,$cmdargs,$proc,$pipes);

				if ( array_key_exists('Error',$cur_exif) ) {
					error_log("For $file read_one_exif got {$cur_exif['Error']}\n");
				}

				$found_exif[$file] = json_encode($cur_exif,JSON_FORCE_OBJECT | JSON_PARTIAL_OUTPUT_ON_ERROR);
			}

			$this->fb->sql_update_case_when($this->id,"UPDATE fastback SET _util=NULL, exif=CASE",$found_exif,"ELSE exif END",True);
			$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'process_exif'"); // If we found exif, then we need to process it.
			$this->cron->sql_update_cron_status('get_meta');

		} while (!empty($queue) );

		fputs($pipes[0], "-stay_open\nFalse\n");
		fflush($pipes[0]);
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);

		$this->cron->sql_update_cron_status('get_meta',true);
	}

	public function process_meta() {
		$this->cron->sql_update_cron_status('process_meta');
		do {
			$queue = $this->fb->sql_get_queue("
			(

				-- Needs tags
				(tags IS NULL
				AND (
						exif GLOB '*\"Subject\"*'
						OR exif GLOB '*\"XPKeywords\"*'
						OR exif GLOB '*\"Categories\"*'
						OR exif GLOB '*\"TagsList\"*'
						OR exif GLOB '*\"LastKeywordXMP\"*'
						OR exif GLOB '*\"HierarchicalSubject\"*'
						OR exif GLOB '*\"CatalogSets\"*'
						OR exif GLOB '*\"Keywords\"*'
					)
				) 
				OR
				-- Needs Geo
				(
					lat IS NULL 
					AND lon IS NULL 
					AND elev IS NULL 
					AND nullgeom IS NOT TRUE 
					AND (exif->'GPSPosition' IS NOT NULL OR exif->'GPSLatitude' IS NOT NULL)
				)
				OR 
				-- Needs time
				(
					sorttime IS NULL
				)		
				OR
				-- Meme status is null
				(
				maybe_meme IS NULL
				)
				OR
				-- Live status is null
				(
					content_identifier IS NULL
					AND (exif->'ContentIdentifier' IS NOT NULL)	
				)
			) AND (
				exif IS NOT NULL
				AND exif != \"\"
				AND exif NOT LIKE '\"Error\":'
				AND module={$this->id}
			)");

			$this->fb->_sql->query("BEGIN DEFERRED");

			if ( count($queue) == 0 ) {
				$this->fb->log("Empty queue");
			}

			foreach($queue as $row) {

				$exif = json_decode($row['exif'],true);
				$this->fb->log("Processing exif for {$row['file']}");

				if ( !is_array($exif) ) {
					ob_start();
					var_dump($row);
					$content = ob_get_contents();
					file_put_contents(__DIR__ . '/cache/row',$content);
					ob_end_clean();
					$this->fb->log("Non array exif value found");
					$this->fb->log($row['exif']);
				}

				if ( array_key_exists('Error',$exif) ) {
					$this->fb->log("Couldn't process exif for {$row['file']} because {$exif['Error']}");
					continue;
				}

				$tags = $this->_process_exif_tags($exif,$row['file'],$row);
				$geo = $this->_process_exif_geo($exif,$row['file'],$row);
				$time = $this->_process_exif_time($exif,$row['file'],$row);
				$meme = $this->_process_exif_meme($exif,$row['file'],$row);
				$live = $this->_process_exif_live($exif,$row['file'],$row);

				$found_vals = array_merge($tags,$geo,$time,$meme,$live);
				$q = "UPDATE fastback SET ";
				foreach($found_vals as $k => $v){
					if ( is_null($v) ) {
						$q .= "$k=NULL, ";
					} else {
						$q .= "$k='" . SQLite3::escapeString($v). "', ";
					}
				}
				$q .= "file=file WHERE file='" . SQLite3::escapeString($row['file']) . "' AND module={$this->id}";

				$this->fb->_sql->query($q);
			}
			$this->fb->_sql->query("COMMIT");
			$this->fb->sql_query_single("UPDATE cron SET due_to_run=1 WHERE job = 'make_csv'"); // If we updated exif, we need a new csv
			$this->cron->sql_update_cron_status('process_meta');

		} while ( count($queue) > 0 );
		$this->cron->sql_update_cron_status('process_meta',true);
	}
}
