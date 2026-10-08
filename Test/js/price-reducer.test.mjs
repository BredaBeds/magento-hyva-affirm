/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * The product message reducer: identical to the template's copy, and its behaviour on the event payloads of
 * stock Hyvä and of themes that send custom option prices as {final, regular} objects.
 */
import assert from 'node:assert/strict';
import {describe, it} from 'node:test';
import {createPriceState, recordPriceEvent} from './price-recorder.mjs';
import {isBackorderedSelection, isDisplayable, reduceAmount, toCents, toPrice} from './price-reducer.mjs';
import {extractBlock, readPackageFile} from './template-source.mjs';

const TEMPLATE = 'src/view/frontend/templates/js/product-message.phtml';

/** State after a sequence of [type, detail, productId?] events. */
const stateAfter = (events) => {
    const state = createPriceState();
    events.forEach(([type, detail, productId]) => recordPriceEvent(state, type, detail, productId));
    return state;
};

const active = (customOptionId, isActive = true) => ['update-custom-option-active', {customOptionId, active: isActive}];

describe('reducer source', () => {
    it('is identical in the template and in the test copy', () => {
        assert.equal(
            extractBlock(readPackageFile(TEMPLATE), 'reducer'),
            extractBlock(readPackageFile('Test/js/price-reducer.mjs'), 'reducer'),
        );
    });
});

describe('toPrice', () => {
    it('reads numbers, numeric strings, {final} and {amount}', () => {
        assert.equal(toPrice(12.5), 12.5);
        assert.equal(toPrice('12.50'), 12.5);
        assert.equal(toPrice({final: 30, regular: 40}), 30);
        assert.equal(toPrice({final: '7.25', regular: '9'}), 7.25);
        assert.equal(toPrice({amount: 99}), 99);
    });

    it('returns NaN for values without a price', () => {
        for (const value of [undefined, null, '', 'abc', {}, {regular: 5}, [], true, Infinity]) {
            assert.ok(Number.isNaN(toPrice(value)), JSON.stringify(value));
        }
    });
});

describe('reduceAmount', () => {
    it('falls back to the server price without any event', () => {
        assert.equal(reduceAmount(createPriceState(), '1', 1299.99, 'finalPrice'), 1299.99);
        assert.equal(reduceAmount(undefined, '1', 1299.99, 'finalPrice'), 1299.99);
        assert.equal(reduceAmount(null, '1', 0, 'finalPrice'), 0);
    });

    it('adds active option prices given as {final, regular} when no final price event fires', () => {
        // A simple product without a quantity field: Hyvä never dispatches update-product-final-price.
        const state = stateAfter([
            ['update-custom-option-prices', {
                '11': {final: 0, regular: 0},
                '11_101': {final: 150, regular: 200},
                '11_102': {final: 300, regular: 400},
                '12_201': {final: 49.99, regular: 59.99},
            }],
            active('11_102'),
            active('12_201'),
        ]);

        assert.equal(toCents(reduceAmount(state, '7', 2199, 'finalPrice')), 254899);
    });

    it('uses the latest final price event instead of the server price', () => {
        const state = stateAfter([['update-product-final-price', 1100], ['update-product-final-price', 1050.5]]);

        assert.equal(reduceAmount(state, '7', 2199, 'finalPrice'), 1050.5);
    });

    it('ignores inactive, unknown and priceless options', () => {
        const state = stateAfter([
            ['update-custom-option-prices', {'1': '10', '2': '20', '3': 'n/a'}],
            active('1'),
            active('2'),
            active('2', false),
            active('3'),
            active('99'),
            ['update-custom-option-active', {customOptionId: undefined, active: true}],
        ]);

        assert.equal(reduceAmount(state, '7', 100, 'finalPrice'), 110);
    });

    it('accepts numeric strings and numbers in the option map', () => {
        const state = stateAfter([
            ['update-custom-option-prices', {'4': '12.50', '5_6': 7, '8': '0'}],
            active('4'),
            active('5_6'),
            active(8),
        ]);

        assert.equal(reduceAmount(state, '7', '100.00', 'finalPrice'), 119.5);
    });

    it('replaces the option price map on every update instead of merging it', () => {
        const state = stateAfter([
            ['update-custom-option-prices', {'1': 10, '2': 20}],
            active('1'),
            active('2'),
            ['update-custom-option-prices', {'1': 15}],
        ]);

        assert.equal(reduceAmount(state, '7', 100, 'finalPrice'), 115);
    });

    it('adds a paid custom option to a bundle without counting the bundle selections twice', () => {
        // Hyvä's bundle price includes the selected items in update-product-final-price.
        const state = stateAfter([
            ['update-bundle-option-prices', {oldPrice: 140, finalPrice: 120, basePrice: 120}],
            ['update-product-final-price', 150],
            ['update-custom-option-prices', {'5': '20.00'}],
            active('5'),
        ]);

        assert.equal(reduceAmount(state, '9', 80, 'finalPrice'), 170);
    });

    it('uses the configurable selection prices when no final price event was recorded', () => {
        const prices = {
            oldPrice: {amount: 120},
            basePrice: {amount: 90},
            finalPrice: {amount: 99},
            tierPrices: [],
            isMinimalPrice: false,
        };
        const state = stateAfter([['update-prices', prices, '12']]);

        assert.equal(reduceAmount(state, '12', 79, 'finalPrice'), 99);
        assert.equal(reduceAmount(state, '12', 79, 'basePrice'), 90);
        assert.equal(reduceAmount(state, '12', 79, undefined), 99);
        assert.equal(reduceAmount(state, '13', 79, 'finalPrice'), 79);
    });

    it('prefers the final price event of the configurable flow, which includes tier prices', () => {
        const state = stateAfter([
            ['update-prices', {finalPrice: {amount: 99}, basePrice: {amount: 90}}, '12'],
            ['update-qty', 5, '12'],
            ['update-product-final-price', 89],
        ]);

        assert.equal(reduceAmount(state, '12', 79, 'finalPrice'), 89);
    });

    it('treats a zero final price like price.phtml does: the fallback applies', () => {
        const state = stateAfter([['update-product-final-price', 0]]);

        assert.equal(reduceAmount(state, '7', 55, 'finalPrice'), 55);
    });

    it('does not multiply by the quantity', () => {
        const state = stateAfter([['update-qty', 3, '7']]);

        assert.equal(reduceAmount(state, '7', 55, 'finalPrice'), 55);
    });
});

describe('toCents', () => {
    it('rounds to whole cents', () => {
        assert.equal(toCents(2149.99), 214999);
        assert.equal(toCents(0.1 + 0.2), 30);
        assert.equal(toCents(19.995), 2000);
        assert.equal(toCents(NaN), 0);
        assert.equal(toCents(Infinity), 0);
    });
});

describe('isDisplayable', () => {
    const limits = {min: 5000, max: 1750000, minMpp: 10000};

    it('applies inclusive bounds and min_mpp (equal shows)', () => {
        assert.equal(isDisplayable(10000, limits), true);
        assert.equal(isDisplayable(9999, limits), false);
        assert.equal(isDisplayable(1750000, limits), true);
        assert.equal(isDisplayable(1750001, limits), false);
        assert.equal(isDisplayable(5000, {min: 5000, max: 1750000, minMpp: null}), true);
        assert.equal(isDisplayable(4999, {min: 5000, max: 1750000, minMpp: null}), false);
    });

    it('treats missing bounds as unbounded but never shows zero', () => {
        assert.equal(isDisplayable(1, {}), true);
        assert.equal(isDisplayable(99999999, undefined), true);
        assert.equal(isDisplayable(0, {}), false);
        assert.equal(isDisplayable(-100, {}), false);
    });
});

describe('isBackorderedSelection', () => {
    it('hides when the complete selection is a backordered child', () => {
        const selection = {productId: '12', productIndex: '31', candidates: ['31']};

        assert.equal(isBackorderedSelection(selection, [31, 33]), true);
        assert.equal(isBackorderedSelection({...selection, productIndex: '32', candidates: ['32']}, [31, 33]), false);
    });

    it('hides a partial selection only when every candidate is backordered', () => {
        assert.equal(isBackorderedSelection({productIndex: '31', candidates: ['31', '32']}, [31]), false);
        assert.equal(isBackorderedSelection({productIndex: '31', candidates: ['31', '33']}, [31, 33]), true);
    });

    it('falls back to productIndex when the payload has no candidates', () => {
        assert.equal(isBackorderedSelection({productIndex: '31'}, [31]), true);
        assert.equal(isBackorderedSelection({productIndex: 0}, [31]), false);
    });

    it('never hides without a selection or backordered children', () => {
        assert.equal(isBackorderedSelection(null, [31]), false);
        assert.equal(isBackorderedSelection(undefined, [31]), false);
        assert.equal(isBackorderedSelection({productIndex: '31', candidates: ['31']}, []), false);
        assert.equal(isBackorderedSelection({productIndex: '31', candidates: ['31']}, undefined), false);
    });
});
