const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

test('alert detail omits duplicated header and keeps back navigation and tabs', async () => {
    const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/record-page.js'), 'utf8');
    const root = { innerHTML: '' };
    const listeners = {};
    let wentBack = false;
    const context = vm.createContext({
        window: {},
        document: { querySelector: () => root, addEventListener: (name, callback) => { listeners[name] = callback; } },
        VKTable: { escapeHtml: String, statusBadge: String, translateType: String, translateEntity: String, money: String },
    });
    vm.runInContext(source, context);
    await context.window.VKRecordPage.open({ root: '#alertsRoot', type: 'alert', id: 1,
        preview: { title: 'Theo dõi tồn kho', status: 'open', created_at: '2026-10-09', updated_at: '2026-10-09' },
        onBack: () => { wentBack = true; },
    });
    assert.doesNotMatch(root.innerHTML, /class="record-page-head"/);
    assert.match(root.innerHTML, /Thông tin cảnh báo/);
    assert.match(root.innerHTML, /Cập nhật/);
    assert.match(root.innerHTML, /data-record-page-tab="history"/);
    assert.match(root.innerHTML, /data-record-page-tab="notes"/);
    assert.match(root.innerHTML, /data-record-page-back="#alertsRoot"/);
    listeners.click({ target: { closest: selector => selector === '[data-record-page-back]' ? { dataset: { recordPageBack: '#alertsRoot' } } : null } });
    assert.equal(wentBack, true);
});
