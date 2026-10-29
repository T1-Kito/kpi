const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

test('swapping tasks and approvals never reapplies the old URL to the menu',()=>{
 const source=fs.readFileSync('public/assets/js/layout.js','utf8');
 const fn=source.slice(source.indexOf('    function swapPage('),source.indexOf('    function resolveCurrentPage('));
 const active=[];
 const ctx=vm.createContext({window:{location:{pathname:'/tasks'}},document:{body:{dataset:{}},title:''},
   resolvePageKey:path=>path.slice(1),replaceHtml(){},applyNavigation:path=>active.push(path)});
 vm.runInContext(fn,ctx);
 const doc={body:{dataset:{}},title:'Page',querySelector:()=>({innerHTML:'content'})};
 ctx.swapPage(doc,'/approvals');
 ctx.window.location.pathname='/approvals';
 ctx.swapPage(doc,'/tasks');
 assert.deepEqual(active,['/approvals','/tasks']);
});

test('clicking the current customer tab restores the list after the inline form closes',async()=>{
 const source=fs.readFileSync('public/assets/js/layout.js','utf8');
 const fn=source.slice(source.indexOf('    async function navigatePjax('),source.indexOf('    async function fetchPageHtml('));
 const calls=[];let content='';
 const ctx=vm.createContext({URL,pjaxBusy:false,window:{location:{origin:'http://localhost',pathname:'/customers',search:'',href:'http://localhost/customers'}},document:{body:{classList:{add(){},remove(){}}},querySelector:()=>({setAttribute(){}}),dispatchEvent(){}},history:{pushState(){calls.push('push');}},CustomEvent:class{},DOMParser:class{parseFromString(){return {}; }},closeTransientUi(){calls.push('close');content='';},setActiveUrl(){},fetchPageHtml:async()=>{calls.push('fetch');return 'page';},swapPage(){calls.push('swap');return 'customers';},bootFeature:async page=>{calls.push(page);content='Danh sách khách hàng';}});
 vm.runInContext(fn,ctx);await ctx.navigatePjax('http://localhost/customers');
 assert.equal(content,'Danh sách khách hàng');assert.deepEqual(calls,['close','fetch','swap','customers']);assert.equal(ctx.pjaxBusy,false);
});
