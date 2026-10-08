/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Tests the cart page and minicart Alpine components (templates/js/cart-message.phtml and
 * templates/js/minicart-message.phtml) by running their scripts against a minimal fake DOM, Alpine and loader.
 */
import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const TEMPLATES = new URL('../../src/view/frontend/templates/js/', import.meta.url);

const readTemplate = (file) => readFileSync(new URL(file, TEMPLATES), 'utf8');

const scriptOf = (file) => {
    const match = readTemplate(file).match(/<script>\n([\s\S]*?)<\/script>/);
    assert.ok(match, `${file} contains one inline script`);
    return match[1];
};

class FakeClassList {
    constructor(classes) {
        this.classes = new Set(classes);
    }

    contains(name) {
        return this.classes.has(name);
    }

    toggle(name, force) {
        const add = force === undefined ? !this.classes.has(name) : Boolean(force);
        if (add) {
            this.classes.add(name);
        } else {
            this.classes.delete(name);
        }
        return add;
    }
}

class FakeElement {
    constructor(attributes = {}, classes = [], child = null) {
        this.attributes = {...attributes};
        this.classList = new FakeClassList(classes);
        this.children = [];
        this.textContent = '';
        this.child = child;
    }

    getAttribute(name) {
        return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null;
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    removeAttribute(name) {
        delete this.attributes[name];
    }

    querySelector(selector) {
        assert.equal(selector, '.affirm-as-low-as');
        return this.child;
    }
}

class FakeMutationObserver {
    constructor(callback) {
        this.callback = callback;
        FakeMutationObserver.last = this;
    }

    observe(target, options) {
        this.target = target;
        this.options = options;
    }

    disconnect() {
        this.disconnected = true;
    }

    fire() {
        this.callback([]);
    }
}

const fakeLoader = (outcome = 'resolve') => {
    const calls = {load: 0, refresh: 0, whenIdle: 0};
    return {
        calls,
        load() {
            calls.load += 1;
            return outcome === 'resolve' ? Promise.resolve({}) : Promise.reject(new Error('affirm.js failed'));
        },
        refresh() {
            calls.refresh += 1;
        },
        whenIdle(fn) {
            calls.whenIdle += 1;
            return Promise.resolve().then(fn);
        }
    };
};

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

/**
 * Runs a component script and returns a factory for component instances bound to a fresh DOM.
 */
const loadComponent = (file, name, loader) => {
    const registered = {};
    const window = {
        Alpine: {data: (componentName, factory) => { registered[componentName] = factory; }},
        hyvaAffirm: loader,
        addEventListener: () => assert.fail('Alpine exists, so the component registers immediately')
    };
    new Function('window', 'MutationObserver', scriptOf(file))(window, FakeMutationObserver);
    assert.equal(typeof registered[name], 'function', `${name} is registered with Alpine.data`);
    return registered[name];
};

const mount = (factory, {rootAttributes = {}, messageClasses = [], scope = {}} = {}) => {
    const message = new FakeElement({'data-amount': '150000', 'data-promo-id': 'SERVER'}, [
        'affirm-as-low-as', 'invisible', ...messageClasses
    ]);
    const root = new FakeElement(rootAttributes, [], message);
    const component = Object.assign(factory(), scope, {$root: root, $nextTick: (fn) => fn()});
    component.init();
    return {component, message, observer: FakeMutationObserver.last};
};

const cartConfig = (cartRule) => JSON.stringify({
    limits: {min: 100000, max: 1750000, minMpp: null},
    cartRule: cartRule ?? {
        min: 1500, max: 5000, promoInRange: 'CART_SIZE', promoOutOfRange: 'DATED', higherPrecedenceApplies: false
    }
});

const totalsEvent = (grandTotal, baseGrandTotal) => ({
    detail: {data: {grand_total: grandTotal, base_grand_total: baseGrandTotal}}
});

test('cart helpers mirror the server rules', () => {
    const source = readTemplate('cart-message.phtml');
    const start = source.indexOf('/* hyva-affirm-cart:start */');
    const end = source.indexOf('/* hyva-affirm-cart:end */');
    assert.ok(start > 0 && end > start);
    const {toCents, isInRange, selectPromoId} = new Function(
        source.slice(start, end) + '\nreturn {toCents, isInRange, selectPromoId};'
    )();

    assert.equal(toCents(1234.5), 123450);
    assert.equal(toCents('999.999'), 100000);
    assert.equal(toCents('abc'), null);
    assert.equal(toCents(undefined), null);

    const limits = {min: 100000, max: 1750000};
    assert.equal(isInRange(100000, limits), true);
    assert.equal(isInRange(99999, limits), false);
    assert.equal(isInRange(1750000, limits), true);
    assert.equal(isInRange(1750001, limits), false);
    assert.equal(isInRange(5, {min: null, max: null}), true);
    assert.equal(isInRange(null, limits), false);
    assert.equal(isInRange(150000, undefined), false);

    const rule = {min: 1500, max: 5000, promoInRange: 'IN', promoOutOfRange: 'OUT', higherPrecedenceApplies: false};
    assert.equal(selectPromoId(rule, 1500), 'IN', 'minimum is inclusive');
    assert.equal(selectPromoId(rule, '5000.00'), 'IN', 'maximum is inclusive');
    assert.equal(selectPromoId(rule, 1499.99), 'OUT');
    assert.equal(selectPromoId(rule, 5000.01), 'OUT');
    assert.equal(selectPromoId({...rule, higherPrecedenceApplies: true}, 2000), null);
    assert.equal(selectPromoId({...rule, promoOutOfRange: 'IN'}, 100), null, 'equal promo ids never switch');
    assert.equal(selectPromoId({...rule, min: null}, 2000), null);
    assert.equal(selectPromoId(rule, undefined), null);
    assert.equal(selectPromoId(null, 2000), null);
});

test('cart message loads affirm.js after idle and shows once affirm.js rendered', async () => {
    const loader = fakeLoader();
    const factory = loadComponent('cart-message.phtml', 'hyvaAffirmCartMessage', loader);
    const {message, observer} = mount(factory, {rootAttributes: {'data-hyva-affirm-cart': cartConfig()}});

    assert.equal(loader.calls.whenIdle, 1);
    await flush();
    assert.equal(loader.calls.load, 1);
    assert.equal(loader.calls.refresh, 1);
    assert.equal(message.classList.contains('hidden'), false);
    assert.equal(message.classList.contains('invisible'), true);

    message.textContent = 'Starting at $90/mo with Affirm.';
    observer.fire();
    assert.equal(message.classList.contains('invisible'), false);
});

test('cart message out of range at render does not load affirm.js', async () => {
    const loader = fakeLoader();
    const factory = loadComponent('cart-message.phtml', 'hyvaAffirmCartMessage', loader);
    mount(factory, {rootAttributes: {'data-hyva-affirm-cart': cartConfig()}, messageClasses: ['hidden']});
    await flush();

    assert.equal(loader.calls.whenIdle + loader.calls.load, 0);
});

test('cart message follows update-totals: amount, bounds and the cart-size promo id', async () => {
    const loader = fakeLoader();
    const factory = loadComponent('cart-message.phtml', 'hyvaAffirmCartMessage', loader);
    const {component, message} = mount(factory, {rootAttributes: {'data-hyva-affirm-cart': cartConfig()}});
    await flush();

    component.$event = totalsEvent(2000.5, 2000.5);
    component.onUpdateTotals();
    await flush();
    assert.equal(message.getAttribute('data-amount'), '200050');
    assert.equal(message.getAttribute('data-promo-id'), 'CART_SIZE');
    assert.equal(message.classList.contains('hidden'), false);
    assert.ok(loader.calls.refresh >= 2);

    component.$event = totalsEvent('6000.00', '6000.00');
    component.onUpdateTotals();
    assert.equal(message.getAttribute('data-promo-id'), 'DATED');

    component.$event = totalsEvent(900, 900);
    component.onUpdateTotals();
    assert.equal(message.getAttribute('data-amount'), '90000');
    assert.equal(message.classList.contains('hidden'), true, 'below min_order_total');

    component.$event = {detail: {}};
    component.onUpdateTotals();
    assert.equal(message.getAttribute('data-amount'), '90000', 'events without totals are ignored');
});

test('cart message keeps the server promo id when the rule must not switch', async () => {
    const factory = loadComponent('cart-message.phtml', 'hyvaAffirmCartMessage', fakeLoader());
    const rule = {min: 1500, max: 5000, promoInRange: 'FINAL', promoOutOfRange: 'FINAL', higherPrecedenceApplies: true};
    const {component, message} = mount(factory, {rootAttributes: {'data-hyva-affirm-cart': cartConfig(rule)}});

    component.$event = totalsEvent(2000, 2000);
    component.onUpdateTotals();
    assert.equal(message.getAttribute('data-promo-id'), 'SERVER');
});

test('cart message removes an empty promo id and collapses when affirm.js fails', async () => {
    const loader = fakeLoader('reject');
    const factory = loadComponent('cart-message.phtml', 'hyvaAffirmCartMessage', loader);
    const rule = {min: 1500, max: 5000, promoInRange: 'IN', promoOutOfRange: '', higherPrecedenceApplies: false};
    const {component, message, observer} = mount(factory, {rootAttributes: {'data-hyva-affirm-cart': cartConfig(rule)}});
    await flush();
    assert.equal(message.classList.contains('hidden'), true);

    component.$event = totalsEvent(6000, 6000);
    component.onUpdateTotals();
    assert.equal(message.getAttribute('data-promo-id'), null);

    component.destroy();
    assert.equal(observer.disconnected, true);
});

test('cart message refreshes when the cart finished loading', async () => {
    const loader = fakeLoader();
    const factory = loadComponent('cart-message.phtml', 'hyvaAffirmCartMessage', loader);
    const {component} = mount(factory, {rootAttributes: {'data-hyva-affirm-cart': cartConfig()}});
    await flush();
    const refreshes = loader.calls.refresh;

    component.$event = {detail: {isLoading: true}};
    component.onCartIsLoading();
    assert.equal(loader.calls.refresh, refreshes);
    component.$event = {detail: {isLoading: false}};
    component.onCartIsLoading();
    assert.equal(loader.calls.refresh, refreshes + 1);
});

const sectionCart = (overrides = {}) => ({
    affirm_amount: 150000,
    affirm_minicart: {available: true, limits: {min: 100000, max: 1750000, minMpp: null}},
    allow_affirm_quote_aslowas: true,
    mfpValue: 'MINI',
    learnMore: 'false',
    ...overrides
});

test('minicart message applies the drawer section data on init', async () => {
    const loader = fakeLoader();
    const factory = loadComponent('minicart-message.phtml', 'hyvaAffirmMinicartMessage', loader);
    const {message} = mount(factory, {messageClasses: ['hidden'], scope: {cart: sectionCart(), open: false}});

    assert.equal(message.getAttribute('data-amount'), '150000');
    assert.equal(message.getAttribute('data-promo-id'), 'MINI');
    assert.equal(message.getAttribute('data-learnmore-show'), 'false');
    assert.equal(message.classList.contains('hidden'), false);
    assert.equal(loader.calls.load, 0, 'affirm.js waits for the drawer to open');
});

test('minicart message is hidden unless every eligibility condition holds', () => {
    const factory = loadComponent('minicart-message.phtml', 'hyvaAffirmMinicartMessage', fakeLoader());
    const ineligible = [
        sectionCart({affirm_minicart: {available: false, limits: {min: 100000, max: 1750000}}}),
        sectionCart({allow_affirm_quote_aslowas: false}),
        sectionCart({affirm_amount: 99999}),
        sectionCart({affirm_amount: '150000'}),
        sectionCart({affirm_minicart: undefined}),
        {}
    ];
    for (const cart of ineligible) {
        const {message} = mount(factory, {messageClasses: ['hidden'], scope: {cart}});
        assert.equal(message.classList.contains('hidden'), true, JSON.stringify(cart));
    }
    const {message} = mount(factory, {scope: {cart: sectionCart({mfpValue: ''})}});
    assert.equal(message.getAttribute('data-promo-id'), null, 'blank promo id below min_mpp is removed');
});

test('minicart message requests affirm.js when the drawer opens', async () => {
    const loader = fakeLoader();
    const factory = loadComponent('minicart-message.phtml', 'hyvaAffirmMinicartMessage', loader);
    const {component} = mount(factory, {scope: {cart: sectionCart()}});

    component.$event = {detail: {isOpen: false}};
    component.onToggleCart();
    assert.equal(loader.calls.load, 0);

    component.$event = {detail: {isOpen: true}};
    component.onToggleCart();
    component.$event = {};
    component.onToggleCart();
    assert.equal(loader.calls.load, 2);
    await flush();
    assert.ok(loader.calls.refresh >= 1);

    mount(factory, {scope: {cart: sectionCart(), open: true}});
    assert.equal(loader.calls.load, 3, 'mounted inside an open drawer');
});

test('minicart message re-applies section data after private-content-loaded', () => {
    const factory = loadComponent('minicart-message.phtml', 'hyvaAffirmMinicartMessage', fakeLoader());
    const {component, message} = mount(factory, {scope: {cart: sectionCart()}});

    component.cart = sectionCart({affirm_amount: 1800000});
    component.onPrivateContentLoaded();
    assert.equal(message.getAttribute('data-amount'), '1800000');
    assert.equal(message.classList.contains('hidden'), true, 'above Affirm\'s maximum');
});

test('minicart message collapses when affirm.js fails to load', async () => {
    const factory = loadComponent('minicart-message.phtml', 'hyvaAffirmMinicartMessage', fakeLoader('reject'));
    const {message} = mount(factory, {scope: {cart: sectionCart(), open: true}});
    await flush();

    assert.equal(message.classList.contains('hidden'), true);
});
