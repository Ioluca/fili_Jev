<?php
/**
 * Plugin Name:       Fili
 * Description:       Trova i link interni che mancano e gli articoli che raccontano due volte la stessa notizia. Propone, non scrive: ogni link lo approvi tu e si annulla con un clic.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Luca Cazzaniga
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       fili
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FILI_VERSION', '0.1.0' );
define( 'FILI_DIR', plugin_dir_path( __FILE__ ) );
define( 'FILI_URL', plugin_dir_url( __FILE__ ) );

require_once FILI_DIR . 'includes/class-fili-db.php';
require_once FILI_DIR . 'includes/class-fili-lang.php';
require_once FILI_DIR . 'includes/class-fili-text.php';
require_once FILI_DIR . 'includes/class-fili-jev.php';
require_once FILI_DIR . 'includes/class-fili-engine.php';
require_once FILI_DIR . 'includes/class-fili-apply.php';

register_activation_hook( __FILE__, array( 'Fili_DB', 'install' ) );

if ( is_admin() ) {
	require_once FILI_DIR . 'includes/class-fili-admin.php';
	Fili_Admin::boot();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once FILI_DIR . 'includes/class-fili-cli.php';
}

/**
 * Settings with their defaults. One place, so nothing reads an option by a bare string.
 *
 * @return array<string,mixed>
 */
function fili_settings(): array {
	$defaults = array(
		'route'          => 'typesafe',
		'language'       => 'it',
		'post_types'     => array( 'post' ),
		'threshold'      => 0.60,
		'max_per_post'   => 3,
		'monthly_budget' => 1.00,
		'themes'         => '',
		'read_only'      => 1, // while on, Fili can propose but has no way to touch a post
	);
	$saved = get_option( 'fili_settings', array() );
	return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
}

/**
 * The API key. A constant in wp-config.php wins; otherwise the option, which is
 * stored with autoload off and never printed back in full.
 */
function fili_api_key(): string {
	if ( defined( 'FILI_API_KEY' ) && FILI_API_KEY ) {
		return (string) FILI_API_KEY;
	}
	return (string) get_option( 'fili_api_key', '' );
}
