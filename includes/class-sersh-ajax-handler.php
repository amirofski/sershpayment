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
    }

    /**
     * Verify payment
     */
    public function verify_payment() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc-sersh-payment-nonce')) {
                throw new Exception(__('Invalid security token', 'wc-sersh-payment'));
            }

            if (!isset($_POST['order_id']) || !isset($_POST['tx_hash'])) {
                throw new Exception(__('Missing required parameters', 'wc-sersh-payment'));
            }

            $order_id = intval($_POST['order_id']);
            $tx_hash = sanitize_text_field($_POST['tx_hash']);
            $wallet_address = isset($_POST['user_address']) ? sanitize_text_field($_POST['user_address']) : '';

            // Get gateway instance for logging
            $gateway = new WC_Gateway_Sersh();
            $debug_mode = 'yes' === $gateway->get_option('debug');
            
            if ($debug_mode) {
                WC_Sersh_Payment::log(sprintf(
                    'Processing transaction verification - Order ID: %s, TX Hash: %s, Wallet: %s',
                    $order_id,
                    $tx_hash,
                    $wallet_address
                ), 'debug');
            }

            // Get order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'wc-sersh-payment'));
            }

            // Set the transaction ID
            $order->set_transaction_id($tx_hash);
            
            // Add order note about the transaction hash
            $order->add_order_note(sprintf(
                __('SERSH blockchain transaction hash: %s', 'wc-sersh-payment'),
                $tx_hash
            ));
            
            // If wallet address was provided, save it
            if (!empty($wallet_address)) {
                $order->update_meta_data('_sersh_payer_wallet', $wallet_address);
                $order->add_order_note(sprintf(
                    __('Payer wallet address (Transaction verification): %s', 'wc-sersh-payment'),
                    $wallet_address
                ));
            }
            
            // Save the order with transaction ID
            $order->save();
            
            // Verify the transaction ID was saved properly
            $saved_tx_id = $order->get_transaction_id();
            if ($debug_mode) {
                WC_Sersh_Payment::log(sprintf(
                    'Verification - Saved transaction ID: %s, Expected: %s',
                    $saved_tx_id,
                    $tx_hash
                ), 'debug');
                
                // Check if the transaction ID was actually saved
                if ($saved_tx_id !== $tx_hash) {
                    WC_Sersh_Payment::log(
                        'Warning: Transaction ID mismatch after saving. This could indicate a persistence issue.',
                        'error'
                    );
                }
            }

            // Verify the transaction
            $verifier = new Sersh_Transaction_Verifier();
            $result = $verifier->verify_transaction($tx_hash, $order_id);

            if ($result['success']) {
                if ($debug_mode) {
                    WC_Sersh_Payment::log(sprintf(
                        'Transaction verification successful - Order ID: %s, TX Hash: %s',
                        $order_id,
                        $tx_hash
                    ), 'debug');
                }
                wp_send_json_success($result['message']);
            } else {
                if ($debug_mode) {
                    WC_Sersh_Payment::log(sprintf(
                        'Transaction verification failed - Order ID: %s, TX Hash: %s, Message: %s',
                        $order_id,
                        $tx_hash,
                        $result['message']
                    ), 'error');
                }
                wp_send_json_error($result['message']);
            }

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

            if (!isset($_POST['user_address']) || !isset($_POST['amount'])) {
                throw new Exception(__('Missing required parameters', 'wc-sersh-payment'));
            }

            $user_address = sanitize_text_field($_POST['user_address']);
            $usd_amount = floatval($_POST['amount']);

            // Get gateway instance
            $gateway = new WC_Gateway_Sersh();
            

             // Get or create order ID
             $order_id = $this->get_or_create_order();
            
             if (!$order_id) {
                 throw new Exception(__('Could not create or retrieve order', 'wc-sersh-payment'));
             }

            // Getting user ID From Wordpress
            $user_id = get_current_user_id();

            if ('yes' === $gateway->get_option('debug')) {
                WC_Sersh_Payment::log(sprintf(
                    'Payment signature generated - USD: %f, User: %s, Order ID: %d',
                    $usd_amount,
                    $user_address,
                    $order_id
                ), 'debug');
            }

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
                if ('yes' === $gateway->get_option('debug')) {
                    WC_Sersh_Payment::log(sprintf(
                        'Payment signature generated - USD: %f, SERSH: %s, User: %s',
                        $usd_amount,
                        $token_amount,
                        $user_address
                    ), 'debug');
                }
                
                $result['data']['orderId'] = $order_id;
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
     * Get current order ID or create a new order
     *
     * @return int|null Order ID or null on failure
     */
    private function get_or_create_order() {
        // First check if there's a pending order in the session
        $order_id = WC()->session ? WC()->session->get('order_awaiting_payment') : null;
        
        if ($order_id) {
            return $order_id;
        }
        
        // If no pending order, check if WooCommerce checkout has created an order
        if (isset($_POST['order_id'])) {
            return intval($_POST['order_id']);
        }
        
        // If not, create a new order from the current cart
        if (!WC()->cart || WC()->cart->is_empty()) {
            return null;
        }
        
        // Create an order programmatically
        try {
            // Create the order using WC_Checkout
            $checkout = WC()->checkout();
            if (!$checkout) {
                return null;
            }
            
            // Get checkout fields
            $data = array(
                'billing_email' => WC()->session ? WC()->session->get('customer')['email'] : '',
                'payment_method' => 'sersh'
            );
            
            // Create order and get ID
            // Note: This process will be completed properly during the actual checkout
            $order_id = $checkout->create_order($data);
            
            // Set status to pending
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_status('pending', __('Order created for SERSH payment', 'wc-sersh-payment'));
                
                // Store order ID in session
                if (WC()->session) {
                    WC()->session->set('order_awaiting_payment', $order_id);
                }
                
                if ('yes' === (new WC_Gateway_Sersh())->get_option('debug')) {
                    WC_Sersh_Payment::log(sprintf(
                        'Created temporary order #%d for payment signature',
                        $order_id
                    ), 'debug');
                }
            }
            
            return $order_id;
        } catch (Exception $e) {
            WC_Sersh_Payment::log('Error creating order: ' . $e->getMessage(), 'error');
            return null;
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

            // Get order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'wc-sersh-payment'));
            }

            // Save wallet address to order meta
            $order->update_meta_data('_sersh_payer_wallet', $wallet_address);
            $order->add_order_note(sprintf(
                __('Payer wallet address (AJAX): %s', 'wc-sersh-payment'),
                $wallet_address
            ));
            $order->save();

            wp_send_json_success(__('Wallet address saved', 'wc-sersh-payment'));
        } catch (Exception $e) {
            WC_Sersh_Payment::log('AJAX error saving wallet address: ' . $e->getMessage(), 'error');
            wp_send_json_error($e->getMessage());
        }
    }
} 