/**
 * SERSH Checkout Handler
 */
jQuery(function($) {
    'use strict';

    class SershCheckout {
        constructor() {
            this.init();
        }

        init() {
            this.initializeWeb3();
            this.bindEvents();
        }

        initializeWeb3() {
            if (typeof window.ethereum !== 'undefined') {
                this.web3 = new Web3(window.ethereum);
            } else {
                console.warn('MetaMask is not installed!');
            }
        }

        bindEvents() {
            $('form.checkout').on('checkout_place_order_sersh', this.processCheckout.bind(this));
            $(document.body).on('payment_method_selected', this.onPaymentMethodSelected.bind(this));
        }

        async processCheckout() {
            try {
                if (!this.web3) {
                    throw new Error('Web3 is not initialized');
                }

                const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
                if (!accounts || !accounts.length) {
                    throw new Error('No accounts found');
                }

                // Add your payment processing logic here
                
                return true;
            } catch (error) {
                console.error('Checkout error:', error);
                this.showError(error.message);
                return false;
            }
        }

        onPaymentMethodSelected(e, selectedPaymentMethod) {
            if (selectedPaymentMethod === 'sersh') {
                this.initializeWeb3();
            }
        }

        showError(message) {
            $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
            $('form.checkout').prepend(
                `<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">
                    <ul class="woocommerce-error">
                        <li>${message}</li>
                    </ul>
                </div>`
            );
            $('html, body').animate({
                scrollTop: $('.woocommerce-NoticeGroup-checkout').offset().top - 100
            }, 1000);
        }
    }

    // Initialize the checkout
    new SershCheckout();
}); 