<?php
/**
 * What Orbit needs to judge a site's health: WordPress's own Site Health
 * results, plugins and themes with their updates, server and database facts,
 * caching, scheduled tasks and a few security settings. Read only.
 */

defined( 'ABSPATH' ) || exit;

class Orbit_Health {

	public static function report() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// WordPress only asks WordPress.org again when its own check is stale.
		wp_update_plugins();
		wp_update_themes();

		return array(
			'generated_at' => gmdate( 'c' ),
			'site_health'  => self::site_health(),
			'wordpress'    => self::wordpress(),
			'server'       => self::server(),
			'database'     => self::database(),
			'plugins'      => self::plugins(),
			'themes'       => self::themes(),
			'cron'         => self::cron(),
			'security'     => self::security(),
		);
	}

	/** Every Site Health test WordPress runs on Tools → Site Health, run here directly. */
	private static function site_health() {
		$health  = WP_Site_Health::get_instance();
		$tests   = WP_Site_Health::get_tests();
		$results = array();
		$run     = array();
		foreach ( $tests['direct'] as $key => $test ) {
			if ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
				$run[ $key ] = array( $health, 'get_test_' . $test['test'] );
			} elseif ( is_callable( $test['test'] ) ) {
				$run[ $key ] = $test['test'];
			}
		}
		foreach ( $tests['async'] as $key => $test ) {
			if ( ! empty( $test['async_direct_test'] ) && is_callable( $test['async_direct_test'] ) ) {
				$run[ $key ] = $test['async_direct_test'];
			}
		}
		foreach ( $run as $key => $callback ) {
			try {
				$result = apply_filters( 'site_status_test_result', call_user_func( $callback ) );
			} catch ( Throwable $e ) {
				continue;
			}
			if ( ! is_array( $result ) || empty( $result['status'] ) ) {
				continue;
			}
			$results[] = array(
				'test'        => (string) ( isset( $result['test'] ) ? $result['test'] : $key ),
				'label'       => wp_strip_all_tags( (string) $result['label'] ),
				'status'      => (string) $result['status'],
				'area'        => isset( $result['badge']['label'] ) ? wp_strip_all_tags( (string) $result['badge']['label'] ) : '',
				'description' => self::text( isset( $result['description'] ) ? $result['description'] : '' ),
				'actions'     => self::text( isset( $result['actions'] ) ? $result['actions'] : '' ),
			);
		}
		return $results;
	}

	private static function wordpress() {
		global $wp_version;
		$core_update = null;
		$updates     = get_core_updates();
		if ( is_array( $updates ) ) {
			foreach ( $updates as $update ) {
				if ( isset( $update->response ) && 'upgrade' === $update->response ) {
					$core_update = $update->current;
					break;
				}
			}
		}
		return array(
			'version'        => $wp_version,
			'update'         => $core_update,
			'home_url'       => home_url( '/' ),
			'https'          => 0 === strpos( home_url(), 'https://' ),
			'multisite'      => is_multisite(),
			'environment'    => wp_get_environment_type(),
			'debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'debug_display'  => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ),
			'script_debug'   => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'wp_cache'       => defined( 'WP_CACHE' ) && WP_CACHE,
			'memory_limit'   => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
			'revisions'      => defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : true,
			'search_visible' => (bool) get_option( 'blog_public' ),
			'dropins'        => array_keys( get_dropins() ),
			'mu_plugins'     => count( get_mu_plugins() ),
		);
	}

	private static function server() {
		global $wpdb;
		$opcache = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$status  = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$opcache = is_array( $status ) && ! empty( $status['opcache_enabled'] );
		}
		return array(
			'php_version'        => PHP_VERSION,
			'php_memory_limit'   => ini_get( 'memory_limit' ),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'opcache'            => $opcache,
			'object_cache'       => (bool) wp_using_ext_object_cache(),
			'database'           => method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : '',
			'web_server'         => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
		);
	}

	private static function database() {
		global $wpdb;
		$autoload_values = "'yes','on','auto-on','auto'";
		$autoload        = $wpdb->get_row( "SELECT COUNT(*) AS n, SUM(LENGTH(option_value)) AS bytes FROM {$wpdb->options} WHERE autoload IN ($autoload_values)", ARRAY_A ); // phpcs:ignore WordPress.DB
		$largest         = $wpdb->get_results( "SELECT option_name AS name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ($autoload_values) ORDER BY LENGTH(option_value) DESC LIMIT 8", ARRAY_A ); // phpcs:ignore WordPress.DB
		$expired         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ); // phpcs:ignore WordPress.DB

		$size = null;
		if ( defined( 'DB_NAME' ) && ! defined( 'DB_ENGINE' ) ) { // DB_ENGINE: the SQLite drop-in has no information_schema.
			$size = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s', DB_NAME ) ); // phpcs:ignore WordPress.DB
		}
		return array(
			'size_bytes'         => null === $size ? null : (int) $size,
			'autoload_count'     => (int) $autoload['n'],
			'autoload_bytes'     => (int) $autoload['bytes'],
			'autoload_largest'   => array_map(
				function ( $row ) {
					return array( 'name' => $row['name'], 'bytes' => (int) $row['bytes'] );
				},
				$largest ? $largest : array()
			),
			'transients_expired' => $expired,
			'transients'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%' ) ), // phpcs:ignore WordPress.DB
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ), // phpcs:ignore WordPress.DB
			'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ), // phpcs:ignore WordPress.DB
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ), // phpcs:ignore WordPress.DB
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ), // phpcs:ignore WordPress.DB
			'posts'              => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'" ), // phpcs:ignore WordPress.DB
		);
	}

	private static function plugins() {
		$updates      = get_site_transient( 'update_plugins' );
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$out          = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug  = false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			$out[] = array(
				'file'        => $file,
				'slug'        => $slug,
				'name'        => wp_strip_all_tags( $data['Name'] ),
				'version'     => $data['Version'],
				'author'      => wp_strip_all_tags( $data['AuthorName'] ? $data['AuthorName'] : $data['Author'] ),
				'active'      => is_plugin_active( $file ),
				'update'      => isset( $updates->response[ $file ]->new_version ) ? $updates->response[ $file ]->new_version : null,
				'auto_update' => in_array( $file, $auto_updates, true ),
				'requires_wp' => isset( $data['RequiresWP'] ) ? $data['RequiresWP'] : '',
			);
		}
		return $out;
	}

	private static function themes() {
		$updates = get_site_transient( 'update_themes' );
		$current = wp_get_theme();
		$out     = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[] = array(
				'slug'    => $slug,
				'name'    => wp_strip_all_tags( $theme->get( 'Name' ) ),
				'version' => $theme->get( 'Version' ),
				'active'  => $slug === $current->get_stylesheet(),
				'parent'  => $slug === $current->get_template() && $current->get_template() !== $current->get_stylesheet(),
				'update'  => isset( $updates->response[ $slug ]['new_version'] ) ? $updates->response[ $slug ]['new_version'] : null,
			);
		}
		return $out;
	}

	private static function cron() {
		$crons   = _get_cron_array();
		$events  = 0;
		$overdue = 0;
		$late    = time() - HOUR_IN_SECONDS;
		foreach ( (array) $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $instances ) {
				$events += count( (array) $instances );
				if ( $timestamp < $late ) {
					$overdue += count( (array) $instances );
				}
			}
		}
		return array(
			'events'          => $events,
			'overdue'         => $overdue,
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	private static function security() {
		$counts = count_users();
		return array(
			'administrators'    => isset( $counts['avail_roles']['administrator'] ) ? (int) $counts['avail_roles']['administrator'] : 0,
			'users'             => (int) $counts['total_users'],
			'file_editing'      => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'xmlrpc'            => (bool) apply_filters( 'xmlrpc_enabled', true ),
			'users_can_register' => (bool) get_option( 'users_can_register' ),
			'default_role'      => get_option( 'default_role' ),
			'auto_updates_core' => ! ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ),
		);
	}

	/** Site Health's HTML as plain sentences. */
	private static function text( $html ) {
		$text = wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />', '</li>' ), "\n", (string) $html ) );
		return trim( preg_replace( "/\n\s*\n+/", "\n", html_entity_decode( $text, ENT_QUOTES ) ) );
	}
}
