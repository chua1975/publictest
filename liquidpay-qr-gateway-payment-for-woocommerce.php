<?php
/**
 * Plugin Name: Liquidpay QR Code Gateway Payment for WooCommerce
 * Description: It enables a Woocommerce site to accept payments Liquid pay.
 * Version: 1.0.10
 * Author: Dewatasoft
 * Author URI: http://dewatasoft.com
 * License: GPLv3
 * Text Domain: liquidpay-qr-gateway-payment-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 3.1
 * WC tested up to: 5.2

 * @category WooCommerce
 * @package  LiquidPay QR Code Payment for WooCommerce
 * @author   Dewatasoft <nabil@dewatasoft.com>
 * @license  http://www.gnu.org/licenses/ GNU General Public License
 * @link     https://wordpress.org/plugins/liquidpay-qr-gateway-payment-for-woocommerce/
 *
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$consts = array(
    'LIQUIDPAY_WOO_PLUGIN_VERSION'       => '1.0.10', // plugin version
    'LIQUIDPAY_WOO_PLUGIN_BASENAME'      => plugin_basename( __FILE__ ),
	'LIQUIDPAY_WOO_PLUGIN_DIR'           => plugin_dir_url( __FILE__ ),
	//'LIQUIDPAY_WOO_PLUGIN_ENABLE_DEBUG'  => true
);

foreach( $consts as $const => $value ) {
    if ( ! defined( $const ) ) {
        define( $const, $value );
    }
}

// Internationalization
add_action( 'plugins_loaded', 'liquidpay_plugin_load_textdomain' );

/**
 * Load plugin textdomain.
 *
 * @since 1.0.0
 */
function liquidpay_plugin_load_textdomain() {
    load_plugin_textdomain( 'liquidpay-qr-gateway-payment-for-woocommerce', false, dirname( LIQUIDPAY_WOO_PLUGIN_BASENAME ) . '/languages/' );
}

// register activation hook
register_activation_hook( __FILE__, 'liquidpay_plugin_activation' );

function liquidpay_plugin_activation() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    set_transient( 'liquidpay-admin-notice-on-activation', true, 5 );
}

// register deactivation hook
register_deactivation_hook( __FILE__, 'liquidpay_plugin_deactivation' );

function liquidpay_plugin_deactivation() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    delete_option( 'liquidpay_plugin_dismiss_rating_notice' );
    delete_option( 'liquidpay_plugin_no_thanks_rating_notice' );
    delete_option( 'liquidpay_plugin_installed_time' );
}

// plugin action links
add_filter( 'plugin_action_links_' . LIQUIDPAY_WOO_PLUGIN_BASENAME, 'liquidpay_add_action_links', 10, 2 );

function liquidpay_add_action_links( $links ) {
    $liquidwclinks = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc-liquidpay' ) . '">' . __( 'Settings', 'liquidpay-qr-gateway-payment-for-woocommerce' ) . '</a>',
    );
    return array_merge( $liquidwclinks, $links );
}

// plugin row elements
add_filter( 'plugin_row_meta', 'liquidpay_plugin_meta_links', 10, 2 );

function liquidpay_plugin_meta_links( $links, $file ) {
    $plugin = LIQUIDPAY_WOO_PLUGIN_BASENAME;
    if ( $file == $plugin ) // only for this plugin
        return array_merge( $links,
            array( '<a href="https://wordpress.org/support/plugin/liquidpay-qr-gateway-payment-for-woocommerce/" target="_blank">' . __( 'Support', 'liquidpay-qr-gateway-payment-for-woocommerce' ) . '</a>' ),
            array( '<a href="https://wordpress.org/plugins/liquidpay-qr-gateway-payment-for-woocommerce/#faq" target="_blank">' . __( 'FAQ', 'liquidpay-qr-gateway-payment-for-woocommerce' ) . '</a>' ),
            array( '<a href="https://www.paypal.me/iamsayan/" target="_blank">' . __( 'Donate', 'liquidpay-qr-gateway-payment-for-woocommerce' ) . '</a>' )
        );
    return $links;
}

// add admin notices
add_action( 'admin_notices', 'liquidpay_new_plugin_install_notice' );

function liquidpay_new_plugin_install_notice() {
    // Show a warning to sites running PHP < 5.6
    if( version_compare( PHP_VERSION, '5.6', '<' ) ) {
	    echo '<div class="error"><p>' . __( 'Your version of PHP is below the minimum version of PHP required by LIQUIDPAY QR Code Payment for WooCommerce plugin. Please contact your host and request that your version be upgraded to 5.6 or later.', 'liquidpay-qr-gateway-payment-for-woocommerce' ) . '</p></div>';
    }

    // Check transient, if available display notice
    if( get_transient( 'liquidpay-admin-notice-on-activation' ) ) { ?>
        <div class="notice notice-success">
            <p><strong><?php printf( __( 'Thanks for installing %1$s v%2$s plugin. Click <a href="%3$s">here</a> to configure plugin settings.', 'liquidpay-qr-gateway-payment-for-woocommerce' ), 'LIQUIDPAY QR Code Payment for WooCommerce', LIQUIDPAY_WOO_PLUGIN_VERSION, admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc-liquidpay' ) ); ?></strong></p>
        </div> <?php
        delete_transient( 'liquidpay-admin-notice-on-activation' );
    }
}

//require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/liquid.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/payment.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/notice.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/pages.php';