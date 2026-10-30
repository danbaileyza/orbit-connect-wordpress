<?php
/**
 * The REST endpoints Orbit calls, under /wp-json/orbit/v1.
 */

defined( 'ABSPATH' ) || exit;

class Orbit_REST {

	const NS = 'orbit/v1';

	public static function register_routes() {
		$admin = array( __CLASS__, 'can_manage' );

		register_rest_route( self::NS, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/page', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_page' ),
			'permission_callback' => $admin,
			'args'                => array( 'url' => array( 'required' => true, 'type' => 'string' ) ),
		) );
		register_rest_route( self::NS, '/page/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_page' ),
				'permission_callback' => $admin,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_page' ),
				'permission_callback' => $admin,
				'args'                => array(
					'title'       => array( 'type' => array( 'string', 'null' ), 'maxLength' => 300 ),
					'description' => array( 'type' => array( 'string', 'null' ), 'maxLength' => 1000 ),
					'canonical'   => array( 'type' => array( 'string', 'null' ), 'maxLength' => 2000 ),
					'noindex'     => array( 'type' => array( 'boolean', 'null' ) ),
					'note'        => array( 'type' => 'string', 'maxLength' => 255 ),
				),
			),
		) );
		register_rest_route( self::NS, '/image', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'set_image' ),
			'permission_callback' => $admin,
			'args'                => array(
				'id'   => array( 'type' => 'integer' ),
				'src'  => array( 'type' => 'string' ),
				'alt'  => array( 'required' => true, 'type' => 'string', 'maxLength' => 500 ),
				'note' => array( 'type' => 'string', 'maxLength' => 255 ),
			),
		) );
		register_rest_route( self::NS, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( 'Orbit_Health', 'report' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/media/lookup', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'lookup_media' ),
			'permission_callback' => $admin,
			'args'                => array(
				'srcs' => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 200 ),
			),
		) );
		register_rest_route( self::NS, '/redirects', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_redirects' ),
				'permission_callback' => $admin,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'add_redirect' ),
				'permission_callback' => $admin,
				'args'                => array(
					'from' => array( 'required' => true, 'type' => 'string' ),
					'to'   => array( 'required' => true, 'type' => 'string', 'format' => 'uri' ),
					'code' => array( 'type' => 'integer', 'default' => 301 ),
					'note' => array( 'type' => 'string', 'maxLength' => 255 ),
				),
			),
		) );
		register_rest_route( self::NS, '/changes', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_changes' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/changes/(?P<id>\d+)/undo', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'undo' ),
			'permission_callback' => $admin,
		) );
	}

	public static function can_manage() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'orbit_forbidden',
			is_user_logged_in() ? 'This user can\'t manage the site. Connect Orbit with an administrator\'s application password.' : 'Sign in with an application password.',
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public static function status() {
		global $wp_version;
		$user = wp_get_current_user();
		return array(
			'plugin_version'     => ORBIT_CONNECT_VERSION,
			'wordpress_version'  => $wp_version,
			'php_version'        => PHP_VERSION,
			'site_name'          => get_bloginfo( 'name' ),
			'home_url'           => home_url( '/' ),
			'seo_plugin'         => Orbit_SEO_Fields::active_plugin(),
			'seo_plugin_version' => Orbit_SEO_Fields::active_plugin_version(),
			'seo_plugin_problem' => Orbit_SEO_Fields::active_plugin_problem(),
			'user'               => $user->user_login,
			'features'           => array( 'seo_fields', 'image_alt', 'media_lookup', 'health', 'redirects', 'undo' ),
		);
	}

	/** By id, or by the URL Orbit crawled. */
	public static function get_page( WP_REST_Request $request ) {
		$post = self::post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return self::present_page( $post );
	}

	public static function set_page( WP_REST_Request $request ) {
		$post = self::post_from_request( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'orbit_cannot_edit', 'This user can\'t edit that page.', array( 'status' => 403 ) );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$values = array();
		foreach ( Orbit_SEO_Fields::FIELDS as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$values[ $field ] = $params[ $field ];
			}
		}
		if ( ! $values ) {
			return new WP_Error( 'orbit_nothing_to_set', 'Send at least one of title, description, canonical or noindex.', array( 'status' => 400 ) );
		}
		if ( isset( $values['title'] ) ) {
			$values['title'] = sanitize_text_field( $values['title'] );
		}
		if ( isset( $values['description'] ) ) {
			$values['description'] = sanitize_textarea_field( $values['description'] );
		}
		if ( isset( $values['canonical'] ) && '' !== trim( $values['canonical'] ) ) {
			$canonical = esc_url_raw( trim( $values['canonical'] ), array( 'http', 'https' ) );
			if ( ! $canonical ) {
				return new WP_Error( 'orbit_bad_canonical', 'The canonical must be a full http or https address.', array( 'status' => 400 ) );
			}
			$values['canonical'] = $canonical;
		}
		$changes = Orbit_SEO_Fields::write( $post->ID, $values, (string) $request->get_param( 'note' ) );
		return array(
			'changes' => $changes,
			'page'    => self::present_page( get_post( $post->ID ) ),
		);
	}

	public static function set_image( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! $id && $request->get_param( 'src' ) ) {
			$id = Orbit_Images::attachment_for_src( $request->get_param( 'src' ) );
		}
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new WP_Error( 'orbit_image_not_found', 'That image isn\'t in the site\'s media library, so its alt text can\'t be set here.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'orbit_cannot_edit', 'This user can\'t edit that image.', array( 'status' => 403 ) );
		}
		$change = Orbit_Images::set_alt( $id, $request->get_param( 'alt' ), (string) $request->get_param( 'note' ) );
		return array(
			'changes' => $change ? array( $change ) : array(),
			'image'   => Orbit_Images::read( $id ),
		);
	}

	/**
	 * Which of these image addresses (as a page shows them) are in the media
	 * library, with their alt text. Read only.
	 */
	public static function lookup_media( WP_REST_Request $request ) {
		$out = array();
		foreach ( array_unique( array_map( 'strval', (array) $request->get_param( 'srcs' ) ) ) as $src ) {
			$id    = Orbit_Images::attachment_for_src( $src );
			$out[] = array(
				'src' => $src,
				'id'  => $id,
				'alt' => $id ? (string) get_post_meta( $id, Orbit_Images::ALT_KEY, true ) : null,
			);
		}
		return $out;
	}

	public static function list_redirects() {
		return array_values( Orbit_Redirects::all() );
	}

	public static function add_redirect( WP_REST_Request $request ) {
		$change = Orbit_Redirects::add( $request->get_param( 'from' ), $request->get_param( 'to' ), $request->get_param( 'code' ), (string) $request->get_param( 'note' ) );
		if ( is_wp_error( $change ) ) {
			return $change;
		}
		return array( 'changes' => array( $change ) );
	}

	public static function list_changes( WP_REST_Request $request ) {
		return Orbit_Changes::list_changes(
			sanitize_key( (string) $request->get_param( 'object_type' ) ),
			(int) $request->get_param( 'object_id' ),
			$request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : 100
		);
	}

	/**
	 * Puts back the value from before a change, only while the field still holds
	 * the value that change wrote.
	 */
	public static function undo( WP_REST_Request $request ) {
		$change = Orbit_Changes::get( (int) $request['id'] );
		if ( ! $change ) {
			return new WP_Error( 'orbit_change_not_found', 'There\'s no change with that id on this site.', array( 'status' => 404 ) );
		}
		if ( $change['undone_at'] ) {
			return new WP_Error( 'orbit_already_undone', 'That change was already undone.', array( 'status' => 409 ) );
		}

		if ( 'redirect' === $change['object_type'] ) {
			if ( ! Orbit_Redirects::remove_if_unchanged( $change['object_id'], $change['after'] ) ) {
				return self::changed_since();
			}
		} else {
			$id = $change['object_id'];
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'orbit_cannot_edit', 'This user can\'t edit that page.', array( 'status' => 403 ) );
			}
			$key     = $change['storage_key'];
			$exists  = metadata_exists( 'post', $id, $key );
			$current = $exists ? get_post_meta( $id, $key, true ) : null;
			$matches = null === $change['after'] ? ! $exists : ( $exists && wp_json_encode( $current ) === wp_json_encode( $change['after'] ) );
			if ( ! $matches ) {
				return self::changed_since();
			}
			if ( $change['before_exists'] ) {
				update_post_meta( $id, $key, $change['before'] );
			} else {
				delete_post_meta( $id, $key );
			}
			Orbit_SEO_Fields::after_change( 'attachment' === $change['object_type'] ? (int) wp_get_post_parent_id( $id ) : $id );
		}
		Orbit_Changes::mark_undone( $change['id'] );
		return array( 'change' => Orbit_Changes::get( $change['id'] ) );
	}

	private static function changed_since() {
		return new WP_Error( 'orbit_changed_since', 'This value has been changed on the site since Orbit set it, so undo would overwrite that change. Edit it in WordPress instead.', array( 'status' => 409 ) );
	}

	private static function post_from_request( WP_REST_Request $request ) {
		if ( $request['id'] ) {
			$post = get_post( (int) $request['id'] );
		} else {
			$url = (string) $request->get_param( 'url' );
			$id  = url_to_postid( $url );
			if ( ! $id && '/' === Orbit_Redirects::normalise_path( $url ) ) {
				if ( 'page' !== get_option( 'show_on_front' ) ) {
					return new WP_Error( 'orbit_home_not_a_page', 'The home page lists the latest posts, so its SEO title is set in the SEO plugin\'s settings rather than on a page.', array( 'status' => 404 ) );
				}
				$id = (int) get_option( 'page_on_front' );
			}
			$post = $id ? get_post( $id ) : null;
		}
		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'orbit_page_not_found', 'No post or page on this site has that address.', array( 'status' => 404 ) );
		}
		return $post;
	}

	private static function present_page( $post ) {
		return array(
			'id'         => $post->ID,
			'type'       => $post->post_type,
			'status'     => $post->post_status,
			'url'        => get_permalink( $post ),
			'post_title' => get_the_title( $post ),
			'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
			'seo_plugin' => Orbit_SEO_Fields::active_plugin(),
			'seo'        => Orbit_SEO_Fields::read( $post->ID ),
			'images'     => Orbit_Images::in_post( $post ),
		);
	}
}
