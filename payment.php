<?php
/**
 * The admin-facing functionality of the plugin.
 *
 * @package    LIQUIDPAY QR Code Payment for WooCommerce
 * @subpackage Includes
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 */

// add Gateway to woocommerce
add_filter('woocommerce_payment_gateways', 'liquidpay_woocommerce_payment_add_gateway_class');

function liquidpay_woocommerce_payment_add_gateway_class($gateways)
{
    $gateways[] = 'WC_LIQUIDPAY_Payment_Gateway'; // class name
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
*/
add_action('plugins_loaded', 'liquidpay_payment_gateway_init');
add_action("wp_ajax_status_payment", "liquidpay_status_payment");
add_action("wp_ajax_nopriv_status_payment", "liquidpay_status_payment");

function liquidpay_status_payment()
{
    header("Content-Type: text/javascript");
    $WC_LIQUIDPAY_Payment_Gateway = new WC_LIQUIDPAY_Payment_Gateway();
    $liq = new LiquidPay($WC_LIQUIDPAY_Payment_Gateway->merchant_id, $WC_LIQUIDPAY_Payment_Gateway->liquid_api_key, $WC_LIQUIDPAY_Payment_Gateway->liquid_secret_key , $WC_LIQUIDPAY_Payment_Gateway->liquid_url);
    $params = (htmlspecialchars($_REQUEST['params']));
    $order_id = wc_get_order_id_by_order_key($params);
    $order = wc_get_order($order_id);
    $bill_ref_no = get_post_meta($order_id, '_liquidpay_order_ref', true);
    $response = $liq->findBill($bill_ref_no);

    if ($response->bill_status === "A")
    {

        //SEND MAIL here
        $mailer = WC()->mailer();
        $mails = $mailer->get_emails();
        if (!empty($mails))
        {
            foreach ($mails as $mail)
            {
                if ($mail->id == 'customer_processing_order')
                {
                    $mail->trigger($order->id);
                }
            }
        }

    }
    else if ($response->bill_status != "P" && $response->bill_status != "W" && $response->bill_status != "A")
    {
        //cancel
        // Cancel the order + restore stock.
        WC()
            ->session
            ->set('order_awaiting_payment', false);
        $order->update_status('cancelled', __('Order cancelled by Gateway.', 'liquidpay-qr-gateway-payment-for-woocommerce'));
        wc_add_notice(apply_filters('woocommerce_order_cancelled_notice', __('Your order was cancelled.', 'woocommerce')) , apply_filters('woocommerce_order_cancelled_notice_type', 'notice'));
        do_action('woocommerce_cancelled_order', $order->get_id());

    }

    echo "retrieveBill(" . json_encode($response) . ")";

    die();
}

function liquidpay_payment_gateway_init()
{

    // If the WooCommerce payment gateway class is not available nothing will return
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_LIQUIDPAY_Payment_Gateway extends WC_Payment_Gateway
    {
        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {

            $this->id = 'wc-liquidpay';
            $this->icon = apply_filters('liquidpay_custom_gateway_icon', LIQUIDPAY_WOO_PLUGIN_DIR . 'includes/icon/logo.png' . '" style="height:35px !important;width:auto !important;');
            $this->has_fields = true;
            $this->method_title = __('Liquidpay QR Code', 'liquidpay-qr-gateway-payment-for-woocommerce');
            $this->method_description = __('Allows customers to use LIQUIDPAY .', 'liquidpay-qr-gateway-payment-for-woocommerce');
            $this->order_button_text = __('Proceed to Payment', 'liquidpay-qr-gateway-payment-for-woocommerce');

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions', $this->description);
            $this->confirm_message = $this->get_option('confirm_message');
            $this->thank_you = $this->get_option('thank_you');
            $this->payment_status = $this->get_option('payment_status', 'on-hold');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->liquid_api_key = $this->get_option('liquid_api_key');
            $this->liquid_secret_key = $this->get_option('liquid_secret_key');
            $this->liquid_url = $this->get_option('merchant_url');
            $this->pay_button = $this->get_option('pay_button');
            $this->button_text = $this->get_option('button_text');
            $this->app_theme = $this->get_option('theme', 'light');
            $this->timeout = $this->get_option('timeout');
            $this->debug = $this->get_option('debug');
            $this->default_status = apply_filters('liquidpay_process_payment_order_status', 'pending');
            $this->label_payload = "Please select one of the following options";
            $this->push_notif_url = "liquidpay-webhook-url";

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));

            // We need custom JavaScript to obtain the transaction number
            add_action('wp_enqueue_scripts', array(
                $this,
                'payment_scripts'
            ));

            // thank you page output
            add_action('woocommerce_receipt_' . $this->id, array(
                $this,
                'generate_qr_code'
            ) , 4, 1);

            // verify payment from redirection
            add_action('woocommerce_api_liquidpay-payment', array(
                $this,
                'capture_payment'
            ));

            // add support for payment for on hold orders
            add_action('woocommerce_valid_order_statuses_for_payment', array(
                $this,
                'on_hold_payment'
            ) , 10, 2);

            // change wc payment link if exists payment method is QR Code
            add_filter('woocommerce_get_checkout_payment_url', array(
                $this,
                'custom_checkout_url'
            ) , 10, 2);

            // add custom text on thankyou page
            add_filter('woocommerce_thankyou_order_received_text', array(
                $this,
                'order_received_text'
            ) , 10, 2);

            add_action('woocommerce_api_' . strtolower(get_class($this)) , array(
                $this,
                'callback_handler'
            ));
            
            // wc_liquidpay_payment_gateway
            add_action('woocommerce_api_liquidpay-webhook', array(
                $this,
                'webhook'
            ));

            // custom wc_liquidpay_payment_gateway
            add_action('woocommerce_api_' . $this->push_notif_url, array(
                $this,
                'webhook'
            ));

            // echo strtolower( get_class($this) );
            if (!$this->is_valid_for_use())
            {
                $this->enabled = 'no';
            }
        }

        public function get_request_headers()
        {
            if (!function_exists('getallheaders'))
            {
                $headers = array();

                foreach ($_SERVER as $name => $value)
                {
                    if ('HTTP_' === substr($name, 0, 5))
                    {
                        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5))))) ] = $value;
                    }
                }

                return $headers;
            }
            else
            {
                return getallheaders();
            }
        }

        private function verifySignature($data = false){
            if(!empty($data)){
                $data = preg_replace('/:\s*(\-?\d+(\.\d+)?([e|E][\-|\+]\d+)?)/', ': "$1"', $data);
                $data = json_decode($data,true);
                $payloads = [];
                $skip = ["nonce", "sign"];
                foreach ($data as $key => $value) {
                    if(!in_array($key,$skip)){
                        $payloads[$key] = $value;
                    }
                }

                ksort($payloads);
                $requestQry = http_build_query($payloads);
                $requestQry = strtoupper($requestQry);

                $requestQry.='&NONCE='.$data["nonce"].'&SECRET='.$this->liquid_secret_key;
                $signature = strtoupper(hash('sha512',$requestQry));

                return ($signature == $data["sign"]);
            }
            
            return false;

        }

        public function webhook()
        {
           
            if ( ( 'POST' !== $_SERVER['REQUEST_METHOD'] )){
                return;
            }
        
            $request_headers = array_change_key_case($this->get_request_headers() , CASE_UPPER);
            $request_body = file_get_contents('php://input');

            $body = json_encode(["date" => date('m/d/Y h:i:s a', time()) , "body" => $request_body , "header" => $request_headers , "server" => $_SERVER , "request" => $_REQUEST]);

            $log_file = plugin_dir_path(__FILE__) . "../logs/logs.log";

            if (is_writable($log_file))
            {

                file_put_contents($log_file, $body . "\n", FILE_APPEND);

            }
            else
            {
                $log_file = fopen($log_file, "w");

                fwrite($log_file, $body . "\n");

            }

            if ($this->is_valid_request($request_headers, $request_body))
            {
                $this->process_webhook($request_body);
                status_header(200);
                exit;
            }
            else
            {
                status_header(400);
                exit;
            }
        }

        private function is_valid_request($request_headers, $request_body)
        {
            if (null === $request_headers || null === $request_body)
            {
                return false;
            }

            if (!empty($request_headers['USER-AGENT']) && !preg_match('/PTSV/', $request_headers['USER-AGENT']))
            {
                // return false;
                
            }

            return true;

        }

        public function process_webhook($raw_post)
        {
            global $wpdb;

            $request = json_decode($raw_post);
            
            if ($this->verifySignature($raw_post) && isset($request->id) && isset($request->bill_ref_no))
            {
                // $request->bill_ref_no = "d2Nfb3JkZXJfdkdIT3NkbTZtNXdzRC1KUnhqbQ==";
                $order_id = $wpdb->get_var($wpdb->prepare("SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key = %s", $request->bill_ref_no, '_liquidpay_order_ref'));

                if (!empty($order_id))
                {
                    $order = wc_get_order($order_id);

                    if ($request->bill_status === "A")
                    {
                        if ($order->get_meta('_liquidpay_order_paid', true) !== 'yes')
                        {
                            $order->add_order_note(__('This payment was verified.', 'liquidpay-qr-gateway-payment-for-woocommerce') , false);
                            // set liquidpay id as trnsaction id
                            if (isset($request->id) && !empty($request->id))
                            {
                                update_post_meta($order->get_id() , '_transaction_id', sanitize_text_field($request->id));
                            }

                            // reduce stock level
                            wc_reduce_stock_levels($order->get_id());

                            // check order if it actually needs payment
                            if (in_array($this->payment_status, apply_filters('liquidpay_valid_order_status_for_note', array(
                                'pending',
                                'on-hold'
                            ))))
                            {
                                // set order note
                                $order->add_order_note(__('Payment primarily completed. Needs shop owner\'s verification.', 'liquidpay-qr-gateway-payment-for-woocommerce') , false);
                            }

                            $order->update_status(apply_filters('liquidpay_capture_payment_order_status', $this->payment_status));

                            // update post meta
                            update_post_meta($order->get_id() , '_liquidpay_order_paid', 'yes');
                            // add custom actions
                            do_action('liquidpay_after_payment_verify', $order->get_id() , $order);

                            $mailer = WC()->mailer();

                            $mails = $mailer->get_emails();

                            if (!empty($mails))
                            {
                                foreach ($mails as $mail)
                                {
                                    if ($mail->id == 'customer_processing_order')
                                    {
                                        $mail->trigger($order->id);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        public function callback_handler()
        {
            $raw_post = file_get_contents('php://input');

            $decoded = json_decode($raw_post);
            if (isset($decoded->lqd_transaction_id))
            {
                $order_id = wc_get_order_id_by_order_key($decoded->order_id);
                $order = wc_get_order($order_id);
                update_post_meta($order->get_id() , '_liquidpay_order_paid', 'no');
                $order->update_status('completed');
                $order->payment_complete();
            }
        }

        public static function get_order_by_charge_id($charge_id)
        {
            global $wpdb;

            if (empty($charge_id))
            {
                return false;
            }

            $order_id = $wpdb->get_var($wpdb->prepare("SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key = %s", $charge_id, '_transaction_id'));

            if (!empty($order_id))
            {
                return wc_get_order($order_id);
            }

            return false;
        }

        /**
         * Check if this gateway is enabled and available in the user's country.
         *
         * @return bool
         */
        public function is_valid_for_use()
        {
            if (get_woocommerce_currency() !== 'IDR' && get_woocommerce_currency() !== 'SGD')
            {
                //return false;
                
            }
            return true;
        }

        /**
         * Admin Panel Options.
         *
         * @since 1.0.0
         */
        public function admin_options()
        {
            if ($this->is_valid_for_use())
            {
                parent::admin_options();
            }
            else
            {
?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e('Gateway disabled', 'liquidpay-qr-gateway-payment-for-woocommerce'); ?></strong>: <?php _e('This plugin does not support your store currency. Liquidpay Payment only supports Indonesian Currency.', 'liquidpay-qr-gateway-payment-for-woocommerce'); ?>
	    			</p>
	    		</div>
	    		<?php
            }
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'checkbox',
                    'label' => __('Enable LIQUIDPAY QR Code Payment', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'description' => __('Enable this if you want to collect payment via LIQUIDPAY QR Codes.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => 'yes',
                    'desc_tip' => true,
                ) ,

                'merchant_url' => array(
                    'title'       => __( 'Merchant URL:', 'liquidpay-qr-gateway-payment-for-woocommerce' ),
                    'type'        => 'text',
                    'default'     => 'https://sandbox.api.liquidpay.com/openapi',
                ),

                'merchant_id' => array(
                    'title' => __(' MERCHANT ID:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'text',
                    'description' => __('Please enter Your LIQUIDPAY MERCHANT at which you want to collect payments.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => '',
                    'desc_tip' => true,
                ) ,
                'liquid_api_key' => array(
                    'title' => __(' MERCHANT KEY:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'text',
                    'description' => __('Please enter Your LIQUIDPAY MERCHANT KEY at which you want to collect payments.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => '',
                    'desc_tip' => true,
                ) ,
                'liquid_secret_key' => array(
                    'title' => __(' SECRET KEY:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'text',
                    'description' => __('Please enter Your LIQUIDPAY MERCHANT SECRET at which you want to collect payments.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => '',
                    'desc_tip' => true,
                ) ,

                'liquid_push_notif_url' => array(
                    'title' => __('Push Notification URL:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'text',
                    'default' => get_site_url() . '/wc-api/liquidpay-webhook',
                    'custom_attributes' => array('readonly' => 'readonly'),
                ) ,
                'title' => array(
                    'title' => __('Title:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => __('Pay with LIQUIDPAY QR Code', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'desc_tip' => true,
                ) ,
                'description' => array(
                    'title' => __('Description:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => __('It uses LIQUIDPAY app to make payment.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'desc_tip' => true,
                ) ,
                'thank_you' => array(
                    'title' => __('Thank You Message:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'textarea',
                    'description' => __('This displays a message to customer after a successful payment is made.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => __('Thank you for your payment. Your transaction has been completed, and your order has been successfully placed. Please check you Email inbox for details. Please check your bank account statement to view transaction details.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'desc_tip' => true,
                ) ,
                'payment_status' => array(
                    'title' => __('Payment Success Status:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'select',
                    'description' => __('Payment action on successful LIQUIDPAY Transaction ID submission.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'desc_tip' => true,
                    'default' => 'on-hold',
                    'options' => apply_filters('liquidpay_settings_order_statuses', array(
                        'pending' => __('Pending Payment', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                        'on-hold' => __('On Hold', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                        'processing' => __('Processing', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                        'completed' => __('Completed', 'liquidpay-qr-gateway-payment-for-woocommerce')
                    ))
                ) ,
                'timeout' => array(
                    'title' => __(' Timeout:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'number',
                    'description' => __('Timeout.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => 180,
                    'desc_tip' => true,
                ) ,
                'pay_button' => array(
                    'title' => __('Pay Now Button Text:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'text',
                    'description' => __('Enter the text to show as the payment button.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => __('Scan & Pay Now', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'desc_tip' => true,
                ) ,

                'theme' => array(
                    'title' => __('Popup Theme:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'select',
                    'description' => __('Select the QR Code Popup theme from here.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'desc_tip' => true,
                    'default' => 'light',
                    'options' => apply_filters('liquidpay_popup_themes', array(
                        'light' => __('Light Theme', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                        'dark' => __('Dark Theme', 'liquidpay-qr-gateway-payment-for-woocommerce')
                    ))
                ) ,
                'debug' => array(
                    'title' => __('Debug QR code:', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'type' => 'select',
                    'description' => __('Debug QR code.', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                    'default' => 'false',
                    'options' => apply_filters('liquidpay_debug', array(
                        'true' => __('Enable', 'liquidpay-qr-gateway-payment-for-woocommerce') ,
                        'false' => __('Disabled', 'liquidpay-qr-gateway-payment-for-woocommerce')
                    ))
                ) ,
            );
        }

        /**
         * Display the liquidpay Id field
         */

        public function payment_fields()
        {
            // display description before the payment form
            if ($this->description)
            {
                // display the description with <p> tags
                echo wpautop(wp_kses_post($this->description));
            }

            $handleTypes = $this->get_payment_types();


            $handles = apply_filters('liquidpaywc_liquidpay_handle_list', (isset($handleTypes->data)) ? $handleTypes->data : ["LIQUID"]);

            // sort($handles);

            $class = 'form-row-wide';

            $liquid_address = (isset($_POST['customer_liquidpay_address'])) ? sanitize_text_field($_POST['customer_liquidpay_address']) : '';

            $required = ' <span class="required">*</span>';

            echo '<fieldset id="' . esc_attr($this->id) . '-payment-form" class="wc-liquidpay-form wc-payment-form" style="background:transparent;">';

            do_action('woocommerce_liquidpay_form_start', $this->id);

            echo '<div class="form-row form-row liquidpay-input"><label>' . __($this->label_payload, 'liquidpay-qr-gateway-payment-for-woocommerce') . $required . '</label>
				<select id="liquidpay-handle" name="customer_liquidpay_handle" style="width: 100%;height: 34px;min-height: 34px;"><option selected disabled hidden value="">' . __('-- Select --', 'liquidpay-qr-gateway-payment-for-woocommerce') . '</option>';
            foreach ($handles as $key => $handle)
            {
                echo '<option value="' . $handle->payload_code . '">' . $handle->name . ' [' . $handle->country_code . ']</option>';
            }
            echo '</select></div>';

            do_action('woocommerce_liquidpay_form_end', $this->id);

            echo '<div class="clear"></div></fieldset>'; ?>
			<script type="text/javascript">
				(function($){
					if( $('#liquidpay-handle').length ) {
						$("#liquidpay-handle").selectize({
							create: <?php echo apply_filters('liquidpay_valid_order_status_for_note', 'false'); ?>,
						});
					}
				})(jQuery);
			</script>
			<?php
        }

        /**
         * Validate liquidpay ID field
         */
        public function validate_fields()
        {
            $label = $this->label_payload;
            if (empty($_POST['customer_liquidpay_handle']))
            {
                wc_add_notice(__("Please select your $label!", 'liquidpay-qr-gateway-payment-for-woocommerce') , 'error');
                return false;
            }

            return true;
        }

        /*
         * Custom CSS and JS
        */
        public function payment_scripts()
        {
            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ('no' === $this->enabled)
            {
                return;
            }

            $ver = LIQUIDPAY_WOO_PLUGIN_VERSION;
            if (defined('IQUIDPAY_WOO_PLUGIN_ENABLE_DEBUG'))
            {
                $ver = time();
            }

            if (is_checkout())
            {
                wp_localize_script('liquidpay-ajax', 'WPURLS', array(
                    'siteurl' => get_option('siteurl')
                ));
                wp_enqueue_style('liquidpay-selectize', plugins_url('css/selectize.min.css', __FILE__) , array() , '0.12.6');
                wp_enqueue_script('liquidpay-selectize-js', plugins_url('js/selectize.min.js', __FILE__) , array(
                    'jquery'
                ) , '0.12.6', false);
            }

            wp_register_style('liquidpay-jquery-confirm', plugins_url('css/jquery-confirm.min.css', __FILE__) , array() , '3.3.4');
            wp_register_style('liquidpay-qr-code', plugins_url('css/liquidpay.css', __FILE__) , array(
                'liquidpay-jquery-confirm'
            ) , $ver);
            wp_register_script('liquidpay-jquery-confirm-js', plugins_url('js/jquery-confirm.min.js', __FILE__) , array(
                'jquery'
            ) , '3.3.4', true);
            wp_register_script('liquidpay-qr-code-js', plugins_url('js/easy.qrcode.min.js', __FILE__) , array(
                'jquery'
            ) , '3.6.0', true);
            wp_register_script('liquidpay-js', plugins_url('js/liquidpay.js', __FILE__) , array(
                'jquery',
                'liquidpay-qr-code-js',
                'liquidpay-jquery-confirm-js'
            ) , $ver, true);
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {

            $order = wc_get_order($order_id);
            $liquidpay_address = !empty($_POST['customer_liquidpay_handle']) ? sanitize_text_field($_POST['customer_liquidpay_handle']) : 'LIQUIDPAY';

            // Mark as pending (we're awaiting the payment)
            $order->update_status($this->default_status);

            // add some order notes
            $order->add_order_note(apply_filters('liquidpay_process_payment_note', sprintf(__('Awaiting LIQUIDPAY Payment!%1$sLIQUIDPAY ID: %2$s', 'liquidpay-qr-gateway-payment-for-woocommerce') , '<br>', $liquidpay_address) , $order) , false);

            // update meta
            update_post_meta($order->get_id() , '_liquidpay_order_paid', 'no');

            if (!empty($liquidpay_address))
            {
                update_post_meta($order->get_id() , '_transaction_id', $liquidpay_address);

            }

            if (apply_filters('liquidpay_payment_empty_cart', false))
            {
                // Empty cart
                WC()
                    ->cart
                    ->empty_cart();
            }

            do_action('liquidpay_after_payment_init', $order_id, $order);

            // Return redirect
            return array(
                'result' => 'success',
                'redirect' => apply_filters('liquidpay_process_payment_redirect', $order->get_checkout_payment_url(true) , $order)
            );
        }

        protected function get_payment_types()
        {
            $liq = new LiquidPay($this->merchant_id, $this->liquid_api_key, $this->liquid_secret_key , $this->liquid_url);
            $paymentType = $liq->paymentType();

            return $paymentType;
        }

        protected function create_payment($order)
        {
            $this->errors = [];

            $liq = new LiquidPay($this->merchant_id, $this->liquid_api_key, $this->liquid_secret_key, $this->liquid_url);

            $transaction_id = get_post_meta($order->get_id() , '_transaction_id', true);

            $createBill = $liq->createBill(["transaction_id" => $transaction_id, "bill_ref_no" => $order->get_order_key() , "amount" => (float)$order->get_total() , "items" => $order->get_items(), "shipping" =>  $order->get_items("shipping") ]);

            if ($createBill->type === "bill" && $createBill->bill_status)
            {
                if (isset($createBill->qr_payload) && !empty($createBill->qr_payload))
                {
                    update_post_meta($order->get_id() , '_liquidpay_order_qr', $createBill->qr_payload);
                    update_post_meta($order->get_id() , '_liquidpay_order_id', $createBill->id);
                    update_post_meta($order->get_id() , '_liquidpay_order_ref', $createBill->bill_ref_no);
                    return $createBill->qr_payload;
                }
                else
                {
                    return get_post_meta($order->get_id() , '_liquidpay_order_qr', true);

                }
            }

            $this->errors = $createBill;

            //update_post_meta( $order->get_id(), '_liquidpay_order_qr', $createBill );
            return false;
        }

        /** wechat qrc_code using PHP */
        protected function generate_qr_ref($order_id)
        {
            $order = wc_get_order($order_id);

            if (!$order)
            {

                return '';
            }

            $url = $this->get_qr_code_uri($order);
            $return_url = $this->get_return_url($order);
            $base_qr = $this->notify_url . '?QRData=';
            $error = $this
                ->wechat
                ->getError();

            if ($error)
            {
                self::log(__METHOD__ . ': ' . wc_print_r($error, true) , 'error');
                $order->update_status('failed', $error['message']);
                WC()
                    ->cart
                    ->empty_cart();

                $error = __('The order has failed. Reason: ', 'woo-wechatpay') . $error['message'];
            }
            elseif (empty($url))
            {
                $status_message = __('QR code url is empty', 'woo-wechatpay');

                $order->update_status('failed', $status_message);
                WC()
                    ->cart
                    ->empty_cart();

                $error = __('The order has failed. Reason: ', 'woo-wechatpay');
            }

            set_query_var('qr_url', $base_qr . $url);
            set_query_var('qr_img_header', $this->qr_img_header);
            set_query_var('qr_img_footer', $this->qr_img_footer);
            set_query_var('qr_phone_bg', $this->qr_phone_bg);
            set_query_var('qr_placeholder', $this->qr_placeholder);
            set_query_var('has_result', (!$this
                ->wechat
                ->getError() && !empty($url)));
            set_query_var('order_id', $order_id);
            set_query_var('error', $error);

            ob_start();
            WP_Weixin::locate_template('computer-pay-qr.php', true, true, 'woo-wechatpay');

            $html = ob_get_clean();

            echo $html; // WPCS: XSS OK
            
        }
        /**
         * Show LIQUIDPAY details as html output
         *
         * @param WC_Order $order_id Order id.
         * @return string
         */
        public function generate_qr_code($order_id)
        {
            // get order object from id
            $order = wc_get_order($order_id);
            $total = apply_filters('liquidpay_order_total_amount', $order->get_total() , $order);

            $types = $this->get_payment_types();

            $paymentType = [];
            if (($types->type == "list"))
            {
                $transaction_id = get_post_meta($order->get_id() , '_transaction_id', true);
                $transaction_id = htmlspecialchars_decode(str_replace("\r\n", "", $transaction_id));

                list($paymentType) = $types->data;
                foreach ($types->data as $key => $type)
                {
                    if (trim(strtolower($transaction_id)) == trim(strtolower($type->payload_code)))
                    {
                        $paymentType = $type;

                    }

                }

            }

            $imageUrl = $paymentType->image_url;
            $image = file_get_contents($paymentType->image_url);
            if ($image !== false)
            {
                $imageUrl = 'data:image/png;base64,' . base64_encode($image);

            }

            //we can have this later version
            $qr_config = json_encode(['logo' => ['width' => 256, 'height' => 256, 'background' => '#ffffff', 'dotScale' => 1]]);

            if (wp_is_mobile())
            {
                $qr_config = json_encode(['logo' => ['width' => 180, 'height' => 180, 'background' => '#ffffff', 'dotScale' => 1]]);
            }

            // enqueue required css & js files
            wp_enqueue_style('liquidpay-jquery-confirm');
            wp_enqueue_style('liquidpay-qr-code');
            wp_enqueue_script('liquidpay-jquery-confirm-js');
            wp_enqueue_script('liquidpay-qr-code-js');
            wp_enqueue_script('liquidpay-js');

            // add localize scripts
            wp_localize_script('liquidpay-js', 'liquidpay_params', array(
                'ajaxurl' => admin_url('admin-ajax.php') ,
                'orderid' => $order_id,
                'order_key' => $order->get_order_key() ,
                'confirm_message' => $this->confirm_message,
                'processing_text' => apply_filters('liquidpay_payment_processing_text', __('Please wait while we are processing your request...', 'liquidpay-qr-gateway-payment-for-woocommerce')) ,
                'callback_url' => add_query_arg(array('wc-api' => 'liquidpay-payment') , trailingslashit(get_home_url())) ,
                'cancel_url' => apply_filters('liquidpay_payment_cancel_url', wc_get_checkout_url() , $this->get_return_url($order) , $order) ,
                'payment_status' => $this->payment_status,
                'app_theme' => $this->app_theme,
                'prevent_reload' => apply_filters('liquidpay_enable_payment_reload', true) ,
                'app_version' => LIQUIDPAY_WOO_PLUGIN_VERSION,
                'pay_button' => $this->pay_button,
                'pay_timeout' => $this->timeout,
                'imageLogo' => $imageUrl,
                'qr_config' => $qr_config,
                'title' => $this->pay_button
            ));

            // call create_payment function
            $qr_code = $this->create_payment($order);

            if (!$qr_code)
            {

                if($this->errors && isset($this->errors->errors)){
                    ?>
                    <ul class="woocommerce-error" role="alert">
                        <?php
                        foreach ($this->errors->errors as $key => $value) {
                            // wc_add_notice(__($value->message, 'liquidpay-qr-gateway-payment-for-woocommerce') , 'error');
                            echo "<li>" . __($value->message, 'liquidpay-qr-gateway-payment-for-woocommerce') . "</li>";
                        }
                        ?>
                    </ul>
                    <?php
                }

                return false;
            }
            // add html output on payment endpoint
            if ('yes' === $this->enabled && $order->needs_payment() === true && $order->has_status($this->default_status))
            { ?>
			    <section class="woo-liquidpay-section">
				    <div class="liquidpay-info">
				        <h6 class="liquidpay-waiting-text"><?php _e('Please wait and don\'t press back or refresh this page while we are processing your payment.', 'liquidpay-qr-gateway-payment-for-woocommerce'); ?></h6>
                        <button id="liquidpay-processing" class="btn button" disabled="disabled"><?php _e('Waiting for payment...', 'liquidpay-qr-gateway-payment-for-woocommerce'); ?></button>
						<?php do_action('liquidpay_after_before_title', $order); ?>
						<div class="liquidpay-buttons" style="display: none;">
						    <button id="liquidpay-confirm-payment" class="btn button" data-theme="<?php echo apply_filters('liquidpay_payment_dialog_theme', 'blue'); ?>"><?php echo esc_html(apply_filters('liquidpay_payment_button_text', $this->pay_button)); ?></button>
			    	        <button id="liquidpay-cancel-payment" class="btn button"><?php _e('Cancel', 'liquidpay-qr-gateway-payment-for-woocommerce'); ?></button>
						</div>
						
						<?php do_action('liquidpay_after_payment_buttons', $order); ?>
				        <div id="js_qrcode">
							<?php if (isset($paymentType->image_url))
                { ?>
								<div style="display:none;" data-image="<?php echo $imageUrl; ?>" id="liquidpay-logo"><img src="<?php echo $paymentType->image_url; ?>"/></div>
							<?php
                } ?>
					        <?php /* if ( apply_filters( 'liquidpay_show_liquidpay_id', true ) ) { ?>
                 <div id="liquidpay-liquidpay-id" class="liquidpay-liquidpay-id"><?php _e( 'LIQUIDPAY ID:', 'liquidpay-qr-gateway-payment-for-woocommerce' ); ?> <span id="liquidpay-liquidpay-id-raw"><?php echo htmlentities( strtoupper( $this->merchant_id ) ); ?></span></div>
                <?php } */ ?>
					    	<div id="liquidpay-qrcode"></div>

					    	<?php if (apply_filters('liquidpay_show_order_total', true))
                { ?>
					    	    <div id="liquidpay-order-total" class="liquidpay-order-total"><?php echo sprintf( __( 'Amount(%s) : %02.2f', 'liquidpay-qr-gateway-payment-for-woocommerce'), get_woocommerce_currency() , $total) ; ?></div>
					    	<?php
                } ?>
					    	<?php /* if ( wp_is_mobile() && apply_filters( 'liquidpay_show_mobile_button', true ) ) { ?>
                <div class="jconfirm-buttons">
                  <a class="liquidpay-mobile-pay-link" href="<?php echo $link_to_pay ; ?>" onclick="window.onbeforeunload = null;"><button type="button" id="liquidpay-pay" class="btn btn-dark btn-liquidpay-pay"><?php echo $this->button_text; ?></button></a>
                </div>
                <?php } ?>
					    	<?php if (apply_filters('liquidpay_show_description', true))
                { ?>
					    	    <div id="liquidpay-description" class="liquidpay-description"><?php echo wptexturize($this->instructions); ?></div>
					        <?php
                } */
                
                ?>
					    	<?php if (wp_is_mobile())
                { ?>
					    	    <input type="hidden" id="data-qr-code" data-width="200" data-height="200" data-link="<?php echo $qr_code; ?>">
					    	    <input type="hidden" id="data-dialog-box" data-pay="100%" data-confirm="100%" data-redirect="95%" data-offset="0">
					    	    <input type="hidden" id="data-ref" value="<?php print $order->get_order_key(); ?>">
					    	<?php
                }
                else
                { ?>
					    	    <input type="hidden" id="data-qr-code" data-width="256" data-height="256" data-link="<?php echo $qr_code; ?>">
					    		<input type="hidden" id="data-dialog-box" data-pay="60%" data-confirm="50%" data-redirect="40%" data-offset="40">
					    		<input type="hidden" id="data-ref" value="<?php print $order->get_order_key(); ?>">
					    	<?php
                } ?>
							<?php if ($this->debug == 'true')
                { ?>
								<textarea><?php echo $qr_code; ?></textarea>
							<?php
                } ?>
							
					    </div>
						<div id="payment-success-container" style="display: none;"></div>
					</div>
				</section><?php
            }
        }

        /**
         * Process payment verification.
         */
        public function capture_payment()
        {
            // get order id
            if (('POST' !== $_SERVER['REQUEST_METHOD']) || !isset($_GET['wc-api']) || ('liquidpay-payment' !== $_GET['wc-api']))
            {
                // create redirect
                wp_safe_redirect(home_url());
                exit;
            }

            // generate order
            $order = wc_get_order(wc_get_order_id_by_order_key(sanitize_text_field($_POST['wc_order_key'])));

            // check if it an order
            if (is_a($order, 'WC_Order'))
            {
                if ($order->get_meta('_liquidpay_order_paid', true) !== 'yes')
                {
                    $order->update_status(apply_filters('liquidpay_capture_payment_order_status', $this->payment_status));
                    // set liquidpay id as trnsaction id
                    if (isset($_POST['wc_transaction_id']) && !empty($_POST['wc_transaction_id']))
                    {
                        update_post_meta($order->get_id() , '_transaction_id', sanitize_text_field($_POST['wc_transaction_id']));
                    }
                    // reduce stock level
                    wc_reduce_stock_levels($order->get_id());
                    // check order if it actually needs payment
                    if (in_array($this->payment_status, apply_filters('liquidpay_valid_order_status_for_note', array(
                        'pending',
                        'on-hold'
                    ))))
                    {
                        // set order note
                        $order->add_order_note(__('Payment primarily completed. Needs shop owner\'s verification.', 'liquidpay-qr-gateway-payment-for-woocommerce') , false);
                    }
                    // update post meta
                    update_post_meta($order->get_id() , '_liquidpay_order_paid', 'yes');
                    // add custom actions
                    do_action('liquidpay_after_payment_verify', $order->get_id() , $order);
                }
                else{
                    wp_safe_redirect( apply_filters( 'liquidpay_payment_redirect_url', $this->get_return_url( $order ), $order ) );
                }

                
            }
            else
            {
                // create redirect
                $title = __('Order can\'t be found against this Order ID. If the money debited from your account, please Contact with Site Administrator for further action.', 'liquidpay-qr-gateway-payment-for-woocommerce');

                wp_die($title, get_bloginfo('name'));
                exit;
            }

            // create redirect
            wp_safe_redirect( apply_filters( 'liquidpay_payment_redirect_url', $this->get_return_url( $order ), $order ) );
            exit;
        }

        /**
         * Custom checkout URL.
         *
         * @param string   $url Default URL.
         * @param WC_Order $order Order data.
         * @return string
         */
        public function custom_checkout_url($url, $order)
        {
            if ($this->id === $order->get_payment_method() && (($order->has_status('on-hold') && $this->default_status === 'on-hold') || ($order->has_status('pending') && apply_filters('liquidpay_custom_checkout_url', false))))
            {
                return esc_url(remove_query_arg('pay_for_order', $url));
            }

            return $url;
        }

        /**
         * Allows payment for orders with on-hold status.
         *
         * @param string   $statuses  Default status.
         * @param WC_Order $order     Order data.
         * @return string
         */
        public function on_hold_payment($statuses, $order)
        {
            if ($this->id === $order->get_payment_method() && $order->has_status('on-hold') && $order->get_meta('_liquidpay_order_paid', true) !== 'yes' && $this->default_status === 'on-hold')
            {
                $statuses[] = 'on-hold';
            }

            return $statuses;
        }

        /**
         * Custom order received text.
         *
         * @param string   $text Default text.
         * @param WC_Order $order Order data.
         * @return string
         */
        public function order_received_text($text, $order)
        {
            if ($this->id === $order->get_payment_method() && !empty($this->thank_you))
            {
                return esc_html($this->thank_you);
            }

            return $text;
        }
    }
}

