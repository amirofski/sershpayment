<?php
/**
 * SERSH Transaction Verifier
 *
 * @package WC_Sersh_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sersh_Transaction_Verifier Class
 */
class Sersh_Transaction_Verifier {
    /**
     * Web3 provider URL
     *
     * @var string
     */
    private $web3_provider;

    /**
     * Constructor
     */
    public function __construct() {
        $gateway = new WC_Gateway_Sersh();
        $this->web3_provider = $gateway->testmode ? 
            'https://bsc-testnet.nodereal.io/v1/351dc832166e47bbb76426ca5dc45189' : 
            'https://bsc-mainnet.nodereal.io/v1/351dc832166e47bbb76426ca5dc45189';
    }

    /**
     * Verify a transaction
     *
     * @param string $tx_hash Transaction hash
     * @param int    $order_id WooCommerce order ID
     * @return array
     */
    public function verify_transaction($tx_hash, $order_id) {
        try {
            // Add debug log for transaction verification start
            WC_Sersh_Payment::log(sprintf(
                'Starting transaction verification for hash %s and order %s',
                $tx_hash,
                $order_id
            ), 'debug');

            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc-sersh-payment-nonce')) {
                throw new Exception(__('Invalid security token', 'wc-sersh-payment'));
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'wc-sersh-payment'));
            }

            // Log the request to the node
            WC_Sersh_Payment::log(sprintf(
                'Calling eth_getTransactionReceipt on %s for tx %s',
                $this->web3_provider,
                $tx_hash
            ), 'debug');

            // Get transaction receipt
            $response = wp_remote_post($this->web3_provider, array(
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode(array(
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getTransactionReceipt',
                    'params' => array($tx_hash),
                    'id' => 1
                )),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                WC_Sersh_Payment::log('RPC Error: ' . $response->get_error_message(), 'error');
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            // Log the response for debugging
            WC_Sersh_Payment::log('RPC Response: ' . wp_json_encode($body), 'debug');
            
            if (!isset($body['result'])) {
                // Check if there's an error message in the response
                $error_msg = isset($body['error']) ? json_encode($body['error']) : 'Unknown error';
                WC_Sersh_Payment::log('Invalid receipt received: ' . $error_msg, 'error');
                throw new Exception(__('Invalid transaction receipt: ' . $error_msg, 'wc-sersh-payment'));
            }

            $receipt = $body['result'];
            
            // Check if transaction was successful
            if ($receipt['status'] !== '0x1') {
                throw new Exception(__('Transaction failed on the blockchain', 'wc-sersh-payment'));
            }

            // Verify payment contract
            $gateway = new WC_Gateway_Sersh();
            if (strtolower($receipt['to']) !== strtolower($gateway->payment_address)) {
                WC_Sersh_Payment::log('Invalid payment contract: ' . $receipt['to'], 'error');
                throw new Exception(__('Invalid payment contract address', 'wc-sersh-payment'));
            }

            // Get transaction input data
            $tx_data = $this->get_transaction_data($tx_hash);
            if (!$tx_data) {
                throw new Exception(__('Could not retrieve transaction data', 'wc-sersh-payment'));
            }

            // Verify token transfer from logs
            $transfer_data = $this->verify_token_transfer($receipt['logs'], $gateway->token_address, $gateway->merchant_address);
            if (!$transfer_data['success']) {
                throw new Exception($transfer_data['message']);
            }

            // TODO: Uncomment this when we have a price feed and discount is applied
            // // Verify payment amount
            // $expected_value = $this->convert_fiat_to_tokens($order->get_total());
            // $actual_value = $transfer_data['amount'];
            
            // if ($actual_value < $expected_value) {
            //     WC_Sersh_Payment::log(sprintf('Insufficient payment: expected %f, got %f', $expected_value, $actual_value), 'error');
            //     throw new Exception(__('Insufficient payment amount', 'wc-sersh-payment'));
            // }

            $this->log_verification_success($tx_hash, $order_id);
            return array(
                'success' => true,
                'message' => __('Transaction verified successfully', 'wc-sersh-payment')
            );

        } catch (Exception $e) {
            $this->log_verification_error($tx_hash, $order_id, $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Get transaction data
     *
     * @param string $tx_hash Transaction hash
     * @return array|false
     */
    private function get_transaction_data($tx_hash) {
        $response = wp_remote_post($this->web3_provider, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'jsonrpc' => '2.0',
                'method' => 'eth_getTransactionByHash',
                'params' => array($tx_hash),
                'id' => 1
            )),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['result']) ? $body['result'] : false;
    }

    /**
     * Verify token transfer from transaction logs
     *
     * @param array  $logs Transaction logs
     * @param string $token_address Token contract address
     * @param string $merchant_address Merchant wallet address
     * @return array
     */
    private function verify_token_transfer($logs, $token_address, $merchant_address) {
        $transfer_event_signature = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        
        foreach ($logs as $log) {
            if (strtolower($log['address']) === strtolower($token_address)) {
                // Check if this is a transfer event
                if ($log['topics'][0] === $transfer_event_signature) {
                    // Get the recipient address from the topics
                    $recipient = '0x' . substr($log['topics'][2], 26);
                    if (strtolower($recipient) === strtolower($merchant_address)) {
                        // Get the transfer amount
                        $amount = $this->decode_transaction_value($log['data']);
                        return array(
                            'success' => true,
                            'amount' => $amount
                        );
                    }
                }
            }
        }
        
        return array(
            'success' => false,
            'message' => __('No valid token transfer found', 'wc-sersh-payment')
        );
    }

    /**
     * Convert fiat amount to token amount using price feed
     *
     * @param float $fiat_amount Amount in fiat currency
     * @return float
     */
    private function convert_fiat_to_tokens($fiat_amount) {
        $gateway = new WC_Gateway_Sersh();
        $price_feed_url = $gateway->get_option('price_feed_url');
        
        if (empty($price_feed_url)) {
            WC_Sersh_Payment::log('No price feed URL configured, using 1:1 conversion', 'warning');
            return $fiat_amount;
        }

        try {
            $response = wp_remote_get($price_feed_url, array(
                'timeout' => 15,
                'headers' => array(
                    'Accept' => 'application/json'
                )
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $price_data = json_decode(wp_remote_retrieve_body($response), true);
            
            // Validate the response structure
            if (!isset($price_data['quotes']) || 
                !is_array($price_data['quotes']) || 
                empty($price_data['quotes']) ||
                !isset($price_data['quotes'][0]['price'])) {
                throw new Exception('Invalid price feed response format');
            }

            // Get the USD price from the quotes array
            $token_price = floatval($price_data['quotes'][0]['price']);
            
            if ($token_price <= 0) {
                throw new Exception('Invalid token price: ' . $token_price);
            }

            // Calculate tokens amount (fiat amount divided by token price)
            $tokens_amount = $fiat_amount / $token_price;

            WC_Sersh_Payment::log(sprintf(
                'Price conversion: %f USD = %f SERSH (price: %f USD/SERSH)',
                $fiat_amount,
                $tokens_amount,
                $token_price
            ), 'info');

            return $tokens_amount;

        } catch (Exception $e) {
            WC_Sersh_Payment::log('Price conversion failed: ' . $e->getMessage(), 'error');
            throw new Exception(
                sprintf(
                    __('Unable to convert price: %s', 'wc-sersh-payment'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Decode transaction value from hex
     *
     * @param string $hex_value Hex value from transaction
     * @return float
     */
    private function decode_transaction_value($hex_value) {
        $gateway = new WC_Gateway_Sersh();
        $decimals = intval($gateway->get_option('token_decimals', 18));
        return hexdec(substr($hex_value, 2)) / pow(10, $decimals);
    }

    /**
     * Log verification success
     *
     * @param string $tx_hash Transaction hash
     * @param int    $order_id Order ID
     */
    private function log_verification_success($tx_hash, $order_id) {
        WC_Sersh_Payment::log(sprintf(
            'Transaction %s verified successfully for order %s',
            $tx_hash,
            $order_id
        ), 'info');
    }

    /**
     * Log verification error
     *
     * @param string $tx_hash Transaction hash
     * @param int    $order_id Order ID
     * @param string $error_message Error message
     */
    private function log_verification_error($tx_hash, $order_id, $error_message) {
        WC_Sersh_Payment::log(sprintf(
            'Transaction %s verification failed for order %s: %s',
            $tx_hash,
            $order_id,
            $error_message
        ), 'error');
    }
} 