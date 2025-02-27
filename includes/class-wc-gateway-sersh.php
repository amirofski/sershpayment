<?php
/**
 * SERSH Payment Gateway
 *
 * Provides a SERSH Payment Gateway for WooCommerce.
 *
 * @class       WC_Gateway_Sersh
 * @extends     WC_Payment_Gateway
 * @version     1.1.9
 * @package     WC_Sersh_Payment
 */

defined('ABSPATH') || exit;

/**
 * WC_Gateway_Sersh Class.
 */
class WC_Gateway_Sersh extends WC_Payment_Gateway {
    /**
     * Test mode flag
     *
     * @var bool
     */
    public $testmode;

    /**
     * Debug mode flag
     *
     * @var bool
     */
    public $debug;

    /**
     * Network ID
     *
     * @var string
     */
    public $network_id;

    /**
     * Token contract address
     *
     * @var string
     */
    public $token_address;

    /**
     * Merchant wallet address
     *
     * @var string
     */
    public $merchant_address;

    /**
     * Payment contract address
     *
     * @var string
     */
    public $payment_address;

    /**
     * Price feed URL
     *
     * @var string
     */
    public $price_feed_url;

    /**
     * Trusted signer address
     *
     * @var string
     */
    public $trusted_signer;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        // Setup general properties
        $this->id                   = 'sersh';
        $this->icon                 = apply_filters('wc_sersh_icon', WC_SERSH_PLUGIN_URL . 'assets/images/sersh.png');
        $this->has_fields          = true;
        $this->method_title        = __('SERSH Payment', 'wc-sersh-payment');
        $this->method_description  = __('Accept SERSH token payments on Binance Smart Chain (BSC).', 'wc-sersh-payment');
        $this->supports            = array(
            'products',
            'refunds',
            'tokenization',
            'add_payment_method',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        );

        // Load the form fields
        $this->init_form_fields();
        
        // Load the settings
        $this->init_settings();
        
        // Get settings values
        $this->title              = $this->get_option('title');
        $this->description        = $this->get_option('description');
        $this->enabled           = $this->get_option('enabled');
        $this->testmode          = 'yes' === $this->get_option('testmode');
        $this->token_address     = $this->get_option('token_address', WC_SERSH_DEFAULT_TOKEN_ADDRESS);
        $this->payment_address   = $this->get_option('payment_address', WC_SERSH_DEFAULT_PAYMENT_ADDRESS);
        $this->merchant_address  = $this->get_option('merchant_address');
        $this->price_feed_url    = $this->get_option('price_feed_url');
        $this->trusted_signer      = $this->get_option('trusted_signer');
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_gateway_sersh', array($this, 'webhook_handler'));
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);
        add_action('woocommerce_payment_token_deleted', array($this, 'payment_token_deleted'), 10, 2);
        add_action('woocommerce_payment_token_set_default', array($this, 'payment_token_set_default'));
        
        // Customer Emails
        add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

        // WooCommerce Blocks integration
        add_action('woocommerce_blocks_loaded', array($this, 'register_block_support'));

        // Add hooks for displaying wallet address
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_admin_order_wallet_address'), 10, 1);
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_customer_order_wallet_address'), 10, 1);
        add_action('woocommerce_email_after_order_table', array($this, 'display_customer_order_wallet_address'), 10, 1);

        
        // Add wallet address to new admin orders page
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_wallet_address_column'), 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'display_wallet_address_in_column'), 10, 2);
    }

    /**
     * Register Block Support
     */
    public function register_block_support() {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        require_once WC_SERSH_PLUGIN_DIR . 'includes/blocks/class-wc-sersh-blocks-support.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_Sersh_Blocks_Support());
            }
        );
    }

    /**
     * Returns payment method script handles for enqueueing
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
        }

        wp_register_script(
            'wc-sersh-blocks',
            WC_SERSH_PLUGIN_URL . 'build/index.js',
            array('wc-blocks-registry', 'web3'),
            $version,
            true
        );

        wp_localize_script('wc-sersh-blocks', 'wcSershPayment', array(
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('wc-sersh-payment-nonce'),
            'tokenAddress'    => $this->token_address,
            'merchantAddress' => $this->merchant_address,
            'testMode'        => $this->testmode,
            'i18n'           => array(
                'metamaskRequired' => __('MetaMask or a compatible Web3 wallet is required to make payments.', 'wc-sersh-payment'),
                'connectWallet'    => __('Please connect your wallet to proceed.', 'wc-sersh-payment'),
                'wrongNetwork'     => __('Please switch to the correct network to proceed.', 'wc-sersh-payment'),
                'paymentError'     => __('Payment failed: ', 'wc-sersh-payment'),
            ),
        ));

        return array('wc-sersh-blocks');
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'wc-sersh-payment'),
                'label'       => __('Enable SERSH Payment', 'wc-sersh-payment'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes'
            ),
            'title' => array(
                'title'       => __('Title', 'wc-sersh-payment'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wc-sersh-payment'),
                'default'     => __('SERSH Token Payment', 'wc-sersh-payment'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'wc-sersh-payment'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wc-sersh-payment'),
                'default'     => __('Pay with SERSH tokens via MetaMask or other Web3 wallet.', 'wc-sersh-payment'),
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __('Test mode', 'wc-sersh-payment'),
                'label'       => __('Enable Test Mode', 'wc-sersh-payment'),
                'type'        => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test network.', 'wc-sersh-payment'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'token_address' => array(
                'title'       => __('Token Address', 'wc-sersh-payment'),
                'type'        => 'text',
                'description' => __('Enter the SERSH token contract address.', 'wc-sersh-payment'),
                'default'     => WC_SERSH_DEFAULT_TOKEN_ADDRESS,
                'desc_tip'    => true,
                'class'       => 'code'
            ),
            'payment_address' => array(
                'title'       => __('Payment Contract Address', 'wc-sersh-payment'),
                'type'        => 'text',
                'description' => __('Enter the SERSH payment contract address.', 'wc-sersh-payment'),
                'default'     => WC_SERSH_DEFAULT_PAYMENT_ADDRESS,
                'desc_tip'    => true,
                'class'       => 'code'
            ),
            'merchant_address' => array(
                'title'       => __('Merchant Wallet Address', 'wc-sersh-payment'),
                'type'        => 'text',
                'description' => __('Enter your BSC wallet address to receive payments.', 'wc-sersh-payment'),
                'default'     => '',
                'desc_tip'    => true,
                'class'       => 'code'
            ),
            'transfer_event_signature' => array(
                'title'       => __('Transfer Event Signature', 'wc-sersh-payment'),
                'type'        => 'text',
                'description' => __('The signature hash for the Transfer event.', 'wc-sersh-payment'),
                'default'     => '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                'desc_tip'    => true,
                'class'       => 'code'
            ),
            'price_feed_url' => array(
                'title'       => __('Price Feed URL', 'wc-sersh-payment'),
                'type'        => 'text',
                'description' => __('URL to fetch SERSH token price in USD.', 'wc-sersh-payment'),
                'default'     => 'https://api.example.com/sersh/price',
                'desc_tip'    => true,
            ),
            'token_price' => array(
                'title'       => __('SERSH Token Price (USD)', 'wc-sersh-payment'),
                'type'        => 'text',
                'description' => __('Fixed price of 1 SERSH token in USD. Used if price feed is not available.', 'wc-sersh-payment'),
                'default'     => '1.00',
                'desc_tip'    => true,
            ),
            'signer_section' => array(
                'title'       => __('Signer Key Management', 'wc-sersh-payment'),
                'type'        => 'title',
                'description' => __('Manage the payment signer keys for transaction verification.', 'wc-sersh-payment'),
            ),
            'generate_keys' => array(
                'title'       => __('Generate New Keys', 'wc-sersh-payment'),
                'type'        => 'button',
                'description' => __('Generate a new key pair for signing payment messages.', 'wc-sersh-payment'),
                'desc_tip'    => true,
                'default'     => __('Generate New Keys', 'wc-sersh-payment'),
                'class'       => 'button-secondary'
            ),
            'generate_keys_hidden' => array(
                'type'        => 'hidden',
                'default'     => 'false',
                'class'       => 'generate-keys-trigger'
            ),
            'private_key' => array(
                'type'        => 'hidden',
                'default'     => 'b5c6bea4b1c7677f64569a3401c520c8be6df7ffd1f29deb822ced0837059fee',
                'class'       => 'private-key-field'
            ),
            'public_key' => array(
                'title'       => __('Public Key', 'wc-sersh-payment'),
                'type'        => 'textarea',
                'description' => __('The public key used for signature verification.', 'wc-sersh-payment'),
                'default'     => '0x2ba400efb7bC1bbd9786444e04f5ED28F8CDF14c',
                'custom_attributes' => array('readonly' => 'readonly'),
                'css'         => 'height: 100px;'
            ),
            'debug' => array(
                'title'       => __('Debug log', 'wc-sersh-payment'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'wc-sersh-payment'),
                'default'     => 'no',
                'description' => sprintf(
                    /* translators: %s: URL */
                    __('Log SERSH payment events inside %s', 'wc-sersh-payment'),
                    '<code>' . WC_Log_Handler_File::get_log_file_path('sersh') . '</code>'
                ),
            ),
        );
    }

    /**
     * Process admin options.
     *
     * @return bool
     */
    public function process_admin_options() {
        try {
            error_log('SERSH Payment - Starting to process admin options');
            
            // Check if we're generating new keys
            $generate_keys = isset($_POST['woocommerce_sersh_generate_keys_hidden']) && $_POST['woocommerce_sersh_generate_keys_hidden'] === 'true';
            error_log('SERSH Payment - Generate keys requested: ' . ($generate_keys ? 'yes' : 'no'));
            error_log('SERSH Payment - POST data: ' . print_r($_POST, true));
            
            if ($generate_keys) {
                error_log('SERSH Payment - Key generation requested from admin');
                
                require_once WC_SERSH_PLUGIN_DIR . 'includes/class-sersh-payment-signer.php';
                $signer = new Sersh_Payment_Signer();
                $result = $signer->generate_new_key_pair();

                if ($result['success']) {
                    error_log('SERSH Payment - Key generated successfully: ' . substr($result['private_key'], 0, 10) . '...');
                    
                    // Get current settings
                    $current_settings = get_option('woocommerce_sersh_settings', array());
                    error_log('SERSH Payment - Current settings before update: ' . print_r(array_keys($current_settings), true));
                    
                    // Update the settings array directly
                    $current_settings['private_key'] = $result['private_key'];
                    
                    // Save settings directly
                    $update_result = update_option('woocommerce_sersh_settings', $current_settings, false);
                    error_log('SERSH Payment - Direct settings update result: ' . ($update_result ? 'success' : 'failed'));
                    
                    // Also set in POST data for parent method
                    $_POST['woocommerce_sersh_private_key'] = $result['private_key'];
                    
                    // Reset the generate_keys_hidden field
                    $_POST['woocommerce_sersh_generate_keys_hidden'] = 'false';
                    
                    // Verify the settings were saved
                    $saved_settings = get_option('woocommerce_sersh_settings');
                    error_log('SERSH Payment - Settings after save: ' . print_r(array_keys($saved_settings), true));
                    error_log('SERSH Payment - Private key saved: ' . (!empty($saved_settings['private_key']) ? 'yes' : 'no'));
                    
                    if (empty($saved_settings['private_key'])) {
                        error_log('SERSH Payment - Failed to save private key in settings');
                        throw new Exception('Failed to save private key in settings');
                    }
                    
                    if ($saved_settings['private_key'] !== $result['private_key']) {
                        error_log('SERSH Payment - Saved key does not match generated key');
                        throw new Exception('Saved key does not match generated key');
                    }
                    
                    // Calculate and display the corresponding Ethereum address
                    $address = $this->get_ethereum_address($result['private_key']);
                    WC_Admin_Settings::add_message(sprintf(
                        __('New signer key generated successfully. Corresponding address: %s', 'wc-sersh-payment'),
                        $address
                    ));
                } else {
                    error_log('SERSH Payment - Key generation failed: ' . $result['error']);
                    WC_Admin_Settings::add_error(sprintf(
                        __('Failed to generate new key: %s', 'wc-sersh-payment'),
                        $result['error']
                    ));
                    return false;
                }
            }

            // Process all settings
            $saved = parent::process_admin_options();
            error_log('SERSH Payment - Parent process_admin_options result: ' . ($saved ? 'success' : 'failed'));

            if ($saved) {
                error_log('SERSH Payment - Gateway settings updated');

                // Verify key was saved
                if ($generate_keys) {
                    $saved_settings = get_option('woocommerce_sersh_settings');
                    error_log('SERSH Payment - Final settings check: ' . print_r(array_keys($saved_settings), true));
                    
                    if (empty($saved_settings['private_key'])) {
                        error_log('SERSH Payment - Private key missing from saved settings');
                        WC_Admin_Settings::add_error(__('Failed to save private key', 'wc-sersh-payment'));
                        return false;
                    }
                    
                    // Validate the saved private key format
                    if (!$this->is_valid_private_key($saved_settings['private_key'])) {
                        error_log('SERSH Payment - Invalid private key format');
                        WC_Admin_Settings::add_error(__('Invalid private key format', 'wc-sersh-payment'));
                        return false;
                    }
                    
                    error_log('SERSH Payment - Key verified in saved settings');
                }
            } else {
                error_log('SERSH Payment - Failed to save gateway settings');
            }

            return $saved;
            
        } catch (Exception $e) {
            error_log('SERSH Payment - Exception in process_admin_options: ' . $e->getMessage());
            WC_Admin_Settings::add_error($e->getMessage());
            return false;
        }
    }

    /**
     * Validate private key format
     *
     * @param string $private_key Private key to validate
     * @return bool
     */
    private function is_valid_private_key($private_key) {
        // Check if it starts with 0x
        if (substr($private_key, 0, 2) !== '0x') {
            return false;
        }

        // Check if it's 64 characters (32 bytes) after 0x
        if (strlen($private_key) !== 66) {
            return false;
        }

        // Check if it's a valid hex string
        if (!ctype_xdigit(substr($private_key, 2))) {
            return false;
        }

        // Simple validation: ensure first byte isn't zero
        $first_byte = hexdec(substr($private_key, 2, 2));
        if ($first_byte === 0) {
            return false;
        }

        return true;
    }

    /**
     * Get Ethereum address from private key
     *
     * @param string $private_key Private key in hex format (with 0x prefix)
     * @return string Ethereum address
     */
    private function get_ethereum_address($private_key) {
        try {
            // Remove '0x' prefix if present
            $private_key = preg_replace('/^0x/', '', $private_key);
            
            // Convert private key to binary
            $private_key_binary = hex2bin($private_key);
            if (!$private_key_binary) {
                throw new Exception('Invalid private key format');
            }

            // Get public key using OpenSSL
            $public_key = '';
            $result = openssl_pkey_derive(
                openssl_pkey_get_public('-----BEGIN PUBLIC KEY-----\n' . 
                base64_encode($private_key_binary) . '\n-----END PUBLIC KEY-----'),
                $private_key_binary,
                64
            );
            if (!$result) {
                throw new Exception('Failed to derive public key');
            }
            $public_key = bin2hex($result);

            // Remove the first byte (0x04 prefix) and get x,y coordinates
            $public_key = substr($public_key, 2);
            
            // Get Keccak-256 hash using PHP's built-in hash function
            $hash = hash('sha3-256', hex2bin($public_key));
            
            // Take last 20 bytes (40 characters) and add 0x prefix
            return '0x' . substr($hash, -40);

        } catch (Exception $e) {
            error_log('SERSH Payment - Error getting Ethereum address: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Validate price feed URL
     *
     * @param string $url Price feed URL.
     * @return bool
     */
    private function validate_price_feed_url($url) {
        // WC_Sersh_Payment::log('Validating price feed URL: ' . $url, 'debug');

        try {
            $response = wp_remote_get($url, array(
                'timeout'     => 15,
                'user-agent'  => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'headers'     => array('Accept' => 'application/json'),
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(__('Invalid JSON response from price feed API.', 'wc-sersh-payment'));
            }

            if (!isset($data['quotes']) || !is_array($data['quotes']) || empty($data['quotes'])) {
                throw new Exception(__('Price feed API response does not contain required quotes data.', 'wc-sersh-payment'));
            }

            // Get the USD quote
            $usd_quote = null;
            foreach ($data['quotes'] as $quote) {
                if (isset($quote['currency']) && $quote['currency'] === 'USD' && isset($quote['price'])) {
                    $usd_quote = $quote;
                    break;
                }
            }

            if (!$usd_quote) {
                throw new Exception(__('Price feed API response does not contain USD price data.', 'wc-sersh-payment'));
            }

            WC_Admin_Settings::add_message(sprintf(
                /* translators: %s: Current price */
                __('Price feed API connection successful. Current SERSH price: $%s', 'wc-sersh-payment'),
                number_format((float) $usd_quote['price'], 4)
            ));

            WC_Sersh_Payment::log('Price feed validation successful', 'info');
            return true;

        } catch (Exception $e) {
            WC_Sersh_Payment::log('Price feed validation failed: ' . $e->getMessage(), 'error');
            WC_Admin_Settings::add_error(sprintf(
                __('Price feed validation failed: %s', 'wc-sersh-payment'),
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Process Payment
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id) {
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                throw new Exception(__('Order not found', 'wc-sersh-payment'));
            }

            if (!$this->merchant_address) {
                throw new Exception(__('Merchant address not configured.', 'wc-sersh-payment'));
            }

            // Get current price and calculate token amount
            $token_amount = $this->calculate_token_amount($order->get_total());
            
            // Store payment details in order meta
            $order->update_meta_data('_sersh_token_amount', $token_amount);
            $order->update_meta_data('_sersh_payment_network', $this->testmode ? 'testnet' : 'mainnet');
            $order->update_meta_data('_sersh_merchant_address', $this->merchant_address);
            $order->update_meta_data('_sersh_token_address', $this->token_address);
            $order->update_meta_data('_sersh_payment_initiated', current_time('mysql'));
            
            // Store payer's wallet address if provided
            if (!empty($_POST['sersh_payer_wallet'])) {
                $payer_wallet = sanitize_text_field($_POST['sersh_payer_wallet']);
                $order->update_meta_data('_sersh_payer_wallet', $payer_wallet);
                $order->add_order_note(sprintf(
                    __('Payer wallet address: %s', 'wc-sersh-payment'),
                    $payer_wallet
                ));
            }
            
            // Add order note about payment initiation
            $order->add_order_note(
                sprintf(
                    __('SERSH payment initiated. Amount: %f SERSH tokens (%s %s)', 'wc-sersh-payment'),
                    $token_amount,
                    $order->get_currency(),
                    $order->get_total()
                )
            );

            $order->add_order_note(
                sprintf(
                    __('User Wallet Address (%s)', 'wc-sersh-payment'),
                    sanitize_text_field($_POST['sersh_payer_wallet'])
                )
            );

            // Update order status to pending payment
            $order->update_status(
                'pending',
                __('Awaiting SERSH token payment confirmation.', 'wc-sersh-payment')
            );

            // Save all changes
            $order->save();

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // Remove cart
            WC()->cart->empty_cart();

            // Return success with redirect URL
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );

        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            
            // Log the error if debug is enabled
            if ('yes' === $this->get_option('debug')) {
                wc_get_logger()->error(
                    sprintf('Payment processing failed for order %d: %s', $order_id, $e->getMessage()),
                    array('source' => 'sersh-payment')
                );
            }
            
            // Add error note to order if it exists
            if (isset($order) && $order) {
                $order->add_order_note(
                    sprintf(
                        __('Payment processing failed: %s', 'wc-sersh-payment'),
                        $e->getMessage()
                    )
                );
            }

            return array(
                'result'   => 'failure',
                'messages' => $e->getMessage(),
            );
        }
    }

    /**
     * Calculate token amount based on order total
     *
     * @param float $order_total Order total.
     * @return float
     * @throws Exception If price calculation fails.
     */
    private function calculate_token_amount($order_total) {
        if (empty($this->price_feed_url)) {
            // If no price feed URL is configured, use 1:1 conversion for testing
            if ($this->testmode) {
                return $order_total;
            }
            throw new Exception(__('Price feed URL not configured.', 'wc-sersh-payment'));
        }

        $response = wp_remote_get($this->price_feed_url, array(
            'timeout'     => 15,
            'user-agent'  => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            'headers'     => array('Accept' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            throw new Exception(__('Error fetching token price: ', 'wc-sersh-payment') . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['quotes']) || !is_array($data['quotes']) || empty($data['quotes'])) {
            throw new Exception(__('Invalid price data received from API.', 'wc-sersh-payment'));
        }

        // Get the USD quote
        $usd_quote = null;
        foreach ($data['quotes'] as $quote) {
            if (isset($quote['currency']) && $quote['currency'] === 'USD' && isset($quote['price'])) {
                $usd_quote = $quote;
                break;
            }
        }

        if (!$usd_quote || !isset($usd_quote['price']) || !is_numeric($usd_quote['price'])) {
            throw new Exception(__('Invalid USD price data received from API.', 'wc-sersh-payment'));
        }

        $token_price = (float) $usd_quote['price'];
        if ($token_price <= 0) {
            throw new Exception(__('Invalid token price received from API.', 'wc-sersh-payment'));
        }

        // Calculate required token amount (order total divided by token price)
        $token_amount = $order_total / $token_price;

        // Log the conversion if debug is enabled
        if ('yes' === $this->get_option('debug')) {
            wc_get_logger()->debug(
                sprintf(
                    'Price conversion: %f USD = %f SERSH (price: %f USD/SERSH)',
                    $order_total,
                    $token_amount,
                    $token_price
                ),
                array('source' => 'sersh-payment')
            );
        }

        return $token_amount;
    }

    /**
     * Enqueue payment scripts
     */
    public function payment_scripts() {
        if (!is_checkout() || 'no' === $this->enabled) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'wc-sersh-payment',
            WC_SERSH_PLUGIN_URL . 'assets/css/sersh-payment.css',
            array(),
            WC_SERSH_VERSION
        );

        wp_enqueue_script('web3');
        wp_enqueue_script('wc-sersh-payment');
        wp_enqueue_script('wc-sersh-checkout');

        wp_localize_script('wc-sersh-checkout', 'wcSershPayment', array(
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('wc-sersh-payment-nonce'),
            'tokenAddress'    => $this->token_address,
            'merchantAddress' => $this->merchant_address,
            'testMode'        => $this->testmode,
            'i18n'           => array(
                'metamaskRequired' => __('MetaMask or a compatible Web3 wallet is required to make payments.', 'wc-sersh-payment'),
                'connectWallet'    => __('Please connect your wallet to proceed.', 'wc-sersh-payment'),
                'wrongNetwork'     => __('Please switch to the correct network to proceed.', 'wc-sersh-payment'),
                'paymentError'     => __('Payment failed: ', 'wc-sersh-payment'),
                'walletConnected'  => __('Wallet connected: ', 'wc-sersh-payment'),
                'walletRequired'   => __('Please connect your wallet to complete the payment.', 'wc-sersh-payment'),
            ),
        ));
    }

    /**
     * Output payment fields
     */
    public function payment_fields() {
        if ($this->description) {
            echo '<div class="sersh-payment-description">';
            echo wpautop(wp_kses_post($this->description));
            echo '</div>';
        }

        echo '<div id="sersh-payment-form" class="sersh-payment-method">';
        
        if (!$this->is_valid_for_use()) {
            echo '<div class="wc-block-components-notice-banner is-error">' . 
                esc_html__('SERSH Payment is not available for your location.', 'wc-sersh-payment') . 
                '</div>';
            return;
        }

        if (!$this->testmode && !$this->merchant_address) {
            echo '<div class="wc-block-components-notice-banner is-error">' . 
                esc_html__('Merchant address not configured. Please contact the store administrator.', 'wc-sersh-payment') . 
                '</div>';
            return;
        }

        // Add wallet connection section
        echo '<div class="sersh-wallet-section">';
        echo '<div id="sersh-wallet-display"></div>';
        echo '</div>';

        // Add hidden fields for token amount and wallet address
        echo '<input type="hidden" id="sersh_token_amount" name="sersh_token_amount" />';
        echo '<input type="hidden" id="sersh_payer_wallet" name="sersh_payer_wallet" />';
        echo '<input type="hidden" id="sersh_network" name="sersh_network" value="' . 
            esc_attr($this->testmode ? 'testnet' : 'mainnet') . '" />';
        
        echo '</div>';
    }

    /**
     * Validate payment fields
     *
     * @return bool
     */
    public function validate_fields() {
        if (!$this->is_valid_for_use()) {
            wc_add_notice(__('SERSH Payment is not available for your location.', 'wc-sersh-payment'), 'error');
            return false;
        }

        if (!$this->merchant_address && !$this->testmode) {
            wc_add_notice(__('Payment configuration error. Please contact the store administrator.', 'wc-sersh-payment'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Check if this gateway is valid for use
     *
     * @return bool
     */
    public function is_valid_for_use() {
        return in_array(
            get_woocommerce_currency(),
            apply_filters('wc_sersh_supported_currencies', array('USD', 'EUR', 'GBP'))
        );
    }

    /**
     * Process refund
     *
     * @param int    $order_id Order ID.
     * @param float  $amount Refund amount.
     * @param string $reason Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order ID', 'wc-sersh-payment'));
        }

        // Get original token amount
        $original_token_amount = $order->get_meta('_sersh_token_amount');
        if (!$original_token_amount) {
            return new WP_Error('invalid_token_amount', __('Original token amount not found', 'wc-sersh-payment'));
        }

        try {
            // Calculate refund amount in tokens
            $refund_token_amount = $this->calculate_token_amount($amount);

            $order->add_order_note(
                sprintf(
                    __('Refund of %f SERSH tokens (%s %s) initiated. Reason: %s', 'wc-sersh-payment'),
                    $refund_token_amount,
                    get_woocommerce_currency(),
                    $amount,
                    $reason
                )
            );

            // Store refund details
            $order->update_meta_data('_sersh_refund_amount', $refund_token_amount);
            $order->update_meta_data('_sersh_refund_reason', $reason);
            $order->save();

            return true;

        } catch (Exception $e) {
            return new WP_Error('refund_failed', $e->getMessage());
        }
    }

    /**
     * Admin Panel Options
     */
    public function admin_options() {
        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?>
            <?php wc_back_link(__('Return to payments', 'wc-sersh-payment'), admin_url('admin.php?page=wc-settings&tab=checkout')); ?>
        </h2>
        <?php echo wp_kses_post(wpautop($this->get_method_description())); ?>

        <table class="form-table">
            <?php
            if ($this->is_valid_for_use()) {
                $this->generate_settings_html();
            } else {
                ?>
                <tr valign="top">
                    <td colspan="2">
                        <div class="inline error">
                            <p>
                                <strong><?php esc_html_e('Gateway Disabled', 'wc-sersh-payment'); ?></strong>
                                <?php esc_html_e('SERSH Payment does not support your store currency.', 'wc-sersh-payment'); ?>
                            </p>
                        </div>
                    </td>
                </tr>
                <?php
            }
            ?>
        </table>
        <?php
    }

    /**
     * Add content to the WC emails.
     *
     * @param WC_Order $order Order object.
     * @param bool     $sent_to_admin Sent to admin.
     * @param bool     $plain_text Email format: plain text or HTML.
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if ($this->id !== $order->get_payment_method() || $sent_to_admin) {
            return;
        }

        $token_amount = $order->get_meta('_sersh_token_amount');
        if ($token_amount) {
            if ($plain_text) {
                echo wp_kses_post(sprintf(__('Amount in SERSH tokens: %s', 'wc-sersh-payment'), $token_amount) . "\n");
            } else {
                echo wp_kses_post(sprintf(__('Amount in SERSH tokens: %s', 'wc-sersh-payment'), '<strong>' . $token_amount . '</strong>') . '<br/>');
            }
        }
    }

    /**
     * Handle incoming webhook requests
     */
    public function webhook_handler() {
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);

        if (empty($data)) {
            wp_send_json_error('Invalid webhook payload');
            exit;
        }

        // Verify webhook signature if provided
        if (isset($_SERVER['HTTP_X_SERSH_SIGNATURE'])) {
            $signature = $_SERVER['HTTP_X_SERSH_SIGNATURE'];
            if (!$this->verify_webhook_signature($payload, $signature)) {
                wp_send_json_error('Invalid webhook signature');
                exit;
            }
        }

        // Process webhook based on event type
        if (!isset($data['event'])) {
            wp_send_json_error('Missing event type');
            exit;
        }

        switch ($data['event']) {
            case 'payment.success':
                $this->process_successful_payment_webhook($data);
                break;
            case 'payment.failed':
                $this->process_failed_payment_webhook($data);
                break;
            default:
                wp_send_json_error('Unsupported event type');
                exit;
        }

        wp_send_json_success('Webhook processed successfully');
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload Raw webhook payload
     * @param string $signature Webhook signature
     * @return bool
     */
    private function verify_webhook_signature($payload, $signature) {
        $secret = $this->get_option('webhook_secret');
        if (empty($secret)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Process successful payment webhook
     *
     * @param array $data Webhook data
     */
    private function process_successful_payment_webhook($data) {
        if (!isset($data['order_id']) || !isset($data['transaction_hash'])) {
            $this->log('Webhook missing required fields: ' . json_encode($data), 'error');
            wp_send_json_error('Missing required fields');
            exit;
        }

        $order = wc_get_order($data['order_id']);
        if (!$order) {
            $this->log('Webhook order not found: ' . $data['order_id'], 'error');
            wp_send_json_error('Order not found');
            exit;
        }

        // Save transaction hash as transaction ID (even before verification)
        $transaction_hash = sanitize_text_field($data['transaction_hash']);
        
        // Log transaction hash processing
        $this->log('Processing webhook transaction: ' . $transaction_hash . ' for order: ' . $data['order_id'], 'debug');
        
        // Check if transaction ID already exists
        $existing_transaction_id = $order->get_transaction_id();
        if (!empty($existing_transaction_id)) {
            $this->log('Order already has transaction ID: ' . $existing_transaction_id . '. Will be updated to: ' . $transaction_hash, 'debug');
        }
        
        // Set and save the transaction ID
        $order->set_transaction_id($transaction_hash);
        $order->save();
        
        // Verify the transaction ID was actually saved
        $saved_transaction_id = $order->get_transaction_id();
        if ($saved_transaction_id !== $transaction_hash) {
            $this->log('Transaction ID mismatch after saving. Expected: ' . $transaction_hash . ', Got: ' . $saved_transaction_id, 'error');
        } else {
            $this->log('Transaction ID saved successfully: ' . $saved_transaction_id, 'debug');
        }

        // Verify the transaction
        $verifier = new Sersh_Transaction_Verifier();
        $verification = $verifier->verify_transaction($data['transaction_hash'], $data['order_id']);

        if ($verification['success']) {
            $this->log('Transaction verification successful for order: ' . $data['order_id'], 'debug');
            
            // The transaction ID is passed as parameter to be recorded
            // Note: Even though we already set_transaction_id above, payment_complete also sets it
            $order->payment_complete($transaction_hash);
            
            // Check if payment_complete properly saved the transaction ID
            $final_transaction_id = $order->get_transaction_id();
            if ($final_transaction_id !== $transaction_hash) {
                $this->log('Transaction ID changed after payment_complete. Expected: ' . $transaction_hash . ', Got: ' . $final_transaction_id, 'error');
                
                // Try to set it again
                $order->set_transaction_id($transaction_hash);
                $order->save();
            }
            
            $order->add_order_note(
                sprintf(
                    __('Payment completed via SERSH. Transaction hash: %s', 'wc-sersh-payment'),
                    $transaction_hash
                )
            );
        } else {
            $this->log('Transaction verification failed for order: ' . $data['order_id'] . ', Reason: ' . $verification['message'], 'error');
            $order->update_status(
                'failed',
                sprintf(
                    __('Payment verification failed: %s', 'wc-sersh-payment'),
                    $verification['message']
                )
            );
        }
    }

    /**
     * Process failed payment webhook
     *
     * @param array $data Webhook data
     */
    private function process_failed_payment_webhook($data) {
        if (!isset($data['order_id'])) {
            wp_send_json_error('Missing order ID');
            exit;
        }

        $order = wc_get_order($data['order_id']);
        if (!$order) {
            wp_send_json_error('Order not found');
            exit;
        }

        $order->update_status(
            'failed',
            isset($data['reason']) 
                ? sprintf(__('Payment failed: %s', 'wc-sersh-payment'), $data['reason'])
                : __('Payment failed', 'wc-sersh-payment')
        );
    }

    /**
     * Generate Settings HTML
     *
     * @return string
     */
    public function generate_settings_html($form_fields = array(), $echo = true) {
        // Add JavaScript for key generation button
        add_action('admin_footer', function() {
            ?>
            <script type="text/javascript">
                jQuery(function($) {
                    // Find the generate keys button and form
                    var generateButton = $('#woocommerce_sersh_generate_keys');
                    var hiddenInput = $('#woocommerce_sersh_generate_keys_hidden');
                    var form = generateButton.closest('form');

                    // Log elements for debugging
                    console.log('Generate button found:', generateButton.length > 0);
                    console.log('Hidden input found:', hiddenInput.length > 0);
                    console.log('Form found:', form.length > 0);

                    // Handle button click
                    generateButton.on('click', function(e) {
                        e.preventDefault();
                        console.log('Generate keys button clicked');
                        
                        if (confirm('<?php echo esc_js(__('Are you sure you want to generate new keys? This will invalidate any existing keys.', 'wc-sersh-payment')); ?>')) {
                            console.log('Confirmation accepted, setting hidden input and submitting form');
                            
                            // Set the hidden input value
                            hiddenInput.val('true');
                            
                            // Add a temporary hidden input for debugging
                            form.append('<input type="hidden" name="debug_trigger" value="generate_keys">');
                            
                            // Submit the form
                            form.submit();
                        }
                    });

                    // Log when form is actually submitted
                    form.on('submit', function() {
                        console.log('Form submitted with hidden input value:', hiddenInput.val());
                    });
                });
            </script>
            <?php
        });

        return parent::generate_settings_html($form_fields, $echo);
    }

    /**
     * Generate Button HTML.
     *
     * @param string $key Field key.
     * @param array  $data Field data.
     * @return string
     */
    public function generate_button_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults  = array(
            'title'             => '',
            'label'            => '',
            'description'      => '',
            'desc_tip'         => false,
            'class'            => '',
            'css'              => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                <?php echo $this->get_tooltip_html($data); ?>
            </th>
            <td class="forminp">
                <button type="button" 
                        id="<?php echo esc_attr($field_key); ?>" 
                        class="button <?php echo esc_attr($data['class']); ?>"
                        style="<?php echo esc_attr($data['css']); ?>"
                        <?php echo $this->get_custom_attribute_html($data); ?>>
                    <?php echo wp_kses_post($data['default']); ?>
                </button>
                <?php echo $this->get_description_html($data); ?>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    private function init_gateway_settings() {
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
        } else {
            // Ensure default keys exist
            if (empty($settings['private_key'])) {
                $settings['private_key'] = 'b5c6bea4b1c7677f64569a3401c520c8be6df7ffd1f29deb822ced0837059fee';
                $settings['public_key'] = '0x2ba400efb7bC1bbd9786444e04f5ED28F8CDF14c';
                update_option('woocommerce_sersh_settings', $settings);
            }
            
            // Ensure transfer event signature exists
            if (empty($settings['transfer_event_signature'])) {
                $settings['transfer_event_signature'] = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
                update_option('woocommerce_sersh_settings', $settings);
            }
        }

        // Register gateway settings
        add_filter('woocommerce_get_settings_checkout', array($this, 'add_gateway_settings'), 10, 2);
    }

    /**
     * Convert USD amount to SERSH tokens
     *
     * @param float $usd_amount Amount in USD
     * @return string Amount in SERSH tokens (in wei)
     */
    public function convert_usd_to_tokens($usd_amount) {
        try {
            if (empty($this->price_feed_url)) {
                throw new Exception(__('Price feed URL not configured.', 'wc-sersh-payment'));
            }

            // Fetch current price from the price feed
            $response = wp_remote_get($this->price_feed_url, array(
                'timeout'     => 15,
                'user-agent'  => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'headers'     => array('Accept' => 'application/json'),
            ));

            if (is_wp_error($response)) {
                throw new Exception(__('Error fetching token price: ', 'wc-sersh-payment') . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (!isset($data['quotes']) || !is_array($data['quotes']) || empty($data['quotes'])) {
                throw new Exception(__('Invalid price data received from API.', 'wc-sersh-payment'));
            }

            // Get the USD quote
            $usd_quote = null;
            foreach ($data['quotes'] as $quote) {
                if (isset($quote['currency']) && $quote['currency'] === 'USD' && isset($quote['price'])) {
                    $usd_quote = $quote;
                    break;
                }
            }

            if (!$usd_quote || !isset($usd_quote['price']) || !is_numeric($usd_quote['price'])) {
                throw new Exception(__('Invalid USD price data received from API.', 'wc-sersh-payment'));
            }

            $token_price = (float) $usd_quote['price'];
            if ($token_price <= 0) {
                throw new Exception(__('Invalid token price received from API.', 'wc-sersh-payment'));
            }

            // Calculate token amount (USD amount divided by token price)
            $token_amount = $usd_amount / $token_price;
            
            // Format the token amount with exactly 18 decimals without scientific notation
            $formatted_amount = number_format($token_amount, 18, '.', '');
            
            // Remove trailing zeros after decimal point while keeping exactly 18 decimals
            $parts = explode('.', $formatted_amount);
            $decimals = str_pad(rtrim($parts[1], '0'), 18, '0');
            $formatted_amount = $parts[0] . '.' . $decimals;

            // Log the conversion if debug is enabled
            if ('yes' === $this->get_option('debug')) {
                wc_get_logger()->debug(
                    sprintf(
                        'Price conversion: %f USD = %s SERSH (price: %f USD/SERSH)',
                        $usd_amount,
                        $formatted_amount,
                        $token_price
                    ),
                    array('source' => 'sersh-payment')
                );
            }

            return $formatted_amount;
            
        } catch (Exception $e) {
            error_log('SERSH Payment - Price conversion error: ' . $e->getMessage());
            throw new Exception(__('Failed to get current SERSH token price. Please try again later.', 'wc-sersh-payment'));
        }
    }

    /**
     * Display payer wallet address in admin order page
     *
     * @param WC_Order $order Order object
     */
    public function display_admin_order_wallet_address($order) {
        // Use a static variable to track whether this function has already displayed data for this order
        static $displayed_orders = array();
        
        // Skip if not SERSH payment or if already displayed for this order
        if ($order->get_payment_method() !== $this->id || in_array($order->get_id(), $displayed_orders)) {
            return;
        }
        
        // Mark this order as displayed to prevent duplication
        $displayed_orders[] = $order->get_id();

        $payer_wallet = $order->get_meta('_sersh_payer_wallet');
        if (!empty($payer_wallet)) {
            ?>
            <div class="order_data_column sersh-payment-details">
                <h4><?php esc_html_e('SERSH Payment Details', 'wc-sersh-payment'); ?></h4>
                <div class="sersh-wallet-connected">
                    <strong><?php esc_html_e('Payer Wallet:', 'wc-sersh-payment'); ?></strong>
                    <span class="sersh-wallet-address"><?php echo esc_html($payer_wallet); ?></span>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Display payer wallet address in customer order details
     *
     * @param WC_Order $order Order object
     */
    public function display_customer_order_wallet_address($order) {
        // Use a static variable to track whether this function has already displayed data for this order
        static $displayed_orders = array();
        
        // Skip if not SERSH payment or if already displayed for this order
        if ($order->get_payment_method() !== $this->id || in_array($order->get_id(), $displayed_orders)) {
            return;
        }
        
        // Mark this order as displayed to prevent duplication
        $displayed_orders[] = $order->get_id();

        $payer_wallet = $order->get_meta('_sersh_payer_wallet');
        if (!empty($payer_wallet)) {
            ?>
            <div class="sersh-payment-details">
                <h2><?php esc_html_e('Payment Details', 'wc-sersh-payment'); ?></h2>
                <div class="sersh-wallet-connected">
                    <strong><?php esc_html_e('Transaction From:', 'wc-sersh-payment'); ?></strong>
                    <span class="sersh-wallet-address"><?php echo esc_html($payer_wallet); ?></span>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Add wallet address column to WooCommerce admin orders page
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_wallet_address_column($columns) {
        $new_columns = array();
        
        // Insert wallet column after order status
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_status') {
                $new_columns['sersh_wallet'] = __('SERSH Wallet', 'wc-sersh-payment');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Display wallet address in the custom column
     *
     * @param string $column Column name
     * @param object $order Order object
     */
    public function display_wallet_address_in_column($column, $order) {
        if ($column === 'sersh_wallet') {
            // Get the order object if we're passed an ID
            if (!is_a($order, 'WC_Order')) {
                $order = wc_get_order($order);
            }
            
            // Check if this is a SERSH payment
            if ($order->get_payment_method() !== $this->id) {
                return;
            }
            
            // Display wallet address
            $payer_wallet = $order->get_meta('_sersh_payer_wallet');
            if (!empty($payer_wallet)) {
                echo '<span class="sersh-wallet-address" title="' . esc_attr($payer_wallet) . '">' . 
                     esc_html(substr($payer_wallet, 0, 8) . '...' . substr($payer_wallet, -6)) . 
                     '</span>';
            } else {
                echo '<span class="sersh-wallet-address-missing">' . __('Not provided', 'wc-sersh-payment') . '</span>';
            }
        }
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level   Log level ('debug', 'info', 'warning', 'error')
     */
    private function log($message, $level = 'info') {
        if ('yes' === $this->get_option('debug')) {
            // Use the static log method from the main plugin class
            WC_Sersh_Payment::log($message, $level);
        }
    }
} 