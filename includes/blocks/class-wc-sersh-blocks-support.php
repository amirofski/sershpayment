<?php
/**
 * SERSH Payment Blocks Support
 *
 * @package WC_Sersh_Payment
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;

/**
 * SERSH Payments Blocks integration
 */
class WC_Sersh_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'sersh';

    /**
     * Gateway instance.
     *
     * @var WC_Gateway_Sersh
     */
    private $gateway;

    /**
     * Log to file
     *
     * @param string $message Log message
     * @param string $level   Log level
     */
    public static function log($message, $level = 'info') {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $context = array('source' => 'sersh-payment');

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        $logger->log($level, $message, $context);
    }

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        // Load gateway class if not already loaded
        if (!class_exists('WC_Gateway_Sersh')) {
            require_once WC_SERSH_PLUGIN_DIR . 'includes/class-wc-gateway-sersh.php';
        }
        $this->gateway = new WC_Gateway_Sersh();
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $asset_path = WC_SERSH_PLUGIN_DIR . 'build/index.asset.php';
        $version = WC_SERSH_VERSION;
        
        if (file_exists($asset_path)) {
            $asset = require $asset_path;
            $version = is_array($asset) && isset($asset['version']) 
                ? $asset['version'] 
                : $version;
            $dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : array();
        } else {
            $dependencies = array();
        }

        // Ensure required dependencies
        $dependencies = array_merge($dependencies, array(
            'wp-element',
            'wp-components',
            'wp-i18n',
            'wp-blocks',
            'wp-data',
            'wp-compose',
            'wc-blocks-registry',
            'wc-settings',
            'wc-blocks-checkout'
        ));

        // Register Web3 library first
        wp_register_script(
            'web3',
            'https://cdn.jsdelivr.net/npm/web3@1.9.0/dist/web3.min.js',
            array(),
            '1.9.0',
            true
        );

        // Register our blocks script
        wp_register_script(
            'wc-sersh-blocks',
            WC_SERSH_PLUGIN_URL . 'build/index.js',
            array_merge($dependencies, array('web3')),
            $version,
            true
        );

        // Localize the script
        wp_localize_script(
            'wc-sersh-blocks',
            'wcSershData',
            $this->get_payment_method_data()
        );

        return array('wc-sersh-blocks');
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        // Define payment contract ABI
        $payment_abi = json_encode([
            [
                'constant' => false,
                'inputs' => [
                    ['name' => 'userId', 'type' => 'string'],
                    ['name' => 'amount', 'type' => 'uint256'],
                    ['name' => 'nonce', 'type' => 'uint256'],
                    ['name' => 'expiry', 'type' => 'uint256'],
                    ['name' => 'signature', 'type' => 'bytes']
                ],
                'name' => 'paySubscription',
                'outputs' => [['name' => '', 'type' => 'bool']],
                'payable' => false,
                'stateMutability' => 'nonpayable',
                'type' => 'function'
            ]
        ]);

        return array(
            'title'           => $this->gateway->get_title(),
            'description'     => $this->gateway->get_description(),
            'supports'        => $this->get_supported_features(),
            'tokenAddress'    => $this->gateway->token_address,
            'paymentAddress'  => $this->gateway->payment_address,
            'merchantAddress' => $this->gateway->merchant_address,
            'testMode'        => $this->gateway->testmode,
            'icon'            => $this->gateway->icon,
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('wc-sersh-payment-nonce'),
            'chainId'         => $this->gateway->testmode ? '0x61' : '0x38',
            'rpcUrl'          => $this->gateway->testmode 
                ? 'https://bsc-testnet.nodereal.io/v1/351dc832166e47bbb76426ca5dc45189'
                : 'https://bsc-mainnet.nodereal.io/v1/351dc832166e47bbb76426ca5dc45189',
            'paymentAbi'      => $payment_abi,
            'i18n'           => array(
                'metamaskRequired' => __('MetaMask or a compatible Web3 wallet is required for SERSH payments.', 'wc-sersh-payment'),
                'connectWallet'    => __('Please connect your wallet to proceed.', 'wc-sersh-payment'),
                'wrongNetwork'     => __('Please switch to the correct network to proceed.', 'wc-sersh-payment'),
                'paymentError'     => __('Payment failed: ', 'wc-sersh-payment'),
                'processingPayment' => __('Processing payment...', 'wc-sersh-payment'),
                'waitingConfirmation' => __('Waiting for transaction confirmation...', 'wc-sersh-payment'),
            ),
            'paymentEndpoint' => WC_AJAX::get_endpoint('wc_sersh_process_payment'),
        );
    }

    /**
     * Returns an array of supported features.
     *
     * @return array
     */
    public function get_supported_features() {
        return array(
            'products',
            'refunds',
        );
    }

    /**
     * Process the payment for a cart/order.
     *
     * @param PaymentContext $context Payment context.
     * @param PaymentResult  $result  Payment result.
     */
    public function process_payment($context, $result) {
        try {

            //print the context
            WC_Sersh_Blocks_Support::log(
                'process_payment::Blocks checkout payment context',
                'debug'
            );
            // print the result
            WC_Sersh_Blocks_Support::log(
                'Blocks checkout payment result: ' . json_encode($result),
                'debug'
            );
            // Extract wallet address from payment data if available
            $payment_data = $context->payment_data;
            $wallet_address = null;
            $transaction_hash = null;
            
            // Debug payment data
            if ('yes' === $this->gateway->get_option('debug')) {
                WC_Sersh_Blocks_Support::log(
                    'Blocks checkout payment data: ' . json_encode($payment_data),
                    'debug'
                );
            }
            
            // Check if order_id is present in payment data and ensure it's a string
            if (!empty($payment_data['order_id']) && !is_string($payment_data['order_id'])) {
                $payment_data['order_id'] = (string)$payment_data['order_id'];
                if ('yes' === $this->gateway->get_option('debug')) {
                    WC_Sersh_Blocks_Support::log(
                        'Converting order_id to string: ' . $payment_data['order_id'],
                        'debug'
                    );
                }
            }
            
            if (!empty($payment_data['user_address'])) {
                $wallet_address = sanitize_text_field($payment_data['user_address']);
                
                if ('yes' === $this->gateway->get_option('debug')) {
                    WC_Sersh_Blocks_Support::log(
                        'Saving wallet address from blocks checkout: ' . $wallet_address . ' for order: ' . $context->order->get_id(),
                        'debug'
                    );
                }
                
                // Save wallet address to order meta
                $context->order->update_meta_data('_sersh_payer_wallet', $wallet_address);
                $context->order->add_order_note(sprintf(
                    __('Payer wallet address (Blocks checkout): %s', 'wc-sersh-payment'),
                    $wallet_address
                ));
            }
            
            // Extract transaction hash if available
            if (!empty($payment_data['transaction_hash'])) {
                $transaction_hash = sanitize_text_field($payment_data['transaction_hash']);
                
                if ('yes' === $this->gateway->get_option('debug')) {
                    WC_Sersh_Blocks_Support::log(
                        'Saving transaction hash from blocks checkout: ' . $transaction_hash . ' for order: ' . $context->order->get_id(),
                        'debug'
                    );
                }
                
                // Save transaction hash as the order's transaction ID
                $context->order->set_transaction_id($transaction_hash);
                $context->order->add_order_note(sprintf(
                    __('SERSH blockchain transaction hash: %s from %s', 'wc-sersh-payment'),
                    $transaction_hash,
                    $wallet_address
                ));
            } else if ('yes' === $this->gateway->get_option('debug')) {
                WC_Sersh_Blocks_Support::log(
                    'No transaction hash available in blocks checkout payment data for order: ' . $context->order->get_id(),
                    'debug'
                );
            }
            
            if ($wallet_address || $transaction_hash) {
                $context->order->save();
                
                // Verify data was saved correctly
                if ($transaction_hash && 'yes' === $this->gateway->get_option('debug')) {
                    $saved_tx_id = $context->order->get_transaction_id();
                    WC_Sersh_Blocks_Support::log(
                        'Transaction ID after save: Expected: ' . $transaction_hash . ', Actual: ' . $saved_tx_id,
                        $saved_tx_id === $transaction_hash ? 'debug' : 'error'
                    );
                }
            }
            
            $payment_result = $this->gateway->process_payment($context->order->get_id());

            if (!empty($payment_result['result']) && 'success' === $payment_result['result']) {
                if ('yes' === $this->gateway->get_option('debug')) {
                    WC_Sersh_Blocks_Support::log(
                        'Blocks checkout payment successful for order: ' . $context->order->get_id(),
                        'debug'
                    );
                }
                
                // After successful payment, check transaction ID again
                if ($transaction_hash && 'yes' === $this->gateway->get_option('debug')) {
                    $final_tx_id = $context->order->get_transaction_id();
                    WC_Sersh_Blocks_Support::log(
                        'Final transaction ID check: Expected: ' . $transaction_hash . ', Actual: ' . $final_tx_id,
                        $final_tx_id === $transaction_hash ? 'debug' : 'error'
                    );
                    
                    // If transaction ID was changed, try to restore it
                    if ($final_tx_id !== $transaction_hash) {
                        $context->order->set_transaction_id($transaction_hash);
                        $context->order->save();
                        WC_Sersh_Blocks_Support::log(
                            'Restored transaction ID after payment_result. New value: ' . $context->order->get_transaction_id(),
                            'debug'
                        );
                    }
                }
                
                $result->set_status('success');
                $result->set_redirect_url($payment_result['redirect']);
            } else {
                if ('yes' === $this->gateway->get_option('debug')) {
                    WC_Sersh_Blocks_Support::log(
                        'Blocks checkout payment failed for order: ' . $context->order->get_id(),
                        'error'
                    );
                }
                
                $result->set_status('error');
                $result->set_error_message(
                    isset($payment_result['messages']) 
                        ? $payment_result['messages'] 
                        : __('Payment processing failed. Please try again.', 'wc-sersh-payment')
                );
            }
        } catch (Exception $e) {
            if ('yes' === $this->gateway->get_option('debug')) {
                WC_Sersh_Blocks_Support::log(
                    'Blocks checkout exception: ' . $e->getMessage() . ' for order: ' . $context->order->get_id(),
                    'error'
                );
            }
            
            $result->set_status('error');
            $result->set_error_message($e->getMessage());
        }
    }

    /**
     * Process refund for an order.
     *
     * @param PaymentContext $context Refund context.
     * @param PaymentResult  $result  Refund result.
     */
    public function process_refund($context, $result) {
        try {
            $refund_result = $this->gateway->process_refund(
                $context->order->get_id(),
                $context->amount,
                $context->refund->get_reason()
            );

            if (is_wp_error($refund_result)) {
                $result->set_status('error');
                $result->set_error_message($refund_result->get_error_message());
            } else {
                $result->set_status('success');
            }
        } catch (Exception $e) {
            $result->set_status('error');
            $result->set_error_message($e->getMessage());
        }
    }
} 