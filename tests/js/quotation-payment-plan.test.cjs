const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/features/quotations.js','utf8');
test('editing restores the saved mode, ratio and dates without replacing the saved wording',()=>{
 const fields={'[data-payment-plan-mode]':{value:''},'[data-payment-percent]':{value:'70'},'[data-payment-deposit-date]':{value:''},'[data-payment-balance-date]':{value:''},'[name="payment_terms"]':{value:'Điều khoản đã lưu'}};
 let updates=0;
 const ctx=vm.createContext({document:{querySelector:s=>fields[s]},updateQuotationPaymentPlan(){updates++;fields['[name="payment_terms"]'].value='Nội dung tính lại';}});
 vm.runInContext(source.slice(source.indexOf('function restoreQuotationPaymentPlan('),source.indexOf('function updateQuotationPaymentPlan()')),ctx);
 ctx.restoreQuotationPaymentPlan({mode:'deposit',percent:50,deposit_date:'2026-10-27',balance_date:'2026-10-30'});
 assert.equal(fields['[data-payment-plan-mode]'].value,'deposit');assert.equal(fields['[data-payment-percent]'].value,50);
 assert.equal(fields['[data-payment-deposit-date]'].value,'2026-10-27');assert.equal(fields['[data-payment-balance-date]'].value,'2026-10-30');
 assert.equal(fields['[name="payment_terms"]'].value,'Điều khoản đã lưu');assert.equal(updates,1);
});
test('payment modes calculate proposed amounts and retain saved custom text on edit',()=>{
 const fields={
  '[data-payment-plan-mode]':{value:'deposit'},'[name="payment_terms"]':{value:''},
  '[data-payment-plan-summary]':{dataset:{total:'1000001'}},'[data-payment-deposit]':{},'[data-payment-credit]':{},
  '[data-payment-percent]':{value:'30'},'[data-payment-days]':{value:'45'},
  '[data-payment-deposit-date]':{value:'2026-11-01'},'[data-payment-balance-date]':{value:'2026-11-10'}
 };
 const ctx=vm.createContext({document:{querySelector:s=>fields[s]},formatDraftMoney:v=>v+' đ'});
 vm.runInContext(source.slice(source.indexOf('function updateQuotationPaymentPlan()'),source.indexOf('function updateQuotationCustomerSummary()')),ctx);
 ctx.updateQuotationPaymentPlan();assert.match(fields['[name="payment_terms"]'].value,/30% trước 01\/11\/2026/);
 assert.match(fields['[data-payment-plan-summary]'].textContent,/300000 đ.*700001 đ/);
 fields['[data-payment-plan-mode]'].value='credit';ctx.updateQuotationPaymentPlan();assert.match(fields['[name="payment_terms"]'].value,/45 ngày/);
 assert.equal(fields['[data-payment-deposit]'].hidden,true);assert.equal(fields['[data-payment-credit]'].hidden,false);
 fields['[data-payment-plan-mode]'].value='';const saved=fields['[name="payment_terms"]'].value;ctx.updateQuotationPaymentPlan();assert.equal(fields['[name="payment_terms"]'].value,saved);
});
