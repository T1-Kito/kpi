const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
test('classification picker follows parent paths, escapes labels and preserves legacy groups', async()=>{
 const context=vm.createContext({window:{},document:{addEventListener(){}},VKApi:{request:async()=>({can_manage:true,data:[{id:1,parent_id:null,name:'Khách <đoàn>',is_active:true},{id:2,parent_id:1,name:'Trường học',is_active:true},{id:3,parent_id:2,name:'Tiểu học',is_active:true},{id:4,parent_id:1,name:'Cũ',is_active:false}]})}});
 vm.runInContext(fs.readFileSync('public/assets/js/customer-groups.js','utf8'),context);
 const groups=context.window.VKCustomerGroups; await groups.load();
 assert.equal(groups.canManage,true);
 assert.equal(groups.path(3).map(r=>r.name).join('/'),'Khách <đoàn>/Trường học/Tiểu học');
 const html=groups.field({customer_group_id:3});
 assert.equal((html.match(/class="customer-input-wrap"/g)||[]).length,3);
 assert.match(html,/Nhóm cấp 1/);
 assert.match(html,/Khách &lt;đoàn&gt;/); assert.match(html,/name="customer_group_id" value="3"/);
 assert.equal((html.match(/selected/g)||[]).length,3);assert.doesNotMatch(html,/>Cũ</);
 assert.match(groups.field({customer_group:'Nhóm cũ'}),/Nhóm cũ.*được giữ nguyên/);
 assert.match(groups.options(2),/value="2" selected/);
});
