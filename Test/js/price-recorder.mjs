/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Test copy of the recording rules in src/view/frontend/templates/js/price-state-recorder.phtml. The code
 * between the recorder markers must stay identical to the template's (apart from indentation);
 * price-recorder.test.mjs checks it.
 */
/* recorder:start */
// Page-global Hyvä price events (no product id in name or payload).
const PRICE_EVENTS = [
    'update-product-final-price',
    'update-custom-option-prices',
    'update-custom-option-active',
    'update-bundle-option-prices',
    'configurable-selection-changed',
];

// Per-product events, dispatched as `<type>-<productId>`.
const PRODUCT_EVENTS = ['update-prices', 'update-qty'];

const createPriceState = () => ({
    v: 1,
    global: {
        finalPrice: null,
        optionPrices: null,
        activeOptions: {},
        bundleOptionPrices: null,
        configurableSelection: {},
    },
    products: {},
});

// Event payload ids (product, option) as object keys; '' when absent.
const toKey = (value) => (value === undefined || value === null ? '' : String(value));

// Adds a product entry; false when the id is empty or already tracked.
const trackProduct = (state, productId) => {
    const id = toKey(productId);
    if (id === '' || state.products[id]) {
        return false;
    }
    state.products[id] = {prices: null, qty: null};
    return true;
};

// Applies one event payload to the state with the semantics of Hyvä's price.phtml; true when it changed.
const recordPriceEvent = (state, type, detail, productId) => {
    const global = state.global;
    switch (type) {
        case 'update-product-final-price':
            global.finalPrice = detail === undefined ? null : detail;
            return true;
        case 'update-custom-option-prices':
            // Every payload carries the prices of all option values and replaces the previous map.
            if (!detail || typeof detail !== 'object') {
                return false;
            }
            global.optionPrices = detail;
            return true;
        case 'update-custom-option-active': {
            const optionId = detail && typeof detail === 'object' ? toKey(detail.customOptionId) : '';
            if (optionId === '') {
                return false;
            }
            global.activeOptions[optionId] = !!detail.active;
            return true;
        }
        case 'update-bundle-option-prices':
            global.bundleOptionPrices = detail === undefined ? null : detail;
            return true;
        case 'configurable-selection-changed': {
            const configurableId = detail && typeof detail === 'object' ? toKey(detail.productId) : '';
            if (configurableId === '') {
                return false;
            }
            global.configurableSelection[configurableId] = detail;
            return true;
        }
        case 'update-prices':
        case 'update-qty': {
            const id = toKey(productId);
            if (id === '') {
                return false;
            }
            trackProduct(state, id);
            state.products[id][type === 'update-prices' ? 'prices' : 'qty'] = detail === undefined ? null : detail;
            return true;
        }
        default:
            return false;
    }
};
/* recorder:end */

export {PRICE_EVENTS, PRODUCT_EVENTS, createPriceState, trackProduct, recordPriceEvent};
