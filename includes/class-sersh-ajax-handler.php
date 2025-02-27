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
            
                // WC_Sersh_Payment::log(sprintf(
                //     'Processing transaction verification - TX Hash: %s, Wallet: %s, Order ID: %s',
                //     $tx_hash,
                //     $wallet_address,
                //     $order_id ? $order_id : 'not provided'
                // ), 'debug');

            // If we don't have a valid order ID, try to find it based on the wallet address
            // if (!$order_id) {
                // if ($debug_mode) {
                //     WC_Sersh_Payment::log('No order ID provided. Attempting to find order by wallet address.', 'debug');
                // }

                if (empty($wallet_address)) {
                    throw new Exception(__('Both order ID and wallet address are missing. Cannot verify payment.', 'wc-sersh-payment'));
                }

                // store the tx_hash and wallet address in the session
                WC()->session->set('sersh_tx_hash', $tx_hash);
                WC()->session->set('sersh_wallet_address', $wallet_address);

                // // Find orders with this wallet address
                // $orders = wc_get_orders(array(
                //     'status' => 'pending',
                //     'limit' => 5,
                //     'orderby' => 'date',
                //     'order' => 'DESC',
                //     'meta_key' => '_sersh_payer_wallet',
                //     'meta_value' => $wallet_address,
                // ));

                // if (!empty($orders)) {
                //     $order = reset($orders); // Get the first/most recent order
                //     $order_id = $order->get_id();
                    
                //     if ($debug_mode) {
                //         WC_Sersh_Payment::log(sprintf(
                //             'Found order #%d with matching wallet address %s',
                //             $order_id,
                //             $wallet_address
                //         ), 'debug');
                //     }
                // } else {
                //     // If we still don't have an order, look for recent pending orders
                //     $recent_orders = wc_get_orders(array(
                //         'status' => 'pending',
                //         'limit' => 10,
                //         'orderby' => 'date',
                //         'order' => 'DESC'
                //     ));

                //     if (!empty($recent_orders)) {
                //         // Find an order without wallet address but with matching payment method
                //         foreach ($recent_orders as $recent_order) {
                //             if ($recent_order->get_payment_method() === 'sersh' && !$recent_order->get_meta('_sersh_payer_wallet')) {
                //                 $order = $recent_order;
                //                 $order_id = $order->get_id();
                                
                //                 if ($debug_mode) {
                //                     WC_Sersh_Payment::log(sprintf(
                //                         'Found recent pending SERSH order #%d without wallet address',
                //                         $order_id
                //                     ), 'debug');
                //                 }
                //                 break;
                //             }
                //         }
                //     }
                // }
                
                // if (!$order_id) {
                //     throw new Exception(__('Could not find a matching order for this transaction', 'wc-sersh-payment'));
                // }
            // }

            // Get the order
            // $order = wc_get_order($order_id);
            // if (!$order) {
            //     throw new Exception(sprintf(__('Order #%d not found', 'wc-sersh-payment'), $order_id));
            // }

            // Check if this is a "Test" order and try to find the real customer order
            // $order_billing_first_name = $order->get_billing_first_name();
            // $is_test_order = ($order_billing_first_name === 'Test');
            
            // if ($is_test_order && $debug_mode) {
            //     WC_Sersh_Payment::log(sprintf(
            //         'This appears to be a Test order (#%d). Will attempt to find the real customer order.',
            //         $order_id
            //     ), 'debug');
            // }
            
            // // If the current order is a Test order, try to find the real customer order with the same total
            // $customer_order = null;
            // if ($is_test_order) {
            //     $potential_customer_orders = wc_get_orders(array(
            //         'status' => 'pending',
            //         'date_created' => date('Y-m-d', strtotime($order->get_date_created())),
            //         'meta_query' => array(
            //             array(
            //                 'key' => '_order_total',
            //                 'value' => $order->get_total(),
            //                 'compare' => '=',
            //             )
            //         ),
            //         'excluding' => array($order_id),
            //         'limit' => 5, // Get a few potential matches
            //     ));
                
            //     // Find the order that's not a Test order
            //     foreach ($potential_customer_orders as $potential_order) {
            //         if ($potential_order->get_billing_first_name() !== 'Test') {
            //             $customer_order = $potential_order;
                        
            //             if ($debug_mode) {
            //                 WC_Sersh_Payment::log(sprintf(
            //                     'Found matching customer order #%d for test order #%d',
            //                     $customer_order->get_id(),
            //                     $order_id
            //                 ), 'debug');
            //             }
            //             break;
            //         }
            //     }
            // }

            // Set the transaction ID on the current order
            // $order->set_transaction_id($tx_hash);
            
            // // Add order note about the transaction hash
            // $order->add_order_note(sprintf(
            //     __('SERSH blockchain transaction hash: %s', 'wc-sersh-payment'),
            //     $tx_hash
            // ));
            
            // // If wallet address was provided, save it
            // if (!empty($wallet_address)) {
            //     $order->update_meta_data('_sersh_payer_wallet', $wallet_address);
            //     $order->add_order_note(sprintf(
            //         __('Payer wallet address (Transaction verification): %s', 'wc-sersh-payment'),
            //         $wallet_address
            //     ));
            // }
            
            // Save the order with transaction ID
            // $order->save();
            
            // // If we found a customer order, copy the blockchain details to it as well
            // if ($customer_order) {
            //     // Set transaction hash on the customer order
            //     $customer_order->set_transaction_id($tx_hash);
            //     $customer_order->add_order_note(sprintf(
            //         __('SERSH blockchain transaction hash: %s (synced from test order #%d)', 'wc-sersh-payment'),
            //         $tx_hash,
            //         $order_id
            //     ));
                
            //     // Copy wallet address if available
            //     if (!empty($wallet_address)) {
            //         $customer_order->update_meta_data('_sersh_payer_wallet', $wallet_address);
            //         $customer_order->add_order_note(sprintf(
            //             __('Payer wallet address: %s (synced from test order #%d)', 'wc-sersh-payment'),
            //             $wallet_address,
            //             $order_id
            //         ));
            //     }
                
            //     // Save the customer order
            //     $customer_order->save();
                
            //     if ($debug_mode) {
            //         WC_Sersh_Payment::log(sprintf(
            //             'Blockchain details synced from test order #%d to customer order #%d',
            //             $order_id,
            //             $customer_order->get_id()
            //         ), 'debug');
            //     }
            // }
            
            // // Verify the transaction ID was saved properly
            // $saved_tx_id = $order->get_transaction_id();
            // if ($debug_mode) {
            //     WC_Sersh_Payment::log(sprintf(
            //         'Verification - Saved transaction ID: %s, Expected: %s',
            //         $saved_tx_id,
            //         $tx_hash
            //     ), 'debug');
                
            //     // Check if the transaction ID was actually saved
            //     if ($saved_tx_id !== $tx_hash) {
            //         WC_Sersh_Payment::log(
            //             'Warning: Transaction ID mismatch after saving. This could indicate a persistence issue.',
            //             'error'
            //         );
            //     }
            // }

            // // Verify the transaction
            // $verifier = new Sersh_Transaction_Verifier();
            // $result = $verifier->verify_transaction($tx_hash, $order_id);

            // if ($result['success']) {
            //     if ($debug_mode) {
            //         WC_Sersh_Payment::log(sprintf(
            //             'Transaction verification successful - Order ID: %s, TX Hash: %s',
            //             $order_id,
            //             $tx_hash
            //         ), 'debug');
            //     }
            //     wp_send_json_success($result['message']);
            // } else {
            //     if ($debug_mode) {
            //         WC_Sersh_Payment::log(sprintf(
            //             'Transaction verification failed - Order ID: %s, TX Hash: %s, Message: %s',
            //             $order_id,
            //             $tx_hash,
            //             $result['message']
            //         ), 'error');
            //     }
            //     wp_send_json_error($result['message']);
            // }

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
            
            // Check directly for an order ID from the POST data
            // $order_id = isset($_POST['order_id']) && !empty($_POST['order_id']) ? intval($_POST['order_id']) : null;
            
            // WC_Sersh_Payment::log('received order_id: ' . $order_id, 'debug');

            // If no order ID was passed, check if there's one in the session
            // if (!$order_id) {
            //     $order_id = WC()->session ? WC()->session->get('order_awaiting_payment') : null;
            //     WC_Sersh_Payment::log('session order_id: ' . $order_id, 'debug');
            // }
            
            // If we still don't have an order ID, create a proper order
            // if (!$order_id) {
                // Check if cart exists and is not empty
                // if (!WC()->cart || WC()->cart->is_empty()) {
                //     throw new Exception(__('Cannot create order: cart is empty', 'wc-sersh-payment'));
                // }
                
                // Create a new order properly using the WooCommerce checkout process
                try {
                    // Get customer information
                    // $customer_data = array();
                    // if (WC()->customer) {
                    //     $customer_data = array(
                    //         'billing_first_name' => WC()->customer->get_billing_first_name(),
                    //         'billing_last_name' => WC()->customer->get_billing_last_name(),
                    //         'billing_company' => WC()->customer->get_billing_company(),
                    //         'billing_address_1' => WC()->customer->get_billing_address_1(),
                    //         'billing_address_2' => WC()->customer->get_billing_address_2(),
                    //         'billing_city' => WC()->customer->get_billing_city(),
                    //         'billing_state' => WC()->customer->get_billing_state(),
                    //         'billing_postcode' => WC()->customer->get_billing_postcode(),
                    //         'billing_country' => WC()->customer->get_billing_country(),
                    //         'billing_email' => WC()->customer->get_billing_email(),
                    //         'billing_phone' => WC()->customer->get_billing_phone(),
                    //         'shipping_first_name' => WC()->customer->get_shipping_first_name(),
                    //         'shipping_last_name' => WC()->customer->get_shipping_last_name(),
                    //         'shipping_company' => WC()->customer->get_shipping_company(),
                    //         'shipping_address_1' => WC()->customer->get_shipping_address_1(),
                    //         'shipping_address_2' => WC()->customer->get_shipping_address_2(),
                    //         'shipping_city' => WC()->customer->get_shipping_city(),
                    //         'shipping_state' => WC()->customer->get_shipping_state(),
                    //         'shipping_postcode' => WC()->customer->get_shipping_postcode(),
                    //         'shipping_country' => WC()->customer->get_shipping_country(),
                    //     );
                    // }
                    
                    // // Ensure we have at least an email address for the order
                    // if (empty($customer_data['billing_email']) && WC()->session) {
                    //     $customer = WC()->session->get('customer');
                    //     if (!empty($customer['email'])) {
                    //         $customer_data['billing_email'] = $customer['email'];
                    //     }
                    // }
                    
                    // // If we still don't have an email and user is logged in, use their account email
                    // if (empty($customer_data['billing_email']) && is_user_logged_in()) {
                    //     $current_user = wp_get_current_user();
                    //     $customer_data['billing_email'] = $current_user->user_email;
                        
                    //     // If we don't have a name, use their account name
                    //     if (empty($customer_data['billing_first_name'])) {
                    //         $customer_data['billing_first_name'] = $current_user->first_name;
                    //         $customer_data['billing_last_name'] = $current_user->last_name;
                    //     }
                    // }
                    
                    // // Set payment method to SERSH
                    // $customer_data['payment_method'] = 'sersh';
                    
                    // Create the order using WC_Checkout
                    $checkout = WC()->checkout();
                    if (!$checkout) {
                        throw new Exception(__('Checkout process not available', 'wc-sersh-payment'));
                    }

    
              
                    
                    
                    // Store the wallet address in the order meta
                    // $order->update_meta_data('_sersh_payer_wallet', $user_address);
                    // $order->add_order_note(sprintf(
                    //     __('Payer wallet address (Order creation): %s', 'wc-sersh-payment'),
                    //     $user_address
                    // ));
                    
                    // Store order ID in session
                    // if (WC()->session) {
                    //     WC()->session->set('order_awaiting_payment', $order_id);
                    // }
                    
                    // // Save the order
                    // $order->save();
                    
                    // if ('yes' === $gateway->get_option('debug')) {
                    //     WC_Sersh_Payment::log(sprintf(
                    //         'Created order #%d for payment signature. Customer: %s %s, Email: %s',
                    //         $order_id,
                    //         $customer_data['billing_first_name'] ?? 'Unknown',
                    //         $customer_data['billing_last_name'] ?? '',
                    //         $customer_data['billing_email'] ?? 'Unknown'
                    //     ), 'debug');
                    // }
                    
                } catch (Exception $e) {
                    WC_Sersh_Payment::log('Error creating order: ' . $e->getMessage(), 'error');
                    throw new Exception(__('Failed to create order: ', 'wc-sersh-payment') . $e->getMessage());
                }
            // }
            
            // Log information about the order
            // if ('yes' === $gateway->get_option('debug')) {
            //     WC_Sersh_Payment::log(sprintf(
            //         'Payment signature requested - USD: %f, User: %s, Order ID: %s',
            //         $usd_amount,
            //         $user_address,
            //         $order_id
            //     ), 'debug');
            // }

            // Getting user ID from WordPress
            $user_id = get_current_user_id();

            // Set expiry to 1 hour from now
            $expiry = time() + 3600;

            // Generate nonce using a cryptographically secure method
            $nonce = "0x" . bin2hex(random_bytes(16));

            // Get signature
            require_once WC_SERSH_PLUGIN_DIR . 'includes/class-sersh-payment-signer.php';
            $signer = new Sersh_Payment_Signer();
            $result = $signer->generate_payment_signature($user_id, $usd_amount, $nonce, $expiry, $user_address);
            
            if ($result['success']) {
                // Add debug information if enabled
                // if ('yes' === $gateway->get_option('debug')) {
                //     WC_Sersh_Payment::log(sprintf(
                //         'Payment signature generated successfully for address: %s, Order ID: %d',
                //         $user_address,
                //         $order_id
                //     ), 'debug');
                // }
                
                // Include the order ID in the response
                // $result['data']['orderId'] = $order_id;
                
                // Save wallet address to the order if not already saved
                $order = wc_get_order($order_id);
                // if ($order) {
                //     $existing_wallet = $order->get_meta('_sersh_payer_wallet');
                //     if (empty($existing_wallet)) {
                //         $order->update_meta_data('_sersh_payer_wallet', $user_address);
                //         $order->add_order_note(sprintf(
                //             __('Payer wallet address (Signature generation): %s', 'wc-sersh-payment'),
                //             $user_address
                //         ));
                //         $order->save();
                        
                //         if ('yes' === $gateway->get_option('debug')) {
                //             WC_Sersh_Payment::log(sprintf(
                //                 'Saved wallet address to order #%d during signature generation',
                //                 $order_id
                //             ), 'debug');
                //         }
                //     }
                // }
                
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