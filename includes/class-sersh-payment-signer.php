<?php
/**
 * SERSH Payment Signer
 *
 * @package WC_Sersh_Payment
 */

defined('ABSPATH') || exit;

/**
 * Class Sersh_Payment_Signer
 */
class Sersh_Payment_Signer {
    /**
     * Private key for signing messages
     *
     * @var string
     */
    private $private_key;

    /**
     * Transfer event signature
     *
     * @var string
     */
    private $transfer_event_signature;

    /**
     * Constructor
     */
    public function __construct() {
        error_log('SERSH Payment - Initializing payment signer');
        
        // Get settings
        $settings = get_option('woocommerce_sersh_settings', array());
        error_log('SERSH Payment - Retrieved settings: ' . print_r(array_keys($settings), true));

        // Get private key from settings
        if (!empty($settings['private_key'])) {
            $this->private_key = '0x' . $settings['private_key'];
            error_log('SERSH Payment - Loaded private key: ' . substr($this->private_key, 0, 10) . '...');
        } else {
            // Use default key if none is set
            $this->private_key = '0xb5c6bea4b1c7677f64569a3401c520c8be6df7ffd1f29deb822ced0837059fee';
            error_log('SERSH Payment - Using default private key');
        }

        // Get transfer event signature from settings
        $this->transfer_event_signature = !empty($settings['transfer_event_signature']) 
            ? $settings['transfer_event_signature']
            : '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        
        // Validate the private key
        if (!$this->is_valid_private_key($this->private_key)) {
            error_log('SERSH Payment - Invalid private key format');
            throw new Exception('Invalid private key format');
        }
    }

    /**
     * Generate a new key pair
     *
     * @return array
     */
    public function generate_new_key_pair() {
        try {
            error_log('SERSH Payment - Starting key pair generation');
            
            // Get current settings to preserve other values
            $current_settings = get_option('woocommerce_sersh_settings', array());
            error_log('SERSH Payment - Current settings before generation: ' . print_r(array_keys($current_settings), true));

            // Generate private key using random_bytes
            $private_key = '0x' . bin2hex(random_bytes(32));
            error_log('SERSH Payment - Generated new private key: ' . substr($private_key, 0, 10) . '...');

            // Validate the generated key
            if (!$this->is_valid_private_key($private_key)) {
                error_log('SERSH Payment - Generated key is invalid, retrying');
                return $this->generate_new_key_pair();
            }

            // Calculate public key (Ethereum address)
            $public_key = $this->get_ethereum_address($private_key);
            error_log('SERSH Payment - Calculated public key: ' . $public_key);

            // Update settings with new keys
            $current_settings['private_key'] = substr($private_key, 2); // Remove '0x' prefix for storage
            $current_settings['public_key'] = $public_key;

            // Save settings
            $update_result = update_option('woocommerce_sersh_settings', $current_settings, false);
            error_log('SERSH Payment - Settings update result: ' . ($update_result ? 'success' : 'failed'));

            // Verify the settings were saved
            $saved_settings = get_option('woocommerce_sersh_settings');
            if (empty($saved_settings['private_key']) || $saved_settings['private_key'] !== substr($private_key, 2)) {
                throw new Exception('Failed to save generated keys');
            }

            return array(
                'success' => true,
                'private_key' => substr($private_key, 2),
                'public_key' => $public_key
            );

        } catch (Exception $e) {
            error_log('SERSH Payment - Key generation failed: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Sign a message
     *
     * @param string $message Message to sign
     * @return array
     */
    public function sign_message($message) {
        try {
            if (empty($this->private_key)) {
                error_log('SERSH Payment - Private key not found');
                throw new Exception('Private key not found');
            }

            require_once WC_SERSH_PLUGIN_DIR . 'includes/lib/secp256k1.php';
            $secp256k1 = new Secp256k1();

            // Sign the message
            $signature = $secp256k1->sign($message, substr($this->private_key, 2));

            return array(
                'success' => true,
                'signature' => '0x' . $signature['r'] . $signature['s'] . $signature['v']
            );

        } catch (Exception $e) {
            error_log('SERSH Payment - Signing failed: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
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
            require_once WC_SERSH_PLUGIN_DIR . 'includes/lib/secp256k1.php';
            $secp256k1 = new Secp256k1();

            // Get uncompressed public key
            $pubkey = $secp256k1->getPublicKey(substr($private_key, 2));
            
            // Remove '04' prefix and get Keccak-256 hash
            $pubkey_hash = hash('sha3-256', hex2bin(substr($pubkey, 2)));
            
            // Take last 20 bytes
            return '0x' . substr($pubkey_hash, -40);

        } catch (Exception $e) {
            error_log('SERSH Payment - Error getting Ethereum address: ' . $e->getMessage());
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * ABI encode packed data following Solidity's abi.encodePacked rules
     * 
     * @param mixed ...$args The arguments to encode
     * @return string The encoded data
     */
    private function abi_encode_packed(...$args) {
        $encoded = '';
        
        foreach ($args as $arg) {
            if (is_string($arg)) {
                // Check if it's a hex string (address or bytes)
                if (strpos($arg, '0x') === 0) {
                    $hex = substr($arg, 2);
                    // Ensure even length
                    if (strlen($hex) % 2 !== 0) {
                        $hex = '0' . $hex;
                    }
                    $encoded .= hex2bin($hex);
                } else {
                    // Regular string
                    $encoded .= $arg;
                }
            } else if (is_int($arg) || is_numeric($arg)) {
                // Convert number to 32 bytes
                $hex = $this->numberToHex($arg);
                $encoded .= hex2bin($hex);
            }
        }
        
        return $encoded;
    }

    /**
     * Convert a number to hex representation
     * 
     * @param mixed $number The number to convert
     * @return string Hex string without 0x prefix
     */
    private function numberToHex($number) {
        // Handle zero specially
        if ($number === 0 || $number === '0') {
            return str_pad('0', 64, '0', STR_PAD_LEFT);
        }

        // Convert scientific notation to regular number string
        if (strpos($number, 'e') !== false || strpos($number, 'E') !== false) {
            $number = number_format($number, 0, '', '');
        }

        // Convert to string and remove any decimals
        $number = strval($number);
        if (strpos($number, '.') !== false) {
            $number = substr($number, 0, strpos($number, '.'));
        }

        // Convert large number to hex by processing in chunks
        $hex = '';
        $length = strlen($number);
        
        // Process 8 digits at a time (safe for 32-bit systems)
        for ($i = max(0, $length - 8); $i >= 0; $i -= 8) {
            $chunk = substr($number, max(0, $i), min(8, $i + 8));
            $hex = str_pad(dechex(intval($chunk)), 8, '0', STR_PAD_LEFT) . $hex;
        }

        // Remove leading zeros (but keep at least one digit)
        $hex = ltrim($hex, '0');
        if (empty($hex)) {
            $hex = '0';
        }

        // Ensure even length
        if (strlen($hex) % 2 !== 0) {
            $hex = '0' . $hex;
        }

        // Pad to 32 bytes (64 characters)
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    /**
     * Encode a string parameter according to EIP-712
     * 
     * @param string $value The string to encode
     * @return string The encoded string
     */
    private function encodeString($value) {
        return hash('sha3-256', $value);
    }

    /**
     * Encode an address parameter according to EIP-712
     * 
     * @param string $address The Ethereum address
     * @return string The encoded address
     */
    private function encodeAddress($address) {
        // Remove '0x' prefix if present and pad to 32 bytes
        return str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
    }

    /**
     * Generate signature for payment
     *
     * @param string  $user_id User ID.
     * @param string  $amount Amount in wei.
     * @param integer $nonce Nonce.
     * @param integer $expiry Expiry timestamp.
     * @param string  $user_address User's Ethereum address.
     * @return array
     */
    public function generate_payment_signature($user_id, $amount, $nonce, $expiry, $user_address) {
        try {
            error_log(sprintf(
                'SERSH Payment - Generating payment signature - User ID: %s, Amount: %s, Nonce: %s, Expiry: %d, Address: %s',
                $user_id,
                $amount,
                $nonce,
                $expiry,
                $user_address
            ));
    
            // Send the signature request
            $response = $this->send_signature_request($user_id, $amount, $nonce, $expiry, $user_address);
            
            // Check if the request was successful
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            // Get the response body and decode JSON
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (empty($data)) {
                throw new Exception('Invalid response from signature server');
            }

            error_log('SERSH Payment - Payment signature generated successfully');
            
            return array(
                'success' => true,
                'data' => array(
                    'message' => $data['message'],
                    'signature' => $data['signature']
                )
            );

        } catch (Exception $e) {
            error_log('SERSH Payment - Signature generation failed: ' . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    private function send_signature_request($user_id, $amount, $nonce, $expiry, $user_address) {
        $response = wp_remote_post(
            'https://api-w3.sglobal.io:3443/testing/subscription/create-saxess-signature',
            array(
                'method' => 'POST',
                'body' => array(
                    'userId' => $user_id,
                    'price' => $amount,
                    'nonce' => $nonce,
                    'expiry' => $expiry,
                    'address' => $user_address
                )   
            )
        );

        return $response;
    }
        

    /**
     * Validate Ethereum address format
     * 
     * @param string $address The address to validate
     * @return bool Whether the address is valid
     */
    private function is_valid_address($address) {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }

    /**
     * Get public key
     *
     * @return string
     */
    public function get_public_key() {
        $settings = get_option('woocommerce_sersh_settings', array());
        return isset($settings['public_key']) ? $settings['public_key'] : '';
    }
} 