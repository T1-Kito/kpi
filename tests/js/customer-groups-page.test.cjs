const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
async function render(manager=true,rows=[]){
 const root={innerHTML:''};
 const context=vm.createContext({window:{},document:{getElementById:id=>id==='customerGroupsRoot'?root:null,addEventListener(){}},VKApi:{request:async()=>({data:rows,can_manage:manager})},VKModal:{toast(){}},VKTable:{escapeHtml:v=>String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;')}});
 vm.runInContext(fs.readFileSync('public/assets/js/customer-groups.js','utf8'),context);context.VKCustomerGroups=context.window.VKCustomerGroups;
 vm.runInContext(fs.readFileSync('public/assets/js/features/customer-groups-page.js','utf8'),context);
 await context.window.loadCustomerGroupsPage();return root.innerHTML;
}
test('group page renders folder tree, search, inspector and management actions',async()=>{
 const html=await render(true,[{id:1,parent_id:null,name:'Khách <đoàn>',is_active:true},{id:2,parent_id:1,name:'Trường',is_active:true}]);
 assert.match(html,/role="tree"/);assert.match(html,/aria-level="2"/);assert.match(html,/Khách &lt;đoàn&gt;/);assert.doesNotMatch(html,/Khách <đoàn>/);
 for(const attr of ['data-cgp-search','data-cgp-expand','data-cgp-collapse','data-cgp-import','data-cgp-add'])assert.ok(html.includes(attr));
 assert.doesNotMatch(html,/cgp-detail|cgp-summary|Sắp xếp khách hàng|KHÁCH HÀNG \/ DANH MỤC/);
 assert.match(html,/data-cgp-add="1"/);
 assert.doesNotMatch(html,/<header class="cgp-header"/);
 assert.match(html,/cgp-tools-compact/);
 assert.match(html,/cgp-toolbar-actions/);
});
test('staff can view folders but cannot see mutation actions',async()=>{
 const html=await render(false,[{id:1,parent_id:null,name:'Nhóm chung',is_active:true}]);
 assert.match(html,/Nhóm chung/);assert.doesNotMatch(html,/data-cgp-add|data-cgp-edit|data-cgp-import/);
});
test('empty folder page provides an explicit creation starting point',async()=>{
 const html=await render();assert.match(html,/Tạo nhóm đầu tiên/);assert.match(html,/Danh mục đang chờ bạn sắp xếp/);
});
