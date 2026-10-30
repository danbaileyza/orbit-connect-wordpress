<?php
/**
 * Plugin Name:       Orbit Connect
 * Description:       Lets Orbit check the site's health and read and set SEO titles, descriptions, canonicals, noindex, image alt text and redirects, keeping every previous value so a change can be undone.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DevLabs
 * License:           GPL-2.0-or-later
 * Text Domain:       orbit-connect
 *
 * Orbit signs in with a WordPress application password (Users → Profile →
 * Application Passwords). Every endpoint needs a user who can manage the
 * site, and changing a page also needs permission to edit that page.
 */

defined( 'ABSPATH' ) || exit;

define( 'ORBIT_CONNECT_VERSION', '1.2.0' );
define( 'ORBIT_CONNECT_DB_VERSION', '1' );

require_once __DIR__ . '/includes/class-orbit-changes.php';
require_once __DIR__ . '/includes/class-orbit-seo-fields.php';
require_once __DIR__ . '/includes/class-orbit-redirects.php';
require_once __DIR__ . '/includes/class-orbit-images.php';
require_once __DIR__ . '/includes/class-orbit-health.php';
require_once __DIR__ . '/includes/class-orbit-rest.php';

register_activation_hook( __FILE__, array( 'Orbit_Changes', 'install' ) );

add_action( 'plugins_loaded', array( 'Orbit_Changes', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'Orbit_REST', 'register_routes' ) );

Orbit_SEO_Fields::register_output();
Orbit_Redirects::register();
Orbit_Images::register();
