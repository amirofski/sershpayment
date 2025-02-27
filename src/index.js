/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { useSelect } from '@wordpress/data';
import PaymentABI from  './paymentABI.json';
import ERC20ABI from  './erc20ABI.json';

/**
 * Internal dependencies
 */
import { PAYMENT_METHOD_NAME } from './constants';

/**
 * Content component
 */
const Content = () => {
    const settings = window.wcSershData || {};
    return decodeEntities(settings.description || '');
};

/**
 * Label component
 */
const Label = () => {
    const settings = window.wcSershData || {};
    return (
        <div className="sersh-payment-label">
            {settings.title && <span>{decodeEntities(settings.title)}</span>}
            {settings.icon && <img src={settings.icon} alt="SERSH" />}
        </div>
    );
};

/**
 * Payment Method Component
 */
const PaymentMethodContent = ({ eventRegistration, emitResponse }) => {
    const [isWeb3Available, setIsWeb3Available] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);
    const [error, setError] = useState(null);
    const [walletAddress, setWalletAddress] = useState(null);
    const settings = window.wcSershData || {};

    // Get cart/checkout data from the store
    const { cartTotal } = useSelect((select) => {
        const store = select('wc/store/cart');
        return {
            cartTotal: store ? store.getCartTotals().total_price : 0,
        };
    });

    // Check Web3 availability and wallet connection
    useEffect(() => {
        const checkWeb3 = async () => {
            const isAvailable = typeof window.ethereum !== 'undefined';
            setIsWeb3Available(isAvailable);

            if (isAvailable) {
                try {
                    const accounts = await window.ethereum.request({
                        method: 'eth_accounts'
                    });
                    if (accounts.length > 0) {
                        setWalletAddress(accounts[0]);
                    }

                    // Listen for account changes
                    window.ethereum.on('accountsChanged', handleAccountsChanged);
                    // Listen for chain changes
                    window.ethereum.on('chainChanged', () => window.location.reload());
                } catch (error) {
                    console.error('Error checking wallet:', error);
                }
            }
        };

        checkWeb3();

        // Cleanup listeners
        return () => {
            if (window.ethereum) {
                window.ethereum.removeListener('accountsChanged', handleAccountsChanged);
            }
        };
    }, []);

    const handleAccountsChanged = (accounts) => {
        if (accounts.length === 0) {
            setWalletAddress(null);
            setError(settings.i18n.walletRequired);
        } else {
            setWalletAddress(accounts[0]);
            setError(null);
        }
    };

    const connectWallet = async () => {
        try {
            const accounts = await window.ethereum.request({
                method: 'eth_requestAccounts'
            });
            handleAccountsChanged(accounts);
        } catch (error) {
            console.error('Error connecting wallet:', error);
            setError(error.message);
        }
    };

    // Handle payment processing
    const processPayment = useCallback(async () => {
        if (!window.ethereum) {
            return {
                type: 'error',
                message: settings.i18n.metamaskRequired,
            };
        }

        try {
            setIsProcessing(true);
            setError(null);

            // Ensure wallet is connected
            if (!walletAddress) {
                await connectWallet();
            }

            // Verify wallet is still connected
            const accounts = await window.ethereum.request({
                method: 'eth_accounts'
            });
            
            if (!accounts || !accounts.length) {
                throw new Error(settings.i18n.walletRequired);
            }

            // Check and switch network if needed
            const chainId = await window.ethereum.request({ method: 'eth_chainId' });
            if (chainId !== settings.chainId) {
                try {
                    await window.ethereum.request({
                        method: 'wallet_switchEthereumChain',
                        params: [{ chainId: settings.chainId }],
                    });
                } catch (error) {
                    if (error.code === 4902) {
                        await window.ethereum.request({
                            method: 'wallet_addEthereumChain',
                            params: [{
                                chainId: settings.chainId,
                                chainName: settings.testMode ? 'BSC Testnet' : 'BSC Mainnet',
                                nativeCurrency: {
                                    name: 'BNB',
                                    symbol: settings.testMode ? 'tBNB' : 'BNB',
                                    decimals: 18,
                                },
                                rpcUrls: [settings.rpcUrl],
                                blockExplorerUrls: [
                                    settings.testMode 
                                        ? 'https://testnet.bscscan.com/' 
                                        : 'https://bscscan.com/'
                                ],
                            }],
                        });
                    } else {
                        throw error;
                    }
                }
            }

            const web3 = new Web3(window.ethereum);

            // Get payment signature from backend
            const response = await fetch(settings.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'wc_sersh_get_payment_signature',
                    user_address: walletAddress,
                    amount: cartTotal.toString()
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to get payment signature');
            }

            const signatureData = await response.json();
            if (!signatureData.success) {
                throw new Error(signatureData.data);
            }

            // Process the payment with the smart contract
            const { message, signature, orderId } = signatureData.data;
            const paymentContract = new web3.eth.Contract(
                PaymentABI,
                settings.paymentAddress
            );
            console.log(settings);
            const tokenContract = new web3.eth.Contract(
                ERC20ABI,
                settings.tokenAddress
            );

            const allowance = await tokenContract.methods.allowance(walletAddress, settings.paymentAddress).call();
            console.log({
                allowance,
                amount: message.amount,
            });

            if (web3.utils.toBN(allowance).lt(web3.utils.toBN(message.amount))) {
                const tx = await tokenContract.methods
                    .approve(settings.paymentAddress, message.amount)
                    .send({ from: walletAddress });

                console.log({
                    tx,
                });
            }

            const tx = await paymentContract.methods
                .paySubscription(message.userId, message.amount, message.nonce, message.expiry, signature)
                .send({ from: walletAddress });

            // Explicitly save transaction hash via AJAX to ensure it's recorded properly
            try {
                console.log('Saving transaction hash via AJAX:', tx.transactionHash, 'for order:', orderId);
                const verifyResponse = await fetch(settings.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'wc_sersh_verify_payment',
                        nonce: settings.nonce,
                        order_id: orderId, 
                        tx_hash: tx.transactionHash,
                        user_address: walletAddress
                    }),
                });
                
                const verifyResult = await verifyResponse.json();
                console.log('Transaction verification result:', verifyResult);
            } catch (verifyError) {
                console.error('Error verifying transaction:', verifyError);
                // Continue with checkout even if verification has an error
                // We don't want to block the user if only the verification fails
            }

            return {
                type: 'success',
                meta: {
                    paymentMethodData: {
                        user_address: walletAddress,
                        transaction_hash: tx.transactionHash,
                        user_id: message.userId,
                        order_id: orderId.toString(), // Convert to string to ensure correct type
                    },
                },
            };
        } catch (error) {
            console.error('Payment processing error:', error);
            setError(error.message);
            return {
                type: 'error',
                message: error.message,
            };
        } finally {
            setIsProcessing(false);
        }
    }, [settings, cartTotal, walletAddress]);

    // Register payment processing
    useEffect(() => {
        const { onPaymentProcessing } = eventRegistration;
        if (!onPaymentProcessing) {
            return;
        }

        const unsubscribe = onPaymentProcessing(processPayment);
        return () => unsubscribe();
    }, [eventRegistration, processPayment]);
    
    // Save wallet address when it changes
    useEffect(() => {
        if (walletAddress) {
            // Store in sessionStorage for recovery
            sessionStorage.setItem('sersh_wallet_address', walletAddress);
            
            // Also emit to parent components via a custom event
            const walletEvent = new CustomEvent('sershWalletConnected', { 
                detail: { walletAddress } 
            });
            document.dispatchEvent(walletEvent);
            
            // Register success handler for order completion
            const handleOrderSuccess = (event) => {
                if (event.detail && event.detail.orderId) {
                    // Save wallet address via AJAX
                    fetch(settings.ajaxUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'wc_sersh_save_wallet_address',
                            nonce: settings.nonce,
                            order_id: event.detail.orderId,
                            wallet_address: walletAddress
                        }),
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Wallet address saved via AJAX');
                    })
                    .catch(error => {
                        console.error('Error saving wallet address:', error);
                    });
                }
            };
            
            document.addEventListener('wc-blocks_order_success', handleOrderSuccess);
            return () => {
                document.removeEventListener('wc-blocks_order_success', handleOrderSuccess);
            };
        }
    }, [walletAddress, settings]);

    if (!isWeb3Available) {
        return (
            <div className="wc-block-components-notice-banner is-error">
                {settings.i18n.metamaskRequired}
            </div>
        );
    }

    return (
        <div className="sersh-payment-method">
            <Content />
            {!walletAddress ? (
                <button
                    type="button"
                    className="components-button is-secondary"
                    onClick={connectWallet}
                >
                    {settings.i18n.connectWallet}
                </button>
            ) : (
                <div className="sersh-wallet-connected">
                    {settings.i18n.walletConnected}
                    {`${walletAddress.substring(0, 6)}...${walletAddress.substring(walletAddress.length - 4)}`}
                </div>
            )}
            {isProcessing && (
                <div className="sersh-payment-processing">
                    {settings.i18n.processingPayment}
                </div>
            )}
            {error && (
                <div className="wc-block-components-notice-banner is-error">
                    {error}
                </div>
            )}
        </div>
    );
};

/**
 * SERSH Payment Method Config
 */
const SershPaymentMethod = {
    name: PAYMENT_METHOD_NAME,
    label: <Label />,
    content: <PaymentMethodContent />,
    edit: <Content />,
    canMakePayment: () => typeof window.ethereum !== 'undefined',
    ariaLabel: __('SERSH Payment', 'wc-sersh-payment'),
    supports: {
        features: window.wcSershData?.supports || [],
    },
};

// Register the payment method
registerPaymentMethod(SershPaymentMethod); 