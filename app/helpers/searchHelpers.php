<?php
/** ---------------------------------------------------------------------
 * app/helpers/searchHelpers.php : miscellaneous functions
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2011-2013 Whirl-i-Gig
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
 * @subpackage utils
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 * 
 * ----------------------------------------------------------------------
 */

 /**
   *
   */
   
require_once(__CA_MODELS_DIR__.'/ca_lists.php');


	# ---------------------------------------
	/**
	 * Get search instance for given table name
	 * @param string $pm_table_name_or_num Table name or number
	 * @return BaseSearch
	 */
	function caGetSearchInstance($pm_table_name_or_num, $pa_options=null) {
		$o_dm = Datamodel::load();
		
		$vs_table = (is_numeric($pm_table_name_or_num)) ? $o_dm->getTableName((int)$pm_table_name_or_num) : $pm_table_name_or_num;
		
		switch($vs_table) {
			case 'ca_objects':
				require_once(__CA_LIB_DIR__.'/ca/Search/ObjectSearch.php');
				return new ObjectSearch();
				break;
			case 'ca_entities':
				require_once(__CA_LIB_DIR__.'/ca/Search/EntitySearch.php');
				return new EntitySearch();
				break;
			case 'ca_places':
				require_once(__CA_LIB_DIR__.'/ca/Search/PlaceSearch.php');
				return new PlaceSearch();
				break;
			case 'ca_occurrences':
				require_once(__CA_LIB_DIR__.'/ca/Search/OccurrenceSearch.php');
				return new OccurrenceSearch();
				break;
			case 'ca_collections':
				require_once(__CA_LIB_DIR__.'/ca/Search/CollectionSearch.php');
				return new CollectionSearch();
				break;
			case 'ca_loans':
				require_once(__CA_LIB_DIR__.'/ca/Search/LoanSearch.php');
				return new LoanSearch();
				break;
			case 'ca_movements':
				require_once(__CA_LIB_DIR__.'/ca/Search/MovementSearch.php');
				return new MovementSearch();
				break;
			case 'ca_lists':
				require_once(__CA_LIB_DIR__.'/ca/Search/ListSearch.php');
				return new ListSearch();
				break;
			case 'ca_list_items':
				require_once(__CA_LIB_DIR__.'/ca/Search/ListItemSearch.php');
				return new ListItemSearch();
				break;
			case 'ca_object_lots':
				require_once(__CA_LIB_DIR__.'/ca/Search/ObjectLotSearch.php');
				return new ObjectLotSearch();
				break;
			case 'ca_object_representations':
				require_once(__CA_LIB_DIR__.'/ca/Search/ObjectRepresentationSearch.php');
				return new ObjectRepresentationSearch();
				break;
			case 'ca_representation_annotations':
				require_once(__CA_LIB_DIR__.'/ca/Search/RepresentationAnnotationSearch.php');
				return new RepresentationAnnotationSearch();
				break;
			case 'ca_item_comments':
				require_once(__CA_LIB_DIR__.'/ca/Search/ItemCommentSearch.php');
				return new ItemCommentSearch();
				break;
			case 'ca_item_tags':
				require_once(__CA_LIB_DIR__.'/ca/Search/ItemTagSearch.php');
				return new ItemTagSearch();
				break;
			case 'ca_relationship_types':
				require_once(__CA_LIB_DIR__.'/ca/Search/RelationshipTypeSearch.php');
				return new RelationshipTypeSearch();
				break;
			case 'ca_sets':
				require_once(__CA_LIB_DIR__.'/ca/Search/SetSearch.php');
				return new SetSearch();
				break;
			case 'ca_set_items':
				require_once(__CA_LIB_DIR__.'/ca/Search/SetItemSearch.php');
				return new SetItemSearch();
				break;
			case 'ca_tours':
				require_once(__CA_LIB_DIR__.'/ca/Search/TourSearch.php');
				return new TourSearch();
				break;
			case 'ca_tour_stops':
				require_once(__CA_LIB_DIR__.'/ca/Search/TourStopSearch.php');
				return new TourStopSearch();
				break;
			case 'ca_storage_locations':
				require_once(__CA_LIB_DIR__.'/ca/Search/StorageLocationSearch.php');
				return new StorageLocationSearch();
				break;
			case 'ca_users':
				require_once(__CA_LIB_DIR__.'/ca/Search/UserSearch.php');
				return new UserSearch();
				break;
			case 'ca_user_groups':
				require_once(__CA_LIB_DIR__.'/ca/Search/UserGroupSearch.php');
				return new UserGroupSearch();
				break;
			default:
				return null;
				break;
		}
	}
	 # ------------------------------------------------------------------------------------------------
	/**
	 *
	 */
	function caSearchLink($po_request, $ps_content, $ps_classname, $ps_table, $ps_search, $pa_other_params=null, $pa_attributes=null, $pa_options=null) {
		if (!($vs_url = caSearchUrl($po_request, $ps_table, $ps_search, false, $pa_other_params, $pa_options))) {
			return "<strong>Error: no url for search</strong>";
		}
		
		$vs_tag = "<a href='".$vs_url."'";
		
		if ($ps_classname) { $vs_tag .= " class='$ps_classname'"; }
		if (is_array($pa_attributes)) {
			$vs_tag .= _caHTMLMakeAttributeString($pa_attributes);
		}
		
		$vs_tag .= '>'.$ps_content.'</a>';
		
		return $vs_tag;
	}
	 
	# ---------------------------------------
	/**
	 * 
	 *
	 * @return string 
	 */
	function caSearchUrl($po_request, $ps_table, $ps_search=null, $pb_return_url_as_pieces=false, $pa_additional_parameters=null, $pa_options=null) {
		$o_dm = Datamodel::load();
		
		if (is_numeric($ps_table)) {
			if (!($t_table = $o_dm->getInstanceByTableNum($ps_table, true))) { return null; }
		} else {
			if (!($t_table = $o_dm->getInstanceByTableName($ps_table, true))) { return null; }
		}
		
		$vb_return_advanced = isset($pa_options['returnAdvanced']) && $pa_options['returnAdvanced'];
		
		switch($ps_table) {
			case 'ca_objects':
			case 57:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchObjectsAdvanced' : 'SearchObjects';
				$vs_action = 'Index';
				break;
			case 'ca_object_lots':
			case 51:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchObjectLotsAdvanced' : 'SearchObjectLots';
				$vs_action = 'Index';
				break;
			case 'ca_object_events':
			case 45:
                $vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchObjectEventsAdvanced' : 'SearchObjectEvents';
				$vs_action = 'Index';
                break;
			case 'ca_entities':
			case 20:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchEntitiesAdvanced' : 'SearchEntities';
				$vs_action = 'Index';
				break;
			case 'ca_places':
			case 72:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchPlacesAdvanced' : 'SearchPlaces';
				$vs_action = 'Index';
				break;
			case 'ca_occurrences':
			case 67:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchOccurrencesAdvanced' : 'SearchOccurrences';
				$vs_action = 'Index';
				break;
			case 'ca_collections':
			case 13:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchCollectionsAdvanced' : 'SearchCollections';
				$vs_action = 'Index';
				break;
			case 'ca_storage_locations':
			case 89:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchStorageLocationsAdvanced' : 'SearchStorageLocations';
				$vs_action = 'Index';
				break;
			case 'ca_list_items':
			case 33:
				$vs_module = 'administrate/setup';
				$vs_controller = ($vb_return_advanced) ? '' : 'Lists';
				$vs_action = 'Index';
				break;
			case 'ca_object_representations':
			case 56:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchObjectRepresentationsAdvanced' : 'SearchObjectRepresentations';
				$vs_action = 'Index';
				break;
			case 'ca_representation_annotations':
			case 82:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchRepresentationAnnotationsAdvanced' : 'SearchRepresentationAnnotations';
				$vs_action = 'Index';
				break;
			case 'ca_relationship_types':
			case 79:
				$vs_module = 'administrate/setup';
				$vs_controller = ($vb_return_advanced) ? '' : 'RelationshipTypes';
				$vs_action = 'Index';
				break;
			case 'ca_loans':
			case 133:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchLoansAdvanced' : 'SearchLoans';
				$vs_action = 'Index';
				break;
			case 'ca_movements':
			case 137:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchMovementsAdvanced' : 'SearchMovements';
				$vs_action = 'Index';
				break;
			case 'ca_tours':
			case 153:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchToursAdvanced' : 'SearchTours';
				$vs_action = 'Index';
				break;
			case 'ca_tour_stops':
			case 155:
				$vs_module = 'find';
				$vs_controller = ($vb_return_advanced) ? 'SearchTourStopsAdvanced' : 'SearchTourStops';
				$vs_action = 'Index';
				break;
			default:
				return null;
				break;
		}
		if ($pb_return_url_as_pieces) {
			return array(
				'module' => $vs_module,
				'controller' => $vs_controller,
				'action' => $vs_action
			);
		} else {
			if (!is_array($pa_additional_parameters)) { $pa_additional_parameters = array(); }
			$pa_additional_parameters = array_merge(array('search' => $ps_search), $pa_additional_parameters);
			return caNavUrl($po_request, $vs_module, $vs_controller, $vs_action, $pa_additional_parameters);
		}
	}
	# ---------------------------------------
	/**
	 * 
	 *
	 * @return array 
	 */
	function caSearchGetAccessPoints($ps_search_expression) {
		if(preg_match("!\b([A-Za-z0-9\-\_]+):!", $ps_search_expression, $va_matches)) {
			array_shift($va_matches);
			return $va_matches;
		}
		return array();
	}
	# ---------------------------------------
	/**
	 * 
	 *
	 * @return array 
	 */
	function caSearchGetTablesForAccessPoints($pa_access_points) {
		$o_config = Configuration::load();
		$o_search_config = Configuration::load($o_config->get("search_config"));
		$o_search_indexing_config = Configuration::load($o_search_config->get("search_indexing_config"));	
			
		$va_tables = $o_search_indexing_config->getAssocKeys();
		
		$va_aps = array();
		foreach($va_tables as $vs_table) {
			$va_config = $o_search_indexing_config->getAssoc($vs_table);
			if(is_array($va_config) && is_array($va_config['_access_points'])) {
				if (array_intersect($pa_access_points, array_keys($va_config['_access_points']))) {
					$va_aps[$vs_table] = true;	
				}
			}
		}
		
		return array_keys($va_aps);
	}
	# ---------------------------------------
	/**
	 * 
	 *
	 * @return Configuration 
	 */
	function caGetSearchConfig() {
		return Configuration::load(__CA_APP_DIR__.'/conf/search.conf');
	}
	# ---------------------------------------
	/**
	 * @param Zend_Search_Lucene_Index_Term $po_term
	 * @return Zend_Search_Lucene_Index_Term
	 */
	function caRewriteElasticSearchTermFieldSpec($po_term) {
		if(strlen($po_term->field) > 0) {
			// rewrite ca_objects.dates.dates_value as ca_objects/dates/dates/value, which is
			// how we index in ElasticSsearch (they don't allow periods in field names)
			$vs_new_field = str_replace('.', '\/', str_replace('/', '|', $po_term->field));

			// rewrite ca_objects/dates/dates_value as ca_objects/dates_value, because that's
			// how the SearchIndexer indexes -- we don't care about the container the field is in
			$va_tmp = explode('\\/', $vs_new_field);
			if(sizeof($va_tmp) == 3) {
				unset($va_tmp[1]);
				$vs_new_field = join('\\/', $va_tmp);
			}
		} else {
			$vs_new_field = $po_term->field;
		}

		return new Zend_Search_Lucene_Index_Term($po_term->text, $vs_new_field);
	}
	# ---------------------------------------
	/**
	 * ElasticSearch won't accept dates where day or month is zero, so we have to
	 * rewrite certain dates, especially when dealing with "open-ended" date ranges,
	 * e.g. "before 1998", "after 2012"
	 *
	 * @param string $ps_date
	 * @param bool $pb_is_start
	 * @return string
	 */
	function caRewriteDateForElasticSearch($ps_date, $pb_is_start=true) {
		// substitute start and end of universe values with ElasticSearch's builtin boundaries
		$ps_date = str_replace(TEP_START_OF_UNIVERSE,"-292275054",$ps_date);
		$ps_date = str_replace(TEP_END_OF_UNIVERSE,"9999",$ps_date);

		if(preg_match("/(\d+)\-(\d+)\-(\d+)T(\d+)\:(\d+)\:(\d+)Z/", $ps_date, $va_date_parts)) {
			// fix large (positive) years
			if(intval($va_date_parts[1]) > 9999) { $va_date_parts[1] = "9999"; }
			// fix month-less dates
			if(intval($va_date_parts[2]) < 1) { $va_date_parts[2]  = ($pb_is_start ?  "01" : "12"); }
			// fix messed up months
			if(intval($va_date_parts[2]) > 12) { $va_date_parts[2] = "12"; }
			// fix day-less dates
			if(intval($va_date_parts[3]) < 1) { $va_date_parts[3]  = ($pb_is_start ?  "01" : "31"); }
			// fix messed up days
			$vn_days_in_month = cal_days_in_month(CAL_GREGORIAN, intval($va_date_parts[2]), intval($va_date_parts[1]));
			if(intval($va_date_parts[3]) > $vn_days_in_month) { $va_date_parts[3] = (string) $vn_days_in_month; }

			// fix hours
			if(intval($va_date_parts[4]) > 23) { $va_date_parts[4] = "23"; }
			if(intval($va_date_parts[4]) < 0) { $va_date_parts[4]  = ($pb_is_start ?  "00" : "23"); }
			// minutes and seconds
			if(intval($va_date_parts[5]) > 59) { $va_date_parts[5] = "59"; }
			if(intval($va_date_parts[5]) < 0) { $va_date_parts[5]  = ($pb_is_start ?  "00" : "59"); }
			if(intval($va_date_parts[6]) > 59) { $va_date_parts[6] = "59"; }
			if(intval($va_date_parts[6]) < 0) { $va_date_parts[6]  = ($pb_is_start ?  "00" : "59"); }

			return "{$va_date_parts[1]}-{$va_date_parts[2]}-{$va_date_parts[3]}T{$va_date_parts[4]}:{$va_date_parts[5]}:{$va_date_parts[6]}Z";
		} else {
			return '';
		}
	}
	# ---------------------------------------
	/**
	 * @param Db $po_db
	 * @param int $pn_table_num
	 * @param int $pn_row_id
	 * @return array
	 */
	function caGetChangeLogForElasticSearch($po_db, $pn_table_num, $pn_row_id) {
		$qr_res = $po_db->query("
				SELECT ccl.log_id, ccl.log_datetime, ccl.changetype, u.user_name
				FROM ca_change_log ccl
				LEFT JOIN ca_users AS u ON ccl.user_id = u.user_id
				WHERE
					(ccl.logged_table_num = ?) AND (ccl.logged_row_id = ?)
					AND
					(ccl.changetype <> 'D')
			", $pn_table_num, $pn_row_id);

		$va_return = array();
		while($qr_res->nextRow()) {
			$vs_change_date = caGetISODates(date("c", $qr_res->get('log_datetime')))['start'];
			if ($qr_res->get('changetype') == 'I') {
				$va_return["created"][] = $vs_change_date;

				if($vs_user = $qr_res->get('user_name')) {
					$vs_user = str_replace('.', '/', $vs_user);
					$va_return["created/{$vs_user}"][] = $vs_change_date;
				}
			} else {
				$va_return["modified"][] = $vs_change_date;

				if($vs_user = $qr_res->get('user_name')) {
					$vs_user = str_replace('.', '/', $vs_user);
					$va_return["modified/{$vs_user}"][] = $vs_change_date;
				}
			}
		}

		return $va_return;
	}
	# ---------------------------------------
	/**
	 * get available sort fields for given table
	 *
	 * @param string $ps_table
	 * @param null|int $pn_type_id
	 * @param array $pa_options Options include:
	 *		includeUserSorts = 
	 *		distinguishNonUniqueNames = 
	 *		allowedSorts = 
	 *		disableSorts = Don't return any available sorts. [Default is false]
	 *		request = The current request. [Default is null]
	 *		includeInterstitialSortsFor = Related table [Default is false]
	 *		distinguishInterstitials = [Default is false]
	 * @return array
	 */
	function caGetAvailableSortFields($ps_table, $pn_type_id = null, $pa_options=null) {
		if (caGetOption('disableSorts', $pa_options, false)) { return []; }
	
		require_once(__CA_MODELS_DIR__ . '/ca_user_sorts.php');
		require_once(__CA_MODELS_DIR__.'/ca_editor_uis.php');
		global $g_ui_locale_id;

		if(is_numeric($ps_table)) {
			$ps_table = $o_dm->getTableName($ps_table);
		}
		$o_dm = Datamodel::load();
		if (!($t_table = $o_dm->getInstanceByTableName($ps_table, true))) { return []; }
		
		$t_rel = null;
		if ($ps_related_table = caGetOption('includeInterstitialSortsFor', $pa_options, null)) {
			$o_dm = Datamodel::load();
			if (is_array($va_path = array_keys($o_dm->getPath($ps_table, $ps_related_table))) && (sizeof($va_path) == 3)) {
				$t_rel = $o_dm->getInstanceByTableName($va_path[1], true);
			}
		} 
		
		$va_ui_bundle_label_map = [];
		if (isset($pa_options['request']) && ($t_ui = ca_editor_uis::loadDefaultUI($ps_table, $pa_options['request'], $pn_type_id))) {
			$va_screens = $t_ui->getScreens();
			foreach($va_screens as $va_screen) {
				if (is_array($va_placements = $t_ui->getScreenBundlePlacements($va_screen['screen_id']))) {
					foreach($va_placements as $va_placement) {
						$vs_bundle_name = str_replace('ca_attribute_', '', $va_placement['bundle_name']);
						$va_bundle_bits = explode('.', $vs_bundle_name);
						if (!$o_dm->tableExists($va_bundle_bits[0])) {
							array_unshift($va_bundle_bits, $ps_table);
							$vs_bundle_name = join('.', $va_bundle_bits);	
						}
						
						if (isset($va_placement['settings']['label'])) {
							$va_ui_bundle_label_map[$vs_bundle_name] = isset($va_placement['settings']['label'][$g_ui_locale_id]) ? $va_placement['settings']['label'][$g_ui_locale_id] : array_shift($va_placement['settings']['label']);
						}
						
					}
				}
				
			}
		}
		switch($ps_table) {
			case 'ca_list_items':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_list_item_labels.name_singular' => _t('name'),
					'ca_list_items.idno_sort' => _t('idno')
				);
				break;
			case 'ca_relationship_types':
				$va_base_fields = array(
					'ca_relationship_type_labels.typename' => _t('type name')
				);
				break;
			case 'ca_collections':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_collection_labels.name_sort' => _t('name'),
					'ca_collections.type_id' => _t('type'),
					'ca_collections.idno_sort' => _t('idno')
				);
				break;
			case 'ca_loans':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_loan_labels.name_sort' => _t('short description'),
					'ca_loans.type_id' => _t('type'),
					'ca_loans.idno_sort' => _t('idno')
				);
				break;
			case 'ca_movements':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_movement_labels.name' => _t('short description'),
					'ca_movements.type_id;ca_movement_labels.name' => _t('type'),
					'ca_movements.idno_sort' => _t('idno')
				);
				break;
			case 'ca_entities':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_entity_labels.name_sort' => _t('display name'),
					'ca_entity_labels.surname;ca_entity_labels.forename' => _t('surname, forename'),
					'ca_entity_labels.forename' => _t('forename'),
					'ca_entities.type_id;ca_entity_labels.surname;ca_entity_labels.forename' => _t('type'),
					'ca_entities.idno_sort' => _t('idno')
				);
				break;
			case 'ca_object_lots':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_object_lot_labels.name_sort' => _t('name'),
					'ca_object_lots.type_id' => _t('type'),
					'ca_object_lots.idno_stub_sort' => _t('idno')
				);
				break;
			case 'ca_object_representations':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_object_representation_labels.name_sort' => _t('name'),
					'ca_object_representations.type_id' => _t('type'),
					'ca_object_representations.idno_sort' => _t('idno')
				);
				break;
			case 'ca_objects':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_object_labels.name_sort' => _t('title'),
					'ca_objects.type_id' => _t('type'),
					'ca_objects.idno_sort' => _t('idno')
				);
				break;
			case 'ca_occurrences':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_occurrence_labels.name_sort' => _t('name'),
					'ca_occurrences.type_id' => _t('type'),
					'ca_occurrences.idno_sort' => _t('idno')
				);
				break;
			case 'ca_places':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_place_labels.name_sort' => _t('name'),
					'ca_places.type_id' => _t('type'),
					'ca_places.idno_sort' => _t('idno')
				);
				break;
			case 'ca_storage_locations':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_storage_locations_labels.name_sort' => _t('name'),
					'ca_storage_locations.type_id' => _t('type'),
					'ca_storage_locations.idno_sort' => _t('idno')
				);
				break;
			case 'ca_tours':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_tour_labels.name' => _t('name')
				);
				break;
			case 'ca_tour_stops':
				$va_base_fields = array(
					'_natural' => _t('relevance'),
					'ca_tour_stop_labels.name' => _t('name')
				);
				break;
			case 'ca_item_comments':
				$va_base_fields = array(
					'ca_item_comments.created_on' => _t('date'),
					'ca_item_comments.user_id' => _t('user')
				);
				break;
			case 'ca_item_tags':
				$va_base_fields = array(
					'ca_items_x_tags.created_on' => _t('date'),
					'ca_items_x_tags.user_id' => _t('user')
				);
				break;
			default:
				$va_base_fields = array();
				break;
		}

		if($ps_table) {
			// add user sorts
			if(caGetOption('includeUserSorts', $pa_options, true)) {
				/** @var RequestHTTP $po_request */
				if(!($po_request = caGetOption('request', $pa_options)) || ($po_request->getUser()->canDoAction('can_use_user_sorts'))) {
					$va_base_fields = array_merge($va_base_fields, ca_user_sorts::getAvailableSortsForTable($ps_table));
				}
			}

			// add sortable elements
			require_once(__CA_MODELS_DIR__ . '/ca_metadata_elements.php');
			$va_sortable_elements = ca_metadata_elements::getSortableElements($ps_table, $pn_type_id);
			foreach($va_sortable_elements as $vn_element_id => $va_sortable_element) {
				$va_base_fields[$ps_table.'.'.$va_sortable_element['element_code']] = $va_sortable_element['display_label'];
			}
			
			
		
			// Add interstitial sorts
			if ($t_rel) {
				$va_sortable_elements = ca_metadata_elements::getSortableElements($vs_relation_table = $t_rel->tableName(), null, ['indexByElementCode' => true]);
				
				$pb_distinguish_interstitials = caGetOption('distinguishInterstitials', $pa_options, true);
				foreach($va_sortable_elements as $vn_element_id => $va_sortable_element) {
					$va_base_fields[$vs_relation_table.'.'.$va_sortable_element['element_code']] = $va_sortable_element['display_label'].($pb_distinguish_interstitials ? " ("._t('Interstitial').")" : "");
				}
			}

			if(caGetOption('distinguishNonUniqueNames', $pa_options, true)) {
				foreach(array_count_values($va_base_fields) as $vn_v => $vn_c) {
					if($vn_c > 1) {
						foreach(array_keys($va_base_fields, $vn_v) as $vs_k) {

							$vs_code = explode('.', $vs_k)[1];


							if(is_array($va_sortable_elements[$vs_code]['typeRestrictions'])) {
								$va_restrictions = [];
								foreach($va_sortable_elements[$vs_code]['typeRestrictions'] as $vs_table => $va_type_list) {
									foreach($va_type_list as $vn_type_id => $vs_type_name) {
										$va_restrictions[] = ucfirst($vs_table)." [{$vs_type_name}]";
									}
								}

								$va_base_fields[$vs_k] .= ' (' . join('; ', $va_restrictions) . ')';
							} elseif($vn_parent_id = $va_sortable_elements[$vs_code]['parent_id']) {

								$t_parent = new ca_metadata_elements();
								while($vn_parent_id) {
									$t_parent->load($vn_parent_id);
									$vn_parent_id = $t_parent->get('parent_id');
								}

								if($t_parent->getPrimaryKey()) {
									$va_base_fields[$vs_k] .= ' (' . $t_parent->getLabelForDisplay() . ')';
								}
							}


						}
					}
				}
			}
		}
		
		if (($pa_allowed_sorts = caGetOption('allowedSorts', $pa_options, null)) && !is_array($pa_allowed_sorts)) { $pa_allowed_sorts = [$pa_allowed_sorts]; }
		
		if(is_array($pa_allowed_sorts) && sizeof($pa_allowed_sorts) > 0) {
			foreach($va_base_fields as $vs_k => $vs_v) {
				if (!in_array($vs_k, $pa_allowed_sorts)) { unset($va_base_fields[$vs_k]); }
			}
		}
		
		foreach($va_base_fields as $vs_k => $vs_v) {
			if (isset($va_ui_bundle_label_map[$vs_k])) {
				 $va_base_fields[$vs_k] = $va_ui_bundle_label_map[$vs_k];
			} elseif(sizeof($va_tmp = explode('.', $vs_k)) > 2) {
				array_pop($va_tmp);
				if (isset($va_ui_bundle_label_map[join('.', $va_tmp)])) { $va_base_fields[$vs_k] = $va_ui_bundle_label_map[join('.', $va_tmp)]; }
			}
		}
		
		$va_base_fields = array_map(function($v) { return caUcFirstUTF8Safe($v); }, $va_base_fields);
		
		natcasesort($va_base_fields);
		
		return array_merge(['_natural' => _t('Relevance')], $va_base_fields);
	}
	# ---------------------------------------
	/**
	 * Get given sort fields (semi-colon separated list from ResultContext) for display,
	 * i.e. as array of human readable names
	 * @param string $ps_table
	 * @param array $ps_sort_fields
	 * @return string
	 */
	function caGetSortForDisplay($ps_table, $ps_sort_fields) {
		$va_sort_fields = explode(';', $ps_sort_fields);

		$va_available_sorts = caGetAvailableSortFields($ps_table, null, ['includeUserSorts' => false]);

		$va_return = [];
		foreach($va_sort_fields as $vs_sort_field) {
			if(isset($va_available_sorts[$vs_sort_field])) {
				$va_return[] = $va_available_sorts[$vs_sort_field];
			}
		}

		return $va_return;
	}
	# ---------------------------------------
	/**
	 *
	 */
	function caSearchIsForSets($ps_search, $pa_options=null) {
		$o_config = Configuration::load();
		$o_query_parser = new LuceneSyntaxParser();
		$o_query_parser->setEncoding($o_config->get('character_set'));
		$o_query_parser->setDefaultOperator(LuceneSyntaxParser::B_AND);
		
		$ps_search = preg_replace('![\']+!', '', $ps_search);
		try {
			$o_parsed_query = $o_query_parser->parse($ps_search, $vs_char_set);
		} catch (Exception $e) {
			// Retry search with all non-alphanumeric characters removed
			try {
				$o_parsed_query = $o_query_parser->parse(preg_replace("![^A-Za-z0-9 ]+!", " ", $ps_search), $vs_char_set);
			} catch (Exception $e) {
				$o_parsed_query = $o_query_parser->parse("", $vs_char_set);
			}
		}
		
		switch(get_class($o_parsed_query)) {
			case 'Zend_Search_Lucene_Search_Query_Boolean':
				$va_items = $o_parsed_query->getSubqueries();
				$va_signs = $o_parsed_query->getSigns();
				break;
			case 'Zend_Search_Lucene_Search_Query_MultiTerm':
				$va_items = $o_parsed_query->getTerms();
				$va_signs = $o_parsed_query->getSigns();
				break;
			case 'Zend_Search_Lucene_Search_Query_Phrase':
			case 'Zend_Search_Lucene_Search_Query_Range':
				$va_items = $o_parsed_query;
				break;
			default:
				return false;
				break;
		}

		$va_sets = [];
		foreach ($va_items as $id => $subquery) {
			switch(get_class($subquery)) {
				case 'Zend_Search_Lucene_Search_Query_Phrase':
				
					foreach($subquery->getQueryTerms() as $o_term) {
						$vs_field = $o_term->field;
						$vs_value = $o_term->text;
						
						if ($vs_field == 'ca_sets.set_id') {
							$va_sets[(int)$vs_value] = 1;
						} elseif((in_array($vs_field, ['ca_sets.set_code', 'set'])) && ($vn_set_id = ca_sets::find(['set_code' => $vs_value], ['returnAs' => 'firstId'])))  {
							$va_sets[(int)$vn_set_id] = 1;
						}
					}
					
					break;
				case 'Zend_Search_Lucene_Index_Term':
					$subquery = new Zend_Search_Lucene_Search_Query_Term($subquery);
					// intentional fallthrough to next case here
				case 'Zend_Search_Lucene_Search_Query_Term':
					$vs_field = $subquery->getTerm()->field;
					$vs_value = $subquery->getTerm()->text;
					
					if ($vs_field == 'ca_sets.set_id') {
						$va_sets[(int)$vs_value] = 1;
					} elseif((in_array($vs_field, ['ca_sets.set_code', 'set'])) && ($vn_set_id = ca_sets::find(['set_code' => $vs_value], ['returnAs' => 'firstId'])))  {
						$va_sets[(int)$vn_set_id] = 1;
					}
					
					break;	
				case 'Zend_Search_Lucene_Search_Query_Range':
				case 'Zend_Search_Lucene_Search_Query_Wildcard':
					// noop
					break;
				case 'Zend_Search_Lucene_Search_Query_Boolean':
					foreach($subquery->getSubqueries() as $o_term) {
						if (is_array($va_sub_sets = caSearchIsForSets($o_term))) {
							$va_sets = array_merge($va_sets, $va_sub_sets);
						}
					}
					break;
				default:
					if (is_array($va_sub_sets = caSearchIsForSets($subquery))) {
						$va_sets = array_merge($va_sets, $va_sub_sets);
					}
					break;
			}
		}
		
		if(sizeof($va_sets) == 0) { return false; }
		$t_set = new ca_sets();
		return $t_set->getPreferredDisplayLabelsForIDs(array_keys($va_sets));
	}
	# ---------------------------------------