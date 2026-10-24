const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/js/layout.js', 'utf8');

test('application search accepts Vietnamese accents and unaccented text', () => {
    const context = vm.createContext({});
    vm.runInContext(source.slice(source.indexOf('    function normalizeAppSearch('), source.indexOf('    function bindSidebar(')), context);
    assert.equal(context.normalizeAppSearch('  Báo giá  '), 'bao gia');
    assert.equal(context.normalizeAppSearch('ĐƠN HÀNG'), 'don hang');
    assert.equal(context.normalizeAppSearch('Chăm sóc khách hàng').includes('cham soc'), true);
    assert.equal(context.normalizeAppSearch(null), '');
});

test('launcher removes unauthorized destinations and preserves direct quotation links', () => {
    let refreshes = 0;
    const quotation = { dataset: { permission: 'sales.quotation.view' }, href: '/quotations', remove() { this.removed = true; } };
    const users = { dataset: { permission: 'user.manage' }, href: '/users', remove() { this.removed = true; } };
    const context = vm.createContext({
        window: { location: { pathname: '/quotations' } },
        hasPermission: code => !code || code === 'sales.quotation.view',
        document: {
            querySelectorAll: selector => selector === '#mainNav a, [data-module-toolbar] a, [data-module-card]' ? [quotation, users] : [],
            querySelector: selector => selector === '[data-app-switcher]' ? { dispatchEvent: () => refreshes++ } : null,
        },
        CustomEvent: class {},
        setActiveUrl() {}, applySidebarIcons() {},
    });
    vm.runInContext(source.slice(source.indexOf('    function applyNavigation('), source.indexOf('    function bindAppSwitcher(')), context);
    context.applyNavigation();
    assert.equal(quotation.href, '/quotations');
    assert.equal(quotation.removed, undefined);
    assert.equal(users.removed, true);
    assert.equal(refreshes, 1);
});

test('page transitions close the application panel along with other transient UI', () => {
    const events = [];
    const context = vm.createContext({
        document: { querySelector: () => ({ dispatchEvent: event => events.push(event.type) }) },
        CustomEvent: class { constructor(type) { this.type = type; } },
        window: {},
    });
    vm.runInContext(source.slice(source.indexOf('    function closeTransientUi('), source.indexOf('    function setActiveUrl(')), context);
    context.closeTransientUi();
    assert.deepEqual(events, ['vk:apps:close']);
});
