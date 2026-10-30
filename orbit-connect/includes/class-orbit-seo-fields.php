<?php
/**
 * A page's SEO title, description, canonical and noindex, kept in Yoast's or
 * Rank Math's own fields when either is active and in Orbit's fields otherwise.
 *
 * Yoast caches what it prints in its indexables table; its post-meta watcher
 * rebuilds a page's entry at the end of any request that changed a `_yoast_wpseo_`
 * field, so writing the field is enough. Rank Math reads post meta directly.
 */

defined( 'ABSPATH' ) || exit;

class Orbit_SEO_Fields {

	const FIELDS = array( 'title', 'description', 'canonical', 'noindex' );

	const KEYS = array(
		'yoast'    => array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'canonical'   => '_yoast_wpseo_canonical',
			'noindex'     => '_yoast_wpseo_meta-robots-noindex',
		),
		'rankmath' => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'canonical'   => 'rank_math_canonical_url',
			'noindex'     => 'rank_math_robots',
		),
		'none'     => array(
			'title'       => '_orbit_seo_title',
			'description' => '_orbit_seo_description',
			'canonical'   => '_orbit_seo_canonical',
			'noindex'     => '_orbit_seo_noindex',
		),
	);

	/** Which SEO plugin owns the page's SEO output. Checked late: plugins load alphabetically after us. */
	public static function active_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return 'none';
	}

	public static function active_plugin_version() {
		switch ( self::active_plugin() ) {
			case 'yoast':
				return WPSEO_VERSION;
			case 'rankmath':
				return RANK_MATH_VERSION;
			default:
				return null;
		}
	}

	/** Why the active SEO plugin isn't printing SEO tags, if it isn't. */
	public static function active_plugin_problem() {
		if ( 'rankmath' === self::active_plugin() && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_invalid_registration' ) && \RankMath\Helper::is_invalid_registration() ) {
			return 'Rank Math isn\'t set up on this site (its setup wizard was neither finished nor skipped), so it isn\'t printing SEO tags. Finish or skip it in Rank Math\'s dashboard.';
		}
		return null;
	}

	public static function key( $field ) {
		return self::KEYS[ self::active_plugin() ][ $field ];
	}

	/** The page's current values; null where the field isn't set (the SEO plugin's default applies). */
	public static function read( $post_id ) {
		$out = array();
		foreach ( self::FIELDS as $field ) {
			$raw           = get_post_meta( $post_id, self::key( $field ), true );
			$out[ $field ] = 'noindex' === $field ? self::noindex_from_raw( $raw ) : ( '' === $raw ? null : (string) $raw );
		}
		return $out;
	}

	/**
	 * Sets the given fields and records each change. Fields whose value doesn't
	 * change are skipped. Returns the recorded changes.
	 */
	public static function write( $post_id, $values, $note = '' ) {
		$changes = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $values ) ) {
				continue;
			}
			$key    = self::key( $field );
			$exists = metadata_exists( 'post', $post_id, $key );
			$before = $exists ? get_post_meta( $post_id, $key, true ) : null;
			$after  = 'noindex' === $field ? self::noindex_to_raw( $values[ $field ], $before ) : self::text_to_raw( $values[ $field ] );

			if ( ( null === $after && ! $exists ) || ( $exists && wp_json_encode( $before ) === wp_json_encode( $after ) ) ) {
				continue;
			}
			if ( null === $after ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $after );
			}
			$changes[] = Orbit_Changes::record( 'post', $post_id, $field, $key, $exists, $before, $after, $note );
		}
		if ( $changes ) {
			self::after_change( $post_id );
		}
		return $changes;
	}

	/** Empty text means "not set", so the SEO plugin's default takes over again. */
	private static function text_to_raw( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	private static function noindex_from_raw( $raw ) {
		switch ( self::active_plugin() ) {
			case 'yoast':
				// '1' = noindex, '2' = index, empty = the post type's default.
				return '1' === $raw ? true : ( '2' === $raw ? false : null );
			case 'rankmath':
				$list = is_array( $raw ) ? $raw : array();
				return in_array( 'noindex', $list, true ) ? true : ( in_array( 'index', $list, true ) ? false : null );
			default:
				return '1' === $raw ? true : null;
		}
	}

	/** null clears the page's own setting; true/false set it explicitly. */
	private static function noindex_to_raw( $noindex, $before ) {
		if ( null === $noindex ) {
			return null;
		}
		$noindex = (bool) $noindex;
		switch ( self::active_plugin() ) {
			case 'yoast':
				return $noindex ? '1' : '2';
			case 'rankmath':
				// Keep Rank Math's other robots values (nofollow, noarchive…).
				$list   = is_array( $before ) ? $before : array();
				$list   = array_values( array_diff( $list, array( 'index', 'noindex' ) ) );
				$list[] = $noindex ? 'noindex' : 'index';
				return $list;
			default:
				return $noindex ? '1' : null;
		}
	}

	/** Drops WordPress's and the common page caches' copies of the page, so Orbit's check sees the new value. */
	public static function after_change( $post_id ) {
		clean_post_cache( $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}
		do_action( 'litespeed_purge_post', $post_id );
		do_action( 'orbit_connect_changed', $post_id );
	}

	// MARK: - Output when no SEO plugin is active

	public static function register_output() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_title' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_description' ), 1 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'filter_canonical' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ) );
	}

	private static function own_value( $field, $post_id = 0 ) {
		if ( 'none' !== self::active_plugin() ) {
			return '';
		}
		if ( ! $post_id ) {
			if ( ! is_singular() ) {
				return '';
			}
			$post_id = get_queried_object_id();
		}
		return (string) get_post_meta( $post_id, self::KEYS['none'][ $field ], true );
	}

	public static function filter_title( $title ) {
		$own = self::own_value( 'title' );
		// pre_get_document_title skips WordPress's own escaping.
		return '' !== $own ? esc_html( $own ) : $title;
	}

	public static function print_description() {
		$own = self::own_value( 'description' );
		if ( '' !== $own ) {
			echo '<meta name="description" content="' . esc_attr( $own ) . '" />' . "\n";
		}
	}

	public static function filter_canonical( $url, $post ) {
		$own = self::own_value( 'canonical', $post ? $post->ID : 0 );
		return '' !== $own ? $own : $url;
	}

	public static function filter_robots( $robots ) {
		if ( '1' === self::own_value( 'noindex' ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		return $robots;
	}
}
