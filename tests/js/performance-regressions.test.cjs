const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
test('hover prefetch and navigation share a single in-flight page request',async()=>{
 const code=fs.readFileSync('public/assets/js/layout.js','utf8');let calls=0,finish;
 const context=vm.createContext({pageCache:new Map(),pageRequests:new Map(),fetch:()=>{calls++;return new Promise(resolve=>finish=resolve);},rememberPage:(key,html)=>context.pageCache.set(key,html)});
 vm.runInContext(code.slice(code.indexOf('    async function fetchPageHtml('),code.indexOf('    function rememberPage(')),context);
 const first=context.fetchPageHtml('/customers'),second=context.fetchPageHtml('/customers');assert.equal(calls,1);
 finish({ok:true,text:async()=>'<main>Customers</main>'});assert.deepEqual(await Promise.all([first,second]),['<main>Customers</main>','<main>Customers</main>']);assert.equal(context.pageRequests.size,0);
 await context.fetchPageHtml('/customers');assert.equal(calls,1);
});
test('failed page loads can retry instead of leaving a rejected in-flight promise',async()=>{
 const code=fs.readFileSync('public/assets/js/layout.js','utf8');let calls=0;
 const context=vm.createContext({pageCache:new Map(),pageRequests:new Map(),fetch:async()=>{calls++;return {ok:false};},rememberPage(){}});
 vm.runInContext(code.slice(code.indexOf('    async function fetchPageHtml('),code.indexOf('    function rememberPage(')),context);
 await assert.rejects(context.fetchPageHtml('/customers'));await assert.rejects(context.fetchPageHtml('/customers'));assert.equal(calls,2);assert.equal(context.pageRequests.size,0);
});
test('chat only marks a message read once and retries failures',async()=>{
 const code=fs.readFileSync('public/assets/js/chat.js','utf8');let calls=0,fail=false;
 const context=vm.createContext({window:{VKUser:{id:7}},readReceipts:new Map(),VKApi:{request:async()=>{calls++;if(fail)throw new Error('Network');}}});
 vm.runInContext(code.slice(code.indexOf('async function markReadOnce('),code.indexOf('async function messages(')),context);
 await context.markReadOnce(1,20);await context.markReadOnce(1,20);assert.equal(calls,1);
 fail=true;await assert.rejects(context.markReadOnce(1,21));fail=false;await context.markReadOnce(1,21);assert.equal(calls,3);
});
test('background chat polling is bounded to thirty seconds and hidden tabs do not poll',async()=>{
 const code=fs.readFileSync('public/assets/js/chat.js','utf8');let now=1000000,calls=0,tick;const events={};
 const document={hidden:false,body:{classList:{contains:()=>false}},getElementById:()=>null,querySelectorAll:()=>[],addEventListener:(name,handler)=>events[name]=handler};
 const context=vm.createContext({document,window:{VKUser:{id:7}},Map,Date:class extends Date{static now(){return now;}},setInterval:callback=>tick=callback,VKApi:{request:async()=>{calls++;return {data:[]};}},VKModal:{toast(){}}});
 vm.runInContext(code,context);await events['vk:ready']();assert.equal(calls,1);
 now+=5000;await tick();assert.equal(calls,1);now+=25000;await tick();assert.equal(calls,2);
 document.hidden=true;now+=30000;await tick();assert.equal(calls,2);
});
