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

            // Verify the transaction
            $verifier = new Sersh_Transaction_Verifier();
            $result = $verifier->verify_transaction($tx_hash, $order_id);

            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['message']);
            }

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Get payment signature
     */
    public function get_payment_signature() {
        try {
            // if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc-sersh-payment-nonce')) {
            //     throw new Exception(__('Invalid security token', 'wc-sersh-payment'));
            // }

            if (!isset($_POST['user_address']) || !isset($_POST['amount'])) {
                throw new Exception(__('Missing required parameters', 'wc-sersh-payment'));
            }

            $user_address = sanitize_text_field($_POST['user_address']);
            $usd_amount = floatval($_POST['amount']);

            // Get gateway instance
            $gateway = new WC_Gateway_Sersh();
            
            // Convert USD amount to SERSH tokens with live price
            try {
                $token_amount = $gateway->convert_usd_to_tokens($usd_amount);
                
                // Convert to Wei (multiply by 10^18)
                $decimals = 18; // Standard ERC20 decimals
                $amount_in_wei = bcmul(
                    str_replace('.', '', number_format($token_amount, $decimals, '.', '')),
                    '1',
                    0
                );

                // Convert large number to hex string without using dechex
                $hex = '';
                $num = $amount_in_wei;
                while(bccomp($num, '0') > 0) {
                    $mod = bcmod($num, '16');
                    $hex = dechex(intval($mod)) . $hex;
                    $num = bcdiv($num, '16', 0);
                }
                
                $amount_hex = '0x' . $hex;

                WC_Sersh_Payment::log(sprintf(
                    'Token amount in Wei: %s (hex: %s) (original: %f)',
                    $amount_in_wei,
                    $amount_hex,
                    $token_amount
                ), 'debug');

                $token_amount = $amount_hex;

            } catch (Exception $e) {
                WC_Sersh_Payment::log('Price conversion failed: ' . $e->getMessage(), 'error');
                throw new Exception(__('Failed to get current SERSH token price. Please try again later.', 'wc-sersh-payment'));
            }

            // Generate unique user ID using a secure method
            $user_id = wp_generate_password(32, false);

            // Set expiry to 1 hour from now
            $expiry = time() + 3600;

            // Generate nonce using a cryptographically secure method
            $nonce = "0x" . bin2hex(random_bytes(16));

            // Get signature
            require_once WC_SERSH_PLUGIN_DIR . 'includes/class-sersh-payment-signer.php';
            $signer = new Sersh_Payment_Signer();
            $result = $signer->generate_payment_signature($user_id, $token_amount, $nonce, $expiry, $user_address);

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
} 