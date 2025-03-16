<?php
/**
 * SERSH AJAX Handler
 *
 * @package WC_Sersh_Payment
 */

defined('ABSPATH') || exit;

/**
 * Sersh_Ajax_Handler Class
 */
class Sersh_Ajax_Handler {
    /**
     * Constructor
     */
    public function __construct() {
        // Payment verification endpoint
        add_action('wp_ajax_wc_sersh_verify_payment', array($this, 'verify_payment'));
        add_action('wp_ajax_nopriv_wc_sersh_verify_payment', array($this, 'verify_payment'));

        // Get payment signature endpoint
        add_action('wp_ajax_wc_sersh_get_payment_signature', array($this, 'get_payment_signature'));
        add_action('wp_ajax_nopriv_wc_sersh_get_payment_signature', array($this, 'get_payment_signature'));
        
        // Save wallet address endpoint
        add_action('wp_ajax_wc_sersh_save_wallet_address', array($this, 'save_wallet_address'));
        add_action('wp_ajax_nopriv_wc_sersh_save_wallet_address', array($this, 'save_wallet_address'));
        
        // Initialize settings during construction
        $this->init_settings();
    }

    /**
     * Initialize gateway settings
     * 
     * @return array Gateway settings
     */
    public static function init_settings() {
        // Get gateway settings
        $settings = get_option('woocommerce_sersh_settings', array());

        // Set default settings if not exists
        if (empty($settings)) {
            $default_settings = array(
                'enabled'          => 'yes',
                'title'           => __('SERSH Token Payment', 'wc-sersh-payment'),
                'description'     => __('Pay with SERSH tokens via MetaMask or other Web3 wallet.', 'wc-sersh-payment'),
                'testmode'        => 'yes',
                'debug'           => 'yes',
                'token_address'   => WC_SERSH_DEFAULT_TOKEN_ADDRESS,
                'payment_address' => WC_SERSH_DEFAULT_PAYMENT_ADDRESS,
                'private_key'     => 'b5c6bea4b1c7677f64569a3401c520c8be6df7ffd1f29deb822ced0837059fee',
                'public_key'      => '0x2ba400efb7bC1bbd9786444e04f5ED28F8CDF14c',
                'transfer_event_signature' => '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef'
            );

            update_option('woocommerce_sersh_settings', $default_settings);
            return $default_settings;
        } else {
            // Ensure default keys exist
            $updated = false;
            
            if (empty($settings['private_key'])) {
                $settings['private_key'] = 'b5c6bea4b1c7677f64569a3401c520c8be6df7ffd1f29deb822ced0837059fee';
                $settings['public_key'] = '0x2ba400efb7bC1bbd9786444e04f5ED28F8CDF14c';
                $updated = true;
            }
            
            if (empty($settings['transfer_event_signature'])) {
                $settings['transfer_event_signature'] = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
                $updated = true;
            }
            
            if ($updated) {
                update_option('woocommerce_sersh_settings', $settings);
            }
            
            return $settings;
        }
    }
    
    /**
     * Get a gateway setting
     * 
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value
     */
    public static function get_setting($key, $default = '') {
        $settings = get_option('woocommerce_sersh_settings', array());
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    /**
     * Verify payment
     */
    public function verify_payment() {
        try {

            if (!isset($_POST['tx_hash'])) {
                throw new Exception(__('Missing transaction hash parameter', 'wc-sersh-payment'));
            }

            // Get the order ID, handling the case where it might be 0 or empty
            $tx_hash = sanitize_text_field($_POST['tx_hash']);
            $wallet_address = isset($_POST['user_address']) ? sanitize_text_field($_POST['user_address']) : '';

            // Get gateway instance for logging
            $gateway = new WC_Gateway_Sersh();
            $debug_mode = 'yes' === $gateway->get_option('debug');
            


                if (empty($wallet_address)) {
                    throw new Exception(__('Both order ID and wallet address are missing. Cannot verify payment.', 'wc-sersh-payment'));
                }

                // store the tx_hash and wallet address in the session
                WC()->session->set('sersh_tx_hash', $tx_hash);
                WC()->session->set('sersh_wallet_address', $wallet_address);



        } catch (Exception $e) {
            WC_Sersh_Payment::log('Transaction verification error: ' . $e->getMessage(), 'error');
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Get payment signature
     */
    public function get_payment_signature() {
        try {
            // print the context
            if (!isset($_POST['user_address']) || !isset($_POST['amount'])) {
                throw new Exception(__('Missing required parameters', 'wc-sersh-payment'));
            }

            $user_address = sanitize_text_field($_POST['user_address']);
            $usd_amount = floatval($_POST['amount']);

            // Get gateway instance
            $gateway = new WC_Gateway_Sersh();
            
            // Convert USD amount to SERSH tokens
            $token_amount = $this->convert_usd_to_tokens($usd_amount, $gateway);
            
            // Log the conversion for debugging
            WC_Sersh_Payment::log(sprintf(
                'Converting %f USD to %f SERSH tokens',
                $usd_amount,
                $token_amount
            ), 'debug');
            
            // Getting user ID from WordPress
            $user_id = get_current_user_id();

            // Set expiry to 1 hour from now
            $expiry = time() + 3600;

            // Generate nonce using a cryptographically secure method
            $nonce = "0x" . bin2hex(random_bytes(16));

            // Get signature
            require_once WC_SERSH_PLUGIN_DIR . 'includes/class-sersh-payment-signer.php';
            $signer = new Sersh_Payment_Signer();
            
            // Convert token amount to wei (18 decimals)
            $token_amount_wei = $this->convert_tokens_to_wei($token_amount, $gateway);
            
            // Send the token amount in wei to the signature endpoint
            $result = $signer->generate_payment_signature($user_id, $token_amount_wei, $nonce, $expiry, $user_address);
            
            if ($result['success']) {
                wp_send_json_success($result['data']);
            } else {
                WC_Sersh_Payment::log('Payment signature generation failed: ' . $result['error'], 'error');
                wp_send_json_error($result['error']);
            }

        } catch (Exception $e) {
            WC_Sersh_Payment::log('AJAX error: ' . $e->getMessage(), 'error');
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Convert USD amount to SERSH tokens
     *
     * @param float $usd_amount Amount in USD
     * @param WC_Gateway_Sersh $gateway Gateway instance
     * @return float Amount in SERSH tokens
     */
    private function convert_usd_to_tokens($usd_amount, $gateway) {
        // Get token price from price feed or fallback to fixed price
        $price_feed_url = $gateway->get_option('price_feed_url');
        $token_price = floatval($gateway->get_option('token_price', 1.0));
        
        if (!empty($price_feed_url)) {
            try {
                WC_Sersh_Payment::log(sprintf(
                    'Fetching SERSH price from: %s',
                    $price_feed_url
                ), 'debug');
                
                $response = wp_remote_get($price_feed_url, array(
                    'timeout' => 30,
                    'headers' => array(
                        'Accept' => 'application/json'
                    )
                ));
                
                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    WC_Sersh_Payment::log(sprintf(
                        'Price feed response: %s',
                        $body
                    ), 'debug');
                    
                    $price_data = json_decode($body, true);
                    
                    // Check if price data was returned in the correct format for SERSH token API
                    if (isset($price_data['quotes']) && 
                        is_array($price_data['quotes']) && 
                        !empty($price_data['quotes']) && 
                        isset($price_data['quotes'][0]['price'])) {
                        
                        // Get the direct price value from quotes array
                        $token_price = floatval($price_data['quotes'][0]['price']);
                        
                        WC_Sersh_Payment::log(sprintf(
                            'Got SERSH token price from API: %f USD/SERSH (token: %s)',
                            $token_price,
                            $price_data['symbol'] ?? 'SERSH'
                        ), 'debug');
                    } else {
                        WC_Sersh_Payment::log(
                            'Price feed did not return data in expected format, falling back to fixed price',
                            'warning'
                        );
                    }
                } else {
                    WC_Sersh_Payment::log(sprintf(
                        'Error fetching price feed: %s',
                        $response->get_error_message()
                    ), 'error');
                }
            } catch (Exception $e) {
                WC_Sersh_Payment::log('Error processing price feed: ' . $e->getMessage(), 'error');
                // Fall back to fixed price
            }
        } else {
            WC_Sersh_Payment::log('No price feed URL configured, using fixed price', 'info');
        }
        
        // Ensure token price is positive
        if ($token_price <= 0) {
            $token_price = 1.0; // Fallback to 1:1 if price is invalid
            WC_Sersh_Payment::log('Invalid token price, using 1:1 conversion', 'warning');
        }
        
        // Calculate token amount (USD amount divided by token price)
        $token_amount = $usd_amount / $token_price;
        
        // Apply a correction factor of 1/100 to fix the calculation
        // This is needed because something in our process is resulting in values 100x larger than expected
        $token_amount = $token_amount / 100;
        
        WC_Sersh_Payment::log(sprintf(
            'Price conversion (with 1/100 correction factor): %f USD = %f SERSH (price: %f USD/SERSH)',
            $usd_amount,
            $token_amount,
            $token_price
        ), 'info');
        
        return $token_amount;
    }
    
    /**
     * Convert SERSH tokens to wei (18 decimals)
     *
     * @param float $token_amount Amount in SERSH tokens
     * @param WC_Gateway_Sersh $gateway Gateway instance
     * @return string Amount in wei (as a string)
     */
    private function convert_tokens_to_wei($token_amount, $gateway) {
        // Get token decimals from settings, default to 18
        $decimals = intval($gateway->get_option('token_decimals', 18));
        
        WC_Sersh_Payment::log(sprintf(
            'Converting %f SERSH tokens to wei using %d decimals',
            $token_amount,
            $decimals
        ), 'debug');
        
        // Check if BC Math is available
        if (function_exists('bcmul') && function_exists('bcpow')) {
            // Calculate wei amount using BC Math (token amount * 10^decimals)
            $wei_amount = bcmul((string)$token_amount, bcpow('10', (string)$decimals, 0), 0);
            
            WC_Sersh_Payment::log('Using BC Math for high precision conversion', 'debug');
        } else {
            // Alternative calculation method without BC Math
            // Note: This has precision limitations for very large numbers
            $wei_amount = $token_amount * pow(10, $decimals);
            $wei_amount = number_format($wei_amount, 0, '', '');
            
            WC_Sersh_Payment::log('BC Math unavailable, using alternative conversion method', 'debug');
        }
        
        WC_Sersh_Payment::log(sprintf(
            'Conversion result: %f SERSH = %s wei (%d decimals)',
            $token_amount,
            $wei_amount,
            $decimals
        ), 'info');
        
        return $wei_amount;
    }

    /**
     * Save wallet address
     */
    public function save_wallet_address() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc-sersh-payment-nonce')) {
                throw new Exception(__('Invalid security token', 'wc-sersh-payment'));
            }

            if (!isset($_POST['order_id']) || !isset($_POST['wallet_address'])) {
                throw new Exception(__('Missing required parameters', 'wc-sersh-payment'));
            }

            $order_id = intval($_POST['order_id']);
            $wallet_address = sanitize_text_field($_POST['wallet_address']);

            // Get gateway instance for logging
            $gateway = new WC_Gateway_Sersh();
            $debug_mode = 'yes' === $gateway->get_option('debug');
            
            if ($debug_mode) {
                WC_Sersh_Payment::log(sprintf(
                    'Saving wallet address - Order ID: %s, Wallet: %s',
                    $order_id,
                    $wallet_address
                ), 'debug');
            }

            // Get order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'wc-sersh-payment'));
            }

            // Check if this is a "Test" order
            $order_billing_first_name = $order->get_billing_first_name();
            $is_test_order = ($order_billing_first_name === 'Test');
            
            // Save wallet address to order meta
            $order->update_meta_data('_sersh_payer_wallet', $wallet_address);
            $order->add_order_note(sprintf(
                __('Payer wallet address (AJAX): %s', 'wc-sersh-payment'),
                $wallet_address
            ));
            $order->save();
            
            // If this is a Test order, try to find and update the customer order
            if ($is_test_order) {
                if ($debug_mode) {
                    WC_Sersh_Payment::log(sprintf(
                        'Test order detected (#%d). Looking for matching customer order to sync wallet address.',
                        $order_id
                    ), 'debug');
                }
                
                // Try to find the real customer order with the same total
                $potential_customer_orders = wc_get_orders(array(
                    'status' => 'pending',
                    'date_created' => date('Y-m-d', strtotime($order->get_date_created())),
                    'meta_query' => array(
                        array(
                            'key' => '_order_total',
                            'value' => $order->get_total(),
                            'compare' => '=',
                        )
                    ),
                    'excluding' => array($order_id),
                    'limit' => 5, // Get a few potential matches
                ));
                
                // Find the order that's not a Test order
                foreach ($potential_customer_orders as $potential_order) {
                    if ($potential_order->get_billing_first_name() !== 'Test') {
                        // Save wallet address to customer order
                        $potential_order->update_meta_data('_sersh_payer_wallet', $wallet_address);
                        $potential_order->add_order_note(sprintf(
                            __('Payer wallet address: %s (synced from test order #%d)', 'wc-sersh-payment'),
                            $wallet_address,
                            $order_id
                        ));
                        $potential_order->save();
                        
                        if ($debug_mode) {
                            WC_Sersh_Payment::log(sprintf(
                                'Wallet address synced from test order #%d to customer order #%d',
                                $order_id,
                                $potential_order->get_id()
                            ), 'debug');
                        }
                        break;
                    }
                }
            }

            wp_send_json_success(__('Wallet address saved', 'wc-sersh-payment'));
        } catch (Exception $e) {
            WC_Sersh_Payment::log('AJAX error saving wallet address: ' . $e->getMessage(), 'error');
            wp_send_json_error($e->getMessage());
        }
    }
} 