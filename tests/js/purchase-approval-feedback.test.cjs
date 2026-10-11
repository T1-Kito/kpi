const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/purchase-orders.js'), 'utf8');
const approveSource = source.slice(source.indexOf('async function approvePurchaseOrder('), source.indexOf('function openPurchaseOrderDetail('));

test('approval errors are visible and restore the button', async () => {
    const messages = [];
    const context = vm.createContext({VKApi:{request:async () => {throw new Error('Đơn mua chưa có kho nhận hàng.');}},
        VKModal:{toast:(...args) => messages.push(args)}, poState:{detailCache:{1:{id:1}}}, loadPurchaseOrders:async()=>{}});
    vm.runInContext(approveSource, context);
    const button = {disabled:false, textContent:'Duyệt'};
    await context.approvePurchaseOrder(1, button);
    assert.deepEqual(messages, [['Đơn mua chưa có kho nhận hàng.', 'danger']]);
    assert.equal(button.disabled, false);
    assert.equal(button.textContent, 'Duyệt');
    assert.ok(context.poState.detailCache[1]);
});

test('repeated clicks during approval send one request', async () => {
    let finish, calls = 0, reloads = 0;
    const context = vm.createContext({VKApi:{request:() => {calls++; return new Promise(resolve => {finish=resolve;});}},
        VKModal:{toast(){}}, poState:{detailCache:{1:{id:1}}}, loadPurchaseOrders:async()=>{reloads++;}});
    vm.runInContext(approveSource, context);
    const button = {disabled:false, textContent:'Duyệt'};
    const first = context.approvePurchaseOrder(1,button);
    await context.approvePurchaseOrder(1,button);
    assert.equal(calls,1);
    finish({data:{status:'approved'}});
    await first;
    assert.equal(reloads,1);
    assert.equal(context.poState.detailCache[1],undefined);
    assert.equal(button.disabled,false);
});
