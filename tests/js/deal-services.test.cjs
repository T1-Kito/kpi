const {test}=require('node:test');const assert=require('node:assert/strict');const vm=require('node:vm');const fs=require('node:fs');
test('services are grouped, escaped and inactive historical choices preserved',async()=>{
 const ctx=vm.createContext({window:{},document:{addEventListener(){}},VKTable:{escapeHtml:v=>String(v).replaceAll('<','&lt;').replaceAll('>','&gt;')},VKApi:{request:async()=>({can_manage:true,data:[{id:1,name:'Nhóm <a>',parent_id:null,is_active:true},{id:2,name:'Suất ăn',parent_id:1,is_active:true},{id:3,name:'Cũ',parent_id:1,is_active:false}]})}});
 vm.runInContext(fs.readFileSync('public/assets/js/deal-services.js','utf8'),ctx);await ctx.window.VKDealServices.load();
 const html=ctx.window.VKDealServices.picker({service_ids:[2]});assert.match(html,/Nhóm &lt;a&gt;/);assert.match(html,/value="2" checked/);assert.doesNotMatch(html,/>Cũ/);
 assert.match(ctx.window.VKDealServices.picker({service_ids:[3]}),/Cũ · Ngừng dùng/);
 assert.match(ctx.window.VKDealServices.picker({interests:['Dữ liệu cũ']}),/Dữ liệu cũ/);
});
