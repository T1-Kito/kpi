const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
function render({page=1,total=2,extra=false,canManage=true}={}){
 const root={innerHTML:''};const context=vm.createContext({window:{VKLayout:{hasPermission:()=>canManage}},document:{getElementById:()=>root,querySelector:()=>null},customerState:{pageSize:20,extraColumns:extra,meta:{page,last_page:Math.max(1,Math.ceil(total/20)),total},rows:[{id:1,code:'CUS-00001',name:'<img src=x>',customer_type:'organization',phone:'0901234567',email:'a@example.test',address:'Địa chỉ',sales_owner:{name:'Admin'}}]}});
 context.document.addEventListener=()=>{};
 vm.runInContext(fs.readFileSync('public/assets/js/table.js','utf8'),context);context.VKTable=context.window.VKTable;
 vm.runInContext(fs.readFileSync('public/assets/js/catalog-sharing.js','utf8'),context);context.VKCatalogSharing=context.window.VKCatalogSharing;
 const code=fs.readFileSync('public/assets/js/features/customers.js','utf8');
 vm.runInContext(code.slice(code.indexOf('function renderCustomerTable('),code.indexOf('async function openCustomerModal(')),context);
 vm.runInContext(code.slice(code.indexOf('function renderCustomers('),code.indexOf('async function loadCustomerDuplicates(')),context);
 context.renderCustomers(total);return root.innerHTML;
}
test('customer list has key contact columns, escaped names, explicit actions and a bottom pager',()=>{
 const html=render();assert.match(html,/Email/);assert.match(html,/Địa chỉ/);assert.match(html,/customer-list-avatar/);assert.match(html,/&lt;img/);assert.doesNotMatch(html,/<img src/);
 assert.match(html,/data-edit-customer/);assert.match(html,/data-customer-detail/);assert.match(html,/data-customers-extra/);
 assert.ok(html.indexOf('customer-list-pagination')>html.indexOf('</table>'));assert.match(html,/aria-current="page"/);assert.doesNotMatch(html,/<th[^>]*>Mã số thuế/);
});
test('technical columns are optional and edit actions respect permissions',()=>{
 const html=render({extra:true,canManage:false});assert.match(html,/Mã số thuế/);assert.match(html,/Số CCCD/);assert.doesNotMatch(html,/data-edit-customer/);assert.doesNotMatch(html,/data-create-customer/);
});
test('large pagination is bounded and marks the current page',()=>{
 const html=render({page:50,total:2000});assert.match(html,/Hiển thị <strong>981–981/);assert.match(html,/data-customers-page="50" aria-current="page"/);assert.match(html,/customer-page-gap/);
 assert.ok((html.match(/data-customers-page=/g)||[]).length<=9);
});
test('editing a customer stops before the row detail action and typing invalidates stale responses',()=>{
 const code=fs.readFileSync('public/assets/js/features/customers.js','utf8');
 const edit=code.slice(code.indexOf("if (event.target.matches('[data-edit-customer]'))"),code.indexOf("if (event.target.closest('.row-action-menu')"));
 assert.match(edit,/openCustomerModal[\s\S]*return;/);
 assert.match(code,/customerState.q = event.target.value.toLowerCase\(\);\s*\+\+customerLoadVersion;/);
});
