const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
function setup(kind='leads'){
 let click,modal;const calls=[],file={name:'lead.xlsx',size:100};const result={innerHTML:'',insertAdjacentHTML(_where,html){this.innerHTML+=html;}};
 const input={files:[file],addEventListener(_name,handler){this.changed=handler;}};
 const template={addEventListener(){}};const submit={textContent:''};
 const form={querySelector(selector){return ['[data-directory-file]','[data-product-file]'].includes(selector)?input:result;}};
 const ctx=vm.createContext({window:{loadLeads:async()=>{}},FormData:class{append(){}},document:{addEventListener(_name,fn){click=fn;},querySelector(selector){return selector==='[data-directory-file]'?input:selector==='[data-directory-template]'?template:result;},getElementById(){return submit;}},VKModal:{open(title,html,handler){modal={title,html,handler};},close(){},toast(){}},VKTable:{escapeHtml:v=>String(v??'').replaceAll('<','&lt;')},VKApi:{request:async(url,options)=>{calls.push({url,options});return {data:{id:42,status:url.endsWith('/commit')?'committed':'ready',result:{can_import:true,total:1,imported:url.endsWith('/commit')?1:0,preview:[{row:2,name:'<script>',phone:'0901234567',action:'create'}]}}};}}});
 vm.runInContext(fs.readFileSync('public/assets/js/features/directory-import.js','utf8'),ctx);
 const originalQuery=ctx.document.querySelector;ctx.document.querySelector=selector=>selector==='[data-product-file]'?input:selector==='[data-product-template]'?template:originalQuery(selector);
 click({target:{closest:()=>({dataset:{directoryImport:kind}})}});
 return {get modal(){return modal;},calls,input,result,form};
}
test('lead import previews an immutable batch, escapes data, and confirms only its id',async()=>{
 const s=setup();await s.modal.handler(s.form);assert.equal(s.calls[0].url,'/import-center/lead-preview');assert.match(s.result.innerHTML,/&lt;script>/);assert.doesNotMatch(s.result.innerHTML,/<script>/);
 await s.modal.handler(s.form);assert.equal(s.calls[1].url,'/import-center/batches/42/commit');assert.deepEqual(JSON.parse(s.calls[1].options.body),{confirmed:true});
});
test('changing a lead file invalidates the previous confirmation',async()=>{
 const s=setup();await s.modal.handler(s.form);s.input.changed();await s.modal.handler(s.form);assert.equal(s.calls[1].url,'/import-center/lead-preview');
});
test('all product catalogs preview first and commit only the approved batch id',async()=>{
 for(const kind of ['categories','brands','skus']){
  const s=setup(kind);await s.modal.handler(s.form);assert.equal(s.calls[0].url,`/import-center/products/${kind}/preview`);assert.match(s.result.innerHTML,/&lt;script>/);
  await s.modal.handler(s.form);assert.equal(s.calls[1].url,'/import-center/batches/42/commit');assert.deepEqual(JSON.parse(s.calls[1].options.body),{confirmed:true});
 }
});
test('supplier warehouse and location imports preview then confirm the immutable batch',async()=>{
 for(const kind of ['suppliers','warehouses','locations']){
  const s=setup(kind);await s.modal.handler(s.form);assert.equal(s.calls[0].url,`/import-center/procurement/${kind}/preview`);assert.match(s.result.innerHTML,/&lt;script>/);
  s.input.changed();await s.modal.handler(s.form);assert.equal(s.calls[1].url,`/import-center/procurement/${kind}/preview`);
  await s.modal.handler(s.form);assert.equal(s.calls[2].url,'/import-center/batches/42/commit');
 }
});
test('organization imports use Vietnamese instructions and only confirm the approved batch',async()=>{
 for(const kind of ['positions','departments','users']){
  const s=setup(kind);assert.match(s.modal.html,/chưa kích hoạt, chưa có vai trò/);await s.modal.handler(s.form);assert.equal(s.calls[0].url,`/import-center/organization/${kind}/preview`);
  s.input.changed();await s.modal.handler(s.form);assert.equal(s.calls[1].url,`/import-center/organization/${kind}/preview`);await s.modal.handler(s.form);assert.equal(s.calls[2].url,'/import-center/batches/42/commit');
 }
});
