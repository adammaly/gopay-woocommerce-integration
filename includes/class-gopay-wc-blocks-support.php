<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

use Gopay_Gateway_API;


defined( 'ABSPATH' ) || exit;


/**
 * WC_Gopay_Blocks_Support class.
 *
 * @extends AbstractPaymentMethodType
 */
final class WC_Gopay_Blocks_Support extends AbstractPaymentMethodType {
    private $gateway;
    protected $name = 'gopay'; // Název platební metody pro bloky

    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . GOPAY_GATEWAY_ID . '_settings', [] );
        $this->gateway  = new Gopay_Gateway();
    }

    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-gopay-blocks-integration',
            plugin_dir_url( __FILE__ ) . 'block/index.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            // Předpokládáme, že verze pluginu je definována jinde, např. GOPAY_GATEWAY_VERSION
            defined('GOPAY_GATEWAY_VERSION') ? GOPAY_GATEWAY_VERSION : null,
            true
        );

        wp_set_script_translations(
            'wc-gopay-blocks-integration',
            'gopay-gateway' // Text domain
        );

        return [ 'wc-gopay-blocks-integration' ];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $payment_methods_output = [];

        // Only supported by the currency.
        $supported_payment_methods = $this->gateway->get_option(
            'gopay_payment_methods_' . get_woocommerce_currency(),
            array()
        );
        $supported_banks           = $this->gateway->get_option(
            'gopay_banks_' . get_woocommerce_currency(),
            array()
        );

        // All selected in the settings page.
        $selected_payment_methods = $this->gateway->get_option( 'enable_gopay_payment_methods', array() );
        $selected_banks           = $this->gateway->get_option( 'enable_banks', array() );

        // Intersection of all selected and the supported by the currency.
        $payment_methods = array();
        if (is_array($selected_payment_methods)) {
            foreach ( $selected_payment_methods as $method ) {
                if ( isset( $supported_payment_methods[ $method ] ) ) {
                    $payment_methods[ $method ] = $supported_payment_methods[ $method ];
                }
            }
        }
        $banks = array_intersect_key( (array)$supported_banks, array_flip( (array)$selected_banks ) );

        // Check if subscription - only card payment is enabled.
        if ( class_exists( 'Gopay_Gateway_Subscriptions' ) && Gopay_Gateway_Subscriptions::cart_contains_subscription() ) {
            if ( array_key_exists( 'PAYMENT_CARD', (array) $payment_methods ) ) {
                $payment_methods = array( 'PAYMENT_CARD' => $payment_methods['PAYMENT_CARD'] );
            } else {
                $payment_methods = array();
            }
        }

        // Vytvoření dočasné platby a získání URL pro inline skript
// Defaultní prázdné urlArgs
        $urlArgs   = [];
// Pokus o získání ID objednávky z query var
        $order_id  = filter_input( INPUT_GET, 'order-pay', FILTER_VALIDATE_INT );
        $order     = $order_id ? wc_get_order( $order_id ) : null;

// Vytvoření dočasné platby a získání URL jen pokud máme platnou objednávku
        if ( $order instanceof WC_Order ) {
            $response = Gopay_Gateway_API::create_payment(
                $selectedMethod ?? '',
                $order,
                '',
                false
            );

            if ( 200 === $response->statusCode ) {
                $urlArgs = [
                    'gopay_url' => $response->json['gw_url'],
                    '_wpnonce'  => wp_create_nonce( 'gw_url' ),
                ];
            }
        }

        if (is_array($payment_methods)) {
            foreach ( $payment_methods as $payment_method_code => $payment_method_details ) {
                if ( 'BANK_ACCOUNT' === $payment_method_code && ! $this->gateway->simplified_bank_selection ) {
                    if (is_array($banks)) {
                        foreach ( $banks as $bank_code => $bank_details ) {
                            $payment_methods_output[] = [
                                'id'    => $bank_code,
                                'label' => __( $bank_details['label'] ?? $bank_code, 'gopay-gateway' ),
                                'image' => $bank_details['image'] ?? '',
                            ];
                        }
                    }
                    continue;
                }

                $payment_methods_output[] = [
                    'id'    => $payment_method_code,
                    'label' => __( $payment_method_details['label'] ?? $payment_method_code, 'gopay-gateway' ),
                    'image' => $payment_method_details['image'] ?? '',
                    'urlArgs' => array_merge(
                        $urlArgs,
                        [ 'gopay_payment_method' => $payment_method_code ]
                    ),
                ];
            }
        }

        return [
            'title'           => $this->get_setting( 'title' ),
            'description'     => $this->get_setting( 'description' ),
            'supports'        => $this->get_supported_features(),
            'paymentMethods'  => $payment_methods_output,
            'urlArgs' => $urlArgs,
        ];
    }
}