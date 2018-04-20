<?php
trait HierarchicalModelTrait {
    # --------------------------------------------------------------------------------------------
	# --- Hierarchical functions
	# --------------------------------------------------------------------------------------------
	
	# --------------------------------------------------------------------------------------------
	/**
	 * What type of hierarchical structure is used by this table?
	 * 
	 * @access public
	 * @return int (__CA_HIER_*__ constant)
	 */
	public function getHierarchyType() {
		return $this->getProperty("HIERARCHY_TYPE");
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Fetches primary key of the hierarchy root.
	 * DOES NOT CREATE ROOT - YOU HAVE TO DO THAT YOURSELF (this differs from previous versions of these libraries).
	 * 
	 * @param int $pn_hierarchy_id optional, points to record in related table containing hierarchy description
	 * @return int root id
	 */
	public function getHierarchyRootID($pn_hierarchy_id=null) {
		if (!$this->isHierarchical()) { return null; }
		$vn_root_id = null;
		
		$o_db = $this->getDb();
		switch($this->getHierarchyType()) {
			# ------------------------------------------------------------------
			case __CA_HIER_TYPE_SIMPLE_MONO__:
				// For simple "table is one big hierarchy" setups all you need
				// to do is look for the row where parent_id is NULL
				$qr_res = $o_db->query("
					SELECT ".$this->primaryKey()." 
					FROM ".$this->tableName()." 
					WHERE 
						(".$this->getProperty('HIERARCHY_PARENT_ID_FLD')." IS NULL)
				");
				if ($qr_res->nextRow()) {
					$vn_root_id = $qr_res->get($this->primaryKey());
				}
				break;
			# ------------------------------------------------------------------
			case __CA_HIER_TYPE_MULTI_MONO__:
				// For tables that house multiple hierarchies defined in a second table
				// you need to look for the row where parent_id IS NULL and hierarchy_id = the value
				// passed in $pn_hierarchy_id
				
				if (!$pn_hierarchy_id) {	// if hierarchy_id is not explicitly set use the value in the currently loaded row
					$pn_hierarchy_id = $this->get($this->getProperty('HIERARCHY_ID_FLD'));
				}
				
				$qr_res = $o_db->query("
					SELECT ".$this->primaryKey()." 
					FROM ".$this->tableName()." 
					WHERE 
						(".$this->getProperty('HIERARCHY_PARENT_ID_FLD')." IS NULL)
						AND
						(".$this->getProperty('HIERARCHY_ID_FLD')." = ?)
				", (int)$pn_hierarchy_id);
				if ($qr_res->nextRow()) {
					$vn_root_id = $qr_res->get($this->primaryKey());
				}
				break;
			# ------------------------------------------------------------------
			case __CA_HIER_TYPE_ADHOC_MONO__:
				// For ad-hoc hierarchies you just return the hierarchy_id value
				if (!$pn_hierarchy_id) {	// if hierarchy_id is not explicitly set use the value in the currently loaded row
					$pn_hierarchy_id = $this->get($this->getProperty('HIERARCHY_ID_FLD'));
				}
				$vn_root_id = $pn_hierarchy_id;
				break;
			# ------------------------------------------------------------------
			case __CA_HIER_TYPE_MULTI_POLY__:
				// TODO: implement this
				
				break;
			# ------------------------------------------------------------------
			default:
				die("Invalid hierarchy type: ".$this->getHierarchyType());
				break;
			# ------------------------------------------------------------------
		}
		
		return $vn_root_id;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Fetch a DbResult representation of the whole hierarchy
	 * 
	 * @access public
	 * @param int $pn_id optional, id of record to be treated as root
	 * @param array $pa_options
	 *		returnDeleted = return deleted records in list [default: false]
	 *		additionalTableToJoin = name of table to join to hierarchical table (and return fields from); only fields related many-to-one are currently supported
	 *		idsOnly = return simple array of primary key values for child records rather than full result
	 *      sort = 
	 *
	 * @return Mixed DbResult or array
	 */
	public function &getHierarchy($pn_id=null, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		if (!is_array($pa_options)) { $pa_options = array(); }
		$vs_table_name = $this->tableName();
		
		$t_instance = (!$pn_id) ? $this : Datamodel::getInstanceByTableNum($this->tableNum(), false);
		if (!$pn_id && $this->inTransaction()) { $t_instance->setTransaction($this->getTransaction()); }
		
		if ($this->isHierarchical()) {
			$vs_hier_left_fld 		= $this->getProperty("HIERARCHY_LEFT_INDEX_FLD");
			$vs_hier_right_fld 		= $this->getProperty("HIERARCHY_RIGHT_INDEX_FLD");
			$vs_hier_parent_id_fld	= $this->getProperty("HIERARCHY_PARENT_ID_FLD");
			$vs_hier_id_fld 		= $this->getProperty("HIERARCHY_ID_FLD");
			$vs_hier_id_table 		= $this->getProperty("HIERARCHY_DEFINITION_TABLE");
			
			if (!($vs_rank_fld = caGetOption('sort', $pa_options, $this->getProperty('RANK'))) || !$this->hasField($vs_rank_fld)) { $vs_rank_fld = $vs_hier_left_fld; }
		
			if (!$pn_id) {
				if (!($pn_id = $t_instance->getHierarchyRootID($t_instance->get($vs_hier_id_fld)))) {
					return null;
				}
			}
			
			$vs_hier_id_sql = "";
			if ($vs_hier_id_fld) {
				$vn_hierarchy_id = $t_instance->get($vs_hier_id_fld);
				if ($vn_hierarchy_id) {
					// TODO: verify hierarchy_id exists
					$vs_hier_id_sql = " AND (".$vs_hier_id_fld." = ".$vn_hierarchy_id.")";
				}
			}
			
			
			$o_db = $this->getDb();
			$qr_root = $o_db->query("
				SELECT $vs_hier_left_fld, $vs_hier_right_fld ".(($this->hasField($vs_hier_id_fld)) ? ", $vs_hier_id_fld" : "")."
				FROM ".$this->tableName()."
				WHERE
					(".$this->primaryKey()." = ?)		
			", intval($pn_id));
			if ($o_db->numErrors()) {
				$this->errors = array_merge($this->errors, $o_db->errors());
				return null;
			} else {
				if ($qr_root->nextRow()) {
					
					$va_count = array();
					if (($this->hasField($vs_hier_id_fld)) && (!($vn_hierarchy_id = $t_instance->get($vs_hier_id_fld))) && (!($vn_hierarchy_id = $qr_root->get($vs_hier_id_fld)))) {
						$this->postError(2030, _t("Hierarchy ID must be specified"), "BaseModel->getHierarchy()");
						return false;
					}
					
					$vs_table_name = $this->tableName();
					
					$vs_hier_id_sql = "";
					if ($vn_hierarchy_id) {
						$vs_hier_id_sql = " AND ({$vs_table_name}.{$vs_hier_id_fld} = {$vn_hierarchy_id})";
					}
					
					$va_sql_joins = array();
					if (isset($pa_options['additionalTableToJoin']) && ($pa_options['additionalTableToJoin'])){ 
						$ps_additional_table_to_join = $pa_options['additionalTableToJoin'];
						
						// what kind of join are we doing for the additional table? LEFT or INNER? (default=INNER)
						$ps_additional_table_join_type = 'INNER';
						if (isset($pa_options['additionalTableJoinType']) && ($pa_options['additionalTableJoinType'] === 'LEFT')) {
							$ps_additional_table_join_type = 'LEFT';
						}
						if (is_array($va_rel = Datamodel::getOneToManyRelations($vs_table_name, $ps_additional_table_to_join))) {
							// one-many rel
							$va_sql_joins[] = "{$ps_additional_table_join_type} JOIN {$ps_additional_table_to_join} ON {$vs_table_name}".'.'.$va_rel['one_table_field']." = {$ps_additional_table_to_join}.".$va_rel['many_table_field'];
						} else {
							// TODO: handle many-many cases
						}
						
						// are there any SQL WHERE criteria for the additional table?
						$va_additional_table_wheres = null;
						if (isset($pa_options['additionalTableWheres']) && is_array($pa_options['additionalTableWheres'])) {
							$va_additional_table_wheres = $pa_options['additionalTableWheres'];
						}
						
						$vs_additional_wheres = '';
						if (is_array($va_additional_table_wheres) && (sizeof($va_additional_table_wheres) > 0)) {
							$vs_additional_wheres = ' AND ('.join(' AND ', $va_additional_table_wheres).') ';
						}
					}
					
			
					$vs_deleted_sql = '';
					if ($this->hasField('deleted') && (!isset($pa_options['returnDeleted']) || (!$pa_options['returnDeleted']))) {
						$vs_deleted_sql = " AND ({$vs_table_name}.deleted = 0)";
					}
					$vs_sql_joins = join("\n", $va_sql_joins);
					
					$vs_sql = "
						SELECT * 
						FROM {$vs_table_name}
						{$vs_sql_joins}
						WHERE
							({$vs_table_name}.{$vs_hier_left_fld} BETWEEN ".$qr_root->get($vs_hier_left_fld)." AND ".$qr_root->get($vs_hier_right_fld).")
							{$vs_hier_id_sql}
							{$vs_deleted_sql}
							{$vs_additional_wheres}
						ORDER BY
							{$vs_table_name}.{$vs_rank_fld}
					";
					//print $vs_sql;
					$qr_hier = $o_db->query($vs_sql);
					
					if ($o_db->numErrors()) {
						$this->errors = array_merge($this->errors, $o_db->errors());
						return null;
					} else {
						if (caGetOption('idsOnly', $pa_options, false)) {
							return $qr_hier->getAllFieldValues($this->primaryKey());
						}
						return $qr_hier;
					}
				} else {
					return null;
				}
			}
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Get the hierarchy in list form
	 * 
	 * @param int $pn_id 
	 * @param array $pa_options
	 *
	 *		additionalTableToJoin: name of table to join to hierarchical table (and return fields from); only fields related many-to-one are currently supported
	 *		idsOnly = return simple array of primary key values for child records rather than full data array
	 *		returnDeleted = return deleted records in list (def. false)
	 *		maxLevels = 
	 *		dontIncludeRoot = 
	 *		includeSelf = 
	 * 
	 * @return array
	 */
	public function &getHierarchyAsList($pn_id=null, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		$pb_ids_only 					= caGetOption('idsOnly', $pa_options, false);
		$pn_max_levels 					= caGetOption('maxLevels', $pa_options, null, array('cast' => 'int'));
		$ps_additional_table_to_join 	= caGetOption('additionalTableToJoin', $pa_options, null);
		$pb_dont_include_root 			= caGetOption('dontIncludeRoot', $pa_options, false);
		$pb_include_self 				= caGetOption('includeSelf', $pa_options, false);
		
		if ($pn_id && $pb_include_self) { $pb_dont_include_root = false; }
		
		if ($qr_hier = $this->getHierarchy($pn_id, $pa_options)) {
			if ($pb_ids_only) { 
				if (!$pb_include_self || $pb_dont_include_root) {
					if(($vn_i = array_search($pn_id, $qr_hier)) !== false) {
						unset($qr_hier[$vn_i]);
					}
				}
				return $qr_hier; 
			}
			$vs_hier_right_fld 			= $this->getProperty("HIERARCHY_RIGHT_INDEX_FLD");
			$vs_parent_id_fld 			= $this->getProperty("HIERARCHY_PARENT_ID_FLD");
			
			$va_indent_stack = $va_hier = $va_parent_map = [];
			
			$vn_cur_level = -1;
			
			$vn_root_id = $pn_id;
		
			while($qr_hier->nextRow()) {
			    $vn_row_id = $qr_hier->get($this->primaryKey());
				
				$vn_parent_id = $qr_hier->get($vs_parent_id_fld);
				
				if(!$vn_parent_id) {
			        $vn_cur_level = 0;
			        $va_parent_map[$vn_row_id] = ['level' =>  1];
				} elseif (!isset($va_parent_map[$vn_parent_id])) {
				    $va_parent_map[$vn_parent_id] = ['level' => $vn_cur_level + 1];
				    $vn_cur_level++;
				} else {
				    $vn_cur_level =  $va_parent_map[$vn_parent_id]['level'];
				}
				if (!isset($va_parent_map[$vn_row_id])) {
					$va_parent_map[$vn_row_id] = ['level' => $vn_cur_level + 1];
				}
			}
			
			$qr_hier->seek(0);
			while($qr_hier->nextRow()) {
				$vn_row_id = $qr_hier->get($this->primaryKey());
				if (is_null($vn_root_id)) { $vn_root_id = $vn_row_id; }
				
				if ($pb_dont_include_root && ($vn_row_id == $vn_root_id)) { continue; } // skip root if desired
				
				$vn_parent_id = $qr_hier->get($vs_parent_id_fld);
				
                $vn_cur_level =  $va_parent_map[$vn_parent_id]['level'];
                
				$vn_r = $qr_hier->get($vs_hier_right_fld);
				$vn_c = sizeof($va_indent_stack);
				
				if (is_null($pn_max_levels) || ($vn_cur_level < $pn_max_levels)) {
					$va_field_values = $qr_hier->getRow();
					foreach($va_field_values as $vs_key => $vs_val) {
						$va_field_values[$vs_key] = stripSlashes($vs_val);
					}
					if ($pb_ids_only) {					
						$va_hier[] = $vn_row_id;
					} else {
						$va_node = array(
							"NODE" => $va_field_values,
							"LEVEL" => $vn_cur_level
						);					
						$va_hier[] = $va_node;
					}

				}
				$va_indent_stack[] = $vn_r;
			}
			return $va_hier;
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Returns a list of primary keys comprising all child rows
	 * 
	 * @param int $pn_id node to start from - default is the hierarchy root
	 * @param array $pa_options
	 * @return array id list
	 */
	public function getHierarchyIDs($pn_id=null, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		if(!is_array($pa_options)) { $pa_options = array(); }
		return $this->getHierarchyAsList($pn_id, array_merge($pa_options, array('idsOnly' => true)));
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Returns number of rows in the hierarchy
	 * 
	 * @param int $pn_id node to start from - default is the hierarchy root
	 * @param array $pa_options
	 * @return int count
	 */
	public function getHierarchySize($pn_id=null, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		
		$vs_hier_left_fld 		= $this->getProperty("HIERARCHY_LEFT_INDEX_FLD");
		$vs_hier_right_fld 		= $this->getProperty("HIERARCHY_RIGHT_INDEX_FLD");
		$vs_hier_id_fld 		= $this->getProperty("HIERARCHY_ID_FLD");
		$vs_hier_id_table 		= $this->getProperty("HIERARCHY_DEFINITION_TABLE");
		$vs_hier_parent_id_fld 	= $this->getProperty("HIERARCHY_PARENT_ID_FLD");
		
		$o_db = $this->getDb();
		
		$va_params = array();
		
		$t_instance = null;
		if ($pn_id && ($pn_id != $this->getPrimaryKey())) {
			$t_instance = Datamodel::getInstanceByTableName($this->tableName());
			if (!$t_instance->load($pn_id)) { return null; }
		} else {
			$t_instance = $this;
		}
	
		if ($pn_id > 0) {
			$va_params[] = (float)$t_instance->get($vs_hier_left_fld);
			$va_params[] = (float)$t_instance->get($vs_hier_right_fld);
		}
		if($vs_hier_id_fld) {
			$va_params[] = (int)$t_instance->get($vs_hier_id_fld);
		}
		
		$qr_res = $o_db->query("
			SELECT count(*) c 
			FROM ".$this->tableName()."
			WHERE
				".(($pn_id > 0) ? "({$vs_hier_left_fld} >= ?) AND ({$vs_hier_right_fld} <= ?) " : '').
				($vs_hier_id_fld ? ' '.(($pn_id > 0) ? ' AND ' : '')."({$vs_hier_id_fld} = ?)" : '').
				($this->hasField('deleted') ? ' '.(($vs_hier_id_fld) ? ' AND ' : '')."(deleted = 0)" : '')
				."
		", $va_params);
	
		if ($qr_res->nextRow()) {
			return (int)$qr_res->get('c');
		}
		return null;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Count child rows for specified parent rows
	 *
	 * @param array list of primary keys for which to fetch child counts
	 * @param array, optional associative array of options. Valid keys for the array are:
	 *		returnDeleted = return deleted records in list [Default is false]
	 * @return array List of counts key'ed on primary key values
	 */
	public function getHierarchyChildCountsForIDs($pa_ids, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		$va_additional_table_wheres = array();
		
		if(!is_array($pa_ids)) { 
			if (!$pa_ids) {
				return null; 
			} else {
				$pa_ids = array($pa_ids);
			}
		}
		
		if (!sizeof($pa_ids)) { return array(); }
		
		$o_db = $this->getDb();
		$vs_table_name = $this->tableName();
		$vs_pk = $this->primaryKey();
		
		foreach($pa_ids as $vn_i => $vn_id) {
			$pa_ids[$vn_i] = (int)$vn_id;
		}
		
		if ($this->hasField('deleted') && (!isset($pa_options['returnDeleted']) || (!$pa_options['returnDeleted']))) {
			$va_additional_table_wheres[] = "({$vs_table_name}.deleted = 0)";
		}
		
		
		$qr_res = $o_db->query("
			SELECT parent_id,  count(*) c
			FROM {$vs_table_name}
			WHERE
				parent_id IN (?) ".((sizeof($va_additional_table_wheres)) ? " AND ".join(" AND ", $va_additional_table_wheres) : "")."
			GROUP BY parent_id
		", array($pa_ids));
		
		$va_counts = array();
		while($qr_res->nextRow()) {
			$va_counts[(int)$qr_res->get('parent_id')] = (int)$qr_res->get('c');
		}
		return $va_counts;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Get *direct* child records for currently loaded record or one specified by $pn_id
	 * Note that this only returns direct children, *NOT* children of children and further descendents
	 * If you need to get a chunk of the hierarchy use getHierarchy()
	 * 
	 * @access public
	 * @param int optional, primary key value of a record.
	 * 	Use this if you want to know the children of a record different than $this
	 * @param array, optional associative array of options. Valid keys for the array are:
	 *		additionalTableToJoin: name of table to join to hierarchical table (and return fields from); only fields related many-to-one are currently supported
	 *		returnChildCounts: if true, the number of children under each returned child is calculated and returned in the result set under the column name 'child_count'. Note that this count is always at least 1, even if there are no children. The 'has_children' column will be null if the row has, in fact no children, or non-null if it does have children. You should check 'has_children' before using 'child_count' and disregard 'child_count' if 'has_children' is null.
	 *		returnDeleted = return deleted records in list (def. false)
	 *		sort =
	 * @return DbResult 
	 */
	public function &getHierarchyChildrenAsQuery($pn_id=null, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		$o_db = $this->getDb();
			
		$vs_table_name = $this->tableName();
		
		// return counts of child records for each child found?
		$pb_return_child_counts = isset($pa_options['returnChildCounts']) ? true : false;
		
		$va_additional_table_wheres = array();
		$va_additional_table_select_fields = array();
			
		// additional table to join into query?
		$ps_additional_table_to_join = isset($pa_options['additionalTableToJoin']) ? $pa_options['additionalTableToJoin'] : null;
		if ($ps_additional_table_to_join) {		
			// what kind of join are we doing for the additional table? LEFT or INNER? (default=INNER)
			$ps_additional_table_join_type = 'INNER';
			if (isset($pa_options['additionalTableJoinType']) && ($pa_options['additionalTableJoinType'] === 'LEFT')) {
				$ps_additional_table_join_type = 'LEFT';
			}
			
			// what fields from the additional table are we going to return?
			if (isset($pa_options['additionalTableSelectFields']) && is_array($pa_options['additionalTableSelectFields'])) {
				foreach($pa_options['additionalTableSelectFields'] as $vs_fld) {
					$va_additional_table_select_fields[] = "{$ps_additional_table_to_join}.{$vs_fld}";
				}
			}
			
			// are there any SQL WHERE criteria for the additional table?
			if (isset($pa_options['additionalTableWheres']) && is_array($pa_options['additionalTableWheres'])) {
				$va_additional_table_wheres = $pa_options['additionalTableWheres'];
			}
		}
			
		$va_additional_child_join_conditions = array();
		if ($this->hasField('deleted') && (!isset($pa_options['returnDeleted']) || (!$pa_options['returnDeleted']))) {
			$va_additional_table_wheres[] = "({$vs_table_name}.deleted = 0)";
			$va_additional_child_join_conditions[] = "p2.deleted = 0";
		}
		
		if ($this->isHierarchical()) {
			if (!$pn_id) {
				if (!($pn_id = $this->getPrimaryKey())) {
					return null;
				}
			}
					
			$va_sql_joins = array();
			$vs_additional_table_to_join_group_by = '';
			if ($ps_additional_table_to_join){ 
				if (is_array($va_rel = Datamodel::getOneToManyRelations($this->tableName(), $ps_additional_table_to_join))) {
					// one-many rel
					$va_sql_joins[] = $ps_additional_table_join_type." JOIN {$ps_additional_table_to_join} ON ".$this->tableName().'.'.$va_rel['one_table_field']." = {$ps_additional_table_to_join}.".$va_rel['many_table_field'];
				} else {
					// TODO: handle many-many cases
				}
				
				$t_additional_table_to_join = Datamodel::getInstanceByTableName($ps_additional_table_to_join);
				$vs_additional_table_to_join_group_by = ', '.$ps_additional_table_to_join.'.'.$t_additional_table_to_join->primaryKey();
			}
			$vs_sql_joins = join("\n", $va_sql_joins);
			
			$vs_hier_parent_id_fld = $this->getProperty("HIERARCHY_PARENT_ID_FLD");
			
			// Try to set explicit sort
			if (isset($pa_options['sort']) && $pa_options['sort']) {
				if (!is_array($pa_options['sort'])) { $pa_options['sort'] = array($pa_options['sort']); }
				$va_order_bys = array();
				foreach($pa_options['sort'] as $vs_sort_fld) {
					$va_sort_tmp = explode(".", $vs_sort_fld);
				
					switch($va_sort_tmp[0]) {
						case $this->tableName():
							if ($this->hasField($va_sort_tmp[1])) {
								$va_order_bys[] = $vs_sort_fld;
							}
							break;
						case $ps_additional_table_to_join:
							if ($t_additional_table_to_join->hasField($va_sort_tmp[1])) {
								$va_order_bys[] = $vs_sort_fld;
							}
							break;
					}
				}
				$vs_order_by = join(", ", $va_order_bys);
			} 
			
			// Fall back to default sorts if no explicit sort
			if (!$vs_order_by) {
				if ($vs_rank_fld = $this->getProperty('RANK')) { 
					$vs_order_by = $this->tableName().'.'.$vs_rank_fld;
				} else {
					$vs_order_by = $this->tableName().".".$this->primaryKey();
				}
			}
			
			if ($pb_return_child_counts) {
				$vs_additional_child_join_conditions = sizeof($va_additional_child_join_conditions) ? " AND ".join(" AND ", $va_additional_child_join_conditions) : "";
				$qr_hier = $o_db->query("
					SELECT ".$this->tableName().".* ".(sizeof($va_additional_table_select_fields) ? ', '.join(', ', $va_additional_table_select_fields) : '').", count(*) child_count, sum(p2.".$this->primaryKey().") has_children
					FROM ".$this->tableName()."
					{$vs_sql_joins}
					LEFT JOIN ".$this->tableName()." AS p2 ON p2.".$vs_hier_parent_id_fld." = ".$this->tableName().".".$this->primaryKey()." {$vs_additional_child_join_conditions}
					WHERE
						(".$this->tableName().".{$vs_hier_parent_id_fld} = ?) ".((sizeof($va_additional_table_wheres) > 0) ? ' AND '.join(' AND ', $va_additional_table_wheres) : '')."
					GROUP BY
						".$this->tableName().".".$this->primaryKey()." {$vs_additional_table_to_join_group_by}
					ORDER BY
						".$vs_order_by."
				", (int)$pn_id);
			} else {
				$qr_hier = $o_db->query("
					SELECT ".$this->tableName().".* ".(sizeof($va_additional_table_select_fields) ? ', '.join(', ', $va_additional_table_select_fields) : '')."
					FROM ".$this->tableName()."
					{$vs_sql_joins}
					WHERE
						(".$this->tableName().".{$vs_hier_parent_id_fld} = ?) ".((sizeof($va_additional_table_wheres) > 0) ? ' AND '.join(' AND ', $va_additional_table_wheres) : '')."
					ORDER BY
						".$vs_order_by."
				", (int)$pn_id);
			}
			if ($o_db->numErrors()) {
				$this->errors = array_merge($this->errors, $o_db->errors());
				return null;
			} else {
				return $qr_hier;
			}
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Get *direct* child records for currently loaded record or one specified by $pn_id
	 * Note that this only returns direct children, *NOT* children of children and further descendents
	 * If you need to get a chunk of the hierarchy use getHierarchy().
	 *
	 * Results are returned as an array with either associative array values for each child record, or if the
	 * idsOnly option is set, then the primary key values.
	 * 
	 * @access public
	 * @param int optional, primary key value of a record.
	 * 	Use this if you want to know the children of a record different than $this
	 * @param array, optional associative array of options. Valid keys for the array are:
	 *		additionalTableToJoin: name of table to join to hierarchical table (and return fields from); only fields related many-to-one are currently supported
	 *		returnChildCounts: if true, the number of children under each returned child is calculated and returned in the result set under the column name 'child_count'. Note that this count is always at least 1, even if there are no children. The 'has_children' column will be null if the row has, in fact no children, or non-null if it does have children. You should check 'has_children' before using 'child_count' and disregard 'child_count' if 'has_children' is null.
	 *		idsOnly: if true, only the primary key id values of the children records are returned
	 *		returnDeleted = return deleted records in list [default=false]
	 *		start = Offset to start returning records from [default=0; no offset]
	 *		limit = Maximum number of records to return [default=null; no limit]
	 *
	 * @return array 
	 */
	public function getHierarchyChildren($pn_id=null, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		$pb_ids_only = (isset($pa_options['idsOnly']) && $pa_options['idsOnly']) ? true : false;
		$pn_start = caGetOption('start', $pa_options, 0);
		$pn_limit = caGetOption('limit', $pa_options, 0);
		
		if (!$pn_id) { $pn_id = $this->getPrimaryKey(); }
		if (!$pn_id) { return null; }
		$qr_children = $this->getHierarchyChildrenAsQuery($pn_id, $pa_options);
		
		if ($pn_start > 0) { $qr_children->seek($pn_start); }
		
		$va_children = array();
		$vs_pk = $this->primaryKey();
		
		$vn_c = 0;
		while($qr_children->nextRow()) {
			if ($pb_ids_only) {
				$va_row = $qr_children->getRow();
				$va_children[] = $va_row[$vs_pk];
			} else {
				$va_children[] = $qr_children->getRow();
			}
			$vn_c++;
			if (($pn_limit > 0) && ($vn_c >= $pn_limit)) { break;}
		}
		
		return $va_children;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Get "siblings" records - records with the same parent - as the currently loaded record
	 * or the record with its primary key = $pn_id
	 *
	 * Results are returned as an array with either associative array values for each sibling record, or if the
	 * idsOnly option is set, then the primary key values.
	 * 
	 * @access public
	 * @param int optional, primary key value of a record.
	 * 	Use this if you want to know the siblings of a record different than $this
	 * @param array, optional associative array of options. Valid keys for the array are:
	 *		additionalTableToJoin: name of table to join to hierarchical table (and return fields from); only fields related many-to-one are currently supported
	 *		returnChildCounts: if true, the number of children under each returned sibling is calculated and returned in the result set under the column name 'sibling_count'.d
	 *		idsOnly: if true, only the primary key id values of the chidlren records are returned
	 *		returnDeleted = return deleted records in list (def. false)
	 * @return array 
	 */
	public function getHierarchySiblings($pn_id=null, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		$pb_ids_only = (isset($pa_options['idsOnly']) && $pa_options['idsOnly']) ? true : false;
		
		if (!$pn_id) { $pn_id = $this->getPrimaryKey(); }
		if (!$pn_id) { return null; }
		
		$vs_table_name = $this->tableName();
		
		$va_additional_table_wheres = array($this->primaryKey()." = ?");
		if ($this->hasField('deleted') && (!isset($pa_options['returnDeleted']) || (!$pa_options['returnDeleted']))) {
			$va_additional_table_wheres[] = "({$vs_table_name}.deleted = 0)";
		}
		
		// convert id into parent_id - get the children of the parent is equivalent to getting the siblings for the id
		if ($qr_parent = $this->getDb()->query("
			SELECT ".$this->getProperty('HIERARCHY_PARENT_ID_FLD')." 
			FROM ".$this->tableName()." 
			WHERE ".join(' AND ', $va_additional_table_wheres), (int)$pn_id)) {
			if ($qr_parent->nextRow()) {
				$pn_id = $qr_parent->get($this->getProperty('HIERARCHY_PARENT_ID_FLD'));
			} else {
				$this->postError(250, _t('Could not get parent_id to load siblings by: %1', join(';', $this->getDb()->getErrors())), 'BaseModel->getHierarchySiblings');
				return false;
			}
		} else {
			$this->postError(250, _t('Could not get hierarchy siblings: %1', join(';', $this->getDb()->getErrors())), 'BaseModel->getHierarchySiblings');
			return false;
		}
		if (!$pn_id) { return array(); }
		$qr_children = $this->getHierarchyChildrenAsQuery($pn_id, $pa_options);
		
		
		$va_siblings = array();
		$vs_pk = $this->primaryKey();
		while($qr_children->nextRow()) {
			if ($pb_ids_only) {
				$va_row = $qr_children->getRow();
				$va_siblings[] = $va_row[$vs_pk];
			} else {
				$va_siblings[] = $qr_children->getRow();
			}
		}
		
		return $va_siblings;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Get hierarchy ancestors
	 * 
	 * @access public
	 * @param int optional, primary key value of a record.
	 * 	Use this if you want to know the ancestors of a record different than $this
	 * @param array optional, options 
	 *		idsOnly = just return the ids of the ancestors (def. false)
	 *		includeSelf = include this record (def. false)
	 *		additionalTableToJoin = name of additonal table data to return
	 *		returnDeleted = return deleted records in list (def. false)
	 * @return array 
	 */
	public function &getHierarchyAncestors($pn_id=null, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		$pb_include_self = (isset($pa_options['includeSelf']) && $pa_options['includeSelf']) ? true : false;
		$pb_ids_only = (isset($pa_options['idsOnly']) && $pa_options['idsOnly']) ? true : false;
		
		$vs_table_name = $this->tableName();
			
		$va_additional_table_select_fields = array();
		$va_additional_table_wheres = array();
			
		// additional table to join into query?
		$ps_additional_table_to_join = isset($pa_options['additionalTableToJoin']) ? $pa_options['additionalTableToJoin'] : null;
		if ($ps_additional_table_to_join) {		
			// what kind of join are we doing for the additional table? LEFT or INNER? (default=INNER)
			$ps_additional_table_join_type = 'INNER';
			if (isset($pa_options['additionalTableJoinType']) && ($pa_options['additionalTableJoinType'] === 'LEFT')) {
				$ps_additional_table_join_type = 'LEFT';
			}
			
			// what fields from the additional table are we going to return?
			if (isset($pa_options['additionalTableSelectFields']) && is_array($pa_options['additionalTableSelectFields'])) {
				foreach($pa_options['additionalTableSelectFields'] as $vs_fld) {
					$va_additional_table_select_fields[] = "{$ps_additional_table_to_join}.{$vs_fld}";
				}
			}
			
			// are there any SQL WHERE criteria for the additional table?
			if (isset($pa_options['additionalTableWheres']) && is_array($pa_options['additionalTableWheres'])) {
				$va_additional_table_wheres = $pa_options['additionalTableWheres'];
			}
		}
		
		if ($this->hasField('deleted') && (!isset($pa_options['returnDeleted']) || (!$pa_options['returnDeleted']))) {
			$va_additional_table_wheres[] = "({$vs_table_name}.deleted = 0)";
		}
		
		if ($this->isHierarchical()) {
			if (!$pn_id) {
				if (!($pn_id = $this->getPrimaryKey())) {
					return null;
				}
			}
			
			$vs_hier_left_fld 		= $this->getProperty("HIERARCHY_LEFT_INDEX_FLD");
			$vs_hier_right_fld 		= $this->getProperty("HIERARCHY_RIGHT_INDEX_FLD");
			$vs_hier_id_fld 		= $this->getProperty("HIERARCHY_ID_FLD");
			$vs_hier_id_table 		= $this->getProperty("HIERARCHY_DEFINITION_TABLE");
			$vs_hier_parent_id_fld 	= $this->getProperty("HIERARCHY_PARENT_ID_FLD");
			
			
			$va_sql_joins = array();
			if ($ps_additional_table_to_join){ 
				$va_path = Datamodel::getPath($vs_table_name, $ps_additional_table_to_join);
			
				switch(sizeof($va_path)) {
					case 2:
						$va_rels = Datamodel::getRelationships($vs_table_name, $ps_additional_table_to_join);
						$va_sql_joins[] = $ps_additional_table_join_type." JOIN {$ps_additional_table_to_join} ON ".$vs_table_name.'.'.$va_rels[$ps_additional_table_to_join][$vs_table_name][0][1]." = {$ps_additional_table_to_join}.".$va_rels[$ps_additional_table_to_join][$vs_table_name][0][0];
						break;
					case 3:
						// TODO: handle many-many cases
						break;
				}
			}
			$vs_sql_joins = join("\n", $va_sql_joins);
			
			$o_db = $this->getDb();
			$qr_root = $o_db->query("
				SELECT {$vs_table_name}.* ".(sizeof($va_additional_table_select_fields) ? ', '.join(', ', $va_additional_table_select_fields) : '')."
				FROM {$vs_table_name}
				{$vs_sql_joins}
				WHERE
					({$vs_table_name}.".$this->primaryKey()." = ?) ".((sizeof($va_additional_table_wheres) > 0) ? ' AND '.join(' AND ', $va_additional_table_wheres) : '')."
			", intval($pn_id));
		
			if ($o_db->numErrors()) {
				$this->errors = array_merge($this->errors, $o_db->errors());
				return null;
			} else {
				if ($qr_root->numRows()) {
					$va_ancestors = array();
					
					$vn_parent_id = null;
					$vn_level = 0;
					if ($pb_include_self) {
						while ($qr_root->nextRow()) {
							if (!$vn_parent_id) { $vn_parent_id = $qr_root->get($vs_hier_parent_id_fld); }
							if ($pb_ids_only) {
								$va_ancestors[] = $qr_root->get($this->primaryKey());
							} else {
								$va_ancestors[] = array(
									"NODE" => $qr_root->getRow(),
									"LEVEL" => $vn_level
								);
							}
							$vn_level++;
						}
					} else {
						$qr_root->nextRow();
						$vn_parent_id = $qr_root->get($vs_hier_parent_id_fld);
					}
					
					if($vn_parent_id) {
						do {
							$vs_sql = "
								SELECT {$vs_table_name}.* ".(sizeof($va_additional_table_select_fields) ? ', '.join(', ', $va_additional_table_select_fields) : '')."
								FROM {$vs_table_name} 
								{$vs_sql_joins}
								WHERE ({$vs_table_name}.".$this->primaryKey()." = ?) ".((sizeof($va_additional_table_wheres) > 0) ? ' AND '.join(' AND ', $va_additional_table_wheres) : '')."
							";
							
							$qr_hier = $o_db->query($vs_sql, $vn_parent_id);
							$vn_parent_id = null;
							while ($qr_hier->nextRow()) {
								if (!$vn_parent_id) { $vn_parent_id = $qr_hier->get($vs_hier_parent_id_fld); }
								if ($pb_ids_only) {
									$va_ancestors[] = $qr_hier->get($this->primaryKey());
								} else {
									$va_ancestors[] = array(
										"NODE" => $qr_hier->getRow(),
										"LEVEL" => $vn_level
									);
								}
							}
							$vn_level++;
						} while($vn_parent_id);
						return $va_ancestors;
					} else {
						return $va_ancestors;
					}
				} else {
					return null;
				}
			}
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	# New hierarchy API (2014)
	# --------------------------------------------------------------------------------------------
	/**
	 * 
	 * 
	 * @param string $ps_template 
	 * @param array $pa_options Any options supported by BaseModel::getHierarchyAsList() and caProcessTemplateForIDs() as well as:
	 *		sort = An array or semicolon delimited list of elements to sort on. [Default is null]
	 * 		sortDirection = Direction to sorting, either 'asc' (ascending) or 'desc' (descending). [Default is asc]
	 * @return array
	 */
	public function hierarchyWithTemplate($ps_template, $pa_options=null) {
		if (!$this->isHierarchical()) { return null; }
		if(!is_array($pa_options)) { $pa_options = array(); }
		
		$vs_pk = $this->primaryKey();
		$pn_id = caGetOption($vs_pk, $pa_options, null);
		$va_hier = $this->getHierarchyAsList($pn_id, array_merge($pa_options, array('idsOnly' => false, 'sort' => null)));
		
		$va_levels = $va_ids = $va_parent_ids = [];
		
		if (!is_array($va_hier)) { return array(); }
		foreach($va_hier as $vn_i => $va_item) {
			$va_ids[$vn_i] = $vn_id = $va_item['NODE'][$vs_pk];
			$va_levels[$vn_id] = $va_item['LEVEL'];
			$va_parent_ids[$vn_id] = $va_item['NODE']['parent_id'];
		}
		
		$va_hierarchy_data = [];
		
		$va_vals = caProcessTemplateForIDs($ps_template, $this->tableName(), $va_ids, array_merge($pa_options, array('indexWithIDs' => true, 'includeBlankValuesInArray' => true, 'returnAsArray'=> true)));
		
		$va_ids = array_keys($va_vals);
		$va_vals = array_values($va_vals);
		$pa_sort = caGetOption('sort', $pa_options, null);
		if (!is_array($pa_sort) && $pa_sort) { $pa_sort = explode(";", $pa_sort); }
	
		$ps_sort_direction = strtolower(caGetOption('sortDirection', $pa_options, 'asc'));
		if (!in_array($ps_sort_direction, array('asc', 'desc'))) { $ps_sort_direction = 'asc'; }
		
		if (is_array($pa_sort) && sizeof($pa_sort) && sizeof($va_ids)) {
			$va_sort_keys = array();
			$qr_sort_res = caMakeSearchResult($this->tableName(), $va_ids);
			$vn_i = 0;
			while($qr_sort_res->nextHit()) {
				$va_key = array();
				foreach($pa_sort as $vs_sort) {
					$va_key[] = $qr_sort_res->get($vs_sort);
				}
				$va_sort_keys[$vn_i] = join("_", $va_key)."_{$vn_i}";
				$vn_i++;
			}
			
			foreach($va_vals as $vn_i => $vs_val) {
				$va_hierarchy_data[$va_parent_ids[$va_ids[$vn_i]]][$va_sort_keys[$vn_i]] = array(
					'level' => $va_levels[$va_ids[$vn_i]],
					'id' => $va_ids[$vn_i],
					'parent_id' => $va_parent_ids[$va_ids[$vn_i]],
					'display' => $vs_val
				);
			}
		
			$va_hierarchy_flattened = array();
			foreach($va_hierarchy_data as $vn_parent_id => $va_level_content) {
				ksort($va_hierarchy_data[$vn_parent_id]);
			}
			
			return $this->_getFlattenedHierarchyArray($va_hierarchy_data, $va_parent_ids[$pn_id] ? $va_parent_ids[$pn_id] : null, $ps_sort_direction);
		} else {		
			foreach($va_vals as $vn_i => $vs_val) {
				$va_hierarchy_data[] = array(
					'level' => $va_levels[$va_ids[$vn_i]],
					'id' => $va_ids[$vn_i],
					'display' => $vs_val
				);
			}
		}
		return $va_hierarchy_data;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Traverse parent_id indexed array and return flattened list
	 */
	private function _getFlattenedHierarchyArray($pa_hierarchy_data, $pn_id, $ps_sort_direction='asc') {
		if (!is_array($pa_hierarchy_data[$pn_id])) { return array(); }
		
		$va_data = array();
		foreach($pa_hierarchy_data[$pn_id] as $vs_sort_key => $va_item) {
			$va_data[] = $va_item;
			$va_data = array_merge($va_data, $this->_getFlattenedHierarchyArray($pa_hierarchy_data, $va_item['id']));
		}
		if ($ps_sort_direction == 'desc') { $va_data = array_reverse($va_data); }
		return $va_data;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Return all ancestors for a list of row_ids. The list is an aggregation of ancestors for all of the row_ids. It will not possible
	 * to determine which row_ids have which ancestors from the returned value.
	 * 
	 * @param array $pa_row_ids 
	 * @param array $pa_options Options include:
	 *		transaction = optional Transaction instance. If set then all database access is done within the context of the transaction
	 *		returnAs = what to return; possible values are:
	 *			searchResult			= a search result instance (aka. a subclass of BaseSearchResult), when the calling subclass is searchable (ie. <classname>Search and <classname>SearchResult classes are defined) 
	 *			ids						= an array of ids (aka. primary keys)
	 *			modelInstances			= an array of instances, one for each ancestor. Each instance is the same class as the caller, a subclass of BaseModel 
	 *			firstId					= the id (primary key) of the first ancestor. This is the same as the first item in the array returned by 'ids'
	 *			firstModelInstance		= the instance of the first ancestor. This is the same as the first instance in the array returned by 'modelInstances'
	 *			count					= the number of ancestors
	 *			[Default is ids]
	 *	
	 *		limit = if searchResult, ids or modelInstances is set, limits number of returned ancestoes. [Default is no limit]
	 *		includeSelf = Include initial row_id values in returned set [Default is false]
	 *		
	 * @return mixed
	 */
	static public function getHierarchyAncestorsForIDs($pa_row_ids, $pa_options=null) {
		if(!is_array($pa_row_ids) || (sizeof($pa_row_ids) == 0)) { return null; }
		
		$ps_return_as = caGetOption('returnAs', $pa_options, 'ids', array('forceLowercase' => true, 'validValues' => array('searchResult', 'ids', 'modelInstances', 'firstId', 'firstModelInstance', 'count')));
		$o_trans = caGetOption('transaction', $pa_options, null);
		$vs_table = get_called_class();
		$t_instance = new $vs_table;
		
	 	if (!($vs_parent_id_fld = $t_instance->getProperty('HIERARCHY_PARENT_ID_FLD'))) { return null; }
		$pb_include_self = caGetOption('includeSelf', $pa_options, false);
		if ($o_trans) { $t_instance->setTransaction($o_trans); }
		
		$vs_table_name = $t_instance->tableName();
		$vs_table_pk = $t_instance->primaryKey();
		
		$o_db = $t_instance->getDb();
		
		$va_ancestor_row_ids = $pb_include_self ? $pa_row_ids : array();
		$va_level_row_ids = $pa_row_ids;
		do {
			$qr_level = $o_db->query("
				SELECT {$vs_parent_id_fld}
				FROM {$vs_table_name}
				WHERE
					{$vs_table_pk} IN (?)
			", array($va_level_row_ids));
			$va_level_row_ids = $qr_level->getAllFieldValues($vs_parent_id_fld);
			
			$va_ancestor_row_ids = array_merge($va_ancestor_row_ids, $va_level_row_ids);
		} while(($qr_level->numRows() > 0) && (sizeof($va_level_row_ids))) ;
	
		$va_ancestor_row_ids = array_values(array_unique($va_ancestor_row_ids));
		if (!sizeof($va_ancestor_row_ids)) { return null; }
		
		$vn_limit = (isset($pa_options['limit']) && ((int)$pa_options['limit'] > 0)) ? (int)$pa_options['limit'] : null;
		
		
		switch($ps_return_as) {
			case 'firstmodelinstance':
				$vn_ancestor_id = array_shift($va_ancestor_row_ids);
				if ($t_instance->load((int)$vn_ancestor_id)) {
					return $t_instance;
				}
				return null;
				break;
			case 'modelinstances':
				$va_instances = array();
				$vn_c = 0;
				foreach($va_ancestor_row_ids as $vn_ancestor_id) {
					$t_instance = new $vs_table;
					if ($o_trans) { $t_instance->setTransaction($o_trans); }
					if ($t_instance->load((int)$vn_ancestor_id)) {
						$va_instances[] = $t_instance;
						$vn_c++;
						if ($vn_limit && ($vn_c >= $vn_limit)) { break; }
					}
				}
				return $va_instances;
				break;
			case 'firstid':
				return array_shift($va_ancestor_row_ids);
				break;
			case 'count':
				return sizeof($va_ancestor_row_ids);
				break;
			default:
			case 'ids':
			case 'searchresult':
				if ($vn_limit && (sizeof($va_ancestor_row_ids) >= $vn_limit)) { 
					$va_ancestor_row_ids = array_slice($va_ancestor_row_ids, 0, $vn_limit);
				}
				if ($ps_return_as == 'searchresult') {
					return $t_instance->makeSearchResult($t_instance->tableName(), $va_ancestor_row_ids);
				} else {
					return $va_ancestor_row_ids;
				}
				break;
		}
		return null;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Return all children for a list of row_ids. The list is an aggregation of children for all of the row_ids. It will not possible
	 * to determine which row_ids have which children from the returned value.
	 * 
	 * @param array $pa_row_ids 
	 * @param array $pa_options Options include:
	 *		transaction = optional Transaction instance. If set then all database access is done within the context of the transaction
	 *		returnAs = what to return; possible values are:
	 *			searchResult			= a search result instance (aka. a subclass of BaseSearchResult), when the calling subclass is searchable (ie. <classname>Search and <classname>SearchResult classes are defined) 
	 *			ids						= an array of ids (aka. primary keys)
	 *			modelInstances			= an array of instances, one for each children. Each instance is the same class as the caller, a subclass of BaseModel 
	 *			firstId					= the id (primary key) of the first children. This is the same as the first item in the array returned by 'ids'
	 *			firstModelInstance		= the instance of the first children. This is the same as the first instance in the array returned by 'modelInstances'
	 *			count					= the number of children
	 *			[Default is ids]
	 *	
	 *		limit = if searchResult, ids or modelInstances is set, limits number of returned children. [Default is no limit]
	 *		
	 * @return mixed
	 */
	static public function getHierarchyChildrenForIDs($pa_row_ids, $pa_options=null) {
		if(!is_array($pa_row_ids) || (sizeof($pa_row_ids) == 0)) { return null; }
		
		$ps_return_as = caGetOption('returnAs', $pa_options, 'ids', array('forceLowercase' => true, 'validValues' => array('searchResult', 'ids', 'modelInstances', 'firstId', 'firstModelInstance', 'count')));
		$o_trans = caGetOption('transaction', $pa_options, null);
		$vs_table = get_called_class();
		$t_instance = new $vs_table;
		
	 	if (!($vs_parent_id_fld = $t_instance->getProperty('HIERARCHY_PARENT_ID_FLD'))) { return null; }
		if ($o_trans) { $t_instance->setTransaction($o_trans); }
		
		$vs_table_name = $t_instance->tableName();
		$vs_table_pk = $t_instance->primaryKey();
		
		$o_db = $t_instance->getDb();
		
		$va_child_row_ids = array();
		$va_level_row_ids = $pa_row_ids;
		do {
			$qr_level = $o_db->query("
				SELECT {$vs_table_pk}
				FROM {$vs_table_name}
				WHERE
					{$vs_parent_id_fld} IN (?)
			", array($va_level_row_ids));
			$va_level_row_ids = $qr_level->getAllFieldValues($vs_table_pk);
			$va_child_row_ids = array_merge($va_child_row_ids, $va_level_row_ids);
		} while(($qr_level->numRows() > 0) && (sizeof($va_level_row_ids))) ;
		
		$va_child_row_ids = array_values(array_unique($va_child_row_ids));
		if (!sizeof($va_child_row_ids)) { return null; }
		
		$vn_limit = (isset($pa_options['limit']) && ((int)$pa_options['limit'] > 0)) ? (int)$pa_options['limit'] : null;
		
		
		switch($ps_return_as) {
			case 'firstmodelinstance':
				$vn_child_id = array_shift($va_child_row_ids);
				if ($t_instance->load((int)$vn_child_id)) {
					return $t_instance;
				}
				return null;
				break;
			case 'modelinstances':
				$va_instances = array();
				$vn_c = 0;
				foreach($va_child_row_ids as $vn_child_id) {
					$t_instance = new $vs_table;
					if ($o_trans) { $t_instance->setTransaction($o_trans); }
					if ($t_instance->load((int)$vn_child_id)) {
						$va_instances[] = $t_instance;
						$vn_c++;
						if ($vn_limit && ($vn_c >= $vn_limit)) { break; }
					}
				}
				return $va_instances;
				break;
			case 'firstid':
				return array_shift($va_child_row_ids);
				break;
			case 'count':
				return sizeof($va_child_row_ids);
				break;
			default:
			case 'ids':
			case 'searchresult':
				if ($vn_limit && (sizeof($va_child_row_ids) >= $vn_limit)) { 
					$va_child_row_ids = array_slice($va_child_row_ids, 0, $vn_limit);
				}
				if ($ps_return_as == 'searchresult') {
					return $t_instance->makeSearchResult($t_instance->tableName(), $va_child_row_ids);
				} else {
					return $va_child_row_ids;
				}
				break;
		}
		return null;
	}
	# --------------------------------------------------------------------------------------------
	# Hierarchical indices
	# --------------------------------------------------------------------------------------------
	/**
	 * Rebuild all hierarchical indexing for all rows in this table
	 */
	public function rebuildAllHierarchicalIndexes() {
		$vs_hier_left_fld 		= $this->getProperty("HIERARCHY_LEFT_INDEX_FLD");
		$vs_hier_right_fld 		= $this->getProperty("HIERARCHY_RIGHT_INDEX_FLD");
		$vs_hier_id_fld 		= $this->getProperty("HIERARCHY_ID_FLD");
		$vs_hier_id_table 		= $this->getProperty("HIERARCHY_DEFINITION_TABLE");
		$vs_hier_parent_id_fld 	= $this->getProperty("HIERARCHY_PARENT_ID_FLD");
		
		if (!$vs_hier_id_fld) { return false; }
		
		$o_db = $this->getDb();
		$qr_hier_ids = $o_db->query("
			SELECT DISTINCT ".$vs_hier_id_fld."
			FROM ".$this->tableName()."
		");
		while($qr_hier_ids->nextRow()) {
			$this->rebuildHierarchicalIndex($qr_hier_ids->get($vs_hier_id_fld));
		}
		return true;
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Rebuild hierarchical indexing for the specified hierarchy in this table
	 */
	public function rebuildHierarchicalIndex($pn_hierarchy_id=null) {
		if ($this->isHierarchical()) {
			$vb_we_set_transaction = false;
			if (!$this->inTransaction()) {
				$this->setTransaction(new Transaction($this->getDb()));
				$vb_we_set_transaction = true;
			}
			if ($vn_root_id = $this->getHierarchyRootID($pn_hierarchy_id)) {
				$this->_rebuildHierarchicalIndex($vn_root_id, 1);
				if ($vb_we_set_transaction) { $this->removeTransaction(true);}
				return true;
			} else {
				if ($vb_we_set_transaction) { $this->removeTransaction(false);}
				return null;
			}
		} else {
			return null;
		}
	}
	# --------------------------------------------------------------------------------------------
	/**
	 * Private method that actually performs reindexing tasks
	 */
	private function _rebuildHierarchicalIndex($pn_parent_id, $pn_hier_left) {
		$vs_hier_parent_id_fld 		= $this->getProperty("HIERARCHY_PARENT_ID_FLD");
		$vs_hier_left_fld 			= $this->getProperty("HIERARCHY_LEFT_INDEX_FLD");
		$vs_hier_right_fld 			= $this->getProperty("HIERARCHY_RIGHT_INDEX_FLD");
		$vs_hier_id_fld 			= $this->getProperty("HIERARCHY_ID_FLD");
		$vs_hier_id_table 			= $this->getProperty("HIERARCHY_DEFINITION_TABLE");

		$vn_hier_right = $pn_hier_left + 100;
		
		$vs_pk = $this->primaryKey();
		
		$o_db = $this->getDb();
		
		if (is_null($pn_parent_id)) {
			$vs_sql = "
				SELECT *
				FROM ".$this->tableName()."
				WHERE
					(".$vs_hier_parent_id_fld." IS NULL)
			";
		} else {
			$vs_sql = "
				SELECT *
				FROM ".$this->tableName()."
				WHERE
					(".$vs_hier_parent_id_fld." = ".intval($pn_parent_id).")
			";
		}
		$qr_level = $o_db->query($vs_sql);
		
		if ($o_db->numErrors()) {
			$this->errors = array_merge($this->errors, $o_db->errors());
			return null;
		} else {
			while($qr_level->nextRow()) {
				$vn_hier_right = $this->_rebuildHierarchicalIndex($qr_level->get($vs_pk), $vn_hier_right);
			}
			
			$qr_up = $o_db->query("
				UPDATE ".$this->tableName()."
				SET ".$vs_hier_left_fld." = ".intval($pn_hier_left).", ".$vs_hier_right_fld." = ".intval($vn_hier_right)."
				WHERE 
					(".$vs_pk." = ?)
			", intval($pn_parent_id));
			
			if ($o_db->numErrors()) {
				$this->errors = array_merge($this->errors, $o_db->errors());
				return null;
			} else {
				return $vn_hier_right + 100;
			}
		}
	}
	
	/**
	 *
	 */
	 private function _calcHierarchicalIndexing($pa_parent_info) {
	 	$vs_hier_left_fld = $this->getProperty('HIERARCHY_LEFT_INDEX_FLD');
	 	$vs_hier_right_fld = $this->getProperty('HIERARCHY_RIGHT_INDEX_FLD');
	 	$vs_hier_id_fld = $this->getProperty('HIERARCHY_ID_FLD');
	 	$vs_parent_fld = $this->getProperty('HIERARCHY_PARENT_ID_FLD');
	 	
	 	$o_db = $this->getDb();
	 	
	 	$qr_up = $o_db->query("
			SELECT MAX({$vs_hier_right_fld}) maxChildRight
			FROM ".$this->tableName()."
			WHERE
				({$vs_hier_left_fld} > ?) AND
				({$vs_hier_right_fld} < ?) AND (".$this->primaryKey()." <> ?)".
				($vs_hier_id_fld ? " AND ({$vs_hier_id_fld} = ".intval($pa_parent_info[$vs_hier_id_fld]).")" : '')."
		", $pa_parent_info[$vs_hier_left_fld], $pa_parent_info[$vs_hier_right_fld], $pa_parent_info[$this->primaryKey()]);
	 
	 	if ($qr_up->nextRow()) {
	 		if (!($vn_gap_start = $qr_up->get('maxChildRight'))) {
	 			$vn_gap_start = $pa_parent_info[$vs_hier_left_fld];
	 		}
	 	
			$vn_gap_end = $pa_parent_info[$vs_hier_right_fld];
			$vn_gap_size = ($vn_gap_end - $vn_gap_start);
			
			if ($vn_gap_size < 0.00001) {
				// rebuild hierarchical index if the current gap is not large enough to fit current record
				$this->rebuildHierarchicalIndex($this->get($vs_hier_id_fld));
				$pa_parent_info = $this->_getHierarchyParent($pa_parent_info[$this->primaryKey()]);
				return $this->_calcHierarchicalIndexing($pa_parent_info);
			}

			if (($vn_scale = strlen(floor($vn_gap_size/10000))) < 1) { $vn_scale = 1; } 

			$vn_interval_start = $vn_gap_start + ($vn_gap_size/(pow(10, $vn_scale)));
			$vn_interval_end = $vn_interval_start + ($vn_gap_size/(pow(10, $vn_scale)));
			
			//print "--------------------------\n";
			//print "GAP START={$vn_gap_start} END={$vn_gap_end} SIZE={$vn_gap_size} SCALE={$vn_scale} INC=".($vn_gap_size/(pow(10, $vn_scale)))."\n";
			//print "INT START={$vn_interval_start} INT END={$vn_interval_end}\n";
			//print "--------------------------\n";
			return array('left' => $vn_interval_start, 'right' => $vn_interval_end);
	 	}
	 	return null;
	 }
	 
	 /**
	  *
	  */
	 private function _getHierarchyParent($pn_parent_id) {
	 	$o_db = $this->getDb();
	 	$qr_get_parent = $o_db->query("
			SELECT *
			FROM ".$this->tableName()."
			WHERE 
				".$this->primaryKey()." = ?
		", intval($pn_parent_id));
		
		if($qr_get_parent->nextRow()) {
			return $qr_get_parent->getRow();
		}
		return null;
	 }
	 
	 /**
	  * Internal-use-only function for getting child record ids in a hierarchy. Unlike the public getHierarchyChildren() method
	  * which uses nested set hierarchical indexing to fetch all children in a single pass (and also can return more than just the id's 
	  * of the children), _getHierarchyChildren() recursively traverses the hierarchy using parent_id field values. This makes it useful for
	  * getting children in situations when the hierarchical indexing may not be valid, such as when moving items between hierarchies.
	  *
	  * @access private
	  * @param array $pa_ids List of ids to get children for
	  * @return array ids of all children of specified ids. List includes the original specified ids.
	  */
	 private function _getHierarchyChildren($pa_ids) {
		if(!is_array($pa_ids)) { return null; }
		if (!sizeof($pa_ids)) { return null; }
	 	if (!($vs_parent_id_fld = $this->getProperty('HIERARCHY_PARENT_ID_FLD'))) { return null; }
	 	
	 	foreach($pa_ids as $vn_i => $vn_v) {
	 		$pa_ids[$vn_i] = (int)$vn_v;
	 	}
	 	
	 	$o_db = $this->getDb();
	 	$qr_get_children = $o_db->query("
			SELECT ".$this->primaryKey()."
			FROM ".$this->tableName()."
			WHERE 
				{$vs_parent_id_fld} IN (?)
		", array($pa_ids));
		
		$va_child_ids = $qr_get_children->getAllFieldValues($this->primaryKey());
		if (($va_child_ids && is_array($va_child_ids) && sizeof($va_child_ids) > 0)) { 
			$va_child_ids = array_merge($va_child_ids, $this->_getHierarchyChildren($va_child_ids));
		}
		if (!is_array($va_child_ids)) { $va_child_ids = array(); }
		
		$va_child_ids = array_merge($pa_ids, $va_child_ids);
		return array_unique($va_child_ids, SORT_STRING);
	 }
	# --------------------------------------------------------------------------------------------
}
