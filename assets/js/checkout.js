/**
 * SERSH Checkout Handler
 */
jQuery(function($) {
    'use strict';

    class SershCheckout {
        constructor() {
            this.init();
            this.walletAddress = null;
        }

        init() {
            this.initializeWeb3();
            this.bindEvents();
        }

        initializeWeb3() {
            if (typeof window.ethereum !== 'undefined') {
                this.web3 = new Web3(window.ethereum);
                // Check if already connected
                this.checkWalletConnection();
            } else {
                console.warn('MetaMask is not installed!');
                this.showError(wcSershPayment.i18n.metamaskRequired);
            }
        }

        bindEvents() {
            $('form.checkout').on('checkout_place_order_sersh', this.processCheckout.bind(this));
            $(document.body).on('payment_method_selected', this.onPaymentMethodSelected.bind(this));
            
            // Add connect wallet button event
            $(document).on('click', '#sersh-connect-wallet', this.connectWallet.bind(this));
            
            // Save wallet address when available
            $(document.body).on('checkout_error', this.saveWalletAddressOnError.bind(this));
            $(document.body).on('order_created', this.saveWalletAddressOnSuccess.bind(this));
            
            // Listen for account changes
            if (window.ethereum) {
                window.ethereum.on('accountsChanged', this.handleAccountsChanged.bind(this));
                window.ethereum.on('chainChanged', this.handleChainChanged.bind(this));
            }
        }

        async checkWalletConnection() {
            try {
                const accounts = await this.web3.eth.getAccounts();
                if (accounts.length > 0) {
                    this.handleAccountsChanged(accounts);
                }
            } catch (error) {
                console.error('Error checking wallet connection:', error);
            }
        }

        async connectWallet(e) {
            if (e) {
                e.preventDefault();
            }

            try {
                const accounts = await window.ethereum.request({
                    method: 'eth_requestAccounts'
                });
                this.handleAccountsChanged(accounts);
            } catch (error) {
                console.error('Error connecting wallet:', error);
                this.showError(error.message);
            }
        }

        handleAccountsChanged(accounts) {
            if (accounts.length === 0) {
                this.walletAddress = null;
                this.updateWalletDisplay('');
                this.showError(wcSershPayment.i18n.walletRequired);
            } else {
                this.walletAddress = accounts[0];
                $('#sersh_payer_wallet').val(this.walletAddress);
                this.updateWalletDisplay(this.walletAddress);
                
                // Save the wallet address to sessionStorage for recovery
                if (this.walletAddress) {
                    sessionStorage.setItem('sersh_wallet_address', this.walletAddress);
                }
            }
        }

        handleChainChanged() {
            // Reload the page on chain change as recommended by MetaMask
            window.location.reload();
        }

        updateWalletDisplay(address) {
            const walletDisplay = $('#sersh-wallet-display');
            if (address) {
                const shortAddress = `${address.substring(0, 6)}...${address.substring(address.length - 4)}`;
                if (walletDisplay.length) {
                    walletDisplay.html(wcSershPayment.i18n.walletConnected + shortAddress);
                } else {
                    $('#sersh-payment-form').prepend(
                        `<div id="sersh-wallet-display" class="sersh-wallet-connected">
                            ${wcSershPayment.i18n.walletConnected + shortAddress}
                        </div>`
                    );
                }
                $('#sersh-connect-wallet').hide();
            } else {
                walletDisplay.remove();
                $('#sersh-connect-wallet').show();
            }
        }

        async processCheckout() {
            try {
                if (!this.web3) {
                    throw new Error(wcSershPayment.i18n.metamaskRequired);
                }

                if (!this.walletAddress) {
                    await this.connectWallet();
                }

                // Verify the wallet is still connected
                const accounts = await this.web3.eth.getAccounts();
                if (!accounts || !accounts.length) {
                    throw new Error(wcSershPayment.i18n.walletRequired);
                }

                // Ensure the wallet address is set in the form
                $('#sersh_payer_wallet').val(this.walletAddress);
                
                // Listen for transaction completion to save the transaction hash
                $(document.body).on('checkout_success', this.saveTransactionHash.bind(this));
                
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
                
                // Add connect wallet button if not already present
                if (!$('#sersh-connect-wallet').length && !this.walletAddress) {
                    $('#sersh-payment-form').append(
                        `<button type="button" id="sersh-connect-wallet" class="button">
                            ${wcSershPayment.i18n.connectWallet}
                        </button>`
                    );
                }
                
                // Try to recover wallet address from sessionStorage
                if (!this.walletAddress) {
                    const storedWalletAddress = sessionStorage.getItem('sersh_wallet_address');
                    if (storedWalletAddress) {
                        this.updateWalletDisplay(storedWalletAddress);
                        $('#sersh_payer_wallet').val(storedWalletAddress);
                    }
                }
            }
        }
        
        saveWalletAddressOnError() {
            // If there's a wallet address and we're using SERSH payment
            if (this.walletAddress && $('input[name="payment_method"]:checked').val() === 'sersh') {
                // Store in session storage for recovery
                sessionStorage.setItem('sersh_wallet_address', this.walletAddress);
            }
        }
        
        saveWalletAddressOnSuccess(e, orderData) {
            if (!orderData || !orderData.id) {
                return;
            }
            
            // If we have a wallet address and it's a SERSH payment, save it via AJAX
            if (this.walletAddress && orderData.payment_method === 'sersh') {
                this.saveWalletAddress(orderData.id, this.walletAddress);
            }
        }
        
        saveWalletAddress(orderId, walletAddress) {
            // Send the wallet address to the server
            $.ajax({
                url: wcSershPayment.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wc_sersh_save_wallet_address',
                    nonce: wcSershPayment.nonce,
                    order_id: orderId,
                    wallet_address: walletAddress
                },
                success: function(response) {
                    console.log('Wallet address saved successfully');
                },
                error: function(error) {
                    console.error('Error saving wallet address:', error);
                }
            });
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

        // New method to handle transaction hash saving
        async saveTransactionHash(event, orderData) {
            // If we're not on the SERSH payment, ignore
            if (!orderData || orderData.payment_method !== 'sersh') {
                return;
            }
            
            try {
                // Transaction hash will come from the blockchain transaction
                // This assumes the event includes the transaction hash
                if (event.transactionHash || (event.detail && event.detail.transactionHash)) {
                    const txHash = event.transactionHash || event.detail.transactionHash;
                    
                    console.log('Saving transaction hash:', txHash, 'for order:', orderData.id);
                    
                    // Save via AJAX
                    $.ajax({
                        url: wcSershPayment.ajaxUrl,
                        method: 'POST',
                        data: {
                            action: 'wc_sersh_verify_payment',
                            nonce: wcSershPayment.nonce,
                            order_id: orderData.id,
                            tx_hash: txHash
                        },
                        success: function(response) {
                            console.log('Transaction verification response:', response);
                        },
                        error: function(error) {
                            console.error('Error verifying transaction:', error);
                        }
                    });
                }
            } catch (error) {
                console.error('Error saving transaction hash:', error);
            }
        }
    }

    // Initialize the checkout
    new SershCheckout();
}); 