const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/quotations.js'), 'utf8');
const escape = value => String(value ?? '').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const row = { id: 3, code: 'QUO-3', status: 'draft', workflow_version: 2, created_at: '2026-10-09', updated_at: '2026-10-10', items: [], customer: { name: '<Customer>', phone: '090123', contact_name: 'Lan', email: 'lan@example.com', tax_code: '1234' } };
function setup() {
    const context = vm.createContext({ quotationState: { detailTab: 'overview' }, window: { VKLayout: { hasPermission: () => true } },
        VKTable: { escapeHtml: escape, statusBadge: escape, translateStatus: String, money: String }, formatDate: String,
        canEditQuotation: () => true, canDeleteQuotation: () => true,
        renderQuotationWorkflow: () => '<section>workflow</section>', renderQuotationPageItems: () => '<section>items</section>' });
    for (const [start, end] of [['function renderQuotationPageHeader(', 'function renderQuotationPageTabs('],
        ['function renderQuotationQuickInfo(', 'function renderQuotationInfoPanel('],
        ['function renderQuotationCompactTotals(', 'function renderQuotationItemsTable('],
        ['function renderQuotationPageTabContent(', 'function renderQuotationApprovalPayment(']]) {
        vm.runInContext(source.slice(source.indexOf(start), source.indexOf(end)), context);
    }
    return context;
}
test('reference layout groups items left, totals and dates right without duplicate identity', () => {
    const ctx = setup();
    const header = ctx.renderQuotationPageHeader(row);
    const content = ctx.renderQuotationPageTabContent(row);
    assert.doesNotMatch(header, /quotation-hero-title|&lt;Customer&gt;|QUO-3/);
    assert.doesNotMatch(header, /090123|Ngày tạo/);
    assert.equal(content.split('&lt;Customer&gt;').length - 1, 1);
    assert.equal(content.split('090123').length - 1, 1);
    assert.match(content, /quote-reference-main/);
    assert.match(content, /quote-reference-side/);
    assert.match(content, /Giá trị báo giá/);
    assert.match(content, /Thông tin chung/);
    assert.match(content, /2026-10-10/);
    assert.match(content, /quote-code-text/);
});
test('reference header retains edit preview export deletion and submit hooks', () => {
    const html = setup().renderQuotationPageActions(row);
    for (const hook of ['data-edit-quotation', 'data-delete-quotation', 'data-quotation-preview', 'data-quotation-document-preview', 'data-quote-workflow="submit"']) assert.ok(html.includes(hook));
});
test('quotation actions appear beside tabs instead of a separate footer', () => {
    const render = source.slice(source.indexOf('function renderQuotationDetailPage('), source.indexOf('function renderQuotationSplitList('));
    assert.doesNotMatch(render, /renderQuotationPageHeader/);
    const tabs = source.slice(source.indexOf('function renderQuotationPageTabs('), source.indexOf('function renderQuotationPageTabContent('));
    assert.match(tabs, /quote-tab-actions/);
    assert.match(tabs, /renderQuotationPageActions\(row\)/);
});
