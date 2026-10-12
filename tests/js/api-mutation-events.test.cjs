const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/js/services/api.js', 'utf8');

function contextFor(fetch) {
    const emitted = [];
    class CustomEvent {
        constructor(type, options) { this.type = type; this.detail = options.detail; }
    }
    const window = {
        setTimeout: () => 1,
        clearTimeout() {},
        dispatchEvent: event => emitted.push(event),
    };
    const context = vm.createContext({
        window,
        fetch,
        CustomEvent,
        FormData: class {},
        AbortController: class { constructor() { this.signal = {}; } abort() {} },
        localStorage: { getItem: () => 'token', setItem() {}, removeItem() {} },
        Map,
    });
    vm.runInContext(source, context);
    return { window, emitted };
}

test('successful mutations emit a refresh signal while reads stay silent', async () => {
    const response = () => Promise.resolve({ ok: true, json: async () => ({ data: [] }) });
    const state = contextFor(response);

    await state.window.VKApi.request('/tasks', { cache: false });
    assert.equal(state.emitted.length, 0);

    await state.window.VKApi.request('/tasks', { method: 'POST', body: '{}' });
    assert.equal(state.emitted.length, 1);
    assert.equal(state.emitted[0].type, 'vk:data-mutated');
    assert.deepEqual({ ...state.emitted[0].detail }, { path: '/tasks', method: 'POST' });
});

test('failed mutations never announce stale data as refreshed', async () => {
    const state = contextFor(() => Promise.resolve({ ok: false, json: async () => ({ message: 'Rejected' }) }));
    await assert.rejects(state.window.VKApi.request('/tasks/1', { method: 'PUT', body: '{}' }), /Rejected/);
    assert.equal(state.emitted.length, 0);
});
