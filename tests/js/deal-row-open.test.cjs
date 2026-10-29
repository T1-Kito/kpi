const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
test('clicking an opportunity row opens it while menu controls do not trigger the row',()=>{
 const source=fs.readFileSync('public/assets/js/features/deals.js','utf8');const handlers={},opened=[];
 const ctx=vm.createContext({document:{getElementById:()=>({}),addEventListener:(key,fn)=>handlers[key]=fn},openDealDetail:id=>opened.push(id)});
 vm.runInContext(source.slice(source.indexOf("document.addEventListener('click'"),source.indexOf('async function loadDeals()')),ctx);
 const row={dataset:{dealRow:'3'}};
 const target=control=>({closest:s=>s==='tr[data-deal-row]'?row:s==='button,a,input,select,textarea,summary,.row-action-menu'&&control?{}:null});
 handlers.click({target:target(false)});assert.deepEqual(opened,['3']);handlers.click({target:target(true)});assert.deepEqual(opened,['3']);
 handlers.keydown({target:{matches:()=>true,dataset:{dealRow:'4'}},key:'Enter',preventDefault(){}});assert.deepEqual(opened,['3','4']);
 assert.match(source,/rowAttr:row=>`data-deal-row=/);
});
