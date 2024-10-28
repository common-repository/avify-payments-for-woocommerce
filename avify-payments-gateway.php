<?php

use App\Avify;

class WC_Avify_Payments_Gateway extends WC_Payment_Gateway_CC {

	public function __construct() {
		$this->id = 'avify-payments';
		$this->method_title = __('Avify Payments', 'avify-payments');
		$this->method_description = __(
			'Accept card payments in WooCommerce through Avify Payments',
			'avify-payments'
		);
		$this->title = __('Avify Payments', 'avify-payments');
		$this->has_fields = true;
		$this->supports = array('default_credit_card_form');
		$this->init_form_fields();
		$this->init_settings();

		foreach ($this->settings as $setting_key => $value) {
			$this->$setting_key = $value;
		}

		if (is_admin()) {
			add_action(
				'woocommerce_update_options_payment_gateways_' . $this->id,
				array($this, 'process_admin_options')
			);
		}
	}

	/**
	 * Array of fields to be displayed on the gateway's settings screen.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'     => __('Enabled / Disabled', 'avify-payments'),
				'label'     => __('Activate this payment method', 'avify-payments'),
				'type'      => 'checkbox',
				'default'   => 'no',
			),
			'payment_method_title' => array(
				'title'     => __('Payment Method Title', 'avify-payments'),
				'type'      => 'text',
				'desc_tip'  => __('Title to display for the payment method', 'avify-payments'),
				'default'   => __('Card', 'avify-payments'),
				'custom_attributes' => array('required' => 'required'),
			),
			'description' => array(
				'title'     => __('Description', 'avify-payments'),
				'type'      => 'text',
				'desc_tip'  => __('Payment Description', 'avify-payments'),
				'default'   => __('Pay with your debit or credit card', 'avify-payments'),
				'custom_attributes' => array('required' => 'required'),
			),
			'entity_description' => array(
				'title'     => __('Banking detail (22 characters max)', 'avify-payments'),
				'type'      => 'text',
				'default'   => get_bloginfo('name'),
				'desc_tip'  => __("Detail that appears on the client's bank account statement", 'avify-payments'),
				'custom_attributes' => array(
					'required' => 'required',
					'maxlength' => '22'
				),
			),
			'api_mode' => array(
				'title'     => __('API Mode', 'avify-payments'),
				'type'      => 'select',
				'desc_tip'  => __('Avify Payments API Mode', 'avify-payments'),
				'options'   => array('production' => 'production', 'sandbox' => 'sandbox'),
				'custom_attributes' => array('required' => 'required'),
			),
			'api_version' => array(
				'title'     => __('API Version', 'avify-payments'),
				'type'      => 'select',
				'desc_tip'  => __('Avify Payments API Version', 'avify-payments'),
				'options'   => array('v1' => 'v1'),
				'custom_attributes' => array('required' => 'required'),
			),
			'charge_description' => array(
				'title'     => __('Charge Description', 'avify-payments'),
				'type'      => 'text',
				'default'   => __('Online Purchase', 'avify-payments'),
				'desc_tip'  => __("It is the default description of a charge (purchase) to your client's card", 'avify-payments'),
				'custom_attributes' => array('required' => 'required'),
			),
			'store_id' => array(
				'title'     => __('Store ID', 'avify-payments'),
				'type'      => 'text',
				'desc_tip'  => __('Unique identifier of your store', 'avify-payments'),
				'custom_attributes' => array('required' => 'required'),
			),
			'client_secret' => array(
				'title'     => __('Client Secret', 'avify-payments'),
				'type'      => 'password',
				'desc_tip'  => __('API Client Secret provided by Avify', 'avify-payments'),
				'custom_attributes' => array('required' => 'required'),
			)
		);
	}

	/**
	 * Create a payload object with Avify's custom structure.
	 * 
	 * @param WC_Order $customer_order
	 * 
	 * @return array Structured payment info.
	 */
	private function get_formatted_payment_info(WC_Order $customer_order) {
		$card_info = $this->get_formatted_card_info();
		$payment_info = array(
			'amount' => floatval($customer_order->get_total()), // Required
			'currency' => get_woocommerce_currency(), // Required
			'description' => $this->charge_description, // Required
			'orderReference' => $customer_order->get_order_number(), // Required
			'card' => array( // Required (and all of its subfields)
				'cardHolder' => $card_info['card_holder'],
				'cardNumber' => $card_info['card_number'],
				'cvc' => $card_info['cvc'],
				'expMonth' => $card_info['exp_month'],
				'expYear' => $card_info['exp_year']
			),
			// 'customerId' => '123456789', // Optional
			'customer' => array( // Required (or ignored if customerId is present)
				'firstName' => $customer_order->get_billing_first_name(), // Required
				'lastName' => $customer_order->get_billing_last_name(), // Required
				'email' => $customer_order->get_billing_email(), // Required
				'company' => $customer_order->get_billing_company(), // Optional
				'billingAddress' => array( // Required
					'addressLine1' => $customer_order->get_billing_address_1(), // Required
					'addressLine2' => $customer_order->get_billing_address_2(), // Required
					'country' => $customer_order->get_billing_country(), // Required
					'state' => $customer_order->get_billing_state(), // Required
					// 'district' => 'District', // Optional
					'city' => $customer_order->get_billing_city(), // Required
					'postCode' => $customer_order->get_billing_postcode(), // Required
					// 'geoLat' => 9.087, // Optional
					// 'geoLon' => 1.246, // Optional
					// 'label' => 'Work' // Optional
				)
			),
			'meta' => array('orderId' => $customer_order->get_id()) // Optional
		);

		$shipping_address = $customer_order->get_shipping_address_1();
		if (isset($shipping_address) && $shipping_address !== '') {
			// The shipping address is optional, but if it is present, we need the
			// following structure:
			$payment_info['customer']['shippingAddress'] = array(
				'addressLine1' => $customer_order->get_shipping_address_1(), // Required
				'addressLine2' => $customer_order->get_shipping_address_2(), // Required
				'country' => $customer_order->get_shipping_country(), // Required
				'state' => $customer_order->get_shipping_state(), // Required
				// 'district' => 'Customer district', // Optional
				'city' => $customer_order->get_shipping_city(), // Required
				'postCode' => $customer_order->get_shipping_postcode(), // Required
				// 'geoLat' => 9.087, // Optional
				// 'geoLon' => 1.246, // Optional
				// 'label' => 'Work' // Optional
			);
		}
		return $payment_info;
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * 
	 * @return array
	 */
	public function process_payment($order_id) {
		if (empty($this->store_id) || empty($this->client_secret)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('Avify Payments error: Missing Store ID or Client Secret. You have to add them under Woocommerce > Settings > Payments > Avify Payments');
			}
			wc_add_notice(__('An error occurred while connecting to Avify Payments', 'avify-payments'), 'error');
			return;
		}

		global $woocommerce;

		if ($this->entity_description === '') {
			$this->entity_description = get_bloginfo('name');
		}

		$customer_order = new WC_Order($order_id);
		$payment_info = $this->get_formatted_payment_info($customer_order);

		$avify = new Avify($this->api_mode, $this->api_version, $this->client_secret);
		$avify->set_locale(get_locale());
		$response = $avify->process_payment($payment_info, $this->store_id);

		$error_message = '';
		if (array_key_exists('error', $response)) {
			$error_message = array_key_exists('displayMessage', $response['error']) ? $response['error']['displayMessage'] :  __('Something went wrong', 'avify-payments');
			$customer_order->add_order_note('Error: ' . $error_message);
			wc_add_notice($error_message, 'error');

			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('Avify Payments error: ' . $error_message);
				$developer_error_message = array_key_exists('developerMessage', $response['error']) ? $response['error']['developerMessage'] : '';

				if (!empty($developer_error_message)) {
					error_log('Avify Payments error (dev): ' . $developer_error_message);
				}
			}
			return;
		}
		if (array_key_exists('httpCode', $response) && $response['httpCode'] === 200) {
			$customer_order->add_order_note(__('Payment completed successfully', 'avify-payments'));
			$customer_order->payment_complete();
			$woocommerce->cart->empty_cart();
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url($customer_order),
			);
		}
	}

	/**
	 * Creates a formatted version of the card information, removing dashes, spaces,
	 * and separating the month and year.
	 * 
	 * @return array Formatted card info.
	 */
	public function get_formatted_card_info() {
		$card_holder = isset($_POST['avify-payments-card-holder']) ? sanitize_text_field($_POST['avify-payments-card-holder']) : '';
		$card_number = isset($_POST['avify-payments-card-number']) ? sanitize_text_field($_POST['avify-payments-card-number']) : '';
		$card_expiry = isset($_POST['avify-payments-card-expiry']) ? sanitize_text_field($_POST['avify-payments-card-expiry']) : '';
		$card_cvc = isset($_POST['avify-payments-card-cvc']) ? sanitize_text_field($_POST['avify-payments-card-cvc']) : '';

		return array(
			'card_holder' => $card_holder,
			'card_number' => str_replace(array(' ', '-'), '', $card_number),
			'exp_month' =>  str_replace(' ', '', substr($card_expiry, 0, 2)),
			'exp_year' => intval('20' . substr($card_expiry, -2)),
			'cvc' => str_replace(array(' ', '-'), '', $card_cvc)
		);
	}

	/**
	 * Validate payment fields on the frontend.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$card_info = $this->get_formatted_card_info();
		$card_holder = $card_info['card_holder'];
		$card_number = $card_info['card_number'];
		$exp_month = $card_info['exp_month'];
		$exp_year = $card_info['exp_year'];
		$cvc = $card_info['cvc'];

		if (
			empty($card_holder) || empty($card_number) || empty($exp_month) ||
			empty($exp_year) || empty($cvc)
		) {
			wc_add_notice(__(
				'Card holder, card number, expiry and CVC are required fields',
				'avify-payments'
			), 'error');
			return false;
		}
		if (strlen($exp_month) < 2 || $exp_month > 12 || $exp_month < 1) {
			wc_add_notice(__('Card expiration month is invalid', 'avify-payments'), 'error');
			return false;
		}
		if (strlen($exp_year) < 4 || $exp_year < intval(date('Y'))) {
			wc_add_notice(__('Card expiration year is invalid', 'avify-payments'), 'error');
			return false;
		}
		if (strlen($cvc) < 3) {
			wc_add_notice(__('Card security code must have 3 or 4 digits', 'avify-payments'), 'error');
			return false;
		}
		return true;
	}

	/**
	 * Overrides the original method to add a custom payment form.
	 */
	public function payment_fields() {
		wp_enqueue_script('wc-credit-card-form');

		$fields = array();

		$allowed_tags = array(
			'input' => array(
				'id' => array(),
				'class' => array(),
				'inputmode' => array(),
				'autocomplete' => array(),
				'autocorrect' => array(),
				'autocapitalize' => array(),
				'spellcheck' => array(),
				'type' => array(),
				'maxlength' => array(),
				'placeholder' => array(),
				'name' => array(),
				'style' => array(),
			),
			'label' => array('for' => array()),
			'span' => array('class' => array()),
			'p' => array('class' => array()),
			'fieldset' => array(),
		);

		$cvc_field = '<p class="form-row form-row-last">
            <label for="' . esc_attr($this->id) . '-card-cvc">' . esc_html__('Card Verification Code', 'avify-payments') . '&nbsp;<span class="required">*</span></label>
            <input id="' . esc_attr($this->id) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="' . esc_attr__('CVC', 'woocommerce') . '" ' . $this->field_name('card-cvc') . ' style="width:100px" />
        </p>';

		$default_fields = array(
			'card-holder-field' => '<p class="form-row form-row-wide">
                <label for="' . esc_attr($this->id) . '-card-holder">' . esc_html__('Card holder', 'avify-payments') . '&nbsp;<span class="required">*</span></label>
                <input id="' . esc_attr($this->id) . '-card-holder" class="input-text" type="text"' . $this->field_name('card-holder') . ' />
            </p>',
			'card-number-field' => '<p class="form-row form-row-wide">
                <label for="' . esc_attr($this->id) . '-card-number">' . esc_html__('Card number', 'avify-payments') . '&nbsp;<span class="required">*</span></label>
                <input id="' . esc_attr($this->id) . '-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" ' . $this->field_name('card-number') . ' />
            </p>',
			'card-expiry-field' => '<p class="form-row form-row-first">
                <label for="' . esc_attr($this->id) . '-card-expiry">' . esc_html__('Expiry (MM/YY)', 'avify-payments') . '&nbsp;<span class="required">*</span></label>
                <input id="' . esc_attr($this->id) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" autocomplete="cc-exp" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="' . esc_attr__('MM / YY', 'woocommerce') . '" ' . $this->field_name('card-expiry') . ' />
            </p>',
		);

		if (!$this->supports('credit_card_form_cvc_on_saved_method')) {
			$default_fields['card-cvc-field'] = $cvc_field;
		}

		$fields = wp_parse_args($fields, apply_filters('woocommerce_credit_card_form_fields', $default_fields, $this->id));
?>

		<fieldset id="wc-<?php echo esc_attr($this->id); ?>-cc-form" class='wc-credit-card-form wc-payment-form'>
			<?php do_action('woocommerce_credit_card_form_start', $this->id); ?>
			<?php
			foreach ($fields as $field) {
				printf(wp_kses($field, $allowed_tags));
			}
			?>
			<?php do_action('woocommerce_credit_card_form_end', $this->id); ?>
			<div class="clear"></div>
		</fieldset>
<?php

		if ($this->supports('credit_card_form_cvc_on_saved_method')) {
			printf(wp_kses('<fieldset>' . $cvc_field . '</fieldset>', $allowed_tags));
		}
	}
}
