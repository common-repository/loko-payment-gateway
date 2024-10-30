<?php
/*
 * Plugin Name: Loko Payment Gateway
 * Plugin URI:
 * Description: Take crypto payments on your WooCommerce store.
 * Author: Loko Payment
 * Author URI: https://lokopay.cc
 * Copyright: © 2020 Loko Payment
 * Version: 0.0.1
 *
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOKO_GATEWAY_WC_VERSION', '0.0.1');

 /*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */

function lokopay_check_meet_requirements() {
    
    global $woocommerce;
    $errors = array();

    if (empty($woocommerce)) {
        $errors[] = 'Your Wordpress website need to install WooCommerce plugin first.';
    } elseif ( version_compare($woocommerce->version, '2.2', '<')) {
        $errors[] = 'Your WooCommerce version is too old. The Loko Payment plugin requires 2.2 or higher';
    }

    if(empty($errors)):
        return true;
    else: 
        return false;
    endif;
}

function lokopay_plugin_setup() {
    $pass = lokopay_check_meet_requirements();
    $plugins_url = admin_url('plugins.php');

    if (!$pass) {
        wp_die($failed . '<br><a href="' . $plugins_url . '">Return to plugins screen</a>');
    }
}
register_activation_hook(__FILE__, 'lokopay_plugin_setup');

// Add action links in plugin table
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'lokopay_add_plugin_action_links');
function lokopay_add_plugin_action_links($links) {
    
    $links[] = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=loko_payment_gateway') . '">' . __('Settings', 'woocommerce') . '</a>';
    $links[] = '<a href="https://dashboard.lokopay.cc" target="_blank">Loko Dashboard</a>';
    
    return $links;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'lokopay_init_gateway_class' );
function lokopay_init_gateway_class() {
 
	class WC_LokoPay_Gateway extends WC_Payment_Gateway {
 
 		/**
 		 * Class constructor, more about it in Step 3
 		 */
 		public function __construct() {
            $this->id                 = 'loko_payment_gateway';                                                           // payment gateway plugin ID
            $this->icon               = 'https://web.lokopay.cc/image/logo/png/loko-logo-horz.png';                      // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields         = false;                                                                            // in case you need a custom credit card form
            $this->method_title       = 'Loko Payment Gateway';
            $this->method_description = 'Allow customers to pay with their cryptocurrency on your woocommerce store';     // will be displayed on the options page
            $this->supports           = array('products', 'refunds');
                                 
            // Method with all the options fields
            $this->init_form_fields();
         
            // Load the settings.
            $this->init_settings();
            $this->title       = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled     = $this->get_option( 'enabled' );
            $this->apiKey      = $this->get_option( 'apiKey' );
            $this->apiSecrect  = $this->get_option( 'apiSecrect' );

            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
         
            // We need custom JavaScript to obtain a token
            // add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
         
            // You can also register a webhook here
            add_action( 'woocommerce_api_loko_payment_completed', array( $this, 'lokopay_webhook_payment_completed' ) );
            add_action( 'woocommerce_api_loko_payment_cancelled', array( $this, 'lokopay_webhook_payment_cancelled' ) );
            add_action( 'woocommerce_api_loko_refund_completed', array( $this, 'lokopay_webhook_refund_completed' ) );
            add_action( 'woocommerce_api_loko_ipn_update', array( $this, 'lokopay_webhook_ipn_update' ) );
        }
         
         /**
         * Return the gateway's icon.
         *
         * @return string
         */
        public function get_icon() {

            $icon = $this->icon ? '<img style="margin-top: 6px; margin-left: 5px;" width="48" src="' . WC_HTTPS::force_https_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" /><img src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/46/Bitcoin.svg/1024px-Bitcoin.svg.png" width="28">' : '';

            return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
        }
 
		/**
 		 * Plugin options, we deal with it in Step 3 too
 		 */
 		public function init_form_fields(){
            
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __('Enable/Disable', 'woocommerce'),
                    'label'       => __('Enable Loko Pay Gateway', 'woocommerce'),
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'loko_gateway_merchant_info' => array(
                    'description' => __('If you have not created a Loko Pay Merchant API keys, you can create one on your Loko Pay dashboard. <a href = "'. $this->lokopay_get_dashboard_url() . '" target = "_blank"> Dashboard </a>', 'woocommerce'),
                    'type'        => 'title',
                ),
                'title' => array(
                    'title'       => __('Title', 'woocommerce'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default'     => __('Loko Pay', 'woocommerce'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'woocommerce'),
                    'type'        => 'text',
                    'desc_tip'    => true,
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default'     => __('Pay with your Bitcoin via our secured payment gateway.', 'woocommerce'),
                ),
                'apiKey' => array(
                    'title'       => __('API Key', 'woocommerce'),
                    'type'        => 'text'
                ),
                'apiSecrect' => array(
                    'title'       => __('API Secrect', 'woocommerce'),
                    'type'        => 'text',
                ),
            );
 
	 	}
 
		/**
		 * You will need it if you want your custom credit card form, Step 4 is about it
		 */
		// public function payment_fields() {
		// }
 
		/*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
	 	// public function payment_scripts() {
	 	// }
 
		/*
 		 * Fields validation, more in Step 5
		 */
		// public function validate_fields() {
		// }
 
		/*
		 * We're processing the payments here, everything about it is in Step 5
		 */
		public function process_payment( $order_id ) {
                        
            $order = wc_get_order( $order_id );
            $checkout_url = wc_get_checkout_url();
            if ($order->get_payment_method() != 'loko_payment_gateway') {                
                return array(
                    'result'   => 'failure',
                    'redirect' => $checkout_url
                );
            }
            
            if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>=')) {
                $redirect_url = $this->get_return_url($order);
            } else {
                $redirect_url = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(get_option('woocommerce_thanks_page_id'))));
            }

            $orderItems = array();
            foreach ($order->get_items() as $item_key => $item ):
                $item_data = $item->get_data();
                $product   = $item->get_product();

                $item = [
                    'name'             => $item_data['name'],
                    'productID'        => (string)$item_data['product_id'],
                    'variationID'      => (string)$item_data['variation_id'],
                    'quantity'         => $item_data['quantity'],
                    'price'            => floatval($product->get_price()),
                    'taxClass'         => $item_data['tax_class'],
                    'subtotal'         => floatval($item_data['subtotal']),
                    'subtotalTax'      => floatval($item_data['subtotal_tax']),
                    // 'total'            => $item_data['total'],
                    // 'totalTax'         => $item_data['total_tax'],
                ];
                array_push($orderItems, $item);
            endforeach;
            
            // create new invoice object
            $shopname   = get_bloginfo('name', null);
            $shopURL    = get_bloginfo('url', null);
            $shopID     = (string)get_current_blog_id();
            $orderDesc  = $shopname . ' Order ID: ' . $order_id;
            $order_data = $order->get_data(); // The Order data
            $newInvoice = [            
                'shopName'            => $shopname,
                'shopID'              => $shopID,
                'shopURL'             => $shopURL,
                'redirectURL'         => $redirect_url,
                'cancelRedirectURL'   => $checkout_url,
                'completeCallbackURL' => get_site_url(null, '/wc-api/loko_payment_completed'),
                'notificationURL'     => get_site_url(null, '/wc-api/loko_ipn_update'),
                'order'               => [
                    'orderID'     => (string)$order_id,
                    'description' => $orderDesc,
                    'currency'    => $order->get_currency(),
                    'total'       => (double)$order->get_total(),
                    'shipping'    => floatval($order->get_shipping_total()),
                    'shippingTax' => floatval($order->get_shipping_tax()),
                    
                    // 'shippingContact' => [
                    //     "firstname" => $order_data['shipping']['first_name'],
                    //     "lastname"  => $order_data['shipping']['last_name'],
                    //     "company"   => $order_data['shipping']['company'],
                    //     "email"     => $order_data['shipping']['email'],
                    //     "phone"     => $order_data['shipping']['phone'],
                    //     "address1"  => $order_data['shipping']['address_1'],
                    //     "address2"  => $order_data['shipping']['address_2'],
                    //     "city"      => $order_data['shipping']['city'],
                    //     "state"     => $order_data['shipping']['state'],
                    //     "country"   => $order_data['shipping']['country'],
                    //     "postcode"  => $order_data['shipping']['postcode'],
                    // ],
                    'billingContact' => [
                        "firstname" => $order_data['billing']['first_name'],
                        "lastname"  => $order_data['billing']['last_name'],
                        "company"   => $order_data['billing']['company'],
                        "email"     => $order_data['billing']['email'],
                        "phone"     => $order_data['billing']['phone'],
                        "address1"  => $order_data['billing']['address_1'],
                        "address2"  => $order_data['billing']['address_2'],
                        "city"      => $order_data['billing']['city'],
                        "state"     => $order_data['billing']['state'],
                        "country"   => $order_data['billing']['country'],
                        "postcode"  => $order_data['billing']['postcode'],
                    ],
                    'items' => $orderItems,
                ],                
            ];
            // $newInvoiceStr = json_encode($newInvoice);
            // error_log('New Invoice: ' . $newInvoiceStr, 0);

            // prepare for api request
            $apiSchema = $this->lokopay_get_api_scheme();
            $apiURL    = $this->lokopay_get_api_url_for('invoice');
            $method    = 'POST';
            
            try {
                // load api request 
                $response = $this->lokopay_load_api($apiSchema, $apiURL, $method, $newInvoice);
            } catch( Excpetion $e) {
                error_log('process_payment wp_remote_post error: ' . $e, 0);                
                wc_add_notice( $e, 'error' );

                return array(
                    'result'   => 'failure'                    
                );
            }            

            if (is_wp_error($response)) {                
                error_log('is_wp_error: ' . $response->get_error_message(), 0);
                wc_add_notice( $response->get_error_message(), 'error' );

                return array(
                    'result'   => 'failure'                    
                );
            }

            if ($this->lokopay_is_error($response)) {
                               
                return new WP_Error('loko_create_invoice_error', $this->lokopay_get_error_message($response));
            }
            
            $result = json_decode( $response['body'], true );
            
            if ( !empty( $result['url'] ) ) {
                $redirect_url = esc_url_raw($result['url']);
                return array(
                    'result'   => 'success',
                    'redirect' => $redirect_url,
                );
            };
        }

        public function process_refund( $order_id, $amount = null, $reason = '') {
            
            $order = wc_get_order( $order_id );

            if ($order->get_payment_method() != 'loko_payment_gateway') {                
                return new WP_Error( 'loko_refund_error', 'Refund Error: payment method was not via Loko Pay.' );
            }

            if ( 0 == $amount || null == $amount ) {
                return new WP_Error( 'loko_refund_error', 'Refund Error: You need to specify a refund amount.' );
            }

            $shopname       = get_bloginfo('name', null);
            $shopURL        = get_bloginfo('url', null);
            $shopID         = (string)get_current_blog_id();
            $old_wc         = version_compare( WC_VERSION, '3.0', '<' );
            $order_currency = $old_wc ? $order->order_currency : $order->get_currency();

            $newRefund = [
                'shopName'             => $shopname,
                'shopURL'              => $shopURL,
                'shopID'               => $shopID,
                'orderID'              => (string)$order_id,
                'amount'               => floatval($amount),
                'currency'             => $order_currency,
                'reason'               => $reason,
                'customerEmail'        => $order->get_billing_email(),
                'completedCallbackURL' => get_site_url(null, '/wc-api/loko_refund_completed?id=').$order_id,
            ];
            
            $apiURL    = $this->lokopay_get_api_url_for('refund');
            $apiSchema = $this->lokopay_get_api_scheme();
            $method    = 'POST';
            
            try {
                $response = $this->lokopay_load_api($apiSchema, $apiURL, $method, $newRefund);
            } catch( Excpetion $e) {
                
                error_log('process_refund wp_remote_post error: ' . $e, 0);
                wc_add_notice( $e, 'error' );
                return new WP_Error('loko_refund_error', $e->get_error_message());
            }            

            if (is_wp_error($response)) {                
                error_log('is_wp_error ' . $response->get_error_message());
                wc_add_notice( $response->get_error_message(), 'error' );
                return new WP_Error('loko_refund_error', $response->get_error_message());
            }
            
            if ($this->lokopay_is_error($response)) {
                error_log('Loko Error Message: ' . $this->lokopay_get_error_message($response));                
                return new WP_Error('loko_refund_error', $this->lokopay_get_error_message($response));
            }
            
            $result = json_decode($response['body'], true);
            if ( isset($result['refundID']) ) {
                $refundID = sanitize_text_field( $result['refundID'] );
                $order->add_order_note(sprintf(__('Loko is processing refund $%s (Refund ID: %s)', 'woocommerce'), $amount, $refundID));
            } else {
                return new WP_Error('loko_refund_error', 'Response error, no refund id');
            }         
            
            return true;
        }        
 
		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		public function lokopay_webhook_payment_completed() {

            if ($this->verify_lokopay_request()) {
                error_log('passed verify');
            } else {
                error_log('verify failed');
                return false;
            }

            $body       = file_get_contents('php://input');
            $json_body  = json_decode($body);
            error_log('invoice status: ' . $json_body->status, 0);  

            $invoice_id = sanitize_text_field( $json_body->invoiceID );
            if ( empty($invoice_id) ) {
                error_log('lokopay_webhook_payment_completed loko invoice id is empty', 0);
                return false;
            }

            $invoice_status = sanitize_text_field( $json_body->status );
            if ( empty($invoice_status) ) {
                error_log('lokopay_webhook_payment_completed order status is empty', 0);
                return false; 
            }

            $order_id = sanitize_text_field( $json_body->orderID );
            if ( empty($order_id) ) {
                error_log('lokopay_webhook_payment_completed order id is empty', 0);
                return false;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                error_log('lokopay_webhook_payment_completed order cannot be found!', 0);
                return false;
            }

            $order_status = $order->get_status();
            if ( $order_status != 'pending' && $order_status != 'cancelled') {
                error_log('order status is not pending or cancelled', 0);
                return false;
            }                

            if ( $invoice_status == 'Completed') {

                global $woocommerce;
                
                // empty cart
                $woocommerce->cart->empty_cart();

                // payment completed
                $order->payment_complete();

                // reduce product stock
                wc_reduce_stock_levels($order);

                // add note to order
                $order->add_order_note(sprintf(__('Payment Completed via Loko (Invoice ID: %s)', 'woocommerce'), $invoiceID));
                
                return true;
            } else if ( $invoice_status == 'Expired' ) {

                global $woocommerce;
                
                // empty cart
                $woocommerce->cart->empty_cart();

                // cancel order
                $order->update_status('cancelled');

                return true;
            }
            
            // log all the checking if it reach this line
            error_log('orderID: ' . $orderID, 0);
            error_log('invoiceStatus: ' . $invoiceStatus, 0);
            return false;            
        }        

        public function lokopay_webhook_payment_cancelled() {
                        
        }

        public function lokopay_webhook_refund_completed() {

            $refund_id = sanitize_text_field( $_GET['refundid'] );
            if ( empty($refund_id) ) {
                error_log('lokopay_webhook_payment_completed loko refund id is empty', 0);
                return false;
            }

            $order_id = sanitize_text_field( $_GET['id'] );
            if ( empty($order_id) ) {
                error_log('lokopay_webhook_payment_completed order id is empty', 0);
                return false;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                error_log('lokopay_webhook_payment_completed order cannot be found!', 0);
                return false;
            }

            $order->add_order_note(__('Refund Completed via Loko ', 'woocommerce'), $refund_id);
        }

        public function lokopay_webhook_ipn_update() {

        }

        protected function verify_lokopay_request() {

            $nonce     = '';
            $signature = '';
            $host      = '';

            foreach (getallheaders() as $name => $value) {
                error_log('name: ' . $name . ' value: ' . $value);
                if ($name == 'X-Nonce') {
                    $nonce = sanitize_text_field($value);
                }
                if ($name == 'X-Signature') {
                    $signature = sanitize_text_field($value);
                }
                if ($name == 'Host') {
                    $host = sanitize_text_field($value);
                }
            }

            $method     = $_SERVER['REQUEST_METHOD'];
            $url        = $host . $_SERVER['REQUEST_URI'];
            $json_body  = file_get_contents('php://input');

            $apiSecrect         = $this->get_option( 'apiSecrect' );            
            $expectedSignature  = $this->lokopay_create_signature($apiSecrect, $method, $url, $json_body, $nonce);

            error_log('Request body: ' . $json_body, 0);            
            error_log('method: ' . $method, 0);
            error_log('url: ' . $url, 0);
            error_log('signature: ' . $signature, 0);
            error_log('expectedSignature: ' . $expectedSignature, 0);

            if ($signature == $expectedSignature) {
                return true;
            } else {
                return false;
            }
        }

        protected function lokopay_load_api($schema, $url, $method, $data) {

            $json_body = NULL;

            if ( !empty($data) ) {
                $json_body = json_encode($data);
            }

            $nonce      = (string)time();
            $apiKey     = $this->get_option( 'apiKey' );
            $apiSecrect = $this->get_option( 'apiSecrect' );            
            $signature  = $this->lokopay_create_signature($apiSecrect, $method, $url, $json_body, $nonce);

            $response = wp_remote_request($schema . $url, array(
                'headers'     => array(
                    'Content-Type' => 'application/json',
                    'x-api-key'    => $apiKey,
                    'x-nonce'      => $nonce,
                    'x-signature'  => $signature
                ),                
                'method'  => $method,
                'body'    => $json_body,
                'timeout' => 200,
            ));

            return $response;
        }

        protected function lokopay_create_signature($apiSecrect, $method, $url, $body, $nonce ) {
            $secrectBytes  = base64_decode($apiSecrect);
            $hashContent   = strtolower($method) . $url . $body;
            $hashedContent = hash('sha256', $hashContent);
            $content       = $nonce.$hashedContent;
            $signature     = hash_hmac('sha512', $content, $secrectBytes);

            return base64_encode(pack('H*',$signature));
        }

        protected function lokopay_is_error( $response ) {

            $body = wp_remote_retrieve_body( $response );
            // error_log('$body: ' . $body);

            if ( isset($body['code'])  && $body['code'] != 200 && $body['message'] != "") {
                return true;
            }
            return false;
        }

        protected function lokopay_get_error_message( $response ) {
            $body = wp_remote_retrieve_body( $response );
            return $body['message'];
        }

        protected function getLokoGatewayBaseURL() {
            return 'https://lokopay.cc';
        }

        protected function lokopay_get_api_url_for( $resource ) {            
            // return 'api.lokopay.cc/' . $resource;
            return 'api.bleev.cloud/' . $resource;
            // return 'localhost:8000/' . $resource;
            // return 'b742d7625b0c.ngrok.io/' . $resource;
        }

        protected function lokopay_get_api_scheme() {            
            return 'https://';
            // return 'http://';
        }

        protected function lokopay_get_dashboard_url() {
            return esc_url('https://dashboard.lokopay.cc');
        }
 	}
}

#add the loko payment gatway to woocommerce
add_filter( 'woocommerce_payment_gateways', 'wc_lokopay_add_to_gateway_class' );
function wc_lokopay_add_to_gateway_class( $gateways ) {
	$gateways[] = 'WC_LokoPay_Gateway';
	return $gateways;
} 

?>
