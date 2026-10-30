<?php
/**
 * Image alt text. WordPress keeps it on the media item; images already placed in
 * a page carry their own copy in the page's HTML, often empty. Rather than
 * rewrite page content, empty alts in content are filled from the media item
 * when the page is shown.
 */

defined( 'ABSPATH' ) || exit;

class Orbit_Images {

	const ALT_KEY = '_wp_attachment_image_alt';

	public static function register() {
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'fill_empty_alt' ), 10, 3 );
	}

	/**
	 * The media item behind an image URL. The crawler sees resized copies
	 * (photo-300x200.jpg) and big-image copies (photo-scaled.jpg), so those are
	 * tried as the original too.
	 */
	public static function attachment_for_src( $src ) {
		$src = strtok( (string) $src, '?#' );
		$id  = attachment_url_to_postid( $src );
		if ( $id ) {
			return $id;
		}
		$original = preg_replace( '/-(\d+x\d+|scaled|rotated)(?=\.[a-z0-9]+$)/i', '', $src );
		if ( $original !== $src ) {
			$id = attachment_url_to_postid( $original );
			if ( $id ) {
				return $id;
			}
		}
		// Uploads may be stored with a different scheme or host than the crawler used.
		$uploads = wp_get_upload_dir();
		$path    = wp_parse_url( $original, PHP_URL_PATH );
		$base    = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( $path && $base && 0 === strpos( $path, $base ) ) {
			return attachment_url_to_postid( $uploads['baseurl'] . substr( $path, strlen( $base ) ) );
		}
		return 0;
	}

	public static function read( $attachment_id ) {
		return array(
			'id'  => (int) $attachment_id,
			'src' => wp_get_attachment_url( $attachment_id ),
			'alt' => (string) get_post_meta( $attachment_id, self::ALT_KEY, true ),
		);
	}

	public static function set_alt( $attachment_id, $alt, $note = '' ) {
		$alt    = sanitize_text_field( $alt );
		$exists = metadata_exists( 'post', $attachment_id, self::ALT_KEY );
		$before = $exists ? get_post_meta( $attachment_id, self::ALT_KEY, true ) : null;
		if ( $exists && $before === $alt ) {
			return null;
		}
		update_post_meta( $attachment_id, self::ALT_KEY, $alt );
		$change = Orbit_Changes::record( 'attachment', $attachment_id, 'alt', self::ALT_KEY, $exists, $before, $alt, $note );
		self::purge_pages_using( $attachment_id );
		return $change;
	}

	/** Images in a page's content and its featured image. */
	public static function in_post( $post ) {
		$images = array();
		$seen   = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', (string) $post->post_content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$src = self::attr( $tag, 'src' );
				if ( ! $src || isset( $seen[ $src ] ) ) {
					continue;
				}
				$seen[ $src ] = true;
				$id           = preg_match( '/\bwp-image-(\d+)\b/', (string) self::attr( $tag, 'class' ), $m ) ? (int) $m[1] : self::attachment_for_src( $src );
				$alt          = self::attr( $tag, 'alt' );
				$images[]     = array(
					'id'         => $id,
					'src'        => $src,
					'alt'        => null === $alt ? null : html_entity_decode( $alt, ENT_QUOTES ),
					'media_alt'  => $id ? (string) get_post_meta( $id, self::ALT_KEY, true ) : null,
					'in_content' => true,
				);
			}
		}
		$thumb = get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$images[] = array_merge( self::read( $thumb ), array( 'media_alt' => (string) get_post_meta( $thumb, self::ALT_KEY, true ), 'in_content' => false ) );
		}
		return $images;
	}

	public static function fill_empty_alt( $tag, $context, $attachment_id ) {
		if ( ! $attachment_id ) {
			return $tag;
		}
		$alt = self::attr( $tag, 'alt' );
		if ( null !== $alt && '' !== trim( $alt ) ) {
			return $tag;
		}
		$media_alt = trim( (string) get_post_meta( $attachment_id, self::ALT_KEY, true ) );
		if ( '' === $media_alt ) {
			return $tag;
		}
		$attr = 'alt="' . esc_attr( $media_alt ) . '"';
		if ( null === $alt ) {
			return preg_replace( '/^<img\b/i', '<img ' . $attr, $tag );
		}
		return preg_replace( '/\balt\s*=\s*(["\'])\s*\1/i', $attr, $tag, 1 );
	}

	private static function attr( $tag, $name ) {
		if ( preg_match( '/\s' . $name . '\s*=\s*"([^"]*)"/i', $tag, $m ) || preg_match( "/\s" . $name . "\s*=\s*'([^']*)'/i", $tag, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/** The pages showing this image, so their cached copies are dropped. */
	private static function purge_pages_using( $attachment_id ) {
		global $wpdb;
		$parent = (int) wp_get_post_parent_id( $attachment_id );
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 50",
				'%wp-image-' . (int) $attachment_id . '%'
			)
		);
		foreach ( array_unique( array_filter( array_merge( array( $parent ), array_map( 'intval', $ids ) ) ) ) as $post_id ) {
			Orbit_SEO_Fields::after_change( $post_id );
		}
	}
}
