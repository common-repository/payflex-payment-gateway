<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
final class WC_Payflex_Blocks extends AbstractPaymentMethodType {
    private $gateway;
    protected $name = 'payflex';// your payment gateway name
    public function initialize() {
        $this->settings = get_option( 'woocommerce_payflex_settings', [] );
        $this->gateway = new WC_Gateway_PartPay();
    }
    public function is_active() {
        return $this->gateway->is_available();
    }
    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-payflex-blocks-integration',
            plugin_dir_url(__FILE__) . '../assets/checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );

        return [ 'wc-payflex-blocks-integration' ];
    }
    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
        ];
    }
}