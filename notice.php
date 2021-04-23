<?php

/**
 * The admin-facing functionality of the plugin.
 *
 * @package    LIQUIDPAY QR Code Payment for WooCommerce
 * @subpackage Includes
 * @author     Novayadi Komang
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 */

add_action( 'admin_notices', 'liquidpay_rating_admin_notice' );
add_action( 'admin_init', 'liquidpay_dismiss_rating_admin_notice' );

function liquidpay_rating_admin_notice() {
    // Show notice after 240 hours (10 days) from installed time.
    if ( liquidpay_plugin_get_installed_time() > strtotime( '-240 hours' )
        || '1' === get_option( 'liquidpay_plugin_dismiss_rating_notice' )
        || ! current_user_can( 'manage_options' )
        || apply_filters( 'liquidpay_plugin_hide_sticky_notice', false ) ) {
        return;
    }

    $dismiss = wp_nonce_url( add_query_arg( 'liquidpay_rating_notice_action', 'dismiss_rating_true' ), 'liquidpay_dismiss_rating_true' ); 
    $no_thanks = wp_nonce_url( add_query_arg( 'liquidpay_rating_notice_action', 'no_thanks_rating_true' ), 'liquidpay_no_thanks_rating_true' ); ?>
    
    <div class="notice notice-success">
        <p><?php _e( 'Hey, I noticed you\'ve been using LIQUIDPAY QR Code Payment for WooCommerce for more than 2 week – that’s awesome! Could you please do me a BIG favor and give it a <strong>5-star</strong> rating on WordPress? Just to help me spread the word and boost my motivation.', 'liquidpay-qr-gateway-payment-for-woocommerce' ); ?></p>
        <p><a href="https://wordpress.org/support/plugin/liquidpay-qr-gateway-payment-for-woocommerce/reviews/?filter=5#new-post" target="_blank" class="button button-secondary"><?php _e( 'Ok, you deserve it', 'liquidpay-qr-gateway-payment-for-woocommerce' ); ?></a>&nbsp;
        <a href="<?php echo $dismiss; ?>" class="already-did"><strong><?php _e( 'I already did', 'liquidpay-qr-gateway-payment-for-woocommerce' ); ?></strong></a>&nbsp;<strong>|</strong>
        <a href="<?php echo $no_thanks; ?>" class="later"><strong><?php _e( 'Nope&#44; maybe later', 'liquidpay-qr-gateway-payment-for-woocommerce' ); ?></strong></a>
    </div>
<?php
}

function liquidpay_dismiss_rating_admin_notice() {
    if( get_option( 'liquidpay_plugin_no_thanks_rating_notice' ) === '1' ) {
        if ( get_option( 'liquidpay_plugin_dismissed_time' ) > strtotime( '-360 hours' ) ) {
            return;
        }
        delete_option( 'liquidpay_plugin_dismiss_rating_notice' );
        delete_option( 'liquidpay_plugin_no_thanks_rating_notice' );
    }

    if ( ! isset( $_GET['liquidpay_rating_notice_action'] ) ) {
        return;
    }

    if ( 'dismiss_rating_true' === $_GET['liquidpay_rating_notice_action'] ) {
        check_admin_referer( 'liquidpay_dismiss_rating_true' );
        update_option( 'liquidpay_plugin_dismiss_rating_notice', '1' );
    }

    if ( 'no_thanks_rating_true' === $_GET['liquidpay_rating_notice_action'] ) {
        check_admin_referer( 'liquidpay_no_thanks_rating_true' );
        update_option( 'liquidpay_plugin_no_thanks_rating_notice', '1' );
        update_option( 'liquidpay_plugin_dismiss_rating_notice', '1' );
        update_option( 'liquidpay_plugin_dismissed_time', time() );
    }

    wp_redirect( remove_query_arg( 'liquidpay_rating_notice_action' ) );
    exit;
}

function liquidpay_plugin_get_installed_time() {
    $installed_time = get_option( 'liquidpay_plugin_installed_time' );
    if ( ! $installed_time ) {
        $installed_time = time();
        update_option( 'liquidpay_plugin_installed_time', $installed_time );
    }
    return $installed_time;
}