/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { useSelect } from '@wordpress/data';
import PaymentABI from  './paymentABI.json';

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
    const settings = window.wcSershData || {};

    // Get cart/checkout data from the store
    const { cartTotal } = useSelect((select) => {
        const store = select('wc/store/cart');
        return {
            cartTotal: store ? store.getCartTotals().total_price : 0,
        };
    });

    // Check Web3 availability
    useEffect(() => {
        const checkWeb3 = async () => {
            const isAvailable = typeof window.ethereum !== 'undefined';
            setIsWeb3Available(isAvailable);

            if (isAvailable) {
                // Listen for account changes
                window.ethereum.on('accountsChanged', () => {
                    setError(null);
                });

                // Listen for chain changes
                window.ethereum.on('chainChanged', () => {
                    setError(null);
                });
            }
        };

        checkWeb3();
    }, []);

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

            // Request account access
            const accounts = await window.ethereum.request({
                method: 'eth_requestAccounts',
            });
            const userAddress = accounts[0];

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
                    user_address: userAddress,
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
            const { userId, amount, nonce, expiry, signature } = signatureData.data;
            console.log(settings.paymentAbi);
            // Create contract instance
            const paymentContract = new web3.eth.Contract(
                PaymentABI,
                settings.paymentAddress
            );
         console.log(signatureData.data);
            // Execute payment transaction
            const tx = await paymentContract.methods
                .paySubscription(userId, amount, nonce, expiry, signature)
                .send({ from: userAddress });

            return {
                type: 'success',
                meta: {
                    paymentMethodData: {
                        user_address: userAddress,
                        transaction_hash: tx.transactionHash,
                        user_id: userId,
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
    }, [settings, cartTotal]);

    // Register payment processing
    useEffect(() => {
        const { onPaymentProcessing } = eventRegistration;
        if (!onPaymentProcessing) {
            return;
        }

        const unsubscribe = onPaymentProcessing(processPayment);
        return () => unsubscribe();
    }, [eventRegistration, processPayment]);

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