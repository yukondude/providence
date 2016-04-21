<?php
/** ---------------------------------------------------------------------
 * app/lib/core/Search/SearchStatistics.php : 
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2016 Whirl-i-Gig
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
 * @subpackage Search
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

/**
 *
 */

require_once(__CA_LIB_DIR__."/core/Search/SearchBase.php");
require_once(__CA_LIB_DIR__.'/core/Utils/Graph.php');
require_once(__CA_LIB_DIR__.'/core/Utils/Timer.php');
require_once(__CA_LIB_DIR__.'/core/Utils/CLIProgressBar.php');
require_once(__CA_LIB_DIR__.'/core/Search/Common/Stemmer/SnoballStemmer.php');
require_once(__CA_LIB_DIR__.'/core/Zend/Cache.php');
require_once(__CA_APP_DIR__.'/helpers/utilityHelpers.php');

class SearchStatistics extends SearchBase {
	# -------------------------------------------------------
	private $search_config = null;
	private $indexing_tokenizer_regex;
	private $stemmer;
	
	private $last_id = null;
	
	static $s_stop_words = array("a", "about", "above", "above", "across", "after", "afterwards", "again", "against", "all", "almost", "alone", "along", "already", "also","although","always","am","among", "amongst", "amoungst", "amount",  "an", "and", "another", "any","anyhow","anyone","anything","anyway", "anywhere", "are", "around", "as",  "at", "back","be","became", "because","become","becomes", "becoming", "been", "before", "beforehand", "behind", "being", "below", "beside", "besides", "between", "beyond", "bill", "both", "bottom","but", "by", "call", "can", "cannot", "cant", "co", "con", "could", "couldnt", "cry", "de", "describe", "detail", "do", "done", "down", "due", "during", "each", "eg", "eight", "either", "eleven","else", "elsewhere", "empty", "enough", "etc", "even", "ever", "every", "everyone", "everything", "everywhere", "except", "few", "fifteen", "fify", "fill", "find", "fire", "first", "five", "for", "former", "formerly", "forty", "found", "four", "from", "front", "full", "further", "get", "give", "go", "had", "has", "hasnt", "have", "he", "hence", "her", "here", "hereafter", "hereby", "herein", "hereupon", "hers", "herself", "him", "himself", "his", "how", "however", "hundred", "ie", "if", "in", "inc", "indeed", "interest", "into", "is", "it", "its", "itself", "keep", "last", "latter", "latterly", "least", "less", "ltd", "made", "many", "may", "me", "meanwhile", "might", "mill", "mine", "more", "moreover", "most", "mostly", "move", "much", "must", "my", "myself", "name", "namely", "neither", "never", "nevertheless", "next", "nine", "no", "nobody", "none", "noone", "nor", "not", "nothing", "now", "nowhere", "of", "off", "often", "on", "once", "one", "only", "onto", "or", "other", "others", "otherwise", "our", "ours", "ourselves", "out", "over", "own","part", "per", "perhaps", "please", "put", "rather", "re", "same", "see", "seem", "seemed", "seeming", "seems", "serious", "several", "she", "should", "show", "side", "since", "sincere", "six", "sixty", "so", "some", "somehow", "someone", "something", "sometime", "sometimes", "somewhere", "still", "such", "system", "take", "ten", "than", "that", "the", "their", "them", "themselves", "then", "thence", "there", "thereafter", "thereby", "therefore", "therein", "thereupon", "these", "they", "thickv", "thin", "third", "this", "those", "though", "three", "through", "throughout", "thru", "thus", "to", "together", "too", "top", "toward", "towards", "twelve", "twenty", "two", "un", "under", "until", "up", "upon", "us", "very", "via", "was", "we", "well", "were", "what", "whatever", "when", "whence", "whenever", "where", "whereafter", "whereas", "whereby", "wherein", "whereupon", "wherever", "whether", "which", "while", "whither", "who", "whoever", "whole", "whom", "whose", "why", "will", "with", "within", "without", "would", "yet", "you", "your", "yours", "yourself", "yourselves", "the");

	# -------------------------------------------------------
	/**
	 *
	 *
	 */
	public function __construct() {
		parent::__construct();
		$this->search_config = caGetSearchConfig();
		$this->indexing_tokenizer_regex = $this->search_config->get('indexing_tokenizer_regex');
		$this->stemmer = new SnoballStemmer();
	}
	# -------------------------------------------------------
	/**
	 * 
	 */
	static public function analyze($pa_tables=null, $pa_options=null) {
		$o_stats = new SearchStatistics();
		
		$o_db = $o_stats->getDb();
		$o_db->query("TRUNCATE TABLE ca_search_phrase_ngrams");
		$o_db->query("TRUNCATE TABLE ca_search_phrase_statistics");
		
		if ($pa_table_names) {
			if (!is_array($pa_table_names)) { $pa_table_names = array($pa_table_names); }

			$va_table_names = array();
			foreach($pa_table_names as $vs_table) {
				if ($o_stats->opo_datamodel->tableExists($vs_table)) {
					$vn_num = $o_stats->opo_datamodel->getTableNum($vs_table);
					$t_instance = $o_stats->opo_datamodel->getInstanceByTableName($vs_table, true);
					$va_table_names[] = $vs_table;
				}
			}
			if (!sizeof($va_table_names)) { return false; }
		} else {
			$va_table_names = $o_stats->getIndexedTables();
		}
		
		foreach($va_table_names as $vs_table) {
			$t_instance = $o_stats->opo_datamodel->getInstanceByTableName($vs_table, true);

			$vn_table_num = $t_instance->tableNum();

			$va_fields_to_index = $o_stats->getFieldsToIndex($vn_table_num);
			if (!is_array($va_fields_to_index) || (sizeof($va_fields_to_index) == 0)) {
				continue;
			}

			$o_db->query("ALTER TABLE {$vs_table} DISABLE KEYS");

			$qr_all = $o_db->query("SELECT ".$t_instance->primaryKey()." FROM {$vs_table}");

			$vn_num_rows = $qr_all->numRows();
			if ($pb_display_progress) {
				print CLIProgressBar::start($vn_num_rows, _t('Analyzing %1', $t_instance->getProperty('NAME_PLURAL')));
			}

			$vn_c = 0;
			$va_ids = $qr_all->getAllFieldValues($t_instance->primaryKey());

			$va_element_ids = null;
			if (method_exists($t_instance, "getApplicableElementCodes")) {
				$va_element_ids = array_keys($t_instance->getApplicableElementCodes(null, false, false));
			}

			$vn_table_num = $t_instance->tableNum();
			$vs_table_pk = $t_instance->primaryKey();
			$va_field_data = array();

			$va_intrinsic_list = $o_stats->getFieldsToIndex($vs_table, $vs_table, array('intrinsicOnly' => true));
			$va_intrinsic_list[$vs_table_pk] = array();

			foreach($va_ids as $vn_i => $vn_id) {
				if (!($vn_i % 500)) {	// Pre-load attribute values for next 500 items to index; improves index performance
					$va_id_slice = array_slice($va_ids, $vn_i, 500);
					if ($va_element_ids) {
						ca_attributes::prefetchAttributes($o_db, $vn_table_num, $va_id_slice, $va_element_ids);
					}
					$qr_field_data = $o_db->query("
						SELECT ".join(", ", array_keys($va_intrinsic_list))." 
						FROM {$vs_table}
						WHERE {$vs_table_pk} IN (?)	
					", array($va_id_slice));

					$va_field_data = array();
					while($qr_field_data->nextRow()) {
						$va_field_data[(int)$qr_field_data->get($vs_table_pk)] = $qr_field_data->getRow();
					}

					SearchResult::clearCaches();
				}

				//$o_stats->indexRow($vn_table_num, $vn_id, $va_field_data[$vn_id], true);
				$o_stats->analyzeRow($t_instance, $vn_id);
				
				
				if ($pb_display_progress && $pb_interactive_display) {
					CLIProgressBar::setMessage("Memory: ".caGetMemoryUsage());
					print CLIProgressBar::next();
				}
				$vn_c++;
			}
			$qr_all->free();
			
			$o_db->query("ALTER TABLE {$vs_table} ENABLE KEYS");
			
			unset($t_instance);
			if ($pb_display_progress && $pb_interactive_display) {
				print CLIProgressBar::finish();
			}

			$vn_tc++;
		}
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	public function analyzeRow($pt_instance, $pn_id) {
		if($pt_instance->load($pn_id)) {
			$va_fields_to_index = $this->getFieldsToIndex($pt_instance->tableNum());
			
			foreach($va_fields_to_index as $vs_fld => $va_fld_info) {
				if(substr($vs_fld, 0, 14) == '_ca_attribute_') {
					$vs_val = $pt_instance->get($pt_instance->tableName().".".ca_metadata_elements::getElementCodeForId(substr($vs_fld, 14)));
				} else {
					$vs_val = $pt_instance->get($pt_instance->tableName().".".$vs_fld);
				}
				
				if (!trim($vs_val)) { continue; }
				
				$va_words = $this->tokenize($vs_val); 
				
				// words
				foreach($va_words as $vs_word) {
					$vs_word = trim($vs_word);
					if (preg_match("![\d]+!", $vs_word)) { continue; }
					$vs_word = preg_replace("![^A-Za-z ]+!", "", $vs_word);
					
					$this->logPhrase([$vs_word], $pn_id);
				}
				
				// phrases
				for($vn_i=0; $vn_i < sizeof($va_words); $vn_i++) {
					for($vn_l=4; $vn_l > 0; $vn_l--) {
						$va_phrase = array_values(array_slice($va_words, $vn_i, $vn_l));
						$va_phrase = array_map(trim, $va_phrase);
							
						// Skip phrase if first or last word is stop word					
						if (
							((strlen($va_phrase[0]) < 2) && ($va_phrase[0] !== 'a'))
							||
							(strlen($va_phrase[sizeof($va_phrase)-1]) < 2)
							||
							in_array($va_phrase[0], SearchStatistics::$s_stop_words)
							||
							in_array($va_phrase[sizeof($va_phrase)-1], SearchStatistics::$s_stop_words)
						) {
							continue;
						}
						
						$va_phrase_proc = [];
						foreach($va_phrase as $vs_word) {
							if (preg_match("![\d\_]+!", $vs_word)) { break; }	
							$vb_is_end = preg_match("![\.\;]+$!", $vs_word); // word ends with period or semicolon forcing end of phrase
							$vs_word = preg_replace("![^A-Za-z \'\"]+!", "", $vs_word);
							
							$va_phrase_proc[] = $vs_word;
							
							if ($vb_is_end) { break; }
						}
						if (sizeof($va_phrase_proc) > 1) {
							$vn_l = sizeof($va_phrase_proc);
							$this->logPhrase($va_phrase_proc, $pn_id);
						}
					}
				}
			}
		}
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	public function logPhrase($pa_phrase, $pn_id) {
		$vs_phrase = join(" ", $pa_phrase);
		if (strlen($vs_phase) > 1024) { return null; }		// phrase too long
		
		$qr_res = $this->getDb()->query("SELECT phrase_id, tf, idf, tf_idf FROM ca_search_phrase_statistics WHERE phrase = ?", [$vs_phrase]);
	
		if ($qr_res->nextRow()) {
			if ($pn_id !== $this->last_id) {
				$this->last_id = $pn_id;
				$vn_idf = (int)$qr_res->get('idf') + 1;
			} else {
				$vn_idf = (int)$qr_res->get('idf');
			}
			
			$vn_tf = (int)$qr_res->get('tf') + 1;
			
			$this->getDb()->query("UPDATE ca_search_phrase_statistics SET tf = ?, idf = ?, tf_idf = ? WHERE phrase_id = ?", [$vn_tf, $vn_idf, $vn_tf / $vn_idf, (int)$qr_res->get('phrase_id')]);
		} else {
			$va_stems = [];
			foreach($pa_phrase as $vs_word) {
				$va_stems[] = $this->stemmer->stem($vs_word);
			}
			
			$this->getDb()->query("INSERT INTO ca_search_phrase_statistics (phrase, stem, word_count, tf, idf, tf_idf) VALUES (?, ?, ?, 1, 1, 1)", [$vs_phrase, join(" ", $va_stems), sizeof($pa_phrase)]);
			$vn_phrase_id = $this->getDb()->getLastInsertID();
			$this->createNgrams($vn_phrase_id, join(" ", $pa_phrase), 2);
			$this->createNgrams($vn_phrase_id, join(" ", $pa_phrase), 3);
			$this->createNgrams($vn_phrase_id, join(" ", $pa_phrase), 4);
		}
		return true;
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	public function createNgrams($pn_phrase_id, $ps_phrase, $pn_n=4) {
		// create ngrams
		$va_ngrams = caNgrams($ps_phrase, $pn_n);
		$vn_seq = 0;
		
		$va_ngram_buf = array();
		foreach($va_ngrams as $vs_ngram) {
			$va_ngram_buf[] = "({$pn_phrase_id},'{$vs_ngram}',{$vn_seq})";
			$vn_seq++;
		}
		
		if (sizeof($va_ngram_buf)) {
			$vs_sql = "INSERT INTO ca_search_phrase_ngrams (phrase_id, ngram, seq) VALUES ".join(",", $va_ngram_buf);
			$this->opo_db->query($vs_sql);
		}
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	static public function suggest($ps_text, $pa_options=null) {
		$o_dm = Datamodel::load();
		$o_stats = new SearchStatistics();
		$va_words = $o_stats->tokenize($ps_text);
		$vn_ngram_len = 3;
		
		$va_ngrams = caNgrams($ps_text, $vn_ngram_len);
		
		$qr_res = $o_stats->getDb()->query("
				SELECT ng.phrase_id, stat.phrase, stat.tf, count(*) sc
				FROM ca_search_phrase_ngrams ng
				INNER JOIN ca_search_phrase_statistics AS stat ON stat.phrase_id = ng.phrase_id
				WHERE
					ng.ngram IN (?) AND stat.word_count = ? 
				GROUP BY ng.phrase_id
				ORDER BY sc DESC, ABS(length(stat.phrase) - ".strlen($ps_text).") ASC, stat.tf DESC
				LIMIT 5
			", [$va_ngrams, sizeof($va_words)]);
			
		while($qr_res->nextRow()) {
			print_R($qr_res->getRow());
		}
		
	}
	# -------------------------------------------------------
	/**
	 *
	 */
	public function tokenize($ps_text, $pa_options=null) {
		$va_words = preg_split('!['.$this->indexing_tokenizer_regex.']+!', $ps_text);
		$va_words = array_filter($va_words, strlen);
		$va_words = array_map(function($v) { return strtolower($v); }, $va_words);
		
		return $va_words;
	}
	# -------------------------------------------------------
}