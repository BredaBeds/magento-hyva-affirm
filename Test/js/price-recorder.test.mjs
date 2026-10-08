/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * The price-state recorder: identical rules in the template and the test copy, the rules themselves, and the
 * template's script run against a minimal window/document.
 */
import assert from 'node:assert/strict';
import {beforeEach, describe, it} from 'node:test';
import vm from 'node:vm';
import {createPriceState, recordPriceEvent, trackProduct} from './price-recorder.mjs';
import {extractBlock, extractScript, readPackageFile} from './template-source.mjs';

const TEMPLATE = 'src/view/frontend/templates/js/price-state-recorder.phtml';

// Objects created inside the vm context have that context's prototypes; compare their plain data.
const plain = (value) => JSON.parse(JSON.stringify(value));

describe('recorder source', () => {
    it('is identical in the template and in the test copy', () => {
        assert.equal(
            extractBlock(readPackageFile(TEMPLATE), 'recorder'),
            extractBlock(readPackageFile('Test/js/price-recorder.mjs'), 'recorder'),
        );
    });
});

describe('recordPriceEvent', () => {
    it('starts with the contract v1 shape', () => {
        assert.deepEqual(createPriceState(), {
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
    });

    it('keeps the latest final price, bundle prices and option map', () => {
        const state = createPriceState();
        recordPriceEvent(state, 'update-product-final-price', 10);
        recordPriceEvent(state, 'update-product-final-price', 12);
        recordPriceEvent(state, 'update-bundle-option-prices', {finalPrice: 3});
        recordPriceEvent(state, 'update-custom-option-prices', {'1': 5, '2': 6});
        recordPriceEvent(state, 'update-custom-option-prices', {'1': 7});

        assert.equal(state.global.finalPrice, 12);
        assert.deepEqual(state.global.bundleOptionPrices, {finalPrice: 3});
        assert.deepEqual(state.global.optionPrices, {'1': 7});
    });

    it('ignores option price payloads without a map', () => {
        const state = createPriceState();
        recordPriceEvent(state, 'update-custom-option-prices', {'1': 5});

        assert.equal(recordPriceEvent(state, 'update-custom-option-prices', null), false);
        assert.equal(recordPriceEvent(state, 'update-custom-option-prices', '5'), false);
        assert.deepEqual(state.global.optionPrices, {'1': 5});
    });

    it('accumulates active custom options and ignores the select placeholder', () => {
        const state = createPriceState();
        recordPriceEvent(state, 'update-custom-option-active', {customOptionId: '1', active: true});
        recordPriceEvent(state, 'update-custom-option-active', {customOptionId: 2, active: true});
        recordPriceEvent(state, 'update-custom-option-active', {customOptionId: '1', active: false});

        assert.equal(recordPriceEvent(state, 'update-custom-option-active', {customOptionId: undefined, active: true}), false);
        assert.equal(recordPriceEvent(state, 'update-custom-option-active', null), false);
        assert.deepEqual(state.global.activeOptions, {'1': false, '2': true});
    });

    it('keeps the configurable selection per configurable product', () => {
        const state = createPriceState();
        const detail = {productId: '12', productIndex: '31', candidates: ['31']};
        recordPriceEvent(state, 'configurable-selection-changed', detail);

        assert.equal(recordPriceEvent(state, 'configurable-selection-changed', {productIndex: '31'}), false);
        assert.deepEqual(state.global.configurableSelection, {'12': detail});
    });

    it('keeps prices and quantity per product', () => {
        const state = createPriceState();
        recordPriceEvent(state, 'update-prices', {finalPrice: {amount: 9}}, '12');
        recordPriceEvent(state, 'update-qty', 2, 12);

        assert.deepEqual(state.products, {'12': {prices: {finalPrice: {amount: 9}}, qty: 2}});
        assert.equal(recordPriceEvent(state, 'update-qty', 2, ''), false);
        assert.equal(recordPriceEvent(state, 'unknown-event', 2), false);
    });

    it('tracks a product once', () => {
        const state = createPriceState();

        assert.equal(trackProduct(state, 7), true);
        assert.equal(trackProduct(state, '7'), false);
        assert.equal(trackProduct(state, ''), false);
        assert.equal(trackProduct(state, null), false);
        assert.deepEqual(state.products, {'7': {prices: null, qty: null}});
    });
});

describe('recorder script', () => {
    let window;
    let notifications;

    const runRecorder = (productIds) => {
        const script = extractScript(readPackageFile(TEMPLATE));
        const document = {
            querySelectorAll: (selector) => {
                assert.equal(selector, '[data-hyva-affirm-product-id]');
                return productIds.map((id) => ({dataset: {hyvaAffirmProductId: id}}));
            },
        };
        vm.runInNewContext(script, {window, document, CustomEvent});
    };
    const dispatch = (type, detail) => window.dispatchEvent(new CustomEvent(type, {detail}));

    beforeEach(() => {
        window = new EventTarget();
        notifications = [];
        window.addEventListener('hyva-affirm:price-state', (event) => notifications.push(event.detail));
    });

    it('records the events of the products on the page and announces each change', () => {
        runRecorder(['7']);
        const state = window.hyvaAffirmPriceState;

        dispatch('update-custom-option-prices', {'1': {final: 10, regular: 12}});
        dispatch('update-custom-option-active', {customOptionId: '1', active: true});
        dispatch('update-prices-7', {finalPrice: {amount: 5}});
        dispatch('update-qty-7', 3);
        dispatch('update-prices-8', {finalPrice: {amount: 6}});
        dispatch('update-custom-option-active', {customOptionId: undefined, active: true});

        assert.deepEqual(plain(state.global.optionPrices), {'1': {final: 10, regular: 12}});
        assert.deepEqual(plain(state.global.activeOptions), {'1': true});
        assert.deepEqual(plain(state.products), {'7': {prices: {finalPrice: {amount: 5}}, qty: 3}});
        assert.deepEqual(plain(notifications), [
            {type: 'update-custom-option-prices', productId: null},
            {type: 'update-custom-option-active', productId: null},
            {type: 'update-prices', productId: '7'},
            {type: 'update-qty', productId: '7'},
        ]);
    });

    it('tracks products requested later', () => {
        runRecorder([]);

        dispatch('update-prices-9', {finalPrice: {amount: 1}});
        dispatch('hyva-affirm:price-state-track', {productId: 9});
        dispatch('hyva-affirm:price-state-track', {productId: 9});
        dispatch('update-prices-9', {finalPrice: {amount: 2}});

        assert.deepEqual(plain(window.hyvaAffirmPriceState.products), {'9': {prices: {finalPrice: {amount: 2}}, qty: null}});
        assert.equal(notifications.length, 1);
    });

    it('installs itself once per page', () => {
        runRecorder(['7']);
        const state = window.hyvaAffirmPriceState;
        runRecorder(['7']);
        dispatch('update-product-final-price', 42);

        assert.equal(window.hyvaAffirmPriceState, state);
        assert.equal(state.global.finalPrice, 42);
        assert.equal(notifications.length, 1);
    });
});
