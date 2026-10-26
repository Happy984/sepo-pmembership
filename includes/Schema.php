<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/** The plugin's tables: members, payments, and the members' board with its votes. */
final class Schema
{
    public static function members(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'smem_members';
    }

    public static function payments(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'smem_payments';
    }

    public static function board(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'smem_board';
    }

    public static function votes(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'smem_votes';
    }

    public static function log(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'smem_log';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();

        dbDelta('CREATE TABLE ' . self::members() . " (
  user_id bigint(20) unsigned NOT NULL,
  level varchar(64) NOT NULL DEFAULT '',
  pending_level varchar(64) NOT NULL DEFAULT '',
  period varchar(16) NOT NULL DEFAULT 'monthly',
  status varchar(16) NOT NULL DEFAULT 'pending',
  since datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  started datetime NULL DEFAULT NULL,
  expires datetime NULL DEFAULT NULL,
  method varchar(64) NOT NULL DEFAULT '',
  currency varchar(3) NOT NULL DEFAULT '',
  total decimal(14,2) NOT NULL DEFAULT 0,
  pending_total decimal(14,2) NOT NULL DEFAULT 0,
  listed tinyint(1) NOT NULL DEFAULT 0,
  reminded datetime NULL DEFAULT NULL,
  updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (user_id),
  KEY status (status),
  KEY level (level),
  KEY expires (expires)
) $c;");

        dbDelta('CREATE TABLE ' . self::payments() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT 'membership',
  level varchar(64) NOT NULL DEFAULT '',
  period varchar(16) NOT NULL DEFAULT '',
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency varchar(3) NOT NULL DEFAULT '',
  method varchar(64) NOT NULL DEFAULT '',
  reference varchar(190) NOT NULL DEFAULT '',
  message text NULL,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  listed tinyint(1) NOT NULL DEFAULT 0,
  recurring tinyint(1) NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'pending',
  proofs longtext NULL,
  admin_note varchar(255) NOT NULL DEFAULT '',
  withdraw_reason text NULL,
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  confirmed_at datetime NULL DEFAULT NULL,
  confirmed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reminded datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY kind (kind),
  KEY status (status),
  KEY project_id (project_id),
  KEY email (email)
) $c;");

        dbDelta('CREATE TABLE ' . self::board() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  area varchar(64) NOT NULL DEFAULT '',
  kind varchar(16) NOT NULL DEFAULT 'post',
  thread bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(190) NOT NULL DEFAULT '',
  body longtext NULL,
  status varchar(16) NOT NULL DEFAULT 'open',
  pinned tinyint(1) NOT NULL DEFAULT 0,
  replies int(10) unsigned NOT NULL DEFAULT 0,
  votes int(10) unsigned NOT NULL DEFAULT 0,
  last_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY area_kind (area,kind),
  KEY thread (thread),
  KEY user_id (user_id)
) $c;");

        dbDelta('CREATE TABLE ' . self::log() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(32) NOT NULL DEFAULT '',
  note text NULL,
  summary varchar(255) NOT NULL DEFAULT '',
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY payment_id (payment_id),
  KEY user_id (user_id),
  KEY action (action),
  KEY created (created)
) $c;");

        dbDelta('CREATE TABLE ' . self::votes() . " (
  item_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (item_id,user_id),
  KEY user_id (user_id)
) $c;");
    }

    /** Times are stored in UTC. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function ts(?string $utc): int
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return 0;
        }
        $t = strtotime($utc . ' UTC');
        return $t === false ? 0 : $t;
    }

    public static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /** A named lock for a short critical section; true when held. */
    public static function lock(string $name, int $wait = 5): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'smem_' . md5($name), $wait)) === 1;
    }

    public static function unlock(string $name): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'smem_' . md5($name)));
    }
}
