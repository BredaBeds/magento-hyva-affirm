/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * The Alpine component hyvaAffirmProductMessage, run from the templates (recorder + component) against a
 * minimal window, Alpine, loader and message element.
 */
import assert from 'node:assert/strict';
import {beforeEach, describe, it} from 'node:test';
import vm from 'node:vm';
import {extractScript, readPackageFile} from './template-source.mjs';

const RECORDER = extractScript(readPackageFile('src/view/frontend/templates/js/price-state-recorder.phtml'));
const COMPONENT = extractScript(readPackageFile('src/view/frontend/templates/js/product-message.phtml'));
const LIMITS = {min: 100000, max: 1750000, minMpp: null};

class FakeClassList {
    constructor(names) {
        this.names = new Set(names);
    }

    add(name) {
        this.names.add(name);
    }

    remove(name) {
        this.names.delete(name);
    }

    contains(name) {
        return this.names.has(name);
    }

    toggle(name, force) {
        const on = force === undefined ? !this.names.has(name) : !!force;
        if (on) {
            this.add(name);
        } else {
            this.remove(name);
        }
        return on;
    }
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

describe('hyvaAffirmProductMessage', () => {
    let window;
    let context;
    let observers;
    let loader;

    const createLoader = (fail = false) => ({
        loads: 0,
        refreshes: 0,
        idleCalls: 0,
        whenIdle(fn) {
            this.idleCalls++;
            return Promise.resolve().then(fn);
        },
        load() {
            this.loads++;
            return fail ? Promise.reject(new Error('Affirm: affirm.js failed to load')) : Promise.resolve({});
        },
        refresh() {
            this.refreshes++;
        },
    });

    const createElement = (productId, cents, config = {}, classes = []) => ({
        dataset: {
            hyvaAffirmProductId: productId,
            amount: String(cents),
            hyvaAffirmConfig: JSON.stringify({...LIMITS, backordered: [], priceKey: 'finalPrice', ...config}),
            hyvaAffirmLazy: '',
        },
        classList: new FakeClassList(['affirm-as-low-as', 'hyva-affirm-message', 'invisible', ...classes]),
        children: [],
        textContent: '',
    });

    /** Runs the page scripts in order: recorder (with the elements on the page), then the component. */
    const runScripts = (elements) => {
        context.document = {
            querySelectorAll: () => elements.map((element) => ({dataset: element.dataset})),
        };
        vm.runInContext(RECORDER, context);
        vm.runInContext(COMPONENT, context);
    };

    const mount = (element) => {
        const component = window.Alpine.components.hyvaAffirmProductMessage();
        component.$el = element;
        component.init();
        return component;
    };

    const dispatch = (type, detail) => window.dispatchEvent(new CustomEvent(type, {detail}));

    /** affirm.js renders the message text into the element. */
    const affirmRenders = (element) => {
        element.textContent = 'Starting at $93/mo with Affirm. Learn more';
        observers.forEach((observer) => observer.target === element && observer.callback([]));
    };

    beforeEach(() => {
        window = new EventTarget();
        observers = [];
        loader = createLoader();
        window.hyvaAffirm = loader;
        window.Alpine = {
            components: {},
            data(name, factory) {
                this.components[name] = factory;
            },
        };
        class MutationObserver {
            constructor(callback) {
                this.callback = callback;
                this.target = null;
                observers.push(this);
            }

            observe(target) {
                this.target = target;
            }

            disconnect() {
                this.target = null;
            }
        }
        context = vm.createContext({window, CustomEvent, MutationObserver});
    });

    it('registers on alpine:init when Alpine has not started yet', () => {
        const alpine = window.Alpine;
        delete window.Alpine;
        runScripts([]);
        assert.equal(alpine.components.hyvaAffirmProductMessage, undefined);

        window.Alpine = alpine;
        dispatch('alpine:init');

        assert.equal(typeof alpine.components.hyvaAffirmProductMessage, 'function');
    });

    it('requests affirm.js after idle for a displayable amount and reveals the rendered message', async () => {
        const element = createElement('7', 219900);
        runScripts([element]);
        mount(element);
        await flush();

        assert.equal(loader.idleCalls, 1);
        assert.equal(loader.loads, 1);
        assert.equal(loader.refreshes, 1);
        assert.equal(element.dataset.amount, '219900');
        assert.equal(element.classList.contains('hidden'), false);
        assert.equal(element.classList.contains('invisible'), true);

        affirmRenders(element);

        assert.equal(element.classList.contains('invisible'), false);
    });

    it('follows custom option prices given as objects when no final price event fires', async () => {
        const element = createElement('7', 219900);
        runScripts([element]);
        mount(element);
        await flush();

        dispatch('update-custom-option-prices', {'11_102': {final: 300, regular: 400}, '12_201': {final: 49.99, regular: 59.99}});
        dispatch('update-custom-option-active', {customOptionId: '11_102', active: true});
        dispatch('update-custom-option-active', {customOptionId: '12_201', active: true});

        assert.equal(element.dataset.amount, '254899');
        assert.equal(loader.loads, 1);
        assert.equal(loader.refreshes, 3);
    });

    it('starts from options announced before the component mounted', async () => {
        const element = createElement('7', 219900);
        runScripts([element]);
        dispatch('update-custom-option-prices', {'5': '100.00'});
        dispatch('update-custom-option-active', {customOptionId: '5', active: true});

        mount(element);
        await flush();

        assert.equal(element.dataset.amount, '229900');
    });

    it('keeps the amount when the component is destroyed and initialised again', async () => {
        const element = createElement('7', 219900);
        runScripts([element]);
        const first = mount(element);
        await flush();
        dispatch('update-custom-option-prices', {'5': '100.00'});
        dispatch('update-custom-option-active', {customOptionId: '5', active: true});
        assert.equal(element.dataset.amount, '229900');

        first.destroy();
        mount(element);
        await flush();
        mount(element).destroy();

        assert.equal(element.dataset.amount, '229900');
        assert.equal(element.dataset.hyvaAffirmServerAmount, '219900');
    });

    it('collapses outside the bounds without loading affirm.js and shows once the amount qualifies', async () => {
        const element = createElement('7', 50000, {}, ['hidden']);
        runScripts([element]);
        mount(element);
        await flush();

        assert.equal(element.classList.contains('hidden'), true);
        assert.equal(loader.loads, 0);

        dispatch('update-custom-option-prices', {'5': 600});
        dispatch('update-custom-option-active', {customOptionId: '5', active: true});
        await flush();

        assert.equal(element.dataset.amount, '110000');
        assert.equal(element.classList.contains('hidden'), false);
        assert.equal(loader.loads, 1);

        dispatch('update-custom-option-active', {customOptionId: '5', active: false});

        assert.equal(element.classList.contains('hidden'), true);
    });

    it('applies min_mpp to the live amount (equal shows)', async () => {
        const element = createElement('7', 150000, {minMpp: 200000});
        runScripts([element]);
        mount(element);
        await flush();
        assert.equal(element.classList.contains('hidden'), true);

        dispatch('update-product-final-price', 2000);

        assert.equal(element.classList.contains('hidden'), false);
    });

    it('hides the message while the configurable selection is a backordered child', async () => {
        const element = createElement('12', 150000, {backordered: [31]});
        runScripts([element]);
        mount(element);
        await flush();

        dispatch('configurable-selection-changed', {productId: '12', productIndex: '31', candidates: ['31']});
        assert.equal(element.classList.contains('hidden'), true);

        dispatch('configurable-selection-changed', {productId: '12', productIndex: '32', candidates: ['32']});
        assert.equal(element.classList.contains('hidden'), false);

        dispatch('configurable-selection-changed', {productId: '40', productIndex: '31', candidates: ['31']});
        assert.equal(element.classList.contains('hidden'), false);
    });

    it('takes configurable prices from update-prices of its own product only', async () => {
        const element = createElement('12', 150000);
        runScripts([element]);
        mount(element);
        await flush();

        dispatch('update-prices-12', {finalPrice: {amount: 1800}, basePrice: {amount: 1700}});
        assert.equal(element.dataset.amount, '180000');

        dispatch('update-prices-13', {finalPrice: {amount: 9000}});
        assert.equal(element.dataset.amount, '180000');
    });

    it('collapses for good when the loader rejects', async () => {
        loader = createLoader(true);
        window.hyvaAffirm = loader;
        const element = createElement('7', 219900);
        runScripts([element]);
        mount(element);
        await flush();

        assert.equal(element.classList.contains('hidden'), true);

        dispatch('update-product-final-price', 2500);
        await flush();

        assert.equal(element.classList.contains('hidden'), true);
        assert.equal(loader.loads, 1);
    });

    it('collapses when no loader is on the page', async () => {
        delete window.hyvaAffirm;
        const element = createElement('7', 219900);
        runScripts([element]);
        mount(element);
        await flush();

        assert.equal(element.classList.contains('hidden'), true);
    });

    it('asks the recorder to track a product it did not see', async () => {
        const element = createElement('21', 219900);
        runScripts([]);
        mount(element);

        dispatch('update-prices-21', {finalPrice: {amount: 2400}});

        assert.equal(element.dataset.amount, '240000');
    });

    it('works without the recorder from the server amount', async () => {
        const element = createElement('7', 219900);
        vm.runInContext(COMPONENT, context);
        mount(element);
        await flush();

        assert.equal(element.dataset.amount, '219900');
        assert.equal(loader.loads, 1);
    });

    it('stops listening when destroyed', async () => {
        const element = createElement('7', 219900);
        runScripts([element]);
        const component = mount(element);
        await flush();

        component.destroy();
        dispatch('update-product-final-price', 2500);

        assert.equal(element.dataset.amount, '219900');
        assert.equal(observers[0].target, null);
    });
});
