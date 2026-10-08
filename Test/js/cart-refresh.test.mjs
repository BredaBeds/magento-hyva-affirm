/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Tests the cart page refresh script (templates/js/cart-refresh.phtml) against a fake window, document and loader.
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {extractScript, readPackageFile} from './template-source.mjs';

const TEMPLATES = 'src/view/frontend/templates/js/';
const script = extractScript(readPackageFile(TEMPLATES + 'cart-refresh.phtml'));

const fakeLoader = (outcome = 'resolve') => {
    const calls = {load: 0, refresh: 0};
    return {
        calls,
        load() {
            calls.load += 1;
            return outcome === 'resolve' ? Promise.resolve({}) : Promise.reject(new Error('affirm.js failed'));
        },
        refresh() {
            calls.refresh += 1;
        }
    };
};

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

/** Runs the script with the given placement elements on the page; `page.elements` can be replaced later. */
const start = ({loader, elements = []}) => {
    const listeners = {};
    const page = {elements, selectors: []};
    const window = {
        hyvaAffirm: loader,
        addEventListener(type, listener) {
            (listeners[type] = listeners[type] || []).push(listener);
        }
    };
    const document = {
        querySelectorAll(selector) {
            page.selectors.push(selector);
            return page.elements;
        }
    };
    new Function('window', 'document', script)(window, document);
    const dispatch = (type, detail) => (listeners[type] || []).forEach((listener) => listener({type, detail}));
    return {window, page, dispatch, listeners};
};

const element = (name) => ({name});

test('listens to private-content-loaded and cart-is-loading only', () => {
    const {listeners} = start({loader: fakeLoader()});

    assert.deepEqual(Object.keys(listeners).sort(), ['cart-is-loading', 'private-content-loaded']);
});

test('placements present when the page loads are left to the loader', async () => {
    const loader = fakeLoader();
    const {dispatch} = start({loader, elements: [element('banner'), element('cross-sell')]});

    dispatch('private-content-loaded', {data: {}});
    dispatch('cart-is-loading', {isLoading: false});
    await flush();

    assert.deepEqual(loader.calls, {load: 0, refresh: 0});
});

test('new placements after #maincontent is replaced are loaded and refreshed once', async () => {
    const loader = fakeLoader();
    const {page, dispatch} = start({loader, elements: [element('banner')]});

    page.elements = [element('banner'), element('cross-sell')];
    dispatch('private-content-loaded', {data: {}});
    await flush();
    assert.deepEqual(loader.calls, {load: 1, refresh: 1});

    // cart-is-loading {isLoading: false} follows the same replacement: nothing new any more.
    dispatch('cart-is-loading', {isLoading: false});
    await flush();
    assert.deepEqual(loader.calls, {load: 1, refresh: 1});
});

test('a banner that first appears after a reload loads affirm.js', async () => {
    const loader = fakeLoader();
    const {page, dispatch} = start({loader});

    page.elements = [element('banner')];
    dispatch('cart-is-loading', {isLoading: true});
    await flush();
    assert.equal(loader.calls.load, 0, 'still loading');

    dispatch('cart-is-loading', {isLoading: false});
    await flush();
    assert.deepEqual(loader.calls, {load: 1, refresh: 1});
});

test('cart-is-loading without a finished state is ignored', async () => {
    const loader = fakeLoader();
    const {page, dispatch} = start({loader});

    page.elements = [element('banner')];
    dispatch('cart-is-loading', undefined);
    dispatch('cart-is-loading', {});
    await flush();

    assert.equal(loader.calls.load, 0);
});

test('placements seen while no loader exists are handled once the loader exists', async () => {
    const loader = fakeLoader();
    const {window, page, dispatch} = start({loader: undefined});

    page.elements = [element('banner')];
    assert.doesNotThrow(() => dispatch('private-content-loaded', {data: {}}));

    window.hyvaAffirm = loader;
    dispatch('private-content-loaded', {data: {}});
    await flush();
    assert.deepEqual(loader.calls, {load: 1, refresh: 1});
});

test('a failed load is swallowed and retried for the next new placements', async () => {
    const loader = fakeLoader('reject');
    const {page, dispatch} = start({loader});

    page.elements = [element('banner')];
    dispatch('private-content-loaded', {data: {}});
    await flush();
    assert.deepEqual(loader.calls, {load: 1, refresh: 0});

    page.elements = [element('banner after the next reload')];
    dispatch('private-content-loaded', {data: {}});
    await flush();
    assert.deepEqual(loader.calls, {load: 2, refresh: 0});
});

test('selects the same placements as the loader auto-load, never component elements', () => {
    const {page} = start({loader: fakeLoader()});
    const loaderSource = readPackageFile(TEMPLATES + 'sdk-loader.phtml');
    const match = loaderSource.match(/const selector = '([^']+)';/);

    assert.ok(match, 'the loader declares its auto-load selector');
    assert.deepEqual(new Set(page.selectors), new Set([match[1]]));
    assert.ok(match[1].includes('.affirm-as-low-as:not([data-hyva-affirm-lazy])'));
});
