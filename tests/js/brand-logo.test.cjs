const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/assets/js/layout.js'), 'utf8');
const handler = source.slice(source.indexOf('    function hydrateLogo('), source.indexOf('    function applyNavigation('));

function setup() {
    const element = () => ({ dataset: {}, hidden: true,
        classList: { add() { this.owner.hidden = true; }, remove() { this.owner.hidden = false; } },
        removeAttribute(name) { delete this[name]; } });
    const image = element();
    image.dataset.defaultLogo = '/assets/branding/vk-software-logo.png';
    image.src = image.dataset.defaultLogo;
    image.hidden = false;
    const fallback = element();
    for (const item of [image, fallback]) item.classList.owner = item;
    const pending = [];
    const context = vm.createContext({
        document: { querySelector: selector => selector === '[data-brand-logo]' ? image : fallback },
        Image: function () { pending.push(this); },
    });
    vm.runInContext(handler, context);
    return { image, fallback, pending, hydrate: context.hydrateLogo };
}

test('initial markup shows the supplied logo and hides the abstract fallback', () => {
    const blade = fs.readFileSync(path.join(root, 'resources/views/partials/sidebar.blade.php'), 'utf8');
    assert.match(blade, /class="brand-logo hidden"/);
    assert.match(blade, /class="brand-image" src="\/assets\/branding\/vk-software-logo.png"/);
});

test('saved logo is revealed only after loading; repeated hydration does not reload it', () => {
    const s = setup();
    s.hydrate('/logo.png');
    assert.equal(s.image.hidden, false);
    assert.equal(s.fallback.hidden, true);
    s.hydrate('/logo.png');
    assert.equal(s.pending.length, 1);
    s.pending[0].onload();
    assert.equal(s.image.src, '/logo.png');
    assert.equal(s.image.hidden, false);
    assert.equal(s.fallback.hidden, true);
});

test('missing or failed custom logo uses supplied brand; a failure can retry', () => {
    const s = setup();
    s.hydrate('');
    assert.equal(s.fallback.hidden, true);
    s.hydrate('/broken.png');
    assert.equal(s.fallback.hidden, true);
    s.pending[1].onerror();
    assert.equal(s.fallback.hidden, true);
    assert.equal(s.image.hidden, false);
    assert.equal(s.image.src,s.image.dataset.defaultLogo);
    s.hydrate('/broken.png');
    assert.equal(s.pending.length, 3);
});

test('outdated load and error callbacks cannot overwrite the latest logo', () => {
    const s = setup();
    s.hydrate('/old.png');
    s.hydrate('/new.png');
    s.pending[1].onload();
    s.pending[0].onload();
    s.pending[0].onerror();
    assert.equal(s.image.src, '/new.png');
    assert.equal(s.fallback.hidden, true);
    s.hydrate('');
    s.pending[1].onload();
    s.pending[2].onload();
    assert.equal(s.image.src,s.image.dataset.defaultLogo);
    assert.equal(s.image.hidden, false);
    assert.equal(s.fallback.hidden, true);
});

function restoreEarly(values) {
    const s = setup();
    const blade = fs.readFileSync(path.join(root, 'resources/views/partials/sidebar.blade.php'), 'utf8');
    const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1];
    vm.runInNewContext(script, {
        localStorage: { getItem: key => values[key] || null },
        document: { querySelector: selector => selector === '[data-brand-logo]' ? s.image : s.fallback },
    });
    return s;
}

test('cached branding is attached during HTML parsing, without async app bootstrap', () => {
    const s = restoreEarly({ vk_token: 'test-token', 'vk.currentUser.v3': JSON.stringify({
        permissions: ['dashboard.view'], data_scope: 'all', tenant: { logo_url: '/saved.png' },
    }) });
    assert.equal(s.image.src, '/saved.png');
    assert.equal(s.image.hidden, false);
    assert.equal(s.fallback.hidden, true);
    s.hydrate('/saved.png');
    assert.equal(s.pending.length, 0);
});

test('early branding ignores logged-out and malformed cache', () => {
    for (const values of [{}, { vk_token: 'test-token', 'vk.currentUser.v3': '{broken' }]) {
        const s = restoreEarly(values);
        assert.equal(s.image.hidden, false);
        assert.equal(s.image.src,s.image.dataset.defaultLogo);
    }
});
