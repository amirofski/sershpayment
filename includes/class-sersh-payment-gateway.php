<?php

class Sersh_Payment_Gateway {

    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }

        // Load payment contract ABI
        $payment_abi_path = WC_SERSH_PLUGIN_DIR . 'assets/abi/payment.json';
        $payment_abi = file_exists($payment_abi_path) ? json_decode(file_get_contents($payment_abi_path), true) : [];

        wp_enqueue_script(
            'wc-sersh-payment',
            WC_SERSH_PLUGIN_URL . 'assets/js/sersh-payment.js',
            array('jquery'),
            WC_SERSH_VERSION,
            true
        );

        wp_localize_script(
            'wc-sersh-payment',
            'wcSershPayment',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc-sersh-payment'),
                'tokenAddress' => $this->get_option('token_address'),
                'paymentAddress' => $this->get_option('payment_address'),
                'chainId' => $this->get_option('chain_id', 97),
                'testMode' => $this->get_option('test_mode') === 'yes',
                'paymentAbi' => $payment_abi,
                'userId' => get_current_user_id()
            )
        );
    }
} 