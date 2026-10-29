const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/features/quotations.js', 'utf8');
test('selected customer displays separate full fields and clears after deselection', () => {
    const root = {innerHTML: ''};
    const option = {value:'7', dataset:{}};
    const ctx = vm.createContext({quotationCustomers:[{id:7,name:'<Test>',customer_type:'person',identity_number:'001234567890',email:'test@example.com',bank_name:'Ngân hàng A'}],
        document:{getElementById:()=>({selectedOptions:[option]}),querySelector:()=>root},
        VKTable:{escapeHtml:s=>String(s).replaceAll('<','&lt;').replaceAll('>','&gt;')},formatDraftMoney:v=>v+' đ'});
    vm.runInContext(source.slice(source.indexOf('function updateQuotationCustomerSummary()'),source.indexOf('function setDraftMoney(')),ctx);
    ctx.updateQuotationCustomerSummary();
    assert.match(root.innerHTML,/Số CCCD/); assert.match(root.innerHTML,/001234567890/);
    assert.match(root.innerHTML,/Ngân hàng A/);assert.match(root.innerHTML,/&lt;Test&gt;/);
    assert.equal((root.innerHTML.match(/<dt>/g)||[]).length,16);
    option.value='';ctx.updateQuotationCustomerSummary();assert.equal(root.innerHTML,'');
});
