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
        
        // Add block-specific SERSH price display hooks
        add_action('wp_loaded', array($this, 'add_blocks_price_display_hooks'));
    }
    
    /**
     * Add price display hooks for blocks context
     */
    public function add_blocks_price_display_hooks() {
        // Skip if gateway is not available
        if (!$this->gateway->is_available()) {
            return;
        }

        // Add CSS for SERSH prices
        add_action('wp_footer', array($this, 'add_price_display_css'));
        
        // Add filter for totals in blocks context
        add_filter('woocommerce_blocks_formatted_price', array($this, 'add_sersh_price_to_blocks_price'), 10, 2);
    }
    
    /**
     * Add SERSH price to blocks formatted price
     *
     * @param string $formatted_price Formatted price
     * @param float $price Raw price
     * @return string Price with SERSH equivalent
     */
    public function add_sersh_price_to_blocks_price($formatted_price, $price) {
        // Get the WooCommerce decimal settings
        $wc_price_decimals = wc_get_price_decimals();
        
        // Detect if the price might be scaled based on decimal settings
        // When WC decimals is set to 0, prices may be internally multiplied by 100
        if ($wc_price_decimals === 0) {
            // Unscale the price to get the true value
            $unscaled_price = $price / 100;
            
            // Log the price adjustment for debugging
            self::log(sprintf(
                'Price decimal adjustment: %f (scaled) → %f (unscaled)',
                $price,
                $unscaled_price
            ), 'debug');
            
            // Use the unscaled price for the conversion
            $sersh_price = $this->gateway->convert_usd_to_sersh_tokens($unscaled_price);
        } else {
            // Use the price as is when decimals are set to standard value (2)
            $sersh_price = $this->gateway->convert_usd_to_sersh_tokens($price);
        }
        
        // Format the SERSH price with 8 decimal places for accuracy
        $formatted_sersh = number_format($sersh_price, 8, '.', ',');
        
        // Add SERSH price as a small element
        return $formatted_price . ' <small class="sersh-price">(' . __('≈', 'wc-sersh-payment') . ' ' . $formatted_sersh . ' SERSH)</small>';
    }
    
    /**
     * Add CSS for price display
     */
    public function add_price_display_css() {
        ?>
        <style type="text/css">
            .sersh-price {
                display: inline-block;
                font-size: 0.85em;
                opacity: 0.8;
                margin-left: 5px;
                color: #666;
            }
            /* Blocks specific styling */
            .wc-block-components-totals-item__value .sersh-price,
            .wc-block-components-price-slider__range-text .sersh-price,
            .wc-block-components-product-price .sersh-price,
            .wc-block-grid__product-price .sersh-price {
                display: block;
                margin-top: 4px;
                font-weight: normal;
            }
            .wc-block-cart-item__total .sersh-price {
                display: block;
                margin-top: 4px;
            }
            .wc-block-components-order-summary-item__total-price .sersh-price {
                display: block;
                text-align: right;
                margin-top: 2px;
            }
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Get WooCommerce price decimals setting
            const wcPriceDecimals = <?php echo wc_get_price_decimals(); ?>;
            
            // Function to convert USD to SERSH
            // This uses the same formula as in PHP: SERSH amount = USD amount / token price
            function convertUsdToSersh(usdAmount) {
                // Get the token price from settings
                const tokenPrice = parseFloat(<?php echo $this->gateway->get_current_token_price(); ?>);
                
                // Standard conversion formula used for ALL price displays:
                // SERSH amount = USD amount / token price
                // This formula is consistent with the PHP implementation
                return usdAmount / tokenPrice;
            }

            // When the actual payment amount is sent to MetaMask, it will be divided
            // by 100 in the backend to match what we display. This ensures what the
            // user sees is what they'll actually pay in SERSH.
            
            // Function to format the SERSH price
            function formatSershPrice(sershAmount) {
                // Format with 8 decimal places for more accuracy
                return sershAmount.toFixed(8);
            }

            // Function to extract raw price from formatted price string
            function extractPrice(priceText) {
                // Remove currency symbols and non-numeric characters except for decimal point/comma
                let cleanedPrice = priceText.replace(/[^\d.,]/g, '');
                
                // Replace comma with decimal point if needed
                cleanedPrice = cleanedPrice.replace(/,/g, '.');
                
                // If multiple decimal points, keep only the first one
                const parts = cleanedPrice.split('.');
                if (parts.length > 2) {
                    cleanedPrice = parts[0] + '.' + parts.slice(1).join('');
                }
                
                let price = parseFloat(cleanedPrice);
                
                // Handle price scaling based on WooCommerce decimal settings
                if (wcPriceDecimals === 0 && price > 0) {
                    // When decimals is 0, WooCommerce might be scaling prices by 100 internally
                    price = price / 100;
                    console.log('Unscaling price due to decimal settings:', price);
                }
                
                return price;
            }

            // Function to add SERSH price to an element
            function addSershPrice(element, priceText) {
                // Skip if already has SERSH price
                if (element.find('.sersh-price').length > 0) {
                    return;
                }
                
                const price = extractPrice(priceText);
                
                if (!isNaN(price) && price > 0) {
                    const sershAmount = convertUsdToSersh(price);
                    const formattedPrice = formatSershPrice(sershAmount);
                    
                    element.append(
                        $('<small class="sersh-price">').text(
                            '(≈ ' + formattedPrice + ' SERSH)'
                        )
                    );
                }
            }

            // Function to update all prices in all contexts
            function updateBlockPrices() {
                // Update all price elements in blocks context
                [
                    '.wc-block-components-product-price__value',
                    '.wc-block-grid__product-price',
                    '.wc-block-components-totals-item__value',
                    '.wc-block-components-order-summary-item__total-price',
                    '.price'
                ].forEach(selector => {
                    $(selector).each(function() {
                        if ($(this).find('.sersh-price').length > 0) {
                            return; // Skip if already processed
                        }
                        
                        // Get the price text directly from the element
                        const priceText = $(this).text();
                        addSershPrice($(this), priceText);
                    });
                });
            }

            // Run initially
            updateBlockPrices();
            
            // Set up observer for dynamic content changes
            const observer = new MutationObserver(function(mutations) {
                updateBlockPrices();
            });
            
            // Start observing the entire document body for changes
            const bodyEl = document.body;
            if (bodyEl) {
                observer.observe(bodyEl, {
                    subtree: true,
                    childList: true
                });
            }
        });
        </script>
        <?php
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