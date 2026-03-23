<?php
/**
 * Plugin Name: LG Stripe Membership
 * Plugin URI:  https://loothgroup.com
 * Description: Stripe-powered membership billing for The Looth Group.
 * Version:     0.1.0
 * Author:      Ian Davlin
 * Author URI:  https://loothgroup.com
 * Text Domain: lg-stripe-membership
 * Requires PHP: 8.3
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'LGSM_VERSION', '0.1.0' );
define( 'LGSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Composer autoload (Stripe SDK)
if ( file_exists( LGSM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once LGSM_PLUGIN_DIR . 'vendor/autoload.php';
}

// Class autoloader
spl_autoload_register( function ( string $class ): void {
    $prefix = 'LGSM_';
    if ( ! str_starts_with( $class, $prefix ) ) {
        return;
    }
    $relative = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
    $file     = LGSM_PLUGIN_DIR . 'includes/class-' . $relative . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

// Boot the plugin
add_action( 'plugins_loaded', function (): void {
    LGSM_Admin_Settings::init();
    LGSM_Webhook_Handler::init();
    LGSM_Checkout::init();
    LGSM_Reconciler::init();
});
