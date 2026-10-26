const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');

function setup(request){
 const nodes={};for(const id of ['intakeSource','intakeFile','intakePreview','modalSubmit'])nodes[id]={value:'Nguồn',textContent:'',innerHTML:'',listeners:{},addEventListener(type,handler){this.listeners[type]=handler;},replaceChildren(){this.innerHTML='';}};
 let handler;
 const ctx=vm.createContext({window:{},document:{getElementById:id=>nodes[id],querySelector:()=>({})},FormData:class{append(){}},VKTable:{escapeHtml:v=>String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;'),money:v=>String(v)},VKApi:{request},VKModal:{open(title,html,fn){handler=fn;},close(){},toast(){}}});
 vm.runInContext(fs.readFileSync('public/assets/js/crm-intake.js','utf8'),ctx);
 return {api:ctx.window.VKCrmIntake,nodes,submit:form=>handler(form)};
}
const preview={data:{rows:[{row:2,action:'opportunity',customer_name:'Trường A',customer_action:'create',owner_name:'An',errors:[],warnings:[],probability:25,forecast:100}],can_commit:true,error_count:0,ignored:0,token:'opaque'}};
const form={elements:{file:{files:[{}]},source_name:{value:'Nguồn'},intake_confirmed:{checked:true}}};

test('preview escapes customer names, owners, errors and warnings without rendering an executable value',()=>{
 const {api}=setup();
 const html=api.previewHtml({rows:[{row:2,action:'error',customer_name:'<script>alert(1)</script>',owner_name:'<img>',errors:['<svg>'],warnings:['<iframe>']}],error_count:1,can_commit:false,ignored:17});
 assert.doesNotMatch(html,/<script>|<svg>|<iframe>|<img>/);assert.match(html,/&lt;script&gt;/);assert.match(html,/Chưa lưu dữ liệu/);assert.doesNotMatch(html,/intake_confirmed/);
});
test('changing source or file invalidates preview and requires a fresh check before commit',async()=>{
 const calls=[];const {api,nodes,submit}=setup(async path=>{calls.push(path);return preview;});
 api.open();await submit(form);assert.equal(nodes.modalSubmit.textContent,'Xác nhận nhập');
 nodes.intakeSource.listeners.input();await submit(form);nodes.intakeFile.listeners.change();await submit(form);
 assert.deepEqual(calls,['/crm-intake/preview','/crm-intake/preview','/crm-intake/preview']);
});
test('commit failure discards stale token and forces preview again',async()=>{
 const calls=[];const {api,nodes,submit}=setup(async path=>{calls.push(path);if(path.endsWith('commit'))throw new Error('Dữ liệu đã thay đổi');return preview;});
 api.open();await submit(form);await assert.rejects(submit(form));assert.equal(nodes.modalSubmit.textContent,'Kiểm tra lại file');await submit(form);
 assert.deepEqual(calls,['/crm-intake/preview','/crm-intake/commit','/crm-intake/preview']);
});
