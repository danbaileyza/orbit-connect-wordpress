<?php
/**
 * The change log: one row per value Orbit changed, with the value before and
 * after, so any change can be undone.
 *
 * Values are stored JSON-encoded so arrays (Rank Math's robots list) round-trip
 * exactly. A change is undone only while the field still holds the value Orbit
 * wrote; if someone has edited it since, undo refuses rather than overwrite them.
 */

defined( 'ABSPATH' ) || exit;

class Orbit_Changes {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'orbit_changes';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(20) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				field varchar(40) NOT NULL,
				storage_key varchar(191) NOT NULL,
				before_exists tinyint(1) NOT NULL DEFAULT 0,
				before_value longtext NULL,
				after_value longtext NULL,
				note varchar(255) NOT NULL DEFAULT '',
				undone_at datetime NULL,
				PRIMARY KEY  (id),
				KEY object (object_type, object_id)
			) $charset;"
		);
		update_option( 'orbit_connect_db_version', ORBIT_CONNECT_DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'orbit_connect_db_version' ) !== ORBIT_CONNECT_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Records a change and returns it as the API shows it.
	 */
	public static function record( $object_type, $object_id, $field, $storage_key, $before_exists, $before, $after, $note = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'created_at'    => current_time( 'mysql', true ),
				'user_id'       => get_current_user_id(),
				'object_type'   => $object_type,
				'object_id'     => (int) $object_id,
				'field'         => $field,
				'storage_key'   => $storage_key,
				'before_exists' => $before_exists ? 1 : 0,
				'before_value'  => wp_json_encode( $before ),
				'after_value'   => wp_json_encode( $after ),
				'note'          => substr( (string) $note, 0, 255 ),
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::present( $row ) : null;
	}

	public static function list_changes( $object_type = '', $object_id = 0, $limit = 100 ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( $object_type ) {
			$where[] = 'object_type = %s';
			$args[]  = $object_type;
		}
		if ( $object_id ) {
			$where[] = 'object_id = %d';
			$args[]  = $object_id;
		}
		$args[] = max( 1, min( 500, (int) $limit ) );
		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array_map( array( __CLASS__, 'present' ), $rows ? $rows : array() );
	}

	public static function mark_undone( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'undone_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
	}

	/** The stored row as the API returns it, with decoded values and ISO dates. */
	public static function present( $row ) {
		return array(
			'id'            => (int) $row['id'],
			'created_at'    => mysql2date( 'c', $row['created_at'], false ),
			'user_id'       => (int) $row['user_id'],
			'object_type'   => $row['object_type'],
			'object_id'     => (int) $row['object_id'],
			'field'         => $row['field'],
			'storage_key'   => $row['storage_key'],
			'before_exists' => (bool) $row['before_exists'],
			'before'        => json_decode( (string) $row['before_value'], true ),
			'after'         => json_decode( (string) $row['after_value'], true ),
			'note'          => $row['note'],
			'undone_at'     => $row['undone_at'] ? mysql2date( 'c', $row['undone_at'], false ) : null,
		);
	}
}
