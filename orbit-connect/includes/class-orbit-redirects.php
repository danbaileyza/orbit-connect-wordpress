<?php
/**
 * Redirects Orbit adds, kept in one option and served before WordPress picks a
 * template. The same list works whichever SEO plugin is active.
 */

defined( 'ABSPATH' ) || exit;

class Orbit_Redirects {

	const OPTION = 'orbit_connect_redirects';

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 1 );
	}

	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/** A site-relative path, without query or trailing slash ("/" stays "/"). */
	public static function normalise_path( $from ) {
		$path = wp_parse_url( trim( (string) $from ), PHP_URL_PATH );
		$path = '/' . ltrim( (string) $path, '/' );
		return '/' === $path ? $path : untrailingslashit( $path );
	}

	public static function add( $from, $to, $code, $note = '' ) {
		$list = self::all();
		$from = self::normalise_path( $from );
		foreach ( $list as $redirect ) {
			if ( $redirect['from'] === $from ) {
				return new WP_Error( 'orbit_redirect_exists', sprintf( 'A redirect from %s already exists.', $from ), array( 'status' => 409 ) );
			}
		}
		$id          = (int) get_option( 'orbit_connect_redirect_next_id', 1 );
		$redirect    = array(
			'id'   => $id,
			'from' => $from,
			'to'   => esc_url_raw( $to ),
			'code' => in_array( (int) $code, array( 301, 302, 307, 308 ), true ) ? (int) $code : 301,
		);
		$list[ $id ] = $redirect;
		update_option( self::OPTION, $list, true );
		update_option( 'orbit_connect_redirect_next_id', $id + 1, false );
		return Orbit_Changes::record( 'redirect', $id, 'redirect', self::OPTION, false, null, $redirect, $note );
	}

	/** Removes the redirect only if it's still the one Orbit added. */
	public static function remove_if_unchanged( $id, $expected ) {
		$list = self::all();
		if ( ! isset( $list[ $id ] ) || wp_json_encode( $list[ $id ] ) !== wp_json_encode( $expected ) ) {
			return false;
		}
		unset( $list[ $id ] );
		update_option( self::OPTION, $list, true );
		return true;
	}

	public static function serve() {
		$list = self::all();
		if ( ! $list || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = self::normalise_path( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		foreach ( $list as $redirect ) {
			if ( $redirect['from'] === $path && $redirect['to'] ) {
				wp_redirect( $redirect['to'], $redirect['code'], 'Orbit Connect' );
				exit;
			}
		}
	}
}
