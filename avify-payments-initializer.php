<?php

if (!defined('ABSPATH')) exit;

/**
 * Plugin Name: Avify Payments for WooCommerce
 * Plugin URI:
 * Description: Accept card payments in WooCommerce through Avify Payments.
 * Version: 1.0.9
 * Author: Avify
 * Author URI: https://avify.com/
 * Text Domain: avify-payments
 * Domain Path: /languages/
 * Requires at least: 5.6
 * Tested up to: 5.9
 * Requires PHP: 7.0
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * Loads the Avify Payments Gateway.
 */
function init_avify_payments() {
    if (!class_exists('WC_Payment_Gateway')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Avify Payments error: You need to install WooCommerce in order to run Avify Payments');
        }

        /**
         * Outputs an admin notice that WooCommerce needs to be installed.
         */
        function avify_payments_admin_missing_woocommerce() {
?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <?php
                    printf(
                        wp_kses(
                            __(
                                'Your Avify Payments installation failed. You need to install <a href="%1$s" target="_blank" rel="noopener noreferrer">WooCommerce</a> in order to run Avify Payments.',
                                'avify-payments'
                            ),
                            array(
                                'a' => array(
                                    'href'   => array(),
                                    'target' => array(),
                                    'rel'    => array(),
                                ),
                            )
                        ),
                        'https://wordpress.org/plugins/woocommerce/'
                    );
                    ?>
                </p>
            </div>
<?php
        }
        add_action('admin_notices', 'avify_payments_admin_missing_woocommerce');
        return;
    }
    include_once('avify-payments-gateway.php');

    /**
     * Adds Avify Payments methods to WooCommerce.
     */
    function add_avify_payments_gateway($methods) {
        $methods[] = 'WC_Avify_Payments_Gateway';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_avify_payments_gateway');


    /** Avify Shipping */
    include_once('avify-payments-shipping.php');
}
add_action('plugins_loaded', 'init_avify_payments', 0);

/**
 * Provides the following action links to the plugin: settings page.
 */
function avify_payments_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">' . __('Settings', 'avify-payments') . '</a>',
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'avify_payments_action_links');

/**
 * Set up plugin localization.
 */
function load_avify_payments_textdomain() {
    load_plugin_textdomain('avify-payments', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'load_avify_payments_textdomain');
