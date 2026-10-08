const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const modules = {
    customers: ['customer', 'openCustomerDetail'],
    'goods-issues': ['goods-issue', 'openGoodsIssueDetail'],
    'goods-receipts': ['goods-receipt', 'openGoodsReceiptDetail'],
    'purchase-requests': ['purchase-request', 'openPurchaseRequestDetail'],
    'purchase-orders': ['purchase-order', 'openPurchaseOrderDetail'],
    quotations: ['quotation', 'openQuotationDetail'],
    'sales-orders': ['sales-order', 'openSalesOrderDetail'],
    skus: ['sku', 'openSkuDetail'],
    suppliers: ['supplier', 'openSupplierDetail'],
    users: ['user', 'openUserDetail'],
    tasks: ['task', 'openTaskDetail'],
};

const actions = {
    customers: ['edit-customer', 'openCustomerModal'],
    'goods-issues': ['confirm-goods-issue', 'confirmGoodsIssue'],
    'goods-receipts': ['confirm-goods-receipt', 'confirmGoodsReceipt'],
    'purchase-requests': ['approve-purchase-request', 'approvePurchaseRequest'],
    'purchase-orders': ['approve-purchase-order', 'approvePurchaseOrder'],
    quotations: ['edit-quotation', 'openQuotationEditor'],
    'sales-orders': ['confirm-sales-order', 'confirmSalesOrder'],
    skus: ['edit-sku', 'openSkuModal'],
    suppliers: ['edit-supplier', 'openSupplierModal'],
    users: ['sync-user-roles', 'openRoleAssignModal'],
    tasks: ['task-change-status', 'updateTaskStatus'],
};

for (const [file, [entity, opener]] of Object.entries(modules)) {
    const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/features', file + '.js'), 'utf8');
    const handler = source.match(/document\.addEventListener\('click', \(event\) => \{[\s\S]*?^\}\);/m)?.[0];
    assert.ok(handler, file + ' click handler exists');
    for (const mode of ['open', 'nested-open', 'row', 'other-action', 'summary', 'real-action']) {
        test(file + ': ' + mode, () => {
            const calls = [];
            let listener;
            const context = {
                document: { getElementById: () => ({}), addEventListener: (_type, fn) => { listener = fn; } },
                [opener]: id => calls.push(String(id)),
                [actions[file][1]]: id => calls.push('action:' + id),
            };
            vm.runInNewContext(handler, context);
            const key = entity.replace(/-([a-z])/g, (_, c) => c.toUpperCase()) + 'Detail';
            const explicit = { dataset: { [key]: '17' } };
            const isOpen = mode.includes('open');
            const target = {
                dataset: isOpen ? explicit.dataset : { [actions[file][0].replace(/-([a-z])/g, (_, c) => c.toUpperCase())]: '17', status: 'done' },
                matches: selector => (mode === 'open' && selector === `[data-${entity}-detail]`) || (mode === 'real-action' && selector === `[data-${actions[file][0]}]`),
                closest(selector) {
                    if (selector === '.row-action-menu') return mode === 'row' ? null : {};
                    if (selector === 'button') return mode === 'summary' || mode === 'row' ? null : {};
                    if (selector === `[data-${entity}-detail]`) return isOpen ? explicit : null;
                    if (selector === `[data-${entity}-detail], tr[data-row-detail]`) return isOpen ? explicit : { dataset: { rowDetail: '17' } };
                    return null;
                },
            };
            listener({ target, stopPropagation() {}, preventDefault() {} });
            assert.deepEqual(calls, mode === 'real-action' ? ['action:17'] : isOpen || mode === 'row' ? ['17'] : []);
        });
    }
}
