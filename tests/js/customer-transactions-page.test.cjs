const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
test('transactions render inline with empty-state content and a return action',async()=>{
    const calls=[],nodes=[];let html='';
    const context=vm.createContext({
        customerState:{detailCache:{}},VKApi:{request:async()=>({data:{id:1,code:'CUS-1',name:'Khách thử',contacts:[]},meta:{customer_360:{}}})},
        VKTable:{escapeHtml:String,money:String,statusBadge:String},formatCustomer360Date:()=>'',
        VKModal:{open:(_title,body)=>{html=body;},mountPage:()=>calls.push('mount'),close(){},toast:message=>calls.push(message)},
        document:{createElement:()=>({}),getElementById:()=>({prepend:node=>nodes.push(node)}),querySelector:()=>({scrollIntoView(){},addEventListener(){}})},
        openCustomerDetail:async()=>{},renderCustomers:()=>calls.push('fallback'),
    });
    const code=fs.readFileSync('public/assets/js/features/customers.js','utf8');
    vm.runInContext(code.slice(code.indexOf('async function openCustomerTransactions('),code.indexOf('function customerFormIcon(')),context);
    await context.openCustomerTransactions(1);
    assert.deepEqual(calls,['mount']);assert.match(html,/Chưa có giao dịch/);assert.match(html,/Chưa có người liên hệ/);assert.equal(nodes[0].textContent,'← Hồ sơ khách hàng');
});
