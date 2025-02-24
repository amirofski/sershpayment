# WooCommerce SERSH Payment Gateway

A WooCommerce payment gateway plugin that enables accepting SERSH token payments on the Binance Smart Chain (BSC) network.

## Features

- Accept SERSH token payments in your WooCommerce store
- Support for both BSC Mainnet and Testnet
- MetaMask integration for easy payments
- Automatic network detection and switching
- Transaction verification and order status management
- Configurable settings through WooCommerce admin panel

## Requirements

- WordPress 5.6 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher
- MetaMask or compatible Web3 wallet

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Click "Install Now" and then "Activate"

## Configuration

1. Go to WooCommerce > Settings > Payments
2. Click on "SERSH Token Payment" to configure the gateway
3. Enable the payment method
4. Enter your SERSH token contract address
5. Enter your merchant wallet address to receive payments
6. Configure test mode if needed
7. Save changes

### Configuration Options

- **Enable/Disable**: Turn the payment gateway on/off
- **Title**: Payment method title displayed to customers
- **Description**: Payment method description displayed to customers
- **Test Mode**: Enable test mode using BSC Testnet
- **Debug Log**: Enable logging for debugging
- **Token Address**: Your SERSH token contract address
- **Merchant Address**: Your BSC wallet address to receive payments

## Usage

1. Customers select "SERSH Token Payment" at checkout
2. They click "Place Order"
3. MetaMask opens for payment confirmation
4. Customer confirms the transaction
5. Order status updates automatically upon successful payment

## Development

### File Structure

```
wc-sersh-payment/
├── assets/
│   └── js/
│       ├── sersh-payment.js
│       └── checkout.js
├── includes/
│   ├── class-wc-gateway-sersh.php
│   └── class-sersh-transaction-verifier.php
├── wc-sersh-payment.php
└── README.md
```

### Building from Source

1. Clone the repository
2. Install dependencies (if any)
3. Make your modifications
4. Test thoroughly
5. Build and package

## Security Considerations

- Always verify transaction amounts and recipient addresses
- Use SSL/TLS for your website
- Keep your merchant wallet private key secure
- Regularly update the plugin and dependencies
- Monitor transactions for any suspicious activity

## Troubleshooting

### Common Issues

1. **MetaMask not detected**
   - Ensure MetaMask is installed and unlocked
   - Refresh the page

2. **Network switching fails**
   - Check if the correct network is configured in MetaMask
   - Ensure you have sufficient BNB for gas fees

3. **Transaction verification fails**
   - Check the transaction on BSCScan
   - Verify token contract address
   - Check debug logs if enabled

### Debug Mode

Enable debug logging in the plugin settings to help troubleshoot issues. Logs can be found in the WooCommerce status report.

## Support

For support, please:

1. Check the documentation
2. Search existing issues
3. Create a new issue with:
   - WordPress version
   - WooCommerce version
   - Plugin version
   - Detailed description of the problem
   - Steps to reproduce
   - Any relevant error messages

## License

This plugin is licensed under the GPL v2 or later.

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## Changelog

### 1.0.0
- Initial release
- Basic payment gateway functionality
- MetaMask integration
- BSC network support
- Transaction verification 