const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

// WooCommerce Blocks dependency mappings
const wcDepMap = {
    '@woocommerce/blocks-registry': ['wc', 'wcBlocksRegistry'],
    '@woocommerce/settings': ['wc', 'wcSettings'],
    '@woocommerce/block-data': ['wc', 'wcBlocksData'],
    '@woocommerce/shared-context': ['wc', 'wcBlocksSharedContext'],
    '@woocommerce/shared-hocs': ['wc', 'wcBlocksSharedHocs'],
    '@woocommerce/price-format': ['wc', 'priceFormat'],
    '@woocommerce/blocks-checkout': ['wc', 'blocksCheckout']
};

// WooCommerce Blocks handle mappings
const wcHandleMap = {
    '@woocommerce/blocks-registry': 'wc-blocks-registry',
    '@woocommerce/settings': 'wc-settings',
    '@woocommerce/block-data': 'wc-blocks-data-store',
    '@woocommerce/shared-context': 'wc-blocks-shared-context',
    '@woocommerce/shared-hocs': 'wc-blocks-shared-hocs',
    '@woocommerce/price-format': 'wc-price-format',
    '@woocommerce/blocks-checkout': 'wc-blocks-checkout'
};

module.exports = {
    ...defaultConfig,
    entry: {
        index: path.resolve(process.cwd(), 'src/index.js'),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(process.cwd(), 'build'),
    },
    externals: {
        ...defaultConfig.externals,
        '@wordpress/element': ['wp', 'element'],
        '@wordpress/i18n': ['wp', 'i18n'],
        '@wordpress/components': ['wp', 'components'],
        '@wordpress/data': ['wp', 'data'],
        '@wordpress/blocks': ['wp', 'blocks'],
        '@wordpress/block-editor': ['wp', 'blockEditor'],
        '@wordpress/html-entities': ['wp', 'htmlEntities'],
        '@wordpress/compose': ['wp', 'compose'],
        '@wordpress/hooks': ['wp', 'hooks'],
        '@wordpress/url': ['wp', 'url'],
        '@wordpress/api-fetch': ['wp', 'apiFetch'],
        '@wordpress/notices': ['wp', 'notices'],
        '@woocommerce/blocks-registry': ['wc', 'wcBlocksRegistry'],
        '@woocommerce/settings': ['wc', 'wcSettings'],
        'web3': 'Web3'
    },
    resolve: {
        ...defaultConfig.resolve,
        extensions: ['.js', '.jsx', '.ts', '.tsx'],
    }
}; 