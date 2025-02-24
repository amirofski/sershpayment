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
                ? 'https://data-seed-prebsc-1-s1.binance.org:8545'
                : 'https://bsc-dataseed.binance.org/',
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
            $payment_result = $this->gateway->process_payment($context->order->get_id());

            if (!empty($payment_result['result']) && 'success' === $payment_result['result']) {
                $result->set_status('success');
                $result->set_redirect_url($payment_result['redirect']);
            } else {
                $result->set_status('error');
                $result->set_error_message(
                    isset($payment_result['messages']) 
                        ? $payment_result['messages'] 
                        : __('Payment processing failed. Please try again.', 'wc-sersh-payment')
                );
            }
        } catch (Exception $e) {
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