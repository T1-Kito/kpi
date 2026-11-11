const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/features/service-desk.js','utf8');
test('order care shares in-flight loads and retries errors without mixing accounts',async()=>{
 const start=source.indexOf('    const orderRequestCache=new Map();'),end=source.indexOf('    let embeddedContext',start);let calls=0,fail=false;
 const context={Map,Date,URLSearchParams,window:{VKUser:{id:1,tenant_id:1}},VKApi:{request:async()=>{calls++;if(fail)throw new Error('offline');return {data:[],meta:{total:0}};}}};vm.createContext(context);vm.runInContext(source.slice(start,end)+';globalThis.get=orderRequests;',context);
 const document={document_type:'order',document_id:2,customer_id:3};await Promise.all([context.get(document,1),context.get(document,1)]);assert.equal(calls,1);
 await context.get({...document,document_id:4},1);assert.equal(calls,2);context.window.VKUser.id=2;await context.get(document,1);assert.equal(calls,3);
 fail=true;await assert.rejects(context.get(document,2));fail=false;await context.get(document,2);assert.equal(calls,5);
});
