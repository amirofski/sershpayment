/**
 * SERSH Payment Handler
 */
(function($) {
    'use strict';

    const SershPayment = {
        init: function() {
            this.bindEvents();
            this.checkWeb3Provider();
        },

        bindEvents: function() {
            $(document.body).on('payment_method_selected', this.onPaymentMethodSelected);
            $('#place_order').on('click', this.onPlaceOrder.bind(this));
        },

        checkWeb3Provider: function() {
            if (typeof window.ethereum === 'undefined') {
                this.showError('MetaMask is not installed. Please install MetaMask to make payments with SERSH tokens.');
                return false;
            }
            return true;
        },

        onPaymentMethodSelected: function() {
            if ($('input[name="payment_method"]:checked').val() === 'sersh') {
                SershPayment.checkWeb3Provider();
            }
        },

        onPlaceOrder: async function(e) {
            if ($('input[name="payment_method"]:checked').val() !== 'sersh') {
                return true;
            }

            e.preventDefault();

            if (!this.checkWeb3Provider()) {
                return false;
            }

            try {
                await this.processPayment();
            } catch (error) {
                this.showError(error.message);
                return false;
            }
        },

        processPayment: async function() {
            const orderTotal = parseFloat($('input[name="sersh_order_total"]').val());

            try {
                // Request account access
                const accounts = await ethereum.request({ method: 'eth_requestAccounts' });
                const userAddress = accounts[0];

                // Initialize Web3
                if (typeof window.ethereum !== 'undefined') {
                    window.web3 = new Web3(window.ethereum);
                } else {
                    throw new Error('Web3 provider not found');
                }

                // Get payment signature and data from server
                const signatureResponse = await $.ajax({
                    url: wcSershPayment.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'wc_sersh_get_payment_signature',
                        user_address: userAddress,
                        amount: orderTotal
                    }
                });

                if (!signatureResponse.success) {
                    throw new Error(signatureResponse.data);
                }

                console.log(signatureResponse.data);

                const paymentData = signatureResponse.data;
                
                // Ensure amount is properly formatted as hex
                const amount = paymentData.amount.startsWith('0x') ? paymentData.amount : `0x${paymentData.amount}`;
                const amountBN = web3.utils.toBN(amount)
                const nonce = paymentData.nonce.startsWith('0x') ? paymentData.nonce : `0x${paymentData.nonce}`;
                const nonceBN = web3.utils.toBN(nonce);
                
                console.log('Amount BN:', amountBN.toString());
                console.log('Nonce BN:', nonceBN.toString());

                // Check and switch network if necessary
                await this.checkAndSwitchNetwork();

                // Get token contract
                const tokenContract = this.getTokenContract();
                console.log(tokenContract);
                console.log(wcSershPayment.paymentAddress);

                // Check allowance first
                const allowance = await tokenContract.methods.allowance(userAddress, wcSershPayment.paymentAddress).call();
                console.log({
                    allowance: allowance,
                    amountBN: amountBN,
                    web3: web3.utils.toBN(allowance).lt(amountBN)
                })

                if (web3.utils.toBN(allowance).lt(amountBN)) {
                    // Approve tokens first
                    await tokenContract.methods.approve(
                        wcSershPayment.paymentAddress,
                        amount
                    ).send({ from: userAddress });
                }

                // Transfer tokens through the payment contract
                // const paymentContract = this.getPaymentContract();
                // const tx = await paymentContract.methods.paySubscription(
                //     paymentData.userId,
                //     amount,
                //     paymentData.nonce,
                //     paymentData.expiry,
                //     paymentData.signature
                // ).send({ from: userAddress });

                // Verify payment
                await this.verifyPayment(tx.transactionHash);

            } catch (error) {
                if (error.code === 4001) {
                    throw new Error('Transaction rejected by user');
                } else if (error.code === -32603) {
                    throw new Error('Insufficient token balance or allowance');
                } else {
                    throw new Error('Payment failed: ' + error.message);
                }
            }
        },

        checkAndSwitchNetwork: async function() {
            const chainId = await ethereum.request({ method: 'eth_chainId' });
            
            if (chainId !== wcSershPayment.networkId) {
                try {
                    await ethereum.request({
                        method: 'wallet_switchEthereumChain',
                        params: [{ chainId: '0x' + parseInt(wcSershPayment.networkId).toString(16) }],
                    });
                } catch (error) {
                    if (error.code === 4902) {
                        await this.addBscNetwork();
                    } else {
                        throw error;
                    }
                }
            }
        },

        addBscNetwork: async function() {
            const networkParams = wcSershPayment.testMode ? {
                chainId: '0x61', // 97
                chainName: 'BSC Testnet',
                nativeCurrency: {
                    name: 'BNB',
                    symbol: 'tBNB',
                    decimals: 18
                },
                rpcUrls: ['https://data-seed-prebsc-1-s1.binance.org:8545/'],
                blockExplorerUrls: ['https://testnet.bscscan.com/']
            } : {
                chainId: '0x38', // 56
                chainName: 'Binance Smart Chain',
                nativeCurrency: {
                    name: 'BNB',
                    symbol: 'BNB',
                    decimals: 18
                },
                rpcUrls: ['https://bsc-dataseed.binance.org/'],
                blockExplorerUrls: ['https://bscscan.com/']
            };

            await ethereum.request({
                method: 'wallet_addEthereumChain',
                params: [networkParams]
            });
        },

        getTokenContract: function() {
            const tokenAbi = [
                {
                    "constant": false,
                    "inputs": [
                        {
                            "name": "_spender",
                            "type": "address"
                        },
                        {
                            "name": "_value",
                            "type": "uint256"
                        }
                    ],
                    "name": "approve",
                    "outputs": [
                        {
                            "name": "",
                            "type": "bool"
                        }
                    ],
                    "payable": false,
                    "stateMutability": "nonpayable",
                    "type": "function"
                },
                {
                    "constant": true,
                    "inputs": [
                        {
                            "name": "_owner",
                            "type": "address"
                        },
                        {
                            "name": "_spender",
                            "type": "address"
                        }
                    ],
                    "name": "allowance",
                    "outputs": [
                        {
                            "name": "",
                            "type": "uint256"
                        }
                    ],
                    "payable": false,
                    "stateMutability": "view",
                    "type": "function"
                }
            ];

            return new web3.eth.Contract(tokenAbi, wcSershPayment.tokenAddress);
        },

        getPaymentContract: function() {
            // Check if we have the full ABI from PHP
            const paymentAbi = wcSershPayment.paymentAbi || [
                // Fallback minimal ABI if the full one is not available
                {
                    "inputs": [
                        {
                            "internalType": "string",
                            "name": "userId",
                            "type": "string"
                        },
                        {
                            "internalType": "uint256",
                            "name": "amount",
                            "type": "uint256"
                        },
                        {
                            "internalType": "string",
                            "name": "nonce",
                            "type": "string"
                        },
                        {
                            "internalType": "uint256",
                            "name": "expiry",
                            "type": "uint256"
                        },
                        {
                            "internalType": "bytes",
                            "name": "sig",
                            "type": "bytes"
                        }
                    ],
                    "name": "paySubscription",
                    "outputs": [],
                    "stateMutability": "nonpayable",
                    "type": "function"
                }
            ];

            if (!wcSershPayment.paymentAddress) {
                throw new Error('Payment contract address not configured');
            }

            console.log('Creating payment contract with ABI:', paymentAbi);
            console.log('Payment contract address:', wcSershPayment.paymentAddress);

            return new web3.eth.Contract(paymentAbi, wcSershPayment.paymentAddress);
        },

        verifyPayment: async function(txHash) {
            const response = await $.ajax({
                url: wcSershPayment.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wc_sersh_verify_payment',
                    order_id: wcSershPayment.orderId,
                    tx_hash: txHash,
                    nonce: wcSershPayment.nonce
                }
            });

            if (!response.success) {
                throw new Error(response.data);
            }

            window.location.href = wcSershPayment.successUrl;
        },

        convertFiatToTokens: function(fiatAmount) {
            // TODO: Implement conversion logic using price feed
            return fiatAmount;
        },

        toTokenDecimals: function(amount) {
            return web3.utils.toWei(amount.toString(), 'ether');
        },

        showError: function(message) {
            $('.woocommerce-error').remove();
            const errorHtml = $('<div class="woocommerce-error">' + message + '</div>');
            $('.woocommerce-notices-wrapper').prepend(errorHtml);
            $('html, body').animate({
                scrollTop: $('.woocommerce-notices-wrapper').offset().top - 100
            }, 1000);
        }
    };

    $(document).ready(function() {
        SershPayment.init();
    });

})(jQuery); 