<?php


/**
 * Class WC_Gateway_Dibs_Invoice
 */
class WC_Gateway_Dibs_Invoice extends WC_Gateway_Dibs {

	/**
	 * WC_Gateway_Dibs_Invoice constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->id                = 'dibs_invoice';
		$this->method_title      = __( 'DIBS Invoice', 'woocommerce-gateway-dibs' );
		$this->has_fields        = false;
		$this->log               = new WC_Logger();
		$this->paymentwindow_url = 'https://sat1.dibspayment.com/dibspaymentwindow/entrypoint';

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->title          = ( isset( $this->settings['title'] ) ) ? $this->settings['title'] : '';
		$this->description    = ( isset( $this->settings['description'] ) ) ? $this->settings['description'] : '';
		$this->merchant_id    = ( isset( $this->settings['merchant_id'] ) ) ? $this->settings['merchant_id'] : '';
		$this->key_hmac       = ( isset( $this->settings['key_hmac'] ) ) ? html_entity_decode( $this->settings['key_hmac'] ) : '';
		$this->merchant_id_no = ( isset( $this->settings['merchant_id_no'] ) ) ? $this->settings['merchant_id_no'] : '';
		$this->key_hmac_no    = ( isset( $this->settings['key_hmac_no'] ) ) ? html_entity_decode( $this->settings['key_hmac_no'] ) : '';
		$this->merchant_id_dk = ( isset( $this->settings['merchant_id_dk'] ) ) ? $this->settings['merchant_id_dk'] : '';
		$this->key_hmac_dk    = ( isset( $this->settings['key_hmac_dk'] ) ) ? html_entity_decode( $this->settings['key_hmac_dk'] ) : '';

		$this->invoice_fee_id = ( isset( $this->settings['invoice_fee_id'] ) ) ? $this->settings['invoice_fee_id'] : '';
		$this->pg_calc_totals = ( isset( $this->settings['pg_calc_totals'] ) ) ? $this->settings['pg_calc_totals'] : '';
		$this->language       = ( isset( $this->settings['language'] ) ) ? $this->settings['language'] : '';
		$this->testmode       = ( isset( $this->settings['testmode'] ) ) ? $this->settings['testmode'] : '';
		$this->debug          = ( isset( $this->settings['debug'] ) ) ? $this->settings['debug'] : '';

		// Set country based on currency. For DIBS Invoice - available in Sweden, Norway and Denmark
		switch ( $this->selected_currency ) {
			case 'DKK':
				$this->dibs_country = 'DK';
				$dibs_language      = 'da';
				$this->merchant_id  = $this->merchant_id_dk;
				$this->key_hmac     = $this->key_hmac_dk;
				break;
			case 'NOK' :
				$this->dibs_country = 'NO';
				$dibs_language      = 'nb';
				$this->merchant_id  = $this->merchant_id_no;
				$this->key_hmac     = $this->key_hmac_no;
				break;
			case 'SEK' :
				$this->dibs_country = 'SE';
				$dibs_language      = 'sv';
				break;
			default:
				$this->dibs_country = '';
				$dibs_language      = '';
				$this->merchant_id  = '';
				$this->key_hmac     = '';
		}

		// Apply filters for language
		$this->dibs_language = apply_filters( 'dibs_language', $dibs_language );

		// Dibs currency codes http://tech.dibs.dk/toolbox/currency_codes/
		$this->dibs_currency = array(
			'DKK' => '208', // Danish Kroner
			'EUR' => '978', // Euro
			'USD' => '840', // US Dollar $
			'GBP' => '826', // English Pound £
			'SEK' => '752', // Swedish Kroner
			'AUD' => '036', // Australian Dollar
			'CAD' => '124', // Canadian Dollar
			'ISK' => '352', // Icelandic Kroner
			'JPY' => '392', // Japanese Yen
			'NZD' => '554', // New Zealand Dollar
			'NOK' => '578', // Norwegian Kroner
			'CHF' => '756', // Swiss Franc
			'TRY' => '949', // Turkish Lire
		);

		// Invoice fee
		if ( $this->invoice_fee_id == "" ) {
			$this->invoice_fee_id = 0;
		}

		if ( $this->invoice_fee_id > 0 ) :

			// Version check - 1.6.6 or 2.0
			if ( function_exists( 'get_product' ) ) {
				$product = get_product( $this->invoice_fee_id );
			} else {
				$product = new WC_Product( $this->invoice_fee_id );
			}

			if ( $product ) :

				// We manually calculate the tax percentage here
				$this->invoice_fee_tax_percentage = number_format( ( ( $product->get_price() / $product->get_price_excluding_tax() ) - 1 ) * 100, 2, '.', '' );
				$this->invoice_fee_price          = $product->get_price();
				$this->invoice_fee_price_ex_tax   = $product->get_price_excluding_tax();
				$this->invoice_fee_title          = $product->get_title();

			else :

				$this->invoice_fee_price = 0;
				$this->invoice_fee_title = '';

			endif;

		else :

			$this->invoice_fee_price = 0;
			$this->invoice_fee_title = '';

		endif;

		// Check if the currency is supported
		if ( ! isset( $this->dibs_currency[ $this->selected_currency ] ) ) {
			$this->enabled = "no";
		} else {
			$this->enabled = $this->settings['enabled'];
		}

		// Actions
		add_action( 'valid-dibs-callback', array( &$this, 'successful_request' ) );
		add_action( 'woocommerce_receipt_dibs_invoice', array( &$this, 'receipt_page' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		add_action( 'wp_print_footer_scripts', array( $this, 'print_invoice_fee_updater' ) );
	} // End construct

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	function init_form_fields() {

		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-gateway-dibs' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable DIBS Invoice', 'woocommerce-gateway-dibs' ),
				'default' => 'yes'
			),
			'title'          => array(
				'title'       => __( 'Title', 'woocommerce-gateway-dibs' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-dibs' ),
				'default'     => __( 'DIBS Invoice', 'woocommerce-gateway-dibs' )
			),
			'description'    => array(
				'title'       => __( 'Description', 'woocommerce-gateway-dibs' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-dibs' ),
				'default'     => __( "Pay via DIBS Invoice.", 'woocommerce-gateway-dibs' )
			),
			'merchant_id'    => array(
				'title'       => __( 'DIBS Merchant ID - Sweden', 'woocommerce-gateway-dibs' ),
				'type'        => 'text',
				'description' => __( 'Please enter your DIBS Merchant ID for Sweden.', 'woocommerce-gateway-dibs' ),
				'default'     => ''
			),
			'key_hmac'       => array(
				'title'       => __( 'HMAC Key (k) - Sweden', 'woocommerce-gateway-dibs' ),
				'type'        => 'text',
				'description' => __( 'Please enter your DIBS HMAC Key (k) for Sweden.', 'woocommerce-gateway-dibs' ),
				'default'     => ''
			),
			'merchant_id_no' => array(
				'title'       => __( 'DIBS Merchant ID - Norway', 'woocommerce-gateway-dibs' ),
				'type'        => 'text',
				'description' => __( 'Please enter your DIBS Merchant ID for Norway.', 'woocommerce-gateway-dibs' ),
				'default'     => ''
			),
			'key_hmac_no'    => array(
				'title'       => __( 'HMAC Key (k) - Norway', 'woocommerce-gateway-dibs' ),
				'type'        => 'text',
				'description' => __( 'Please enter your DIBS HMAC Key (k) for Norway.', 'woocommerce-gateway-dibs' ),
				'default'     => ''
			),
			'merchant_id_dk' => array(
				'title'       => __( 'DIBS Merchant ID - Denmark', 'woocommerce-gateway-dibs' ),
				'type'        => 'text',
				'description' => __( 'Please enter your DIBS Merchant ID for Denmark.', 'woocommerce-gateway-dibs' ),
				'default'     => ''
			),
			'key_hmac_dk'    => array(
				'title'       => __( 'HMAC Key (k) - Denmark', 'woocommerce-gateway-dibs' ),
				'type'        => 'text',
				'description' => __( 'Please enter your DIBS HMAC Key (k) for Denmark.', 'woocommerce-gateway-dibs' ),
				'default'     => ''
			),
			'language'       => array(
				'title'       => __( 'Language', 'woocommerce-gateway-dibs' ),
				'type'        => 'select',
				'options'     => array(
					'en' => 'English',
					'da' => 'Danish',
					'de' => 'German',
					'es' => 'Spanish',
					'fi' => 'Finnish',
					'fo' => 'Faroese',
					'fr' => 'French',
					'it' => 'Italian',
					'nl' => 'Dutch',
					'no' => 'Norwegian',
					'pl' => 'Polish (simplified)',
					'sv' => 'Swedish',
					'kl' => 'Greenlandic'
				),
				'description' => __( 'Set the language in which the page will be opened when the customer is redirected to DIBS.', 'woocommerce-gateway-dibs' ),
				'default'     => 'sv'
			),
			'invoice_fee_id' => array(
				'title'       => __( 'Invoice Fee', 'woocommerce-gateway-dibs' ),
				'type'        => 'text',
				'description' => __( 'Create a hidden (simple) product that acts as the invoice fee. Enter the ID number in this textfield. Leave blank to disable. ', 'woocommerce-gateway-dibs' ),
				'default'     => ''
			),
			'pg_calc_totals' => array(
				'title'       => __( 'Totals calculation', 'woocommerce-gateway-dibs' ),
				'type'        => 'checkbox',
				'label'       => __( 'Let the payment gateway calculate totals', 'woocommerce-gateway-dibs' ),
				'description' => __( 'Order total sent to DIBS is calculated by the payment gateway, not by WooCommerce. This can be useful when having prices/tax amounts that use 3 or 4 decimals since DIBS process prices with 2 decimals. Both Order total and each order row is passed to DIBS. If they do not add up DIBS will cancel the payment. ', 'woocommerce-gateway-dibs' ),
				'default'     => 'no'
			),
			'testmode'       => array(
				'title'   => __( 'Test Mode', 'woocommerce-gateway-dibs' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable DIBS Test Mode. Read more about the <a href="http://tech.dibs.dk/10_step_guide/your_own_test/" target="_blank">DIBS test process here</a>.', 'woocommerce-gateway-dibs' ),
				'default' => 'yes'
			),
			'debug'          => array(
				'title'   => __( 'Debug', 'woocommerce-gateway-dibs' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging (<code>woocommerce/logs/dibs.txt</code>)', 'woocommerce-gateway-dibs' ),
				'default' => 'no'
			)
		);
	}

	/**
	 * Check if this gateway is enabled and available in the user's country
	 */
	function is_available() {
		if ( $this->enabled == "yes" ) {
			// Base country check
			if ( ! in_array( get_option( 'woocommerce_default_country' ), array( 'SE', 'NO', 'DK' ) ) ) {
				return false;
			}

			// Required fields check
			if ( empty( $this->merchant_id ) || empty( $this->key_hmac ) ) {
				return false;
			}

			// Checkout form check
			if ( isset( WC()->cart->total ) ) {
				// Only activate the payment gateway if the customers country is the same as the shop country ($this->dibs_country)
				if ( WC()->customer->get_country() == true && WC()->customer->get_country() != $this->dibs_country ) {
					return false;
				}
			} // End Checkout form check

			return true;
		}

		return false;
	}

	/**
	 * Admin Panel Options.
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {
		?>
		<h3><?php _e( 'DIBS Invoice', 'woocommerce-gateway-dibs' ); ?></h3>
		<p><?php printf( __( 'Documentation <a href="%s" target="_blank">can be found here</a>.', 'woocommerce-gateway-dibs' ), 'http://docs.woothemes.com/document/dibs/' ); ?></p>
		<table class="form-table">
			<?php
			if ( isset( $this->dibs_currency[ $this->selected_currency ] ) ) {
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
			} else { ?>
				<tr valign="top">
					<th scope="row" class="titledesc">DIBS Invoice disabled</th>
					<td class="forminp">
						<fieldset>
							<legend class="screen-reader-text"><span>DIBS Invoice disabled</span></legend>
							<?php _e( 'DIBS does not support your store currency.', 'woocommerce-gateway-dibs' ); ?><br>
						</fieldset>
					</td>
				</tr>
				<?php
			}
			?>
		</table><!--/.form-table-->
		<?php
	}

	/**
	 * Get payment method icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		switch ( get_woocommerce_currency() ) {
			case 'NOK':
				$icon_html = '<img src="https://cdn.dibspayment.com/logo/checkout/single/horiz/DIBS_checkout_single_faktura.png" alt="DIBS - Payments made easy" width="100"/>';
				break;
			case 'SEK' :
				$icon_html = '<img src="https://cdn.dibspayment.com/logo/checkout/single/horiz/DIBS_checkout_single_faktura.png" alt="DIBS - Payments made easy" width="100"/>';
				break;
			case 'DKK' :
				$icon_html = '<img src="https://cdn.dibspayment.com/logo/checkout/single/horiz/DIBS_checkout_single_faktura.png" alt="DIBS - Payments made easy" width="100"/>';
				break;
			default:
				$icon_html = '<img src="https://cdn.dibspayment.com/logo/checkout/single/horiz/DIBS_checkout_single_invoice.png" alt="DIBS - Payments made easy" width="100"/>';
		}

		return apply_filters( 'wc_dibs_invoice_icon_html', $icon_html );
	}

	/**
	 * There are no payment fields for dibs, but we want to show the description if set.
	 **/
	function payment_fields() {
		if ( $description = $this->get_description() ) {
			echo wpautop( wptexturize( $description ) );
		}
	}

	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// Prepare redirect url
		$redirect_url = $order->get_checkout_payment_url( true );

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url
		);
	}

	/**
	 * Show receipt page.
	 *
	 * @param $order
	 */
	function receipt_page( $order ) {
		echo '<p>' . __( 'Thank you for your order, please click the button below to pay with DIBS.', 'woocommerce-gateway-dibs' ) . '</p>';

		echo $this->generate_dibs_form( $order );
	}

	/**
	 * Generate the dibs button link.
	 *
	 * @param $order_id
	 *
	 * @return string
	 */
	public function generate_dibs_form( $order_id ) {
		$order = wc_get_order( $order_id );

		$payment_gateway_total_cost_calculation = '';

		$args = array(
			'merchant' => $this->merchant_id,
			'paytype'  => 'ALL_INVOICES',
			'currency' => $this->dibs_currency[ $this->selected_currency ],
		);

		// Payment Method - Payment Window
		$args['orderId'] = $order_id;

		// Language
		if ( $this->dibs_language == 'no' ) {
			$this->dibs_language = 'nb';
		}

		$args['language'] = $this->dibs_language;

		// URLs
		// Callback URL doesn't work as in the other gateways. DIBS erase everything after a '?' in a specified callback URL
		// We also need to make the callback url the accept/return url. If we use $this->get_return_url( $order ) the HMAC calculation doesn't add up
		$args['callbackUrl']     = apply_filters( 'woocommerce_dibs_invoice_callbackurl', trailingslashit( site_url( '/woocommerce/dibscallback' ) ) );
		$args['acceptReturnUrl'] = trailingslashit( site_url( '/woocommerce/dibsaccept' ) );
		$args['cancelreturnurl'] = trailingslashit( site_url( '/woocommerce/dibscancel' ) );

		// Address info
		$args['billingFirstName']   = $order->billing_first_name;
		$args['billingLastName']    = $order->billing_last_name;
		$args['billingAddress']     = $order->billing_address_1;
		$args['billingAddress2']    = $order->billing_address_2;
		$args['billingPostalPlace'] = $order->billing_city;
		$args['billingPostalCode']  = $order->billing_postcode;
		$args['billingEmail']       = $order->billing_email;

		$search                = array( '.', ' ', '(', ')', '+', '-' );
		$args['billingMobile'] = str_replace( $search, '', $order->billing_phone );

		// Testmode
		if ( $this->testmode == 'yes' ) {
			$args['test'] = '1';
		}

		$args['oiTypes'] = 'UNITCODE;QUANTITY;DESCRIPTION;AMOUNT;VATAMOUNT;ITEMID';

		// Cart Contents
		$item_loop = 1;
		if ( sizeof( $order->get_items() ) > 0 ) {
			foreach ( $order->get_items() as $item ) {
				$_product = $order->get_product_from_item( $item );

				// Get SKU or product id
				if ( $_product->get_sku() ) {
					$tmp_sku = $_product->get_sku();
				} else {
					$tmp_sku = $_product->id;
				}

				if ( $_product->exists() && $item['qty'] ) {
					$tmp_product                  = 'st;' . $item['qty'] . ';' . $item['name'] . ';' . number_format( $order->get_item_total( $item, false ), 2, '.', '' ) * 100 . ';' . number_format( $order->get_item_tax( $item ), 2, '.', '' ) * 100 . ';' . $tmp_sku;
					$args[ 'oiRow' . $item_loop ] = $tmp_product;

					$payment_gateway_total_cost_calculation = $payment_gateway_total_cost_calculation + ( $order->get_item_total( $item, false ) + $order->get_item_tax( $item ) ) * $item['qty'];
					$item_loop ++;
				}
			}
		}

		// Shipping Cost
		if ( $order->get_total_shipping() > 0 ) {
			$tmp_shipping = 'st;' . '1' . ';' . __( 'Shipping cost', 'woocommerce-gateway-dibs' ) . ';' . $order->get_total_shipping() * 100 . ';' . $order->order_shipping_tax * 100 . ';' . '0';

			$args[ 'oiRow' . $item_loop ] = $tmp_shipping;

			$payment_gateway_total_cost_calculation = $payment_gateway_total_cost_calculation + ( $order->get_total_shipping() + $order->order_shipping_tax );
			$item_loop ++;
		}

		// Discount
		if ( $order->get_order_discount() > 0 ) {
			$tmp_discount = 'st;' . '1' . ';' . __( 'Discount', 'woocommerce-gateway-dibs' ) . ';' . - number_format( $order->get_order_discount(), 2, '.', '' ) * 100 . ';' . '0' . ';' . '0';

			$args[ 'oiRow' . $item_loop ] = $tmp_discount;

			$payment_gateway_total_cost_calculation = $payment_gateway_total_cost_calculation - $order->get_order_discount();
		}

		// Fees
		if ( sizeof( $order->get_fees() ) > 0 ) {
			foreach ( $order->get_fees() as $item ) {
				// We manually calculate the tax percentage here
				if ( $order->get_total_tax() > 0 ) {
					// Calculate tax percentage
					$item_tax_percentage = number_format( ( $item['line_tax'] / $item['line_total'] ) * 100, 2, '.', '' );
				} else {
					$item_tax_percentage = 0.00;
				}

				// Invoice fee or regular fee
				if ( $this->invoice_fee_title == $item['name'] ) {
					$args['invoiceFee']    = number_format( $this->invoice_fee_price_ex_tax, 2, '.', '' ) * 100;
					$args['invoiceFeeVAT'] = round( $item_tax_percentage ) * 100;

					$payment_gateway_total_cost_calculation = $payment_gateway_total_cost_calculation + ( $item['line_total'] + $item['line_tax'] );
				} else {
					$tmp_fee = 'st;' . '1' . ';' . $item['name'] . ';' . number_format( $order->get_item_total( $item, false ), 2, '.', '' ) * 100 . ';' . number_format( $order->get_item_tax( $item ), 2, '.', '' ) * 100 . ';' . '0';

					$args[ 'oiRow' . $item_loop ] = $tmp_fee;

					$payment_gateway_total_cost_calculation = $payment_gateway_total_cost_calculation + ( $item['line_total'] + $item['line_tax'] );
				}
			}
		} // End fees

		// Order total
		if ( $this->pg_calc_totals == 'yes' ) {
			$args['amount'] = strval( number_format( $payment_gateway_total_cost_calculation, 2, '.', '' ) * 100 );
		} else {
			$args['amount'] = strval( number_format( $order->order_total, 2, '.', '' ) * 100 );
		}

		// HMAC
		$formKeyValues = $args;
		require_once( WC_DIBS_PLUGIN_DIR . 'includes/calculateMac.php' );
		$logfile = '';

		// Calculate the MAC for the form key-values to be posted to DIBS.
		$MAC = calculateMac( $formKeyValues, $this->key_hmac, $logfile );

		// Add MAC to the $args array
		$args['MAC'] = $MAC;

		// Prepare the form
		$fields  = '';
		$tmp_log = '';

		// Apply filters to the $args array
		$args = apply_filters( 'dibs_checkout_form', $args, 'dibs_invoice' );

		foreach ( $args as $key => $value ) {
			$fields .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';

			// Debug preparing
			$tmp_log .= $key . '=' . $value . "\r\n";
		}

		// Debug
		if ( $this->debug == 'yes' ) :
			$this->log->add( 'dibs', 'Sending values to DIBS: ' . $tmp_log );
		endif;

		wc_enqueue_js( '
			jQuery("body").block({
					message: "' . esc_js( __( 'Thank you for your order. We are now redirecting you to DIBS to make payment.', 'woocommerce-gateway-dibs' ) ) . '",
					baseZ: 99999,
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
					css: {
				        padding:        "20px",
				        zindex:         "9999999",
				        textAlign:      "center",
				        color:          "#555",
				        border:         "3px solid #aaa",
				        backgroundColor:"#fff",
				        cursor:         "wait",
				        lineHeight:		"24px",
				    }
				});
			jQuery("#submit_dibs_invoice_payment_form").click();
		' );

		// Print out and send the form
		return '<form action="' . $this->paymentwindow_url . '" method="post" id="dibs_invoice_payment_form">
				' . $fields . '
				<input type="submit" class="button-alt" id="submit_dibs_invoice_payment_form" value="' . __( 'Pay via dibs', 'woocommerce-gateway-dibs' ) . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __( 'Cancel order &amp; restore cart', 'woocommerce-gateway-dibs' ) . '</a>
			</form>';
	}

	/**
	 * Cancels an order.
	 *
	 * We do this since DIBS doesn't like GET parameters in callback and cancel url's.
	 *
	 * @param $posted
	 */
	function cancel_order( $posted ) {
		// Payment Window callback
		if ( isset( $posted['orderId'] ) && is_numeric( $posted['orderId'] ) ) {
			// Verify HMAC
			require_once( WC_DIBS_PLUGIN_DIR . 'includes/calculateMac.php' );
			$logfile = '';
			$MAC     = calculateMac( $posted, $this->key_hmac, $logfile );

			$order_id = $posted['orderId'];

			$order = wc_get_order( $order_id );

			if ( $posted['MAC'] == $MAC && $order->id == $order_id && $order->status == 'pending' ) {
				// Cancel the order + restore stock
				$order->cancel_order( __( 'Order cancelled by customer.', 'woocommerce-gateway-dibs' ) );

				// Message
				wc_add_notice( __( 'Your order was cancelled.', 'woocommerce-gateway-dibs' ), 'error' );
			} elseif ( $order->status != 'pending' ) {
				wc_add_notice( __( 'Your order is no longer pending and could not be cancelled. Please contact us if you need assistance.', 'woocommerce-gateway-dibs' ), 'error' );
			} else {
				wc_add_notice( __( 'Invalid order.', 'woocommerce-gateway-dibs' ), 'error' );
			}

			wp_safe_redirect( WC()->cart->get_cart_url() );
			exit;
		} // End Payment Window
	}

	/**
	 * Adds inline javascript in the footer that updates the totals on the checkout form
	 * when a payment method is selected.
	 */
	function print_invoice_fee_updater() {
		if ( is_checkout() && $this->enabled == "yes" && $this->invoice_fee_id > 0 ) {
			?>
			<script type="text/javascript">
				//<![CDATA[
				jQuery(document).ready(function ($) {
					$(document.body).on('change', 'input[name="payment_method"]', function () {
						$('body').trigger('update_checkout');
					});
				});
				//]]>
			</script>
			<?php
		}
	}

	/**
	 * Helper function, gets invoice fee id.
	 *
	 * @return int
	 */
	function get_dibs_invoice_fee_product() {
		return $this->invoice_fee_id;
	}

	/**
	 * Helper function, gets invoice fee price.
	 *
	 * @return int
	 */
	function get_dibs_invoice_fee_price() {
		return $this->invoice_fee_price;
	}

	/**
	 * Helper function, gets invoice fee title.
	 *
	 * @return string
	 */
	function get_dibs_invoice_fee_title() {
		return $this->invoice_fee_title;
	}

}