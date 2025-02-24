<?php

use Mdanter\Ecc\PrivateKey;
use Mdanter\Ecc\PublicKey;
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
     * Sign a message hash using secp256k1
     *
     * @param string $message_hash The message hash to sign (32 bytes)
     * @return array
     */
    private function sign_message($message_hash) {
        try {
            if (!class_exists('\Mdanter\Ecc\EccFactory')) {
                // Load Composer's autoloader if not already loaded
                $autoloader = WC_SERSH_PLUGIN_DIR . 'vendor/autoload.php';
                if (file_exists($autoloader)) {
                    require_once $autoloader;
                } else {
                    throw new Exception('mdanter/ecc package not found. Please run composer install.');
                }
            }

            // Remove '0x' prefix if present from private key and message hash
            $private_key_hex = substr($this->private_key, 2);
            $message_hash = substr($message_hash, 0, 2) === '0x' ? substr($message_hash, 2) : $message_hash;

            // Initialize the curve and math adapter
            $adapter = \Mdanter\Ecc\EccFactory::getAdapter();
            $generator = \Mdanter\Ecc\EccFactory::getSecgCurves()->generator256k1();

            // Create private key from hex
            $private_key = $adapter->hexDec($private_key_hex);
            // $key = new PrivateKey($generator, $private_key);
            $key = new PrivateKey(adapter: $adapter, secretMultiplier: $private_key, publicKey: $generator->getPublicKeyFrom($generator->getX(), $generator->getY(), $generator->getOrder()));

            $bytes = random_bytes(4); // 32 bytes = 256 bits
            $randomNumber = hexdec(bin2hex($bytes));


            $hashBinary = hex2bin($hashHex);
            $signature = $key->sign($hashBinary,$randomNumber);  

            // Calculate recovery ID (v)
            $recid = $this->calculate_recovery_id($hashBinary, $r, $s, $key);
            $v = dechex($recid + 27);

            // Ensure v is padded to 2 characters
            $v = str_pad($v, 2, '0', STR_PAD_LEFT);

            // Combine the signature parts
            $signature = '0x' . $r . $s . $v;

            error_log('SERSH Payment - Message Hash: ' . $message_hash);
            error_log('SERSH Payment - R value: ' . $r);
            error_log('SERSH Payment - S value: ' . $s);
            error_log('SERSH Payment - V value: ' . $v);
            error_log('SERSH Payment - Full signature: ' . $signature);

            return array(
                'success' => true,
                'signature' => $signature
            );

        } catch (Exception $e) {
            error_log('SERSH Payment - Signing error: ' . $e->getMessage());
            error_log('SERSH Payment - Signing error trace: ' . $e->getTraceAsString());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    private function calculate_recovery_id($message_hash, $r, $s, $private_key) {
        $adapter = \Mdanter\Ecc\EccFactory::getAdapter();
        $generator = \Mdanter\Ecc\EccFactory::getSecgCurves()->generator256k1();

        
        // Get the public key point
        $public_key_point = $private_key->getPublicKey()->getPoint();
        
        // Try all possible recovery IDs
        for ($recid = 0; $recid < 4; $recid++) {
            try {
                $recovered_public_key = $this->recover_public_key(
                    $message_hash,
                    $r,
                    $s,
                    $recid,
                    $generator,
                    $adapter
                );
                
                if ($recovered_public_key->equals($public_key_point)) {
                    return $recid;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        throw new Exception('Unable to find valid recovery ID');
    }


    private function recover_public_key($message_hash, $r, $s, $recid, $generator, $adapter) {
        $curve = $generator->getCurve();
        $n = $generator->getOrder();
        
        // Convert hex values to decimal
        $r = $adapter->hexDec($r);
        $s = $adapter->hexDec($s);
        $e = $adapter->hexDec($message_hash);

        // Check if recovery ID is valid
        if ($recid < 0 || $recid > 3) {
            throw new Exception(message: 'Invalid recovery ID');
        }

        // Calculate x coordinate
        $x = $adapter->add($r, $adapter->mul($n, intdiv($recid, 2)));
        
        // Calculate R point
        $R = $curve->getPoint(
            $x,
            $recid&1 ? $curve->recoverYfromX($x) : $curve->recoverYfromX($x)->negate()
        );

        // Calculate public key point
        $rInv = $adapter->inverseMod($r, $n);
        $eG = $generator->mul($e);
        $sR = $R->mul($s);
        $Q = $eG->negate()->add($sR)->mul($rInv);

        return $Q;
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
                    // For regular strings, we need to encode the length and the string data
                    $length = strlen($arg);
                    $encoded .= hex2bin($this->numberToHex($length));
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
     * Keccak-256 hash function as used by Ethereum
     * 
     * @param string $message The message to hash
     * @return string The hash in hex format without 0x prefix
     */
    private function keccak256($message) {
        if (!class_exists('\kornrunner\Keccak')) {
            // Load Composer's autoloader if not already loaded
            $autoloader = WC_SERSH_PLUGIN_DIR . 'vendor/autoload.php';
            if (file_exists($autoloader)) {
                require_once $autoloader;
            } else {
                throw new Exception('Keccak package not found. Please run composer install.');
            }
        }

        return \kornrunner\Keccak::hash($message, 256);
    }

    /**
     * Encode a string parameter according to EIP-712
     * 
     * @param string $value The string to encode
     * @return string The encoded string hash
     */
    private function encodeString($value) {
        // For EIP-712, we need to hash the string as keccak256(abi.encodePacked(value))
        $encoded = $this->abi_encode_packed($value);
        return $this->keccak256($encoded);
    }

    /**
     * Encode an address parameter according to EIP-712
     * 
     * @param string $address The Ethereum address
     * @return string The encoded address
     */
    private function encodeAddress($address) {
        // Remove '0x' prefix if present
        $clean_address = substr($address, 2);
        // Pad to 32 bytes (64 characters)
        return str_pad($clean_address, 64, '0', STR_PAD_LEFT);
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

            if (empty($this->private_key)) {
                error_log('SERSH Payment - Private key not found');
                throw new Exception('Private key not found. Please generate new keys in the gateway settings.');
            }

            // Get contract address and ensure it's valid
            $settings = get_option('woocommerce_sersh_settings', array());
            error_log('SERSH Payment - All settings: ' . print_r($settings, true));
            
            $contract_address = isset($settings['payment_address']) ? $settings['payment_address'] : '';
            error_log('SERSH Payment - Contract address from settings: ' . ($contract_address ?: 'null'));
            
            if (empty($contract_address) || !$this->is_valid_address($contract_address)) {
                error_log('SERSH Payment - Invalid contract address: ' . ($contract_address ?: 'empty'));
                throw new Exception('SERSH Payment - Invalid contract address: ' . ($contract_address ?: 'empty') . $user_id. "-" . $amount. "-" . $nonce. "-" . $expiry. "-" . $user_address);
            }

            // Get chain ID from settings
            $chain_id = isset($settings['chain_id']) ? intval($settings['chain_id']) : 97; // BSC Testnet by default
            error_log('SERSH Payment - Using chain ID: ' . $chain_id);

            // EIP-712 domain hash
            $domain_type_hash = $this->keccak256("EIP712Domain(string name,string version,uint256 chainId,address verifyingContract)");
            error_log('SERSH Payment - Domain Type Hash: ' . $domain_type_hash);

            $domain_separator = $this->keccak256($this->abi_encode_packed(
                hex2bin($domain_type_hash),
                hex2bin($this->encodeString("SBoxSubscription")),
                hex2bin($this->encodeString("1")),
                hex2bin($this->numberToHex($chain_id)),
                hex2bin($this->encodeAddress($contract_address))
            ));
            error_log('SERSH Payment - Domain Separator: ' . $domain_separator);

            // EIP-712 type hash
            $type_hash = $this->keccak256(
                "Payment(address user,string userId,uint256 amount,string nonce,uint256 expiry)"
            );
            error_log('SERSH Payment - Type Hash: ' . $type_hash);

            // Encode the structured data
            $encoded_data = $this->keccak256($this->abi_encode_packed(
                hex2bin($type_hash),
                hex2bin($this->encodeAddress($user_address)),
                hex2bin($this->encodeString($user_id)),
                hex2bin($this->numberToHex($amount)),
                hex2bin($this->encodeString($nonce)),
                hex2bin($this->numberToHex($expiry))
            ));
            error_log('SERSH Payment - Encoded Data: ' . $encoded_data);

            // Final hash combining domain separator and encoded data
            $message_hash = $this->keccak256($this->abi_encode_packed(
                "\x19\x01",
                hex2bin($domain_separator),
                hex2bin($encoded_data)
            ));
            error_log('SERSH Payment - Message Hash: ' . $message_hash);
            
            // Sign the message hash
            $signature_result = $this->sign_message($message_hash);
            
            if (!$signature_result['success']) {
                throw new Exception($signature_result['error']);
            }

            error_log('SERSH Payment - Signature: ' . $signature_result['signature']);
            
            return array(
                'success' => true,
                'data' => array(
                    'userId' => $user_id,
                    'amount' => $amount,
                    'nonce' => $nonce,
                    'expiry' => $expiry,
                    'signature' => $signature_result['signature']
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