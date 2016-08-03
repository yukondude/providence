/*
	Date: 30 July 2016
	Migration: 137
	Description: SQL search indices to improve Pawtucket search performance
*/

/*==========================================================================*/


CREATE INDEX i_index_field_table_num_with_access ON ca_sql_search_word_index(word_id, table_num, field_table_num, access);
CREATE INDEX i_index_field_num_with_access ON ca_sql_search_word_index(word_id, table_num, field_table_num, field_num, access);

/*==========================================================================*/

/* Always add the update to ca_schema_updates at the end of the file */
INSERT IGNORE INTO ca_schema_updates (version_num, datetime) VALUES (137, unix_timestamp());
