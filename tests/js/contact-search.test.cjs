const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function setup() {
    const listeners = {}, requests = [], nodes = {};
    let mounts = 0;
    const root = {
        querySelector: key => nodes[key] || null,
        set innerHTML(value) {
            mounts++;
            for (const key of ['[data-contact-search]', '.list-meta', '[data-contact-results]', '[data-contact-pagination]']) nodes[key] = {};
        },
    };
    const context = vm.createContext({
        window: {},
        document: { getElementById: () => root, addEventListener: (name, fn) => { listeners[name] = fn; } },
        VKTable: { escapeHtml: String, money: String, fullList: () => 'shell', renderTable: (_, rows) => JSON.stringify(rows) },
        VKApi: { request: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })) },
        setTimeout: () => 1, clearTimeout: () => {},
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/customer-contacts.js'), 'utf8'), context);
    return { load: context.window.loadCustomerContacts, listeners, requests, nodes, mounts: () => mounts };
}
const response = data => ({ data, meta: { total: data.length, last_page: 1 } });

test('filter updates preserve the exact search input instead of replacing it', async () => {
    const s = setup();
    let loading = s.load();
    s.requests[0].resolve(response([]));
    await loading;
    const input = s.nodes['[data-contact-search]'];
    input.value = 'Liên hệ';
    input.selectionStart = 3;
    s.listeners.input({ target: { matches: () => true, value: input.value } });
    loading = s.load();
    s.requests[1].resolve(response([{ id: 1 }]));
    await loading;
    assert.equal(s.nodes['[data-contact-search]'], input);
    assert.equal(input.value, 'Liên hệ');
    assert.equal(input.selectionStart, 3);
    assert.equal(s.mounts(), 1);
});

test('typing invalidates an older response before the debounce fires', async () => {
    const s = setup();
    const loading = s.load();
    s.listeners.input({ target: { matches: () => true, value: 'new' } });
    s.requests[0].resolve(response([{ id: 'outdated' }]));
    await loading;
    assert.equal(s.nodes['[data-contact-results]'].innerHTML, undefined);
});

test('failed requests keep the search field available for editing and retry', async () => {
    const s = setup();
    const loading = s.load();
    const input = s.nodes['[data-contact-search]'];
    s.requests[0].reject(new Error('Network unavailable'));
    await loading;
    assert.equal(s.nodes['[data-contact-search]'], input);
    assert.match(s.nodes['[data-contact-results]'].innerHTML, /data-contact-retry/);
    assert.equal(s.mounts(), 1);
});
