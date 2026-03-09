<?php
/**
 * Plugin Name: DonatePress
 * Plugin URI: https://wbcomdesigns.com/
 * Description: Performance-first donation plugin for WordPress with Stripe and PayPal support.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Wbcom Designs
 * Author URI: https://wbcomdesigns.com/
 * Text Domain: donatepress
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DONATEPRESS_VERSION', '0.1.0' );
define( 'DONATEPRESS_FILE', __FILE__ );
define( 'DONATEPRESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DONATEPRESS_URL', plugin_dir_url( __FILE__ ) );

autoload_donatepress();

register_activation_hook( __FILE__, array( 'DonatePress\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DonatePress\\Core\\Activator', 'deactivate' ) );

add_action( 'plugins_loaded', 'donatepress_bootstrap' );

/**
 * Basic PSR-4-like autoloader for the plugin namespace.
 */
function autoload_donatepress() {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'DonatePress\\';

			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}

			$relative_class = substr( $class, strlen( $prefix ) );
			$path           = DONATEPRESS_PATH . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	);
}

/**
 * Boot plugin runtime.
 */
function donatepress_bootstrap() {
	load_plugin_textdomain( 'donatepress', false, dirname( plugin_basename( DONATEPRESS_FILE ) ) . '/languages' );

	$plugin = new DonatePress\Core\Plugin();
	$plugin->init();
}

if ( ! function_exists( 'donatepress_render_form' ) ) {
	/**
	 * Render DonatePress form via PHP.
	 *
	 * @param array<string,mixed> $atts Shortcode-like attributes.
	 */
	function donatepress_render_form( array $atts = array() ): string {
		$shortcode = new DonatePress\Frontend\FormShortcode();
		$shortcode->register_assets();
		return $shortcode->render( $atts );
	}
}
