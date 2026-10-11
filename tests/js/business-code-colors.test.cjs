const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');

test('shared table marks code columns without marking money or customer names', () => {
    const source = read('public/assets/js/table.js');
    const ctx = vm.createContext({ escapeHtml: String });
    vm.runInContext(source.slice(source.indexOf('    function renderTable('), source.indexOf('    function filterBar(')), ctx);
    const html = ctx.renderTable([
        { label: 'Mã khách hàng', render: () => 'CUS-1' },
        { label: 'Mã số thuế', render: () => '12345' },
        { label: 'Khách hàng', render: () => 'Customer' },
        { label: 'Tổng tiền', render: () => '1.000.000' },
    ], [{}]);
    assert.match(html, /<td class="code-cell">CUS-1/);
    assert.match(html, /<td class="code-cell">12345/);
    assert.match(html, /<td class="">Customer/);
    assert.match(html, /<td class="">1\.000\.000/);
});

test('detail code labels are semantic and common code styling remains text-only', () => {
    for (const file of ['public/assets/js/record-page.js', 'public/assets/js/features/tasks.js', 'public/assets/js/features/sales-fulfillment.js', 'public/assets/js/features/customers.js', 'public/assets/js/features/leads.js']) {
        const source = read(file);
        assert.ok(source.includes("/^Mã(?:\\s|$)/i.test(label) ? 'record-code-text'"), file);
        assert.ok(!source.includes('/^Mã(?:\\\\s|$)/'), file);
    }
    const css = read('public/assets/css/pages.css');
    assert.match(css, /--business-code-color:#ec008c/);
    assert.match(css, /color:var\(--business-code-color\)!important;background:transparent!important;border:0!important/);
});
