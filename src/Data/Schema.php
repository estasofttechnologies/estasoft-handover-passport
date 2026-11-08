<?php
/**
 * Database schema (DAT-1 to DAT-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the CREATE TABLE statements for dbDelta.
 *
 * Times are UTC date-times. JSON columns are long text so MySQL and MariaDB behave the same.
 * There are no foreign keys: relations are kept and repaired by the plugin.
 */
final class Schema {

	/**
	 * Schema version. Raise it when a table changes and add a step in Upgrader.
	 */
	public const VERSION = 1;

	/**
	 * Create or change all tables. Safe to run twice.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::statements( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * The statements, one per table.
	 *
	 * @param string $charset Charset and collate clause.
	 * @return array<int, string>
	 */
	public static function statements( string $charset ): array {
		$t = Tables::all();

		return array(
			"CREATE TABLE {$t['scans']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  trigger_kind varchar(20) NOT NULL DEFAULT 'manual',
  status varchar(20) NOT NULL DEFAULT 'running',
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  facts longtext,
  differences longtext,
  error_code varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY started_at (started_at)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(40) NOT NULL,
  subject_key varchar(191) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  data longtext,
  source varchar(10) NOT NULL DEFAULT 'entered',
  vis_client tinyint(1) NOT NULL DEFAULT 0,
  vis_emergency tinyint(1) NOT NULL DEFAULT 0,
  `sensitive` tinyint(1) NOT NULL DEFAULT 0,
  legal_owner varchar(20) NOT NULL DEFAULT '',
  due_date date DEFAULT NULL,
  status varchar(30) NOT NULL DEFAULT '',
  review_days int(10) unsigned DEFAULT NULL,
  verified_at datetime DEFAULT NULL,
  verified_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY kind_subject (kind,subject_key),
  KEY due_date (due_date),
  KEY deleted_at (deleted_at)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['people']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  organisation varchar(190) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'other',
  title varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(60) NOT NULL DEFAULT '',
  language varchar(20) NOT NULL DEFAULT '',
  timezone varchar(60) NOT NULL DEFAULT '',
  urgent_method varchar(255) NOT NULL DEFAULT '',
  active tinyint(1) NOT NULL DEFAULT 1,
  left_on date DEFAULT NULL,
  share_flags longtext,
  note text,
  review_days int(10) unsigned DEFAULT NULL,
  verified_at datetime DEFAULT NULL,
  verified_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY deleted_at (deleted_at)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['links']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  from_type varchar(30) NOT NULL,
  from_id bigint(20) unsigned NOT NULL,
  to_type varchar(30) NOT NULL,
  to_id bigint(20) unsigned NOT NULL,
  relation varchar(60) NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY from_ref (from_type,from_id),
  KEY to_ref (to_type,to_id),
  KEY relation (relation)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['runbooks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  purpose text,
  when_to_use text,
  owner_role varchar(60) NOT NULL DEFAULT '',
  estimated_minutes int(10) unsigned NOT NULL DEFAULT 0,
  current_version int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'draft',
  template_key varchar(60) NOT NULL DEFAULT '',
  restore_scope varchar(20) NOT NULL DEFAULT '',
  critical tinyint(1) NOT NULL DEFAULT 0,
  review_days int(10) unsigned DEFAULT NULL,
  last_reviewed datetime DEFAULT NULL,
  last_rehearsed datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY template_key (template_key),
  KEY deleted_at (deleted_at)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['runbook_versions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  runbook_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  steps longtext,
  prerequisites longtext,
  verification longtext,
  rollback_text text,
  change_note text,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY runbook_version (runbook_id,version)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['tests']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL DEFAULT 'restore_test',
  runbook_id bigint(20) unsigned NOT NULL DEFAULT 0,
  runbook_version int(10) unsigned NOT NULL DEFAULT 0,
  backup_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  test_date date NOT NULL,
  who varchar(190) NOT NULL DEFAULT '',
  scope varchar(20) NOT NULL DEFAULT '',
  place varchar(30) NOT NULL DEFAULT '',
  backup_date date DEFAULT NULL,
  minutes int(10) unsigned NOT NULL DEFAULT 0,
  result varchar(20) NOT NULL DEFAULT '',
  problems text,
  step_log longtext,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY kind_date (kind,test_date),
  KEY runbook_id (runbook_id)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['changes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  changed_at datetime NOT NULL,
  kind varchar(40) NOT NULL,
  subject_type varchar(30) NOT NULL DEFAULT '',
  subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subject_label varchar(255) NOT NULL DEFAULT '',
  before_data longtext,
  after_data longtext,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_label varchar(190) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT 'wordpress',
  batch_id varchar(40) NOT NULL DEFAULT '',
  note text,
  `sensitive` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY changed_at (changed_at),
  KEY subject (subject_type,subject_id),
  KEY batch_id (batch_id)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['findings']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rule_id varchar(20) NOT NULL,
  subject_type varchar(30) NOT NULL DEFAULT '',
  subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
  subject_key varchar(191) NOT NULL DEFAULT '',
  severity varchar(20) NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'open',
  message_values longtext,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  snoozed_until datetime DEFAULT NULL,
  accepted_reason text,
  accepted_by bigint(20) unsigned NOT NULL DEFAULT 0,
  resolved_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY state_severity (state,severity),
  KEY rule_subject (rule_id,subject_type,subject_id)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['reminders']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  lead_days int(11) NOT NULL,
  cycle_key varchar(40) NOT NULL,
  sent_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'sent',
  attempts int(10) unsigned NOT NULL DEFAULT 1,
  recipient_count int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY item_cycle (item_id,cycle_key),
  KEY sent_at (sent_at)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['snapshots']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  profile_key varchar(60) NOT NULL,
  note text,
  content longtext NOT NULL,
  hash char(64) NOT NULL,
  schema_version varchar(10) NOT NULL DEFAULT '1',
  plugin_version varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  acknowledgements longtext,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) ENGINE=InnoDB $charset;",

			"CREATE TABLE {$t['exports']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  exported_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  snapshot_id bigint(20) unsigned NOT NULL DEFAULT 0,
  profile varchar(60) NOT NULL DEFAULT '',
  format varchar(10) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  hash char(64) NOT NULL DEFAULT '',
  emailed tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY exported_at (exported_at)
) ENGINE=InnoDB $charset;",
		);
	}
}
