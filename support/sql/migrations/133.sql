	Date: 11 May 2016
	Migration: 133
	Description: Add tables for did-you-mean statistics
*/

/*==========================================================================*/
drop if exists table ca_search_phrase_statistics;
create table ca_search_phrase_statistics
(
  phrase_id       int unsigned  not null AUTO_INCREMENT,
  phrase         varchar(1024) not null,
  stem           varchar(1024) not null,
  word_count     tinyint unsigned not null default 0,
  tf      		 int unsigned not null default 0,
  idf            decimal(16,2) unsigned not null default 0,
  tf_idf         decimal(16,2),
  

  primary key (phrase_id),
  unique index u_phrase (phrase),
  index i_stem (stem),
  index i_tf (tf),
  index i_idf (idf)
) engine=innodb CHARACTER SET utf8 COLLATE utf8_general_ci;

/*==========================================================================*/
drop if exists table ca_search_phrase_ngrams;
create table ca_search_phrase_ngrams (
  phrase_id int unsigned not null,
  ngram char(4) not null,
  seq tinyint(3) unsigned not null,
  
  index i_ngram (ngram),
  index i_phrase_id (phrase_id)
) engine=innodb CHARACTER SET utf8 COLLATE utf8_general_ci;

/*==========================================================================*/

/* Always add the update to ca_schema_updates at the end of the file */
INSERT IGNORE INTO ca_schema_updates (version_num, datetime) VALUES (133, unix_timestamp());