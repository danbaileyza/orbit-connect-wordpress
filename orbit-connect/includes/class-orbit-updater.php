<?php
/**
 * Updates from the plugin's GitHub releases.
 *
 * The plugin header's `Update URI` points at GitHub, so WordPress never asks
 * WordPress.org about this plugin (a plugin there with the same slug can't
 * replace it) and calls `update_plugins_github.com` instead. The latest
 * release is read once every six hours; a release is offered only when its
 * tag is a newer version than this one and its `orbit-connect.zip` is a
 * download from this repository.
 */

defined( 'ABSPATH' ) || exit;

class Orbit_Updater {

	const REPO   = 'danbaileyza/orbit-connect-wordpress';
	const SLUG   = 'orbit-connect';
	const ASSET  = 'orbit-connect.zip';
	const CACHE  = 'orbit_connect_latest_release';

	public static function register() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
	}

	/** What WordPress shows and installs when a newer release is out; false otherwise. */
	public static function offer( $update, $plugin_data, $plugin_file ) {
		if ( self::SLUG . '/orbit-connect.php' !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $update;
		}

		return array(
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => version_compare( $release['version'], ORBIT_CONNECT_VERSION, '>' ) ? $release['package'] : '',
			'requires_php' => '7.4',
		);
	}

	/** "View details" on the Plugins screen: the release notes. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Orbit Connect',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'DevLabs',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => '<pre>' . esc_html( $release['notes'] ) . '</pre>',
			),
		);
	}

	public static function forget() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * The latest release as `{version, package, url, notes, published}`, or
	 * null when GitHub can't be read or the release isn't usable. Failures are
	 * remembered for an hour so a site doesn't ask on every page load.
	 */
	public static function latest() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached['release'];
		}
		$release = self::fetch();
		set_site_transient( self::CACHE, array( 'release' => $release ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $release;
	}

	private static function fetch() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Orbit-Connect/' . ORBIT_CONNECT_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'v' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}
		$package = null;
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$url = (string) ( $asset['browser_download_url'] ?? '' );
			if ( self::ASSET === ( $asset['name'] ?? '' ) && 0 === strpos( $url, 'https://github.com/' . self::REPO . '/releases/download/' ) ) {
				$package = $url;
			}
		}
		if ( ! $package ) {
			return null;
		}

		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode( (string) $data['tag_name'] ),
			'notes'     => mb_substr( (string) ( $data['body'] ?? '' ), 0, 20000 ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}
}
