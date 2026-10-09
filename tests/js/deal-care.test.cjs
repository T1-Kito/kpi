const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const esc = value => String(value || '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');

test('care panel exposes result action only for active tasks and escapes stored notes', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/record-page.js'), 'utf8');
    const code = source.slice(source.indexOf('    function renderDealFollowUps('), source.indexOf('    function renderTimeline('));
    const context = vm.createContext({ escape: esc, formatDateTime: String, VKTable: { statusBadge: esc } });
    vm.runInContext(code, context);
    const html = context.renderDealFollowUps({ id: 7, status: 'open', tasks: [
        { id: 1, title: 'Call', status: 'new', due_at: '2000-01-01', assignee: { name: 'Sales' } },
        { id: 2, title: 'Done', status: 'completed', history: [{ to_status: 'completed', reason: '<script>bad</script>', created_at: '2026-10-09' }] },
    ] });
    assert.match(html, /data-deal-complete-activity="1"/);
    assert.doesNotMatch(html, /data-deal-complete-activity="2"/);
    assert.match(html, /overdue/);
    assert.match(html, /&lt;script&gt;/);
    assert.doesNotMatch(html, /<script>/);
});

test('result submission sends a boolean and excludes next fields when unchecked', async () => {
    const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/deals.js'), 'utf8');
    const code = source.slice(source.indexOf('async function openDealActivityResultModal('), source.indexOf('function stageLabel('));
    let submit;
    const checkbox = { checked: false, addEventListener() {} };
    const calls = [];
    const context = vm.createContext({
        VKTable: { escapeHtml: esc },
        VKApi: { request: async (url, options) => {
            calls.push({ url, options });
            return { data: { id: 7, code: 'DEAL-7', name: 'Deal', tasks: [{ id: 1, status: 'new', title: 'Call' }] } };
        } },
        VKModal: { field: () => '', select: () => '', open: (_, html, callback) => { submit = callback; }, close() {}, toast() {} },
        document: { querySelector: () => checkbox },
        FormData: function () { return [['outcome', 'contacted'], ['note', 'Done'], ['next_title', 'Next'], ['next_due_at', '2026-12-01']]; },
        reloadDealDetail: async () => {},
    });
    vm.runInContext(code, context);
    await context.openDealActivityResultModal('7', '1');
    await submit({ querySelector: () => checkbox });
    const posted = calls.find(call => call.options?.method === 'POST');
    assert.equal(posted.url, '/deals/7/activities/1/complete');
    const payload = JSON.parse(posted.options.body);
    assert.equal(payload.create_next, false);
    assert.equal(payload.next_title, undefined);
    assert.equal(payload.next_due_at, undefined);
});
