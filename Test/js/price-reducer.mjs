/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Test copy of the pure functions in src/view/frontend/templates/js/product-message.phtml. The code between the
 * reducer markers must stay identical to the template's (apart from indentation); price-reducer.test.mjs checks it.
 */
/* reducer:start */
// A price from a Hyvä price event: a number, a numeric string, {final} (custom option prices of themes that
// also carry the regular price) or {amount} (Magento price objects). NaN when the value holds no price.
const toPrice = (value) => {
    let price = value;
    if (price !== null && typeof price === 'object') {
        price = price.final !== undefined ? price.final : price.amount;
    }
    price = typeof price === 'number' ? price : Number.parseFloat(price);
    return Number.isFinite(price) ? price : NaN;
};

// The unit price the product page shows, reduced like Hyvä's price.phtml: the latest update-product-final-price
// (it includes the tier price for the quantity and, on bundles, the selected bundle items), else the price of the
// configurable's current selection, else the server-rendered price; plus the prices of the ACTIVE custom options
// from the latest option price map. The quantity is not multiplied in (as in Affirm's Luma widget).
const reduceAmount = (state, productId, serverPrice, priceKey) => {
    const global = (state && state.global) || {};
    const product = (state && state.products && state.products[productId]) || {};
    let unit = toPrice(global.finalPrice);
    if (!unit && product.prices && typeof product.prices === 'object') {
        unit = toPrice(product.prices[priceKey || 'finalPrice']);
    }
    if (!unit) {
        unit = toPrice(serverPrice) || 0;
    }
    const optionPrices = global.optionPrices && typeof global.optionPrices === 'object' ? global.optionPrices : {};
    const activeOptions = global.activeOptions || {};
    return Object.keys(activeOptions).reduce((amount, optionId) => {
        const price = activeOptions[optionId] && Object.prototype.hasOwnProperty.call(optionPrices, optionId)
            ? toPrice(optionPrices[optionId])
            : NaN;
        return Number.isFinite(price) ? amount + price : amount;
    }, unit);
};

// Whole cents, half up; the intermediate rounding absorbs binary floating point error (19.995 -> 2000).
const toCents = (amount) => (Number.isFinite(amount) ? Math.round(Number((amount * 100).toFixed(4))) : 0);

// Affirm's amount rules for the message: inclusive bounds in cents (null = none) and min_mpp (equal shows).
const isDisplayable = (cents, limits) => {
    const bound = (value) => (typeof value === 'number' && Number.isFinite(value) ? value : null);
    const min = bound(limits && limits.min);
    const max = bound(limits && limits.max);
    const minMpp = bound(limits && limits.minMpp);
    return cents > 0
        && (min === null || cents >= min)
        && (max === null || cents <= max)
        && (minMpp === null || cents >= minMpp);
};

// Affirm hides the message for backordered configurable children: true when every product the current
// selection can still resolve to is backordered (for a complete selection, the selected child).
const isBackorderedSelection = (selection, backorderedIds) => {
    if (!selection || !Array.isArray(backorderedIds) || backorderedIds.length === 0) {
        return false;
    }
    const backordered = backorderedIds.map(String);
    const candidates = Array.isArray(selection.candidates) ? selection.candidates : [];
    if (candidates.length > 0) {
        return candidates.every((id) => backordered.includes(String(id)));
    }
    return !!selection.productIndex && backordered.includes(String(selection.productIndex));
};
/* reducer:end */

export {toPrice, reduceAmount, toCents, isDisplayable, isBackorderedSelection};
