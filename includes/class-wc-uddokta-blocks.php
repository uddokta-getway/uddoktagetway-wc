<?php
/**
 * WooCommerce Blocks (Cart/Checkout) integration for UddoktaGetway.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Uddokta_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = 'uddoktagetway';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_uddoktagetway_settings', []);
    }

    public function is_active()
    {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'ugwc-blocks',
            UGWC_URL . 'assets/js/blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'],
            UGWC_VERSION,
            true
        );

        return ['ugwc-blocks'];
    }

    public function get_payment_method_data()
    {
        return [
            'title'       => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports'    => ['products'],
        ];
    }
}
