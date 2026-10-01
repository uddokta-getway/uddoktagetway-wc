<?php
/**
 * Plugin Name:       UddoktaGetway for WooCommerce
 * Plugin URI:        https://uddoktagetway.com
 * Description:       Accept bKash, Nagad, Rocket, cards and bank payments in WooCommerce through your UddoktaGetway merchant store.
 * Version:           1.0.0
 * Author:            UddoktaGetway
 * Author URI:        https://uddoktagetway.com
 * Text Domain:       uddoktagetway-wc
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   9.0
 * License:           GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('UGWC_VERSION', '1.0.0');
define('UGWC_FILE', __FILE__);
define('UGWC_DIR', plugin_dir_path(__FILE__));
define('UGWC_URL', plugin_dir_url(__FILE__));

/**
 * Declare compatibility with HPOS (custom order tables) and Cart/Checkout Blocks.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', UGWC_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', UGWC_FILE, true);
    }
});

/**
 * Boot the gateway once WooCommerce is loaded.
 */
add_action('plugins_loaded', 'ugwc_init', 11);

function ugwc_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>UddoktaGetway for WooCommerce</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    require_once UGWC_DIR . 'includes/class-wc-gateway-uddokta.php';

    add_filter('woocommerce_payment_gateways', function ($methods) {
        $methods[] = 'WC_Gateway_UddoktaGetway';
        return $methods;
    });
}

/**
 * WooCommerce Blocks (new Cart/Checkout) support.
 */
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once UGWC_DIR . 'includes/class-wc-uddokta-blocks.php';

    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new WC_Uddokta_Blocks_Support());
    });
});

/**
 * "Settings" link on the Plugins page.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=uddoktagetway');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'uddoktagetway-wc') . '</a>');
    return $links;
});
