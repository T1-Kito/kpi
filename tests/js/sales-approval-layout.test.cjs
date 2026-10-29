const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
test('approval setup retains controls, escaping and delegation permission in the new layout',()=>{
 const source=fs.readFileSync('public/assets/js/features/settings.js','utf8');
 const escape=s=>String(s).replaceAll('<','&lt;').replaceAll('>','&gt;');
 const ctx=vm.createContext({salesApprovalRowSequence:0,salesQuotationApproval:{data:{approvers:[7],delegations:[]},users:[{id:7,name:'<Approver>'}],can_manage_delegation:true},VKModal:{select:(name,label,options)=>`<div class="field"><label>${label}</label><select id="${name}">${options.map(x=>`<option>${escape(x.label)}</option>`).join('')}</select></div>`,field:(name,label,type,value)=>`<div><label>${label}</label><input id="${name}" value="${value}"></div>`}});
 vm.runInContext(source.slice(source.indexOf('function renderSalesQuotationApproval()'),source.indexOf('async function saveSalesQuotationApproval()')),ctx);
 let html=ctx.renderSalesQuotationApproval();
 for(const key of ['data-save-sales-approval','data-add-sales-approver','data-remove-sales-approver','sales_self_approval','sales_exception_approver','sales_minimum_margin','sales_maximum_discount','sales_amount_threshold','data-add-sales-delegation','data-sales-delegation-empty'])assert.ok(html.includes(key),key);
 assert.ok(html.includes('Cấp duyệt 1'));assert.ok(html.includes('&lt;Approver&gt;'));assert.ok(!html.includes('<Approver>'));
 ctx.salesQuotationApproval.can_manage_delegation=false;html=ctx.renderSalesQuotationApproval();assert.ok(!html.includes('data-add-sales-delegation'));
});
