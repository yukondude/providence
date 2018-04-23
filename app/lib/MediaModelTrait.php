<?php
/** ---------------------------------------------------------------------
 * app/lib/MediaModelTrait.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2018 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage BaseModel
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */
 
trait MediaModelTrait {
	# --------------------------------------------------------------------------------
	# --- Uploaded media handling
	# --------------------------------------------------------------------------------
	/**
	 * Check if media content is mirrored (depending on settings in configuration file)
	 *
	 * @return bool
	 */
	public function mediaIsMirrored($ps_field, $ps_version) {
		$va_media_info = $this->get($ps_field, array('returnWithStructure' => true));
		if (!is_array($va_media_info) || !is_array($va_media_info = array_shift($va_media_info))) { return null; }
		
		$vi = self::$_MEDIA_VOLUMES->getVolumeInformation($va_media_info[$ps_version]["VOLUME"]);
		if (!is_array($vi)) { return null; }
		if (is_array($vi["mirrors"])) {
			return true;
		} else {
			return false;
		}
	}
	/**
	 * Get status of media mirror
	 *
	 * @param string $field field name
	 * @param string $version version of the media file, as defined in media_processing.conf
	 * @param string media mirror name, as defined in media_volumes.conf
	 * @return mixed media mirror status
	 */
	public function getMediaMirrorStatus($ps_field, $ps_version, $mirror="") {
		$va_media_info = $this->get($ps_field, array('returnWithStructure' => true));
		if (!is_array($va_media_info) || !is_array($va_media_info = array_shift($va_media_info))) { return null; }
		
		$vi = self::$_MEDIA_VOLUMES->getVolumeInformation($va_media_info[$ps_version]["VOLUME"]);
		if (!is_array($vi)) {
			return "";
		}
		if ($ps_mirror) {
			return $va_media_info["MIRROR_STATUS"][$ps_mirror];
		} else {
			return $va_media_info["MIRROR_STATUS"][$vi["accessUsingMirror"]];
		}
	}
	
	/**
	 * Retry mirroring of given media field. Sets global error properties on failure.
	 *
	 * @param string $ps_field field name
	 * @param string $ps_version version of the media file, as defined in media_processing.conf
	 * @return null
	 */
	public function retryMediaMirror($ps_field, $ps_version) {
		global $AUTH_CURRENT_USER_ID;
		
		$va_media_info = $this->get($ps_field, array('returnWithStructure' => true));
		if (!is_array($va_media_info) || !is_array($va_media_info = array_shift($va_media_info))) { return null; }
		
		$va_volume_info = self::$_MEDIA_VOLUMES->getVolumeInformation($va_media_info[$ps_version]["VOLUME"]);
		if (!is_array($va_volume_info)) { return null; }

		$o_tq = new TaskQueue();
		$vs_row_key = join("/", array($this->tableName(), $this->getPrimaryKey()));
		$vs_entity_key = join("/", array($this->tableName(), $ps_field, $this->getPrimaryKey(), $ps_version));


		foreach($va_media_info["MIRROR_STATUS"] as $vs_mirror_code => $vs_status) {
			$va_mirror_info = $va_volume_info["mirrors"][$vs_mirror_code];
			$vs_mirror_method = $va_mirror_info["method"];
			$vs_queue = $vs_mirror_method."mirror";

			switch($vs_status) {
				case 'FAIL':
				case 'PARTIAL':
					if (!($o_tq->cancelPendingTasksForEntity($vs_entity_key))) {
						//$this->postError(560, _t("Could not cancel pending tasks: %1", $this->error),"BaseModel->retryMediaMirror()");
						//return false;
					}

					if ($o_tq->addTask(
						$vs_queue,
						array(
							"MIRROR" => $vs_mirror_code,
							"VOLUME" => $va_media_info[$ps_version]["VOLUME"],
							"FIELD" => $ps_field,
							"TABLE" => $this->tableName(),
							"VERSION" => $ps_version,
							"FILES" => array(
								array(
									"FILE_PATH" => $this->getMediaPath($ps_field, $ps_version),
									"ABS_PATH" => $va_volume_info["absolutePath"],
									"HASH" => $this->_FIELD_VALUES[$ps_field][$ps_version]["HASH"],
									"FILENAME" => $this->_FIELD_VALUES[$ps_field][$ps_version]["FILENAME"]
								)
							),

							"MIRROR_INFO" => $va_mirror_info,

							"PK" => $this->primaryKey(),
							"PK_VAL" => $this->getPrimaryKey()
						),
						array("priority" => 100, "entity_key" => $vs_entity_key, "row_key" => $vs_row_key, 'user_id' => $AUTH_CURRENT_USER_ID)))
					{
						$va_media_info["MIRROR_STATUS"][$vs_mirror_code] = ""; // pending
						$this->setMediaInfo($ps_field, $va_media_info);
$this->update();
						continue;
					} else {
						$this->postError(100, _t("Couldn't queue mirror using '%1' for version '%2' (handler '%3')", $vs_mirror_method, $ps_version, $vs_queue),"BaseModel->retryMediaMirror()");
					}
					break;
			}
		}

	}

	/**
	 * Returns url of media file
	 *
	 * @param string $ps_field field name
	 * @param string $ps_version version of the media file, as defined in media_processing.conf
	 * @param int $pn_page page number, defaults to 1
	 * @param array $pa_options Supported options include:
	 *		localOnly = if true url to locally hosted media is always returned, even if an external url is available
	 *		externalOnly = if true url to externally hosted media is always returned, even if an no external url is available
	 * @return string the url
	 */
	public function getMediaUrl($ps_field, $ps_version, $pn_page=1, $pa_options=null) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) { return null; }

		#
		# Use icon
		#
		if (isset($va_media_info[$ps_version]['USE_ICON']) && ($vs_icon_code = $va_media_info[$ps_version]['USE_ICON'])) {
			return caGetDefaultMediaIconUrl($vs_icon_code, $va_media_info[$ps_version]['WIDTH'], $va_media_info[$ps_version]['HEIGHT']);
		}
		
		#
		# Is this version externally hosted?
		#
		if (!isset($pa_options['localOnly']) || !$pa_options['localOnly']){
			if (isset($va_media_info[$ps_version]["EXTERNAL_URL"]) && ($va_media_info[$ps_version]["EXTERNAL_URL"])) {
				return $va_media_info[$ps_version]["EXTERNAL_URL"];
			}
		}
		
		if (isset($pa_options['externalOnly']) && $pa_options['externalOnly']) {
			return $va_media_info[$ps_version]["EXTERNAL_URL"];
		}
		
		#
		# Is this version queued for processing?
		#
		if (isset($va_media_info[$ps_version]["QUEUED"]) && ($va_media_info[$ps_version]["QUEUED"])) {
			return null;
		}

		$va_volume_info = self::$_MEDIA_VOLUMES->getVolumeInformation($va_media_info[$ps_version]["VOLUME"]);
		if (!is_array($va_volume_info)) {
			return null;
		}

		# is this mirrored?
		if (
			(isset($va_volume_info["accessUsingMirror"]) && $va_volume_info["accessUsingMirror"])
			&& 
			(
				isset($va_media_info["MIRROR_STATUS"][$va_volume_info["accessUsingMirror"]]) 
				&& 
				($va_media_info["MIRROR_STATUS"][$va_volume_info["accessUsingMirror"]] == "SUCCESS")
			)
		) {
			$vs_protocol = 	$va_volume_info["mirrors"][$va_volume_info["accessUsingMirror"]]["accessProtocol"];
			$vs_host = 		$va_volume_info["mirrors"][$va_volume_info["accessUsingMirror"]]["accessHostname"];
			$vs_url_path = 	$va_volume_info["mirrors"][$va_volume_info["accessUsingMirror"]]["accessUrlPath"];
		} else {
			$vs_protocol = 	$va_volume_info["protocol"];
			$vs_host = 		$va_volume_info["hostname"];
			$vs_url_path = 	$va_volume_info["urlPath"];
		}

		if ($va_media_info[$ps_version]["FILENAME"]) {
			$vs_fpath = join("/",array($vs_url_path, $va_media_info[$ps_version]["HASH"], $va_media_info[$ps_version]["MAGIC"]."_".$va_media_info[$ps_version]["FILENAME"]));
			return $vs_protocol."://$vs_host".$vs_fpath;
		} else {
			return "";
		}
	}

	/**
	 * Returns path of media file
	 *
	 * @param string $ps_field field name
	 * @param string $ps_version version of the media file, as defined in media_processing.conf
	 * @param int $pn_page page number, defaults to 1
	 * @return string path of the media file
	 */
	public function getMediaPath($ps_field, $ps_version, $pn_page=1) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) { return null; }

		#
		# Use icon
		#
		if (isset($va_media_info[$ps_version]['USE_ICON']) && ($vs_icon_code = $va_media_info[$ps_version]['USE_ICON'])) {
			return caGetDefaultMediaIconPath($vs_icon_code, $va_media_info[$ps_version]['WIDTH'], $va_media_info[$ps_version]['HEIGHT']);
		}

		#
		# Is this version externally hosted?
		#
		if (isset($va_media_info[$ps_version]["EXTERNAL_URL"]) && ($va_media_info[$ps_version]["EXTERNAL_URL"])) {
			return '';		// no local path for externally hosted media
		}

		#
		# Is this version queued for processing?
		#
		if (isset($va_media_info[$ps_version]["QUEUED"]) && $va_media_info[$ps_version]["QUEUED"]) {
			return null;
		}

		$va_volume_info = self::$_MEDIA_VOLUMES->getVolumeInformation($va_media_info[$ps_version]["VOLUME"]);

		if (!is_array($va_volume_info)) {
			return "";
		}

		if ($va_media_info[$ps_version]["FILENAME"]) {
			return join("/",array($va_volume_info["absolutePath"], $va_media_info[$ps_version]["HASH"], $va_media_info[$ps_version]["MAGIC"]."_".$va_media_info[$ps_version]["FILENAME"]));
		} else {
			return "";
		}
	}

	/**
	 * Returns appropriate representation of that media version in an html tag, including attributes for display
	 *
	 * @param string $field field name
	 * @param string $version version of the media file, as defined in media_processing.conf
	 * @param string $name name attribute of the img tag
	 * @param string $vspace vspace attribute of the img tag - note: deprecated in HTML 4.01, not supported in XHTML 1.0 Strict
	 * @param string $hspace hspace attribute of the img tag - note: deprecated in HTML 4.01, not supported in XHTML 1.0 Strict
	 * @param string $alt alt attribute of the img tag
	 * @param int $border border attribute of the img tag - note: deprecated in HTML 4.01, not supported in XHTML 1.0 Strict
	 * @param string $usemap usemap attribute of the img tag
	 * @param int $align align attribute of the img tag - note: deprecated in HTML 4.01, not supported in XHTML 1.0 Strict
	 * @return string html tag
	 */
	public function getMediaTag($ps_field, $ps_version, $pa_options=null) {
		if (!is_array($va_media_info = $this->getMediaInfo($ps_field))) { return null; }
		if (!is_array($va_media_info[$ps_version])) { return null; }

		#
		# Use icon
		#
		if (isset($va_media_info[$ps_version]['USE_ICON']) && ($vs_icon_code = $va_media_info[$ps_version]['USE_ICON'])) {
			return caGetDefaultMediaIconTag($vs_icon_code, $va_media_info[$ps_version]['WIDTH'], $va_media_info[$ps_version]['HEIGHT']);
		}
		
		#
		# Is this version queued for processing?
		#
		if (isset($va_media_info[$ps_version]["QUEUED"]) && ($va_media_info[$ps_version]["QUEUED"])) {
			return $va_media_info[$ps_version]["QUEUED_MESSAGE"];
		}

		$url = $this->getMediaUrl($ps_field, $ps_version, isset($pa_options["page"]) ? $pa_options["page"] : null);
		$m = new Media();
		
		$o_vol = new MediaVolumes();
		$va_volume = $o_vol->getVolumeInformation($va_media_info[$ps_version]['VOLUME']);

		return $m->htmlTag($va_media_info[$ps_version]["MIMETYPE"], $url, $va_media_info[$ps_version]["PROPERTIES"], $pa_options, $va_volume);
	}

	/**
	 * Get media information for the given field
	 *
	 * @param string $field field name
	 * @param string $version version of the media file, as defined in media_processing.conf, can be omitted to retrieve information about all versions
	 * @param string $property this is your opportunity to restrict the result to a certain property for the given (field,version) pair.
	 * possible values are:
	 * -VOLUME
	 * -MIMETYPE
	 * -WIDTH
	 * -HEIGHT
	 * -PROPERTIES: returns an array with some media metadata like width, height, mimetype, etc.
	 * -FILENAME
	 * -HASH
	 * -MAGIC
	 * -EXTENSION
	 * -MD5
	 * @return mixed media information
	 */
	public function &getMediaInfo($ps_field, $ps_version=null, $ps_property=null) {
		$va_media_info = self::get($ps_field, array('returnWithStructure' => true, 'USE_MEDIA_FIELD_VALUES' => true));
		if (!is_array($va_media_info) || !is_array($va_media_info = array_shift($va_media_info))) { return null; }
		
		#
		# Use icon
		#
		if ($ps_version && (!$ps_property || (in_array($ps_property, array('WIDTH', 'HEIGHT'))))) {
			if (isset($va_media_info[$ps_version]['USE_ICON']) && ($vs_icon_code = $va_media_info[$ps_version]['USE_ICON'])) {
				if ($va_icon_size = caGetMediaIconForSize($vs_icon_code, $va_media_info[$ps_version]['WIDTH'], $va_media_info[$ps_version]['HEIGHT'])) {
					$va_media_info[$ps_version]['WIDTH'] = $va_icon_size['width'];
					$va_media_info[$ps_version]['HEIGHT'] = $va_icon_size['height'];
				}
			}
		} else {
			if (!$ps_property || (in_array($ps_property, array('WIDTH', 'HEIGHT')))) {
				foreach(array_keys($va_media_info) as $vs_version) {
					if (isset($va_media_info[$vs_version]['USE_ICON']) && ($vs_icon_code = $va_media_info[$vs_version]['USE_ICON'])) {
						if ($va_icon_size = caGetMediaIconForSize($vs_icon_code, $va_media_info[$vs_version]['WIDTH'], $va_media_info[$vs_version]['HEIGHT'])) {
							if (!$va_icon_size['size']) { continue; }
							$va_media_info[$vs_version]['WIDTH'] = $va_icon_size['width'];
							$va_media_info[$vs_version]['HEIGHT'] = $va_icon_size['height'];
						}
					}
				} 
			}
		}

		if ($ps_version) {
			if (!$ps_property) {
				return $va_media_info[$ps_version];
			} else {
				// Try key as passed, then all UPPER and all lowercase
				if($vs_v = $va_media_info[$ps_version][$ps_property]) { return $vs_v; }
				if($vs_v = $va_media_info[$ps_version][strtoupper($ps_property)]) { return $vs_v; }
				if($vs_v = $va_media_info[$ps_version][strtolower($ps_property)]) { return $vs_v; }
			}
		} else {
			return $va_media_info;
		}
	}

	/**
	 * Fetches media input type for the given field, e.g. "image"
	 *
	 * @param $ps_field field name
	 * @return string media input type
	 */
	public function getMediaInputType($ps_field) {
		if ($va_media_info = $this->getMediaInfo($ps_field)) {
			$o_media_proc_settings = new MediaProcessingSettings($this, $ps_field);
			return $o_media_proc_settings->canAccept($va_media_info["INPUT"]["MIMETYPE"]);
		} else {
			return null;
		}
	}
	
	/**
	 * Fetches media input type for the given field, e.g. "image" and version
	 *
	 * @param string $ps_field field name
	 * @param string $ps_version version
	 * @return string media input type
	 */
	public function getMediaInputTypeForVersion($ps_field, $ps_version) {
		if ($va_media_info = $this->getMediaInfo($ps_field)) {
			$o_media_proc_settings = new MediaProcessingSettings($this, $ps_field);
			if($vs_media_type = $o_media_proc_settings->canAccept($va_media_info["INPUT"]["MIMETYPE"])) {
				$va_media_type_info = $o_media_proc_settings->getMediaTypeInfo($vs_media_type);
				if (isset($va_media_type_info['VERSIONS'][$ps_version])) {
					if ($va_rule = $o_media_proc_settings->getMediaTransformationRule($va_media_type_info['VERSIONS'][$ps_version]['RULE'])) {
						return $o_media_proc_settings->canAccept($va_rule['SET']['format']);
					}
				}
			}
		}
		return null;
	}

	/**
	 * Returns default version to display for the given field based upon the currently loaded row
	 *
	 * @param string $ps_field field name
	 */
	public function getDefaultMediaVersion($ps_field) {
		if ($va_media_info = $this->getMediaInfo($ps_field)) {
			$o_media_proc_settings = new MediaProcessingSettings($this, $ps_field);
			$va_type_info = $o_media_proc_settings->getMediaTypeInfo($o_media_proc_settings->canAccept($va_media_info["INPUT"]["MIMETYPE"]));
			
			return ($va_type_info['MEDIA_VIEW_DEFAULT_VERSION']) ? $va_type_info['MEDIA_VIEW_DEFAULT_VERSION'] : array_shift($this->getMediaVersions($ps_field));
		} else {
			return null;
		}	
	}
	
	/**
	 * Returns default version to display as a preview for the given field based upon the currently loaded row
	 *
	 * @param string $ps_field field name
	 */
	public function getDefaultMediaPreviewVersion($ps_field) {
		if ($va_media_info = $this->getMediaInfo($ps_field)) {
			$o_media_proc_settings = new MediaProcessingSettings($this, $ps_field);
			$va_type_info = $o_media_proc_settings->getMediaTypeInfo($o_media_proc_settings->canAccept($va_media_info["INPUT"]["MIMETYPE"]));
			
			return ($va_type_info['MEDIA_PREVIEW_DEFAULT_VERSION']) ? $va_type_info['MEDIA_PREVIEW_DEFAULT_VERSION'] : array_shift($this->getMediaVersions($ps_field));
		} else {
			return null;
		}	
	}
	
	/**
	 * Fetches available media versions for the given field (and optional mimetype), as defined in media_processing.conf
	 *
	 * @param string $ps_field field name
	 * @param string $ps_mimetype optional mimetype restriction
	 * @return array list of available media versions
	 */
	public function getMediaVersions($ps_field, $ps_mimetype=null) {
		if (!$ps_mimetype) {
			# figure out mimetype from field content
			$va_media_desc = $this->get($ps_field, array('returnWithStructure' => true));
			
			if (is_array($va_media_desc) && is_array($va_media_desc = array_shift($va_media_desc))) {
				
				unset($va_media_desc["ORIGINAL_FILENAME"]);
				unset($va_media_desc["INPUT"]);
				unset($va_media_desc["VOLUME"]);
				unset($va_media_desc["_undo_"]);
				unset($va_media_desc["TRANSFORMATION_HISTORY"]);
				unset($va_media_desc["_CENTER"]);
				unset($va_media_desc["_SCALE"]);
				unset($va_media_desc["_SCALE_UNITS"]);
				return array_keys($va_media_desc);
			}
		} else {
			$o_media_proc_settings = new MediaProcessingSettings($this, $ps_field);
			if ($vs_media_type = $o_media_proc_settings->canAccept($ps_mimetype)) {
				$va_version_list = $o_media_proc_settings->getMediaTypeVersions($vs_media_type);
				if (is_array($va_version_list)) {
					// Re-arrange so any versions that are the basis for others are processed first
					$va_basis_versions = array();
					foreach($va_version_list as $vs_version => $va_version_info) {
						if (isset($va_version_info['BASIS']) && isset($va_version_list[$va_version_info['BASIS']])) {
							$va_basis_versions[$va_version_info['BASIS']] = true;
							unset($va_version_list[$va_version_info['BASIS']]);
						}
					}
					
					return array_merge(array_keys($va_basis_versions), array_keys($va_version_list));
				}
			}
		}
		return array();
	}

	/**
	 * Checks if a media version for the given field exists.
	 *
	 * @param string $ps_field field name
	 * @param string $ps_version string representation of the version you are asking for
	 * @return bool
	 */
	public function hasMediaVersion($ps_field, $ps_version) {
		return in_array($ps_version, $this->getMediaVersions($ps_field));
	}

	/**
	 * Fetches processing settings information for the given field with respect to the given mimetype
	 *
	 * @param string $ps_field field name
	 * @param string $ps_mimetype mimetype
	 * @return array containing the information defined in media_processing.conf
	 */
	public function &getMediaTypeInfo($ps_field, $ps_mimetype="") {
		$o_media_proc_settings = new MediaProcessingSettings($this, $ps_field);

		if (!$ps_mimetype) {
			# figure out mimetype from field content
			$va_media_desc = $this->get($ps_field, array('returnWithStructure' => true));
			if (!is_array($va_media_desc) || !is_array($va_media_desc = array_shift($va_media_desc))) { return array(); }
			
			if ($vs_media_type = $o_media_proc_settings->canAccept($va_media_desc["INPUT"]["MIMETYPE"])) {
				return $o_media_proc_settings->getMediaTypeInfo($vs_media_type);
			}
		} else {
			if ($vs_media_type = $o_media_proc_settings->canAccept($ps_mimetype)) {
				return $o_media_proc_settings->getMediaTypeInfo($vs_media_type);
			}
		}
		return null;
	}

	/**
	 * Sets media information
	 *
	 * @param $field field name
	 * @param array $info
	 * @return bool success state
	 */
	public function setMediaInfo($field, $info) {
		if(($this->getFieldInfo($field,"FIELD_TYPE")) == FT_MEDIA) {
			$this->_FIELD_VALUES[$field] = $info;
			$this->_FIELD_VALUE_CHANGED[$field] = 1;
			return true;
		}
		return false;
	}

	/**
	 * Clear media
	 *
	 * @param string $field field name
	 * @return bool always true
	 */
	public function clearMedia($field) {
		$this->_FILES_CLEAR[$field] = 1;
		$this->_FIELD_VALUE_CHANGED[$field] = 1;
		return true;
	}

	/**
	 * Generate name for media file representation.
	 * Makes the application die if you try to call this on a BaseModel object not representing an actual db row.
	 *
	 * @access private
	 * @param string $field
	 * @return string the media name
	 */
	public function _genMediaName($field) {
		$pk = $this->getPrimaryKey();
		if ($pk) {
			return $this->TABLE."_".$field."_".$pk;
		} else {
			die("NO PK TO MAKE media name for $field!");
		}
	}

	/**
	 * Removes media
	 *
	 * @access private
	 * @param string $ps_field field name
	 * @param string $ps_version string representation of the version (e.g. original)
	 * @param string $ps_dont_delete_path
	 * @param string $ps_dont_delete extension
	 * @return null
	 */
	public function _removeMedia($ps_field, $ps_version, $ps_dont_delete_path="", $ps_dont_delete_extension="") {
		global $AUTH_CURRENT_USER_ID;
		
		$va_media_info = $this->getMediaInfo($ps_field,$ps_version);
		if (!$va_media_info) { return true; }

		$vs_volume = $va_media_info["VOLUME"];
		$va_volume_info = self::$_MEDIA_VOLUMES->getVolumeInformation($vs_volume);

		#
		# Get list of media files to delete
		#
		$va_files_to_delete = array();
		
		$vs_delete_path = $va_volume_info["absolutePath"]."/".$va_media_info["HASH"]."/".$va_media_info["MAGIC"]."_".$va_media_info["FILENAME"];
		if (($va_media_info["FILENAME"]) && ($vs_delete_path != $ps_dont_delete_path.".".$ps_dont_delete_extension)) {
			$va_files_to_delete[] = $va_media_info["MAGIC"]."_".$va_media_info["FILENAME"];
			@unlink($vs_delete_path);
		}
		
		# if media is mirrored, delete file off of mirrored server
		if (is_array($va_volume_info["mirrors"]) && sizeof($va_volume_info["mirrors"]) > 0) {
			$o_tq = new TaskQueue();
			$vs_row_key = join("/", array($this->tableName(), $this->getPrimaryKey()));
			$vs_entity_key = join("/", array($this->tableName(), $ps_field, $this->getPrimaryKey(), $ps_version));

			if (!($o_tq->cancelPendingTasksForEntity($vs_entity_key))) {
				$this->postError(560, _t("Could not cancel pending tasks: %1", $this->error),"BaseModel->_removeMedia()");
				return false;
			}
			foreach ($va_volume_info["mirrors"] as $vs_mirror_code => $va_mirror_info) {
				$vs_mirror_method = $va_mirror_info["method"];
				$vs_queue = $vs_mirror_method."mirror";

				if (!($o_tq->cancelPendingTasksForEntity($vs_entity_key))) {
					$this->postError(560, _t("Could not cancel pending tasks: %1", $this->error),"BaseModel->_removeMedia()");
					return false;
				}

				$va_tq_filelist = array();
				foreach($va_files_to_delete as $vs_filename) {
					$va_tq_filelist[] = array(
						"HASH" => $va_media_info["HASH"],
						"FILENAME" => $vs_filename
					);
				}
				if ($o_tq->addTask(
					$vs_queue,
					array(
						"MIRROR" => $vs_mirror_code,
						"VOLUME" => $vs_volume,
						"FIELD" => $ps_field,
						"TABLE" => $this->tableName(),
						"DELETE" => 1,
						"VERSION" => $ps_version,
						"FILES" => $va_tq_filelist,

						"MIRROR_INFO" => $va_mirror_info,

						"PK" => $this->primaryKey(),
						"PK_VAL" => $this->getPrimaryKey()
					),
					array("priority" => 50, "entity_key" => $vs_entity_key, "row_key" => $vs_row_key, 'user_id' => $AUTH_CURRENT_USER_ID)))
				{
					continue;
				} else {
					$this->postError(100, _t("Couldn't queue mirror using '%1' for version '%2' (handler '%3')", $vs_mirror_method, $ps_version, $vs_queue),"BaseModel->_removeMedia()");
				}
			}
		}
	}

	/**
	 * Perform media processing for the given field if something has been uploaded
	 *
	 * @access private
	 * @param string $ps_field field name
	 * @param array options
	 * 
	 * Supported options:
	 * 		delete_old_media = set to zero to prevent that old media files are deleted; defaults to 1
	 *		these_versions_only = if set to an array of valid version names, then only the specified versions are updated with the currently updated file; ignored if no media already exists
	 *		dont_allow_duplicate_media = if set to true, and the model has a field named "md5" then media will be rejected if a row already exists with the same MD5 signature
	 */
	public function _processMedia($ps_field, $pa_options=null) {
		global $AUTH_CURRENT_USER_ID;
		if(!is_array($pa_options)) { $pa_options = array(); }
		if(!isset($pa_options['delete_old_media'])) { $pa_options['delete_old_media'] = true; }
		if(!isset($pa_options['these_versions_only'])) { $pa_options['these_versions_only'] = null; }
		
		$vs_sql = "";

	 	$vn_max_execution_time = ini_get('max_execution_time');
	 	set_time_limit(7200);
	 	
		$o_tq = new TaskQueue();
		$o_media_proc_settings = new MediaProcessingSettings($this, $ps_field);

		# only set file if something was uploaded
		# (ie. don't nuke an existing file because none
		#      was uploaded)
		$va_field_info = $this->getFieldInfo($ps_field);
		if ((isset($this->_FILES_CLEAR[$ps_field])) && ($this->_FILES_CLEAR[$ps_field])) {
			//
			// Clear files
			//
			$va_versions = $this->getMediaVersions($ps_field);

			#--- delete files
			foreach ($va_versions as $v) {
				$this->_removeMedia($ps_field, $v);
			}
			$this->_removeMedia($ps_field, '_undo_');
			
			$this->_FILES[$ps_field] = null;
			$this->_FIELD_VALUES[$ps_field] = null;
			$vs_sql =  "{$ps_field} = ".$this->quote(caSerializeForDatabase($this->_FILES[$ps_field], true)).",";
		} else {
			// Don't try to process files when no file is actually set
			if(isset($this->_SET_FILES[$ps_field]['tmp_name'])) { 
				$o_tq = new TaskQueue();
				$o_media_proc_settings = new MediaProcessingSettings($this, $ps_field);
		
				//
				// Process incoming files
				//
				$m = new Media();
				$va_media_objects = array();
			
				// is it a URL?
				$vs_url_fetched_from = null;
				$vn_url_fetched_on = null;
			
				$vb_allow_fetching_of_urls = (bool)self::$_CONFIG->get('allow_fetching_of_media_from_remote_urls');
				$vb_is_fetched_file = false;

				if($vb_allow_fetching_of_urls && ($o_remote = CA\Media\Remote\Base::getPluginInstance($this->_SET_FILES[$ps_field]['tmp_name']))) {
					$vs_url = $this->_SET_FILES[$ps_field]['tmp_name'];
					$vs_tmp_file = tempnam(__CA_APP_DIR__.'/tmp', 'caUrlCopy');
					try {
						$o_remote->downloadMediaForProcessing($vs_url, $vs_tmp_file);
						$this->_SET_FILES[$ps_field]['original_filename'] = $o_remote->getOriginalFilename($vs_url);
					} catch(Exception $e) {
						$this->postError(1600, $e->getMessage(), "BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
						set_time_limit($vn_max_execution_time);
						return false;
					}

					$vs_url_fetched_from = $vs_url;
					$vn_url_fetched_on = time();
					$this->_SET_FILES[$ps_field]['tmp_name'] = $vs_tmp_file;
					$vb_is_fetched_file = true;
				}
			
				// is it server-side stored user media?
				if (preg_match("!^userMedia[\d]+/!", $this->_SET_FILES[$ps_field]['tmp_name'])) {
					// use configured directory to dump media with fallback to standard tmp directory
					if (!is_writeable($vs_tmp_directory = $this->getAppConfig()->get('ajax_media_upload_tmp_directory'))) {
						$vs_tmp_directory = caGetTempDirPath();
					}
					$this->_SET_FILES[$ps_field]['tmp_name'] = "{$vs_tmp_directory}/".$this->_SET_FILES[$ps_field]['tmp_name'];
				
					// read metadata
					if (file_exists("{$vs_tmp_directory}/".$this->_SET_FILES[$ps_field]['tmp_name']."_metadata")) {
						if (is_array($va_tmp_metadata = json_decode(file_get_contents("{$vs_tmp_directory}/".$this->_SET_FILES[$ps_field]['tmp_name']."_metadata")))) {
							$this->_SET_FILES[$ps_field]['original_filename'] = $va_tmp_metadata['original_filename'];
						}
					}
				}
			
				if (isset($this->_SET_FILES[$ps_field]['tmp_name']) && (file_exists($this->_SET_FILES[$ps_field]['tmp_name']))) {
					if (!isset($pa_options['dont_allow_duplicate_media'])) {
						$pa_options['dont_allow_duplicate_media'] = (bool)$this->getAppConfig()->get('dont_allow_duplicate_media');
					}
					if (isset($pa_options['dont_allow_duplicate_media']) && $pa_options['dont_allow_duplicate_media']) {
						if($this->hasField('md5')) {
							$qr_dupe_chk = $this->getDb()->query("
								SELECT ".$this->primaryKey()." FROM ".$this->tableName()." WHERE md5 = ? ".($this->hasField('deleted') ? ' AND deleted = 0': '')."
							", (string)md5_file($this->_SET_FILES[$ps_field]['tmp_name']));
						
							if ($qr_dupe_chk->nextRow()) {
								$this->postError(1600, _t("Media already exists in database"),"BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
								return false;
							}
						}
					}

					// allow adding zip and (gzipped) tape archives
					$vb_is_archive = false;
					$vs_original_filename = $this->_SET_FILES[$ps_field]['original_filename'];
					$vs_original_tmpname = $this->_SET_FILES[$ps_field]['tmp_name'];
					$va_matches = array();

					

					// ImageMagick partly relies on file extensions to properly identify images (RAW images in particular)
					// therefore we rename the temporary file here (using the extension of the original filename, if any)
					$va_matches = array();
					$vb_renamed_tmpfile = false;
					preg_match("/[.]*\.([a-zA-Z0-9]+)$/",$this->_SET_FILES[$ps_field]['tmp_name'],$va_matches);
					if(!isset($va_matches[1])){ // file has no extension, i.e. is probably PHP upload tmp file
						$va_matches = array();
						preg_match("/[.]*\.([a-zA-Z0-9]+)$/",$this->_SET_FILES[$ps_field]['original_filename'],$va_matches);
						if(strlen($va_matches[1])>0){
							$va_parts = explode("/",$this->_SET_FILES[$ps_field]['tmp_name']);
							$vs_new_filename = sys_get_temp_dir()."/".$va_parts[sizeof($va_parts)-1].".".$va_matches[1];
							if (!move_uploaded_file($this->_SET_FILES[$ps_field]['tmp_name'],$vs_new_filename)) {
								rename($this->_SET_FILES[$ps_field]['tmp_name'],$vs_new_filename);
							}
							$this->_SET_FILES[$ps_field]['tmp_name'] = $vs_new_filename;
							$vb_renamed_tmpfile = true;
						}
					}
				
					$input_mimetype = $m->divineFileFormat($this->_SET_FILES[$ps_field]['tmp_name']);
					if (!$input_type = $o_media_proc_settings->canAccept($input_mimetype)) {
						# error - filetype not accepted by this field
						$this->postError(1600, ($input_mimetype) ? _t("File type %1 not accepted by %2", $input_mimetype, $ps_field) : _t("Unknown file type not accepted by %1", $ps_field),"BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
						set_time_limit($vn_max_execution_time);
						if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
						if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); }
						return false;
					}

					# ok process file...
					if (!($m->read($this->_SET_FILES[$ps_field]['tmp_name']))) {
						$this->errors = array_merge($this->errors, $m->errors());	// copy into model plugin errors
						set_time_limit($vn_max_execution_time);
						if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
						if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); }
						return false;
					}

					$va_media_objects['_original'] = $m;
				
					// preserve center setting from any existing media
					$va_center = null;
					if (is_array($va_tmp = $this->getMediaInfo($ps_field))) { $va_center = caGetOption('_CENTER', $va_tmp, array()); }
					$media_desc = array(
						"ORIGINAL_FILENAME" => $this->_SET_FILES[$ps_field]['original_filename'],
						"_CENTER" => $va_center,
						"_SCALE" => caGetOption('_SCALE', $va_tmp, array()),
						"_SCALE_UNITS" => caGetOption('_SCALE_UNITS', $va_tmp, array()),
						"INPUT" => array(
							"MIMETYPE" => $m->get("mimetype"),
							"WIDTH" => $m->get("width"),
							"HEIGHT" => $m->get("height"),
							"MD5" => md5_file($this->_SET_FILES[$ps_field]['tmp_name']),
							"FILESIZE" => filesize($this->_SET_FILES[$ps_field]['tmp_name']),
							"FETCHED_FROM" => $vs_url_fetched_from,
							"FETCHED_ON" => $vn_url_fetched_on
						 )
					);
				
					if (isset($this->_SET_FILES[$ps_field]['options']['TRANSFORMATION_HISTORY']) && is_array($this->_SET_FILES[$ps_field]['options']['TRANSFORMATION_HISTORY'])) {
						$media_desc['TRANSFORMATION_HISTORY'] = $this->_SET_FILES[$ps_field]['options']['TRANSFORMATION_HISTORY'];
					}
				
					#
					# Extract metadata from file
					#
					$media_metadata = $m->getExtractedMetadata();
					# get versions to create
					$va_versions = $this->getMediaVersions($ps_field, $input_mimetype);
					$error = 0;

					# don't process files that are not going to be processed or converted
					# we don't want to waste time opening file we're not going to do anything with
					# also, we don't want to recompress JPEGs...
					$media_type = $o_media_proc_settings->canAccept($input_mimetype);
					$version_info = $o_media_proc_settings->getMediaTypeVersions($media_type);
					$va_default_queue_settings = $o_media_proc_settings->getMediaTypeQueueSettings($media_type);

					if (!($va_media_write_options = $this->_FILES[$ps_field]['options'])) {
						$va_media_write_options = $this->_SET_FILES[$ps_field]['options'];
					}
				
					# Is an "undo" version set in options?
					if (isset($this->_SET_FILES[$ps_field]['options']['undo']) && file_exists($this->_SET_FILES[$ps_field]['options']['undo'])) {
						if ($volume = $version_info['original']['VOLUME']) {
							$vi = self::$_MEDIA_VOLUMES->getVolumeInformation($volume);
							if ($vi["absolutePath"] && (strlen($dirhash = $this->_getDirectoryHash($vi["absolutePath"], $this->getPrimaryKey())))) {
								$magic = rand(0,99999);
								$vs_filename = $this->_genMediaName($ps_field)."_undo_";
								$filepath = $vi["absolutePath"]."/".$dirhash."/".$magic."_".$vs_filename;
								if (copy($this->_SET_FILES[$ps_field]['options']['undo'], $filepath)) {
									$media_desc['_undo_'] = array(
										"VOLUME" => $volume,
										"FILENAME" => $vs_filename,
										"HASH" => $dirhash,
										"MAGIC" => $magic,
										"MD5" => md5_file($filepath)
									);
								}
							}
						}
					}
				
					$va_process_these_versions_only = array();
					if (isset($pa_options['these_versions_only']) && is_array($pa_options['these_versions_only']) && sizeof($pa_options['these_versions_only'])) {
						$va_tmp = $this->_FIELD_VALUES[$ps_field];
						foreach($pa_options['these_versions_only'] as $vs_this_version_only) {
							if (in_array($vs_this_version_only, $va_versions)) {
								if (is_array($this->_FIELD_VALUES[$ps_field])) {
									$va_process_these_versions_only[] = $vs_this_version_only;
								}
							}
						}
					
						// Copy metadata for version we're not processing 
						if (sizeof($va_process_these_versions_only)) {
							foreach (array_keys($va_tmp) as $v) {
								if (!in_array($v, $va_process_these_versions_only)) {
									$media_desc[$v] = $va_tmp[$v];
								}
							}
						}
					}

					$va_files_to_delete 	= array();
					$va_queued_versions 	= array();
					$queue_enabled			= (!sizeof($va_process_these_versions_only) && $this->getAppConfig()->get('queue_enabled')) ? true : false;
				
					$vs_path_to_queue_media = null;
					foreach ($va_versions as $v) {
						$vs_use_icon = null;
					
						if (sizeof($va_process_these_versions_only) && (!in_array($v, $va_process_these_versions_only))) {
							// only processing certain versions... and this one isn't it so skip
							continue;
						}
					
						$queue 				= $va_default_queue_settings['QUEUE'];
						$queue_threshold 	= isset($version_info[$v]['QUEUE_WHEN_FILE_LARGER_THAN']) ? intval($version_info[$v]['QUEUE_WHEN_FILE_LARGER_THAN']) : (int)$va_default_queue_settings['QUEUE_WHEN_FILE_LARGER_THAN'];
						$rule 				= isset($version_info[$v]['RULE']) ? $version_info[$v]['RULE'] : '';
						$volume 			= isset($version_info[$v]['VOLUME']) ? $version_info[$v]['VOLUME'] : '';

						$basis				= isset($version_info[$v]['BASIS']) ? $version_info[$v]['BASIS'] : '';

						if (isset($media_desc[$basis]) && isset($media_desc[$basis]['FILENAME'])) {
							if (!isset($va_media_objects[$basis])) {
								$o_media = new Media();
								$basis_vi = self::$_MEDIA_VOLUMES->getVolumeInformation($media_desc[$basis]['VOLUME']);
								if ($o_media->read($p=$basis_vi['absolutePath']."/".$media_desc[$basis]['HASH']."/".$media_desc[$basis]['MAGIC']."_".$media_desc[$basis]['FILENAME'])) {
									$va_media_objects[$basis] = $o_media;
								} else {
									$m = $va_media_objects['_original'];
								}
							} else {
								$m = $va_media_objects[$basis];
							}
						} else {
							$m = $va_media_objects['_original'];
						}
						$m->reset();
				
						# get volume
						$vi = self::$_MEDIA_VOLUMES->getVolumeInformation($volume);

						if (!is_array($vi)) {
							print "Invalid volume '{$volume}'<br>";
							exit;
						}
					
						// Send to queue if it's too big to process here
						if (($queue_enabled) && ($queue) && ($queue_threshold > 0) && ($queue_threshold < (int)$media_desc["INPUT"]["FILESIZE"]) && ($va_default_queue_settings['QUEUE_USING_VERSION'] != $v)) {
							$va_queued_versions[$v] = array(
								'VOLUME' => $volume
							);
							$media_desc[$v]["QUEUED"] = $queue;						
							if ($version_info[$v]["QUEUED_MESSAGE"]) {
								$media_desc[$v]["QUEUED_MESSAGE"] = $version_info[$v]["QUEUED_MESSAGE"];
							} else {
								$media_desc[$v]["QUEUED_MESSAGE"] = ($va_default_queue_settings['QUEUED_MESSAGE']) ? $va_default_queue_settings['QUEUED_MESSAGE'] : _t("Media is being processed and will be available shortly.");
							}
						
							if ($pa_options['delete_old_media']) {
								$va_files_to_delete[] = array(
									'field' => $ps_field,
									'version' => $v
								);
							}
							continue;
						}

						# get transformation rules
						$rules = $o_media_proc_settings->getMediaTransformationRule($rule);


						if (sizeof($rules) == 0) {
							$output_mimetype = $input_mimetype;
							$m->set("version", $v);

							#
							# don't process this media, just copy the file
							#
							$ext = ($output_mimetype == 'application/octet-stream') ? pathinfo($this->_SET_FILES[$ps_field]['original_filename'], PATHINFO_EXTENSION) : $m->mimetype2extension($output_mimetype);

							if (!$ext) {
								$this->postError(1600, _t("File could not be copied for %1; can't convert mimetype '%2' to extension", $ps_field, $output_mimetype),"BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
								$m->cleanup();
								set_time_limit($vn_max_execution_time);
								if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
								if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); @unlink($vs_archive_original); }
								return false;
							}

							if (($dirhash = $this->_getDirectoryHash($vi["absolutePath"], $this->getPrimaryKey())) === false) {
								$this->postError(1600, _t("Could not create subdirectory for uploaded file in %1. Please ask your administrator to check the permissions of your media directory.", $vi["absolutePath"]),"BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
								set_time_limit($vn_max_execution_time);
								if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
								if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); @unlink($vs_archive_original); }
								return false;
							}

							if ((bool)$version_info[$v]["USE_EXTERNAL_URL_WHEN_AVAILABLE"]) { 
								$filepath = $this->_SET_FILES[$ps_field]['tmp_name'];
							
								if ($pa_options['delete_old_media']) {
									$va_files_to_delete[] = array(
										'field' => $ps_field,
										'version' => $v
									);
								}
														
								$media_desc[$v] = array(
									"VOLUME" => $volume,
									"MIMETYPE" => $output_mimetype,
									"WIDTH" => $m->get("width"),
									"HEIGHT" => $m->get("height"),
									"PROPERTIES" => $m->getProperties(),
									"EXTERNAL_URL" => $media_desc['INPUT']['FETCHED_FROM'],
									"FILENAME" => null,
									"HASH" => null,
									"MAGIC" => null,
									"EXTENSION" => $ext,
									"MD5" => md5_file($filepath)
								);
							} else {
								$magic = rand(0,99999);
								$filepath = $vi["absolutePath"]."/".$dirhash."/".$magic."_".$this->_genMediaName($ps_field)."_".$v.".".$ext;
							
								if (!copy($this->_SET_FILES[$ps_field]['tmp_name'], $filepath)) {
									$this->postError(1600, _t("File could not be copied. Ask your administrator to check permissions and file space for %1",$vi["absolutePath"]),"BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
									$m->cleanup();
									set_time_limit($vn_max_execution_time);
									if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
									if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); @unlink($vs_archive_original); }
									return false;
								}
							
							
								if ($v === $va_default_queue_settings['QUEUE_USING_VERSION']) {
									$vs_path_to_queue_media = $filepath;
								}
	
								if ($pa_options['delete_old_media']) {
									$va_files_to_delete[] = array(
										'field' => $ps_field,
										'version' => $v,
										'dont_delete_path' => $vi["absolutePath"]."/".$dirhash."/".$magic."_".$this->_genMediaName($ps_field)."_".$v,
										'dont_delete_extension' => $ext
									);
								}
	
								if (is_array($vi["mirrors"]) && sizeof($vi["mirrors"]) > 0) {
									$vs_entity_key = join("/", array($this->tableName(), $ps_field, $this->getPrimaryKey(), $v));
									$vs_row_key = join("/", array($this->tableName(), $this->getPrimaryKey()));
	
									foreach ($vi["mirrors"] as $vs_mirror_code => $va_mirror_info) {
										$vs_mirror_method = $va_mirror_info["method"];
										$vs_queue = $vs_mirror_method."mirror";
	
										if (!($o_tq->cancelPendingTasksForEntity($vs_entity_key))) {
											//$this->postError(560, _t("Could not cancel pending tasks: %1", $this->error),"BaseModel->_processMedia()");
											//$m->cleanup();
											//return false;
										}
										if ($o_tq->addTask(
											$vs_queue,
											array(
												"MIRROR" => $vs_mirror_code,
												"VOLUME" => $volume,
												"FIELD" => $ps_field,
												"TABLE" => $this->tableName(),
												"VERSION" => $v,
												"FILES" => array(
													array(
														"FILE_PATH" => $filepath,
														"ABS_PATH" => $vi["absolutePath"],
														"HASH" => $dirhash,
														"FILENAME" => $magic."_".$this->_genMediaName($ps_field)."_".$v.".".$ext
													)
												),
	
												"MIRROR_INFO" => $va_mirror_info,
	
												"PK" => $this->primaryKey(),
												"PK_VAL" => $this->getPrimaryKey()
											),
											array("priority" => 100, "entity_key" => $vs_entity_key, "row_key" => $vs_row_key, 'user_id' => $AUTH_CURRENT_USER_ID)))
										{
											continue;
										} else {
											$this->postError(100, _t("Couldn't queue mirror using '%1' for version '%2' (handler '%3')", $vs_mirror_method, $v, $queue),"BaseModel->_processMedia()");
										}
	
									}
								}
							
								$media_desc[$v] = array(
									"VOLUME" => $volume,
									"MIMETYPE" => $output_mimetype,
									"WIDTH" => $m->get("width"),
									"HEIGHT" => $m->get("height"),
									"PROPERTIES" => $m->getProperties(),
									"FILENAME" => $this->_genMediaName($ps_field)."_".$v.".".$ext,
									"HASH" => $dirhash,
									"MAGIC" => $magic,
									"EXTENSION" => $ext,
									"MD5" => md5_file($filepath)
								);
							}
						} else {
							$m->set("version", $v);
							while(list($operation, $parameters) = each($rules)) {
								if ($operation === 'SET') {
									foreach($parameters as $pp => $pv) {
										if ($pp == 'format') {
											$output_mimetype = $pv;
										} else {
											$m->set($pp, $pv);
										}
									}
								} else {
									if(is_array($this->_FIELD_VALUES[$ps_field]) && (is_array($va_media_center = $this->getMediaCenter($ps_field)))) {
										$parameters['_centerX'] = caGetOption('x', $va_media_center, 0.5);
										$parameters['_centerY'] = caGetOption('y', $va_media_center, 0.5);
								
										if (($parameters['_centerX'] < 0) || ($parameters['_centerX'] > 1)) { $parameters['_centerX'] = 0.5; }
										if (($parameters['_centerY'] < 0) || ($parameters['_centerY'] > 1)) { $parameters['_centerY'] = 0.5; }
									}
									if (!($m->transform($operation, $parameters))) {
										$error = 1;
										$error_msg = "Couldn't do transformation '$operation'";
										break(2);
									}
								}
							}

							if (!$output_mimetype) { $output_mimetype = $input_mimetype; }

							if (!($ext = $m->mimetype2extension($output_mimetype))) {
								$this->postError(1600, _t("File could not be processed for %1; can't convert mimetype '%2' to extension", $ps_field, $output_mimetype),"BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
								$m->cleanup();
								set_time_limit($vn_max_execution_time);
								if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
								if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); @unlink($vs_archive_original); }
								return false;
							}

							if (($dirhash = $this->_getDirectoryHash($vi["absolutePath"], $this->getPrimaryKey())) === false) {
								$this->postError(1600, _t("Could not create subdirectory for uploaded file in %1. Please ask your administrator to check the permissions of your media directory.", $vi["absolutePath"]),"BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
								set_time_limit($vn_max_execution_time);
								if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
								if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); @unlink($vs_archive_original); }
								return false;
							}
							$magic = rand(0,99999);
							$filepath = $vi["absolutePath"]."/".$dirhash."/".$magic."_".$this->_genMediaName($ps_field)."_".$v;

							if (!($vs_output_file = $m->write($filepath, $output_mimetype, $va_media_write_options))) {
								$this->postError(1600,_t("Couldn't write file: %1", join("; ", $m->getErrors())),"BaseModel->_processMedia()", $this->tableName().'.'.$ps_field);
								$m->cleanup();
								set_time_limit($vn_max_execution_time);
								if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
								if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); @unlink($vs_archive_original); }
								return false;
								break;
							} else {
								if (
									($vs_output_file === __CA_MEDIA_VIDEO_DEFAULT_ICON__)
									||
									($vs_output_file === __CA_MEDIA_AUDIO_DEFAULT_ICON__)
									||
									($vs_output_file === __CA_MEDIA_DOCUMENT_DEFAULT_ICON__)
									||
									($vs_output_file === __CA_MEDIA_3D_DEFAULT_ICON__)
								) {
									$vs_use_icon = $vs_output_file;
								}
							}
						
							if ($v === $va_default_queue_settings['QUEUE_USING_VERSION']) {
								$vs_path_to_queue_media = $vs_output_file;
								$vs_use_icon = __CA_MEDIA_QUEUED_ICON__;
							}

							if (($pa_options['delete_old_media']) && (!$error)) {
								if($vs_old_media_path = $this->getMediaPath($ps_field, $v)) {
									$va_files_to_delete[] = array(
										'field' => $ps_field,
										'version' => $v,
										'dont_delete_path' => $filepath,
										'dont_delete_extension' => $ext
									);
								}
							}

							if (is_array($vi["mirrors"]) && sizeof($vi["mirrors"]) > 0) {
								$vs_entity_key = join("/", array($this->tableName(), $ps_field, $this->getPrimaryKey(), $v));
								$vs_row_key = join("/", array($this->tableName(), $this->getPrimaryKey()));

								foreach ($vi["mirrors"] as $vs_mirror_code => $va_mirror_info) {
									$vs_mirror_method = $va_mirror_info["method"];
									$vs_queue = $vs_mirror_method."mirror";

									if (!($o_tq->cancelPendingTasksForEntity($vs_entity_key))) {
										//$this->postError(560, _t("Could not cancel pending tasks: %1", $this->error),"BaseModel->_processMedia()");
										//$m->cleanup();
										//return false;
									}
									if ($o_tq->addTask(
										$vs_queue,
										array(
											"MIRROR" => $vs_mirror_code,
											"VOLUME" => $volume,
											"FIELD" => $ps_field, "TABLE" => $this->tableName(),
											"VERSION" => $v,
											"FILES" => array(
												array(
													"FILE_PATH" => $filepath.".".$ext,
													"ABS_PATH" => $vi["absolutePath"],
													"HASH" => $dirhash,
													"FILENAME" => $magic."_".$this->_genMediaName($ps_field)."_".$v.".".$ext
												)
											),

											"MIRROR_INFO" => $va_mirror_info,

											"PK" => $this->primaryKey(),
											"PK_VAL" => $this->getPrimaryKey()
										),
										array("priority" => 100, "entity_key" => $vs_entity_key, "row_key" => $vs_row_key, 'user_id' => $AUTH_CURRENT_USER_ID)))
									{
										continue;
									} else {
										$this->postError(100, _t("Couldn't queue mirror using '%1' for version '%2' (handler '%3')", $vs_mirror_method, $v, $queue),"BaseModel->_processMedia()");
									}
								}
							}

						
							if ($vs_use_icon) {
								$media_desc[$v] = array(
									"MIMETYPE" => $output_mimetype,
									"USE_ICON" => $vs_use_icon,
									"WIDTH" => $m->get("width"),
									"HEIGHT" => $m->get("height")
								);
							} else {
								$media_desc[$v] = array(
									"VOLUME" => $volume,
									"MIMETYPE" => $output_mimetype,
									"WIDTH" => $m->get("width"),
									"HEIGHT" => $m->get("height"),
									"PROPERTIES" => $m->getProperties(),
									"FILENAME" => $this->_genMediaName($ps_field)."_".$v.".".$ext,
									"HASH" => $dirhash,
									"MAGIC" => $magic,
									"EXTENSION" => $ext,
									"MD5" => md5_file($vi["absolutePath"]."/".$dirhash."/".$magic."_".$this->_genMediaName($ps_field)."_".$v.".".$ext)
								);
							}
							$m->reset();
						}
					}
				
					if (sizeof($va_queued_versions)) {
						$vs_entity_key = md5(join("/", array_merge(array($this->tableName(), $ps_field, $this->getPrimaryKey()), array_keys($va_queued_versions))));
						$vs_row_key = join("/", array($this->tableName(), $this->getPrimaryKey()));
					
						if (!($o_tq->cancelPendingTasksForEntity($vs_entity_key, $queue))) {
							// TODO: log this
						}
					
						if (!($filename = $vs_path_to_queue_media)) {
							// if we're not using a designated not-queued representation to generate the queued ones
							// then copy the uploaded file to the tmp dir and use that
							$filename = $o_tq->copyFileToQueueTmp($va_default_queue_settings['QUEUE'], $this->_SET_FILES[$ps_field]['tmp_name']);
						}
					
						if ($filename) {
							if ($o_tq->addTask(
								$va_default_queue_settings['QUEUE'],
								array(
									"TABLE" => $this->tableName(), "FIELD" => $ps_field,
									"PK" => $this->primaryKey(), "PK_VAL" => $this->getPrimaryKey(),
								
									"INPUT_MIMETYPE" => $input_mimetype,
									"FILENAME" => $filename,
									"VERSIONS" => $va_queued_versions,
								
									"OPTIONS" => $va_media_write_options,
									"DONT_DELETE_OLD_MEDIA" => ($filename == $vs_path_to_queue_media) ? true : false
								),
								array("priority" => 100, "entity_key" => $vs_entity_key, "row_key" => $vs_row_key, 'user_id' => $AUTH_CURRENT_USER_ID)))
							{
								if ($pa_options['delete_old_media']) {
									foreach($va_queued_versions as $vs_version => $va_version_info) {
										$va_files_to_delete[] = array(
											'field' => $ps_field,
											'version' => $vs_version
										);
									}
								}
							} else {
								$this->postError(100, _t("Couldn't queue processing for version '%1' using handler '%2'", !$v, $queue),"BaseModel->_processMedia()");
							}
						} else {
							$this->errors = $o_tq->errors;
						}
					} else {
						// Generate preview frames for media that support that (Eg. video)
						// and add them as "multifiles" assuming the current model supports that (ca_object_representations does)
						if (!sizeof($va_process_these_versions_only) && ((bool)self::$_CONFIG->get('video_preview_generate_frames') || (bool)self::$_CONFIG->get('document_preview_generate_pages')) && method_exists($this, 'addFile')) {
							if (method_exists($this, 'removeAllFiles')) {
								$this->removeAllFiles();                // get rid of any previously existing frames (they might be hanging ar
							}
							$va_preview_frame_list = $m->writePreviews(
								array(
									'width' => $m->get("width"), 
									'height' => $m->get("height"),
									'minNumberOfFrames' => self::$_CONFIG->get('video_preview_min_number_of_frames'),
									'maxNumberOfFrames' => self::$_CONFIG->get('video_preview_max_number_of_frames'),
									'numberOfPages' => self::$_CONFIG->get('document_preview_max_number_of_pages'),
									'frameInterval' => self::$_CONFIG->get('video_preview_interval_between_frames'),
									'pageInterval' => self::$_CONFIG->get('document_preview_interval_between_pages'),
									'startAtTime' => self::$_CONFIG->get('video_preview_start_at'),
									'endAtTime' => self::$_CONFIG->get('video_preview_end_at'),
									'startAtPage' => self::$_CONFIG->get('document_preview_start_page'),
									'outputDirectory' => __CA_APP_DIR__.'/tmp'
								)
							);
							
							if (is_array($va_preview_frame_list)) {
								foreach($va_preview_frame_list as $vn_time => $vs_frame) {
									$this->addFile($vs_frame, $vn_time, true);	// the resource path for each frame is it's time, in seconds (may be fractional) for video, or page number for documents
									@unlink($vs_frame);		// clean up tmp preview frame file
								}
							}
						}
					}
				
					if (!$error) {
						#
						# --- Clean up old media from versions that are not supported in the new media
						#
						if ($pa_options['delete_old_media']) {
							foreach ($this->getMediaVersions($ps_field) as $old_version) {
								if (!is_array($media_desc[$old_version])) {
									$this->_removeMedia($ps_field, $old_version);
								}
							}
						}

						foreach($va_files_to_delete as $va_file_to_delete) {
							$this->_removeMedia($va_file_to_delete['field'], $va_file_to_delete['version'], $va_file_to_delete['dont_delete_path'], $va_file_to_delete['dont_delete_extension']);
						}
					
						# Remove old _undo_ file if defined
						if ($vs_undo_path = $this->getMediaPath($ps_field, '_undo_')) {
							@unlink($vs_undo_path);
						}

						$this->_FILES[$ps_field] = $media_desc;
						$this->_FIELD_VALUES[$ps_field] = $media_desc;

						$vs_serialized_data = caSerializeForDatabase($this->_FILES[$ps_field], true);
						$vs_sql =  "$ps_field = ".$this->quote($vs_serialized_data).",";
						if (($vs_metadata_field_name = $o_media_proc_settings->getMetadataFieldName()) && $this->hasField($vs_metadata_field_name)) {
						    $vn_embedded_media_metadata_limit = (int)self::$_CONFIG->get('dont_extract_embedded_media_metdata_when_length_exceeds');
						    if (($vn_embedded_media_metadata_limit > 0) && (strlen($vs_serialized_metadata = caSerializeForDatabase($media_metadata, true)) > $vn_embedded_media_metadata_limit)) {
						        $media_metadata = null; $vs_serialized_metadata = '';
						    }
							$this->set($vs_metadata_field_name, $media_metadata);
							$vs_sql .= " ".$vs_metadata_field_name." = ".$this->quote($vs_serialized_metadata).",";
						}
				
						if (($vs_content_field_name = $o_media_proc_settings->getMetadataContentName()) && $this->hasField($vs_content_field_name)) {
							$this->_FIELD_VALUES[$vs_content_field_name] = $this->quote($m->getExtractedText());
							$vs_sql .= " ".$vs_content_field_name." = ".$this->_FIELD_VALUES[$vs_content_field_name].",";
						}
					
						if(is_array($va_locs = $m->getExtractedTextLocations())) {
							MediaContentLocationIndexer::clear($this->tableNum(), $this->getPrimaryKey());
							foreach($va_locs as $vs_content => $va_loc_list) {
								foreach($va_loc_list as $va_loc) {
									MediaContentLocationIndexer::index($this->tableNum(), $this->getPrimaryKey(), $vs_content, $va_loc['p'], $va_loc['x1'], $va_loc['y1'], $va_loc['x2'], $va_loc['y2']);
								}
							}
							MediaContentLocationIndexer::write();
						}
					} else {
						# error - invalid media
						$this->postError(1600, _t("File could not be processed for %1: %2", $ps_field, $error_msg),"BaseModel->_processMedia()");
						#	    return false;
					}

					$m->cleanup();

					if($vb_renamed_tmpfile){
						@unlink($this->_SET_FILES[$ps_field]['tmp_name']);
					}
				} elseif(is_array($this->_FIELD_VALUES[$ps_field])) {
					// Just set field values in SQL (assume in-place update of media metadata) because no tmp_name is set
					// [This generally should not happen]
					$this->_FILES[$ps_field] = $this->_FIELD_VALUES[$ps_field];
					$vs_sql =  "$ps_field = ".$this->quote(caSerializeForDatabase($this->_FILES[$ps_field], true)).",";
				}

				$this->_SET_FILES[$ps_field] = null;
			} elseif(is_array($this->_FIELD_VALUES[$ps_field])) {
				// Just set field values in SQL (usually in-place update of media metadata)
				$this->_FILES[$ps_field] = $this->_FIELD_VALUES[$ps_field];
				$vs_sql =  "$ps_field = ".$this->quote(caSerializeForDatabase($this->_FILES[$ps_field], true)).",";
			}
		}
		set_time_limit($vn_max_execution_time);
		if ($vb_is_fetched_file) { @unlink($vs_tmp_file); }
		if ($vb_is_archive) { @unlink($vs_archive); @unlink($vs_primary_file_tmp); @unlink($vs_archive_original); }
		
		return $vs_sql;
	}
	# --------------------------------------------------------------------------------
	/**
	 * Apply media transformation to media in specified field of current loaded row. When a transformation is applied
	 * it is applied to all versions, including the "original." A copy of the original is stashed in a "virtual" version named "_undo_"
	 * to make it possible to recover the original media, if desired, by calling removeMediaTransformations().
	 *
	 * @param string $ps_field The name of the media field
	 * @param string $ps_op A valid media transformation op code, as defined by the media plugin handling the media being transformed.
	 * @param array $pa_params The parameters for the op code, as defined by the media plugin handling the media being transformed.
	 * @param array $pa_options An array of options. No options are currently implemented.
	 *
	 * @return bool True on success, false if an error occurred.
	 */
	public function applyMediaTransformation ($ps_field, $ps_op, $pa_params, $pa_options=null) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) {
			return null;
		}
		
		if(isset($pa_options['revert']) && $pa_options['revert'] && isset($va_media_info['_undo_'])) {
			$vs_path = $vs_undo_path = $this->getMediaPath($ps_field, '_undo_');
			$va_transformation_history = array();
		} else {
			$vs_path = $this->getMediaPath($ps_field, 'original');
			// Copy original into "undo" slot (if undo slot is empty)
			$vs_undo_path = (!isset($va_media_info['_undo_'])) ? $vs_path : $this->getMediaPath($ps_field, '_undo_');
			if (!is_array($va_transformation_history = $va_media_info['TRANSFORMATION_HISTORY'])) {
				$va_transformation_history = array();
			}
		}
		
		// TODO: Check if transformation valid for this media
		
		
		// Apply transformation to original
		$o_media = new Media();
		$o_media->read($vs_path);
		$o_media->transform($ps_op, $pa_params);
		$va_transformation_history[$ps_op][] = $pa_params;
		
		$vs_tmp_basename = tempnam(caGetTempDirPath(), 'ca_media_rotation_tmp');
		$o_media->write($vs_tmp_basename, $o_media->get('mimetype'), array());
		
		// Regenerate derivatives 
$this->set($ps_field, $vs_tmp_basename.".".$va_media_info['original']['EXTENSION'], $vs_undo_path ? array('undo' => $vs_undo_path, 'TRANSFORMATION_HISTORY' => $va_transformation_history) : array('TRANSFORMATION_HISTORY' => $va_transformation_history));
		$this->setAsChanged($ps_field);
		$this->update();
		
		return $this->numErrors() ? false : true;
	}
	# --------------------------------------------------------------------------------
	/**
	 * Remove all media transformation to media in specified field of current loaded row by reverting to the unmodified media.
	 *
	 * @param string $ps_field The name of the media field
	 * @param array $pa_options An array of options. No options are currently implemented.
	 *
	 * @return bool True on success, false if an error occurred.
	 */
	public function removeMediaTransformations($ps_field, $pa_options=null) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) {
			return null;
		}
		// Copy "undo" media into "original" slot
		if (!isset($va_media_info['_undo_'])) {
			return false;
		}
		
		$vs_path = $this->getMediaPath($ps_field, 'original');
		$vs_undo_path = $this->getMediaPath($ps_field, '_undo_');
		
		// Regenerate derivatives 
$this->set($ps_field, $vs_undo_path ? $vs_undo_path : $vs_path, array('TRANSFORMATION_HISTORY' => array()));
		$this->setAsChanged($ps_field);
		$this->update();
		
		return $this->numErrors() ? false : true;
	}
	# --------------------------------------------------------------------------------
	/**
	 * Get history of transformations applied to media in specified field of current loaded
	 *
	 * @param string $ps_field The name of the media field; if omitted the history for all operations is returned
	 * @param string $ps_op The name of the media transformation operation to fetch history for
	 *
	 * @return array List of transformations for the given operation, or null if the history cannot be retrieved
	 */
	public function getMediaTransformationHistory($ps_field, $ps_op=null) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) {
			return null;
		}
		if ($ps_op && isset($va_media_info['TRANSFORMATION_HISTORY'][$ps_op])	&& is_array($va_media_info['TRANSFORMATION_HISTORY'][$ps_op])) {
			return $va_media_info['TRANSFORMATION_HISTORY'][$ps_op];
		}
		return (isset($va_media_info['TRANSFORMATION_HISTORY']) && is_array($va_media_info['TRANSFORMATION_HISTORY'])) ? $va_media_info['TRANSFORMATION_HISTORY'] : array();
	}
	# --------------------------------------------------------------------------------
	/**
	 * Check if media in specified field of current loaded row has undoable transformation applied
	 *
	 * @param string $ps_field The name of the media field
	 *
	 * @return bool True on if there are undoable changes, false if not
	 */
	public function mediaHasUndo($ps_field) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) {
			return null;
		}
		return isset($va_media_info['_undo_']);
	}
	# --------------------------------------------------------------------------------
	/**
	 * Return coordinates of center of image media as decimals between 0 and 1. By default this is dead-center (x=0.5, y=0.5)
	 * but the user may override this to optimize cropping of images. Currently the center point is only used when cropping the image
	 * from the "center" but it may be used for other transformation (Eg. rotation) in the future.
	 *
	 * @param string $ps_field The name of the media field
	 * @param array $pa_options An array of options. No options are currently implemented.
	 *
	 * @return array An array with 'x' and 'y' keys containing coordinates, or null if no coordinates are available.
	 */
	public function getMediaCenter($ps_field, $pa_options=null) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) {
			return null;
		}
		
		$vn_current_center_x = caGetOption('x', $va_media_info['_CENTER'], 0.5);
		if (($vn_current_center_x < 0) || ($vn_current_center_x > 1)) { $vn_current_center_x = 0.5; }
		
		$vn_current_center_y = caGetOption('y', $va_media_info['_CENTER'], 0.5);
		if (($vn_current_center_y < 0) || ($vn_current_center_y > 1)) { $vn_current_center_y = 0.5; }
		
		return array('x' => $vn_current_center_x, 'y' => $vn_current_center_y);
	}
	# --------------------------------------------------------------------------------
	/**
	 * Sets the center of the currently loaded media. X and Y coordinates are fractions of the width and height respectively
	 * expressed as decimals between 0 and 1. Currently the center point is only used when cropping the image
	 * from the "center" but it may be used for other transformation (Eg. rotation) in the future.
	 *
	 * @param string $ps_field The name of the media field
	 * @param float $pn_center_x X-coordinate for the new center, as a fraction of the width of the image. Value must be between 0 and 1.
	 * @param float $pn_center_y Y-coordinate for the new center, as a fraction of the height of the image. Value must be between 0 and 1.
	 * @param array $pa_options An array of options. No options are currently implemented.
	 *
	 * @return bool True on success, false if an error occurred.
	 */
	public function setMediaCenter($ps_field, $pn_center_x, $pn_center_y, $pa_options=null) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) {
			return null;
		}
		
		$vs_original_filename = $va_media_info['ORIGINAL_FILENAME'];
		
		$vn_current_center_x = caGetOption('x', $va_media_info['_CENTER'], 0.5);
		$vn_current_center_y = caGetOption('y', $va_media_info['_CENTER'], 0.5);
		
		// Is center different?
		if(($vn_current_center_x == $pn_center_x) && ($vn_current_center_y == $pn_center_y)) { return true; }
		
		$va_media_info['_CENTER']['x'] = $pn_center_x;
		$va_media_info['_CENTER']['y'] = $pn_center_y;
		
$this->setMediaInfo($ps_field, $va_media_info);
		$this->update();
		$this->set('media', $this->getMediaPath('media', 'original'), array('original_filename' => $vs_original_filename));
		$this->update();
		
		return $this->numErrors() ? false : true;
	}
	# --------------------------------------------------------------------------------
	/**
	 * Set scaling conversion factor for media. Allows physical measurements to be derived from image pixel measurements.
	 * A measurement with physical units of the kind passable to caConvertMeasurementToPoints() (Eg. "55mm", "5 ft", "10km") and
	 * the percentage of the image *width* the measurement covers are passed, from which the scale factor is calculated and stored.
	 *
	 * @param string $ps_field The name of the media field
	 * @param string $ps_dimension A measurement with dimensional units (ex. "55mm", "5 ft", "10km")
	 * @param float $pn_percent_of_image_width Percentage of image *width* the measurement covers from 0 to 1. If you pass a value > 1 it will be divided by 100 for calculations. [Default is 1]
	 * @param array $pa_options An array of options. No options are currently implemented.
	 *
	 * @return bool True on success, false if an error occurred.
	 */
	public function setMediaScale($ps_field, $ps_dimension, $pn_percent_of_image_width=1, $pa_options=null) {
		if ($pn_percent_of_image_width > 1) { $pn_percent_of_image_width /= 100; }
		if ($pn_percent_of_image_width <= 0) { $pn_percent_of_image_width = 1; }
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) {
			return null;
		}
		
		$vo_parsed_measurement = caParseDimension($ps_dimension);
		
		if ($vo_parsed_measurement && (($vn_measurement = (float)$vo_parsed_measurement->toString(4)) > 0)) {
		
			$va_media_info['_SCALE'] = $pn_percent_of_image_width/$vn_measurement;
			$va_media_info['_SCALE_UNITS'] = caGetLengthUnitType($vo_parsed_measurement->getType(), array('short' => true));

$this->setMediaInfo($ps_field, $va_media_info);
			$this->update();
		}
		
		return $this->numErrors() ? false : true;
	}
	# --------------------------------------------------------------------------------
	/**
	 * Returns scaling conversion factor for media. Allows physical measurements to be derived from image pixel measurements.
	 *
	 * @param string $ps_field The name of the media field
	 * @param array $pa_options An array of options. No options are currently implemented.
	 *
	 * @return array Value or null if not set
	 */
	public function getMediaScale($ps_field, $pa_options=null) {
		$va_media_info = $this->getMediaInfo($ps_field);
		if (!is_array($va_media_info)) {
			return null;
		}
		
		$vn_scale = caGetOption('_SCALE', $va_media_info, null);
		if (!is_numeric($vn_scale)) { $vn_scale = null; }
		$vs_scale_units = caGetOption('_SCALE_UNITS', $va_media_info, null);
		if (!is_string($vs_scale_units)) { $vs_scale_units = null; }
		
		return array('scale' => $vn_scale, 'measurementUnits' => $vs_scale_units);
	}
	# --------------------------------------------------------------------------------
	/**
	 * Fetches hash directory
	 * 
	 * @access protected
	 * @param string $basepath path
	 * @param int $id identifier
	 * @return string directory
	 */
	protected function _getDirectoryHash ($basepath, $id) {
		$n = intval($id / 100);
		$dirs = array();
		$l = strlen($n);
		for($i=0;$i<$l; $i++) {
			$dirs[] = substr($n,$i,1);
			if (!file_exists($basepath."/".join("/", $dirs))) {
				if (!@mkdir($basepath."/".join("/", $dirs))) {
					return false;
				}
			}
		}

		return join("/", $dirs);
	}
}
