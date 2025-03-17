<?php
/**
 * Plugin Name: WooCommerce SERSH Payment Gateway
 * Plugin URI: https://sershpayment.com
 * Description: Accept SERSH token payments on Binance Smart Chain (BSC) in your WooCommerce store.
 * Version: 1.4.4
 * Author: SERSH Payment
 * Author URI: https://sershpayment.com
 * Text Domain: wc-sersh-payment
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.6.2
 * Requires Plugins: woocommerce
 *
 * @package WC_Sersh_Payment
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('WC_SERSH_VERSION', '1.4.4');
define('WC_SERSH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_SERSH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_SERSH_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WC_SERSH_MIN_WC_VERSION', '8.0');

// Default addresses for testnet
define('WC_SERSH_DEFAULT_TOKEN_ADDRESS', '0x7Db1F542Efe605F9181834B6B03d75ea72D80f5D');
define('WC_SERSH_DEFAULT_PAYMENT_ADDRESS', '0x9e39212EbDB62fcd783DC4A2eFaE6d8d33914279');



/**
 * Main SERSH Payment Gateway Class.
 */
final class WC_Sersh_Payment {
    /**
     * Single instance of the WC_Sersh_Payment.
     *
     * @var WC_Sersh_Payment
     */
    private static $instance = null;

    /**
     * Returns main instance.
     *
     * @return WC_Sersh_Payment
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        // Check environment on plugins loaded
        add_action('plugins_loaded', array($this, 'init'), 0);

        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activation_check'));

        // HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_compatibility'));

        // Add settings link
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        // Load text domain
        add_action('init', array($this, 'load_plugin_textdomain'));
    }

    /**
     * Declare compatibility with WooCommerce features.
     */
    public function declare_compatibility() {
        if (!class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            return;
        }

        // Declare HPOS compatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        
        // Declare Cart and Checkout block compatibility
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }

    /**
     * Initialize plugin.
     */
    public function init() {
        // Check if WooCommerce is active and meets minimum version
        if (!$this->check_woocommerce()) {
            return;
        }

        // Load required classes
        require_once WC_SERSH_PLUGIN_DIR . 'includes/class-wc-gateway-sersh.php';
        require_once WC_SERSH_PLUGIN_DIR . 'includes/class-sersh-ajax-handler.php';
        require_once WC_SERSH_PLUGIN_DIR . 'includes/class-sersh-transaction-verifier.php';

        // Initialize gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
        
        // Add compatibility information
        add_filter('woocommerce_system_status_features', array($this, 'add_compatibility_info'));

        // Register scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'register_scripts'));
        
        // Initialize AJAX handler (which also initializes settings)
        new Sersh_Ajax_Handler();

        // Register Block Support
        $this->register_block_support();
    }

    /**
     * Load the plugin text domain for translation.
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain('wc-sersh-payment', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Check WooCommerce status and version.
     *
     * @return bool
     */
    private function check_woocommerce() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }

        // Check WooCommerce version
        if (version_compare(WC_VERSION, WC_SERSH_MIN_WC_VERSION, '<')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . sprintf(
                    /* translators: %s: WooCommerce version */
                    esc_html__('SERSH Payment requires WooCommerce version %s or higher. Please upgrade WooCommerce.', 'wc-sersh-payment'),
                    WC_SERSH_MIN_WC_VERSION
                ) . '</p></div>';
            });
            return false;
        }

        return true;
    }

    /**
     * Runs on plugin activation.
     * Checks if the environment meets all requirements.
     */
    public function activation_check() {
        // Include required WordPress admin functions
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');

        // Check PHP Version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            wp_die(
                sprintf(
                    /* translators: %s: PHP version */
                    esc_html__('SERSH Payment requires PHP version %s or higher. Please upgrade your PHP version.', 'wc-sersh-payment'),
                    '7.4'
                ),
                esc_html__('Plugin Activation Error', 'wc-sersh-payment'),
                array(
                    'back_link' => true,
                )
            );
        }

        // Check if WooCommerce is installed
        if (!class_exists('WooCommerce')) {
            wp_die(
                esc_html__('SERSH Payment requires WooCommerce to be installed and activated.', 'wc-sersh-payment'),
                esc_html__('Plugin Activation Error', 'wc-sersh-payment'),
                array(
                    'back_link' => true,
                )
            );
        }

        // Check WooCommerce version
        if (class_exists('WooCommerce') && version_compare(WC_VERSION, WC_SERSH_MIN_WC_VERSION, '<')) {
            wp_die(
                sprintf(
                    /* translators: %s: WooCommerce version */
                    esc_html__('SERSH Payment requires WooCommerce version %s or higher. Please upgrade WooCommerce.', 'wc-sersh-payment'),
                    WC_SERSH_MIN_WC_VERSION
                ),
                esc_html__('Plugin Activation Error', 'wc-sersh-payment'),
                array(
                    'back_link' => true,
                )
            );
        }

        // Declare compatibility with WooCommerce features
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }

        // Create necessary database tables if needed
        $this->create_tables();

        // Add activation time
        add_option('wc_sersh_payment_activated', time());

        // Clear any cached data
        wp_cache_flush();

        // Trigger action for other integrations
        do_action('wc_sersh_payment_activated');
    }

    /**
     * Create custom database tables.
     */
    private function create_tables() {
        global $wpdb;

        $wpdb->hide_errors();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        // Add your table creation SQL here if needed
        $sql = array();

        // Example table creation (uncomment and modify if needed):
        /*
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_sersh_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            transaction_hash VARCHAR(66) NOT NULL,
            token_amount DECIMAL(65,18) NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY transaction_hash (transaction_hash),
            KEY status (status)
        ) $charset_collate;";
        */

        foreach ($sql as $query) {
            dbDelta($query);
        }
    }

    /**
     * Display WooCommerce missing notice.
     */
    public function woocommerce_missing_notice() {
        $message = sprintf(
            /* translators: %s: WooCommerce URL */
            esc_html__('SERSH Payment requires WooCommerce to be installed and activated. You can download %s here.', 'wc-sersh-payment'),
            '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
        );
        printf('<div class="error"><p>%s</p></div>', wp_kses_post($message));
    }

    /**
     * Check environment.
     *
     * @return bool
     */
    private function check_environment() {
        $environment_check = true;
        
        // Check PHP Version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $environment_check = false;
            $this->add_admin_notice('error', sprintf(
                /* translators: %s: PHP version */
                __('SERSH Payment requires PHP version %s or higher.', 'wc-sersh-payment'),
                '7.4'
            ));
        }

        // Check WooCommerce Version
        if (class_exists('WooCommerce') && version_compare(WC_VERSION, WC_SERSH_MIN_WC_VERSION, '<')) {
            $environment_check = false;
            $this->add_admin_notice('error', sprintf(
                /* translators: %s: WooCommerce version */
                __('SERSH Payment requires WooCommerce version %s or higher.', 'wc-sersh-payment'),
                WC_SERSH_MIN_WC_VERSION
            ));
        }

        return $environment_check;
    }

    /**
     * Add settings link to plugin page.
     *
     * @param array $links Plugin links.
     * @return array
     */
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=wc-settings&tab=checkout&section=sersh'),
            __('Settings', 'wc-sersh-payment')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Add admin notice.
     *
     * @param string $type Notice type.
     * @param string $message Notice message.
     */
    private function add_admin_notice($type, $message) {
        add_action('admin_notices', function() use ($type, $message) {
            echo sprintf(
                '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($type),
                wp_kses_post($message)
            );
        });
    }

    /**
     * Register scripts and styles.
     */
    public function register_scripts() {
        // Enqueue on shop, product, cart and checkout pages
        if (!is_checkout() && !is_cart() && !is_shop() && !is_product()) {
            return;
        }

        // Register Web3 library
        wp_register_script(
            'web3',
            'https://cdn.jsdelivr.net/npm/web3@1.9.0/dist/web3.min.js',
            array(),
            '1.9.0',
            true
        );

        // Register main payment script
        wp_register_script(
            'wc-sersh-payment',
            WC_SERSH_PLUGIN_URL . 'assets/js/sersh-payment.js',
            array('jquery', 'web3'),
            WC_SERSH_VERSION,
            true
        );

        // Register checkout script
        wp_register_script(
            'wc-sersh-checkout',
            WC_SERSH_PLUGIN_URL . 'assets/js/checkout.js',
            array('jquery', 'wc-sersh-payment'),
            WC_SERSH_VERSION,
            true
        );
        
        // Register SERSH price display CSS
        wp_register_style(
            'wc-sersh-price-display',
            WC_SERSH_PLUGIN_URL . 'assets/css/sersh-price-display.css',
            array(),
            WC_SERSH_VERSION
        );

        // Localize script with necessary data
        wp_localize_script('wc-sersh-payment', 'wcSershPayment', array(
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('wc-sersh-payment-nonce'),
            'testMode'        => 'yes' === get_option('woocommerce_sersh_testmode', 'yes'),
            'tokenAddress'    => get_option('woocommerce_sersh_token_address', WC_SERSH_DEFAULT_TOKEN_ADDRESS),
            'paymentAddress'  => get_option('woocommerce_sersh_payment_address', WC_SERSH_DEFAULT_PAYMENT_ADDRESS),
            'i18n'           => array(
                'metamaskRequired' => __('MetaMask or a compatible Web3 wallet is required for SERSH payments.', 'wc-sersh-payment'),
                'connectWallet'    => __('Please connect your wallet to proceed.', 'wc-sersh-payment'),
                'wrongNetwork'     => __('Please switch to the correct network.', 'wc-sersh-payment'),
            ),
        ));

        // Enqueue scripts - Only enqueue the web3 and payment scripts on checkout
        if (is_checkout()) {
            wp_enqueue_script('web3');
            wp_enqueue_script('wc-sersh-payment');
            wp_enqueue_script('wc-sersh-checkout');
        }
        
        // Always enqueue the CSS for price display
        wp_enqueue_style('wc-sersh-price-display');
    }

    /**
     * Register Block Support
     */
    public function register_block_support() {
        if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        if (!class_exists('\Automattic\WooCommerce\Blocks\Package')) {
            return;
        }

        if (!class_exists('\Automattic\WooCommerce\StoreApi\Payments\PaymentContext')) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function(\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                require_once WC_SERSH_PLUGIN_DIR . 'includes/blocks/class-wc-sersh-blocks-support.php';
                $blocks_support = new WC_Sersh_Blocks_Support();
                $payment_method_registry->register($blocks_support);
            }
        );
    }

    /**
     * Log debug messages.
     *
     * @param string $message Message to log.
     * @param string $level   Optional. Emergency|Alert|Critical|Error|Warning|Notice|Info|Debug. Default 'info'.
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
     * Add gateway to WooCommerce payment methods.
     *
     * @param array $methods Payment methods.
     * @return array Modified payment methods.
     */
    public function add_gateway($methods) {
        if ($this->check_environment()) {
            // Initialize Web3 provider check
            add_action('wp_footer', array($this, 'check_web3_provider'));
            
            $methods[] = 'WC_Gateway_Sersh';
        }
        return $methods;
    }

    /**
     * Check Web3 provider availability.
     */
    public function check_web3_provider() {
        if (!is_checkout()) {
            return;
        }

        wp_add_inline_script('wc-sersh-payment', '
        ');
    }
}

// Initialize the plugin
function WC_Sersh_Payment() {
    return WC_Sersh_Payment::instance();
}

// Global for backwards compatibility
$GLOBALS['wc_sersh_payment'] = WC_Sersh_Payment(); 