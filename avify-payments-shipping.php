<?php

use App\Utils\Curl;

/**
 * Check if WooCommerce is active
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    if (!function_exists('avify_log')) {
        function avify_log($entry, $mode = 'a', $file = 'avify')
        {
            // Get WordPress uploads directory.
            $upload_dir = wp_upload_dir();
            $upload_dir = $upload_dir['basedir'];
            // If the entry is array, json_encode.
            if (is_array($entry)) {
                $entry = json_encode($entry);
            }
            // Write the log file.
            $file = $upload_dir . '/wc-logs/' . $file . '.log';
            $file = fopen($file, $mode);
            $bytes = fwrite($file, current_time('mysql') . " : " . $entry . "\n");
            fclose($file);
            return $bytes;
        }
    }

    if (!function_exists('avify_quote')) {
        function create_avify_quote($AVIFY_URL, $AVIFY_SHOP_ID)
        {
            avify_log('create_avify_quote...');
            WC()->session->set('avify_quote_' . WC()->session->get('avify_cart_uuid'), 'loading');
            $responseHeaders = [];
            $avifyQuoteCreate = Curl::post(
                $AVIFY_URL . "/rest/V1/guest-carts",
                ['Content-Type: application/json'],
                json_encode([]), $responseHeaders
            );

            if ($avifyQuoteCreate['success']) {
                if(isset($responseHeaders['set-cookie'][0])) {
                    $avifyQuoteId = $avifyQuoteCreate['data'];
                    WC()->session->set('avify_quote_' . WC()->session->get('avify_cart_uuid'), $avifyQuoteId);
                    WC()->session->set('avify_shop_' . WC()->session->get('avify_cart_uuid'), $AVIFY_SHOP_ID);
                    WC()->session->set('avify_quote_cookie_' . WC()->session->get('avify_cart_uuid'), $responseHeaders['set-cookie'][0]);
                    return $avifyQuoteId;
                }
            }

            WC()->session->set('avify_quote_' . WC()->session->get('avify_cart_uuid'), NULL);
            return false;
        }
    }

    function avify_deliveries_init()
    {
        if (!class_exists('WC_Avify_Deliveries')) {
            class WC_Avify_Deliveries extends WC_Shipping_Method
            {
                /**
                 * Constructor for your shipping class
                 *
                 * @access public
                 * @return void
                 */
                public function __construct($instance_id = 0)
                {
                    parent::__construct($instance_id);

                    $this->id = 'avfdeliveries'; // Id for your shipping method. Should be unique.
                    $this->instance_id = absint($instance_id);
                    $this->title = __('Avify Deliveries');  // Title shown in admin
                    $this->method_title = __('Avify Deliveries');  // Title shown in admin
                    $this->method_description = __('All deliveries in one plugin'); // Description shown in admin
                    $this->tax_status = 'none';
                    $this->enabled = "yes"; // This can be added as an setting but for this example its forced enabled
                    $this->supports = array(
                        'shipping-zones',
                        'instance-settings',
                        'instance-settings-modal',
                    );
                    $this->init();
                }

                /**
                 * Init your settings
                 *
                 * @access public
                 * @return void
                 */
                function init()
                {
                    // Load the settings API
                    $this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
                    $this->init_settings(); // This is part of the settings API. Loads settings you previously init.

                    // Save settings in admin if you have any defined
                    add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
                }

                /**
                 * calculate_shipping function.
                 *
                 * @access public
                 * @param mixed $package
                 * @return void
                 */
                public function calculate_shipping($package = array())
                {
                    $isCheckout = (is_checkout() || is_cart());
                    if ($isCheckout && !WC()->session->get('avify_lock')) {
                        WC()->session->set('avify_lock', true);
                        avify_log('---------------------------------------------------');

                        $AVIFY_URL = $this->get_option('avify_url');
                        $AVIFY_SHOP_ID = $this->get_option('avify_shop_id');

                        $carUUID = WC()->session->get('avify_cart_uuid');
                        if(is_null($carUUID)) {
                            $carUUID = uniqid();
                            WC()->session->set('avify_cart_uuid', $carUUID);
                        }
                        $wooCartKey = $carUUID;
                        if (!$wooCartKey) return null;

                        avify_log('get avify shipping rates woo cart: ' . $wooCartKey);

                        /** Cart Sync **/
                        //Get from session
                        $avifyQuoteId = WC()->session->get('avify_quote_' . $wooCartKey);
                        if (!$avifyQuoteId) {
                            $avifyQuoteId = create_avify_quote($AVIFY_URL, $AVIFY_SHOP_ID);
                        } else {
                            avify_log('validate -> ' . $avifyQuoteId);
                            if($avifyQuoteId == 'loading') {
                                WC()->session->set('avify_quote_' . $wooCartKey, null);
                                return;
                            }
                            //Still valid?
                            $responseHeaders = [];
                            $avifyCookie = WC()->session->get('avify_quote_cookie_' . $wooCartKey);
                            $avifyQuoteId = explode(':', $avifyQuoteId)[0];
                            $avifyQuote = Curl::get($AVIFY_URL . "/rest/V1/guest-carts/{$avifyQuoteId}", [
                                "Cookie: $avifyCookie"
                            ], $responseHeaders);
                            if (!$avifyQuote['success']) {
                                $avifyQuoteId = create_avify_quote($AVIFY_URL, $AVIFY_SHOP_ID);
                            } else {
                                if(isset($responseHeaders['set-cookie'][0])) {
                                    WC()->session->set('avify_quote_cookie_' . $wooCartKey, $responseHeaders['set-cookie'][0]);
                                }
                            }
                        }
                        $avifyCookie = WC()->session->get('avify_quote_cookie_' . $wooCartKey);
                        WC()->session->set('avify_lock', false);
                        avify_log('avify quote id : ' . $avifyQuoteId);
                        avify_log("avify cookie: $avifyCookie");

                        //Local avify cart
                        $cart = WC()->cart;
                        $avifyLocalQuote = WC()->session->get('avify_local_quote_' . $wooCartKey);
                        if ($avifyLocalQuote) {
                            $avifyLocalQuote = json_decode($avifyLocalQuote, true);
                        } else {
                            $avifyLocalQuote = [];
                        }

                        //Update items
                        foreach ($cart->get_cart() as $item) {
                            $sku = $item['data']->get_meta( 'avify_sku', true );
                            $update = false;
                            $add = false;
                            if (!isset($avifyLocalQuote[$sku])) {
                                $add = true;
                                avify_log("add item:{$sku}");
                            } else {
                                $avfLocalItem = explode(':', $avifyLocalQuote[$sku]);
                                if (floatval($avfLocalItem[1]) != floatval($item['quantity'])) {
                                    $update = true;
                                    avify_log("update item {$avfLocalItem[0]}:{$sku}");
                                }
                            }

                            if ($update || $add) {
                                $url = $AVIFY_URL . "/rest/V1/guest-carts/{$avifyQuoteId}/items";
                                $headers = [
                                    "Cookie: $avifyCookie",
                                    'Content-Type: application/json'
                                ];
                                $payload = json_encode([
                                    "cartItem" => [
                                        "sku" => $sku,
                                        "qty" => $item['quantity']
                                    ]
                                ]);

                                $avifyItem = null;
                                if ($add) {
                                    $avifyItem = Curl::post(
                                        $url, $headers, $payload
                                    );
                                } else {
                                    if (isset($avfLocalItem[0]) && $update) {
                                        $avifyItem = Curl::put(
                                            $url . "/{$avfLocalItem[0]}", $headers, $payload
                                        );
                                    }
                                }
                                if ($avifyItem) {
                                    avify_log($avifyItem);
                                    if ($avifyItem['success']) {
                                        $avifyLocalQuote[$sku] = $avifyItem['data']['item_id'] . ':' . $item['quantity'];
                                    } else {
                                        if(isset($avifyItem['httpCode'])) {
                                            if($avifyItem['httpCode'] == 404) {
                                                //Clear
                                                WC()->session->set('avify_quote_' . $wooCartKey, NULL);
                                                WC()->session->set('avify_shop_' . $wooCartKey, NULL);
                                                WC()->session->set('avify_local_quote_' . $wooCartKey, NULL);
                                                WC()->session->set('avify_cart_uuid', NULL);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        //Delete items
                        foreach ($avifyLocalQuote as $avfSku => $avfLocalItem) {
                            $found = false;
                            foreach ($cart->get_cart() as $item) {
                                $sku = $item['data']->get_meta( 'avify_sku', true );
                                if ($sku == $avfSku) {
                                    $found = true;
                                }
                            }
                            if (!$found) {
                                $avfLocalItem = explode(':', $avfLocalItem);
                                avify_log("delete item {$avfLocalItem[0]}:{$avfSku}");
                                Curl::delete($AVIFY_URL . "/rest/V1/guest-carts/{$avifyQuoteId}/items/{$avfLocalItem[0]}",
                                    [
                                        "Cookie: $avifyCookie"
                                    ]
                                );
                                unset($avifyLocalQuote[$avfSku]);
                            }
                        }
                        WC()->session->set('avify_local_quote_' . $wooCartKey, json_encode($avifyLocalQuote));

                        /** Rates **/
                        if (!isset($package['destination'])) {
                            return;
                        }

                        //Coords
                        $latitude = 0.00;
                        $longitude = 0.00;
                        if (isset($_POST['post_data'])) {
                            $fields = explode('&', $_POST['post_data']);
                            foreach ($fields as $field) {
                                $field = explode('=', $field);
                                if (count($field) == 2) {
                                    if ($field[0] == 'lpac_latitude') {
                                        $latitude = $field[1];
                                    }
                                    if ($field[0] == 'lpac_longitude') {
                                        $longitude = $field[1];
                                    }
                                }
                            }
                        } else {
                            if(isset($_POST['lpac_latitude'])) {
                                $latitude = $_POST['lpac_latitude'];
                            }
                            if(isset($_POST['lpac_longitude'])) {
                                $longitude = $_POST['lpac_longitude'];
                            }
                        }

                        //avify_log($_POST);

                        $address = $package['destination'];
                        $avifyRates = Curl::post(
                            $AVIFY_URL . "/rest/V1/guest-carts/{$avifyQuoteId}/estimate-shipping-methods",
                            [
                                "Cookie: $avifyCookie",
                                'Content-Type: application/json'
                            ],
                            json_encode([
                                "address" => [
                                    "city" => $address['city'],
                                    "country_id" => $address['country'],
                                    "postcode" => $address['postcode'],
                                    "region" => $address['state'],
                                    "street" => [$address['address_1'], $address['address_2']],
                                    "custom_attributes" => [
                                        "latitude" => $latitude,
                                        "longitude" => $longitude
                                    ],
                                    "telephone" => "",
                                    "extension_attributes" => [],
                                    "firstname" => "",
                                    "lastname" => "",
                                    "middlename" => "",
                                    "region_code" => "",
                                    "region_id" => 0
                                ]
                            ])
                        );

                        if (!isset($avifyRates['data'])) {
                            avify_log('No rates found on avify...');
                            avify_log($avifyRates);
                            return;
                        }
                        $rates = [];
                        foreach ($avifyRates['data'] as $avifyRate) {
                            if ($avifyRate['available']) {
                                avify_log("{$avifyRate['carrier_code']}_{$avifyRate['method_code']} : {$avifyRate['amount']}");
                                $title = explode('|', $avifyRate['carrier_title']);
                                $title = $title[0];
                                $rates[] = [
                                    "id" => "avfdeliveries-{$avifyRate['carrier_code']}{$avifyRate['method_code']}",
                                    "label" => $title . ' - ' . $avifyRate['method_title'],
                                    "cost" => $avifyRate['amount'],
                                    "package" => $package,
                                    "meta_data" => [
                                        "avify_rate_id" => "{$avifyRate['carrier_code']}_{$avifyRate['method_code']}"
                                    ]
                                ];
                            }
                        }
                        //shuffle($rates);
                        foreach ($rates as $rate) {
                            $this->add_rate($rate);
                        }
                    } else {
                        avify_log(WC()->session->get('avify_lock') ? 'locked...' : 'no-locked...');
                    }

                    if (!$isCheckout) {
                        WC()->session->set('avify_lock', false);
                    }
                }

                /**
                 * Init form fields.
                 */
                public function init_form_fields()
                {
                    $this->instance_form_fields = array(
                        'title' => array(
                            'title' => __('Title', 'woocommerce'),
                            'type' => 'text',
                            'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                            'default' => __('Avify Deliveries', 'woocommerce'),
                            'desc_tip' => true
                        ),
                        'avify_shop_id' => array(
                            'title' => __('Avify Shop ID', 'woocommerce'),
                            'type' => 'text',
                            'description' => __('Avify shop unique identifier.', 'woocommerce'),
                            'default' => null,
                            'desc_tip' => true
                        ),
                        'avify_url' => array(
                            'title' => __('Avify URL', 'woocommerce'),
                            'type' => 'text',
                            'description' => __('Avify Base URL.', 'woocommerce'),
                            'default' => __('https://shop.avify.com', 'woocommerce'),
                            'desc_tip' => true
                        )
                    );
                }
            }
        }
    }
    add_action('woocommerce_shipping_init', 'avify_deliveries_init');

    function add_avify_deliveries($methods)
    {
        $methods['avfdeliveries'] = 'WC_Avify_Deliveries';
        return $methods;
    }
    add_filter('woocommerce_shipping_methods', 'add_avify_deliveries');

    function save_order_avify_meta($order_id)
    {
        avify_log(WC()->session->get('chosen_shipping_methods'));

        //Load order
        if (!$order_id) return;
        //$order = wc_get_order($order_id);
        $wooCartKey = WC()->session->get('avify_cart_uuid');

        //Order meta
        $avifyQuoteId = WC()->session->get('avify_quote_' . $wooCartKey);
        $avifyShopId = WC()->session->get('avify_shop_' . $wooCartKey);
        update_post_meta($order_id, 'avify_quote_id', $avifyQuoteId);
        update_post_meta($order_id, 'avify_shop_id', $avifyShopId);

        //Clear
        WC()->session->set('avify_quote_' . $wooCartKey, NULL);
        WC()->session->set('avify_shop_' . $wooCartKey, NULL);
        WC()->session->set('avify_local_quote_' . $wooCartKey, NULL);
        WC()->session->set('avify_cart_uuid', NULL);
    }
    add_action('woocommerce_checkout_update_order_meta', 'save_order_avify_meta', 10, 1);
}
