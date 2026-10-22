const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const read = name => fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/', name), 'utf8');

test('user management offers role assignment only when the API authorizes it', () => {
    const source = read('users.js');
    const context = vm.createContext({userState:{canAssignRoles:false}, VKTable:{rowActions:values=>values.filter(Boolean).join(' '),smallButton:(label,attr)=>attr}});
    vm.runInContext(source.slice(source.indexOf('function renderActions('),source.indexOf('function openUserModal(')),context);
    assert.doesNotMatch(context.renderActions({id:1,is_active:true}),/data-sync-user-roles/);
    context.userState.canAssignRoles=true;
    assert.match(context.renderActions({id:1,is_active:true}),/data-sync-user-roles/);
    assert.doesNotMatch(source,/Admin@123/);
});

test('employee ledger review controls respect KPI management permission', () => {
    const source=read('kpi-adjustments.js');
    const context=vm.createContext({window:{VKUser:{permissions:[]}},escape:String,periodLabel:()=>'',typeBadge:()=>'',formatPoint:String,statusBadge:String});
    vm.runInContext(source.slice(source.indexOf('function canManagePoints('),source.indexOf('async function safeRequest(')),context);
    vm.runInContext(source.slice(source.indexOf('function renderRow('),source.indexOf('async function openAdjustmentModal(')),context);
    assert.doesNotMatch(context.renderRow({id:2,status:'pending'}),/data-review-kpi-adjustment/);
    context.window.VKUser.permissions=['kpi.adjustment.review'];
    assert.match(context.renderRow({id:2,status:'pending'}),/data-review-kpi-adjustment/);
    assert.doesNotMatch(source,/if \(root.dataset.serverRendered/);
});
