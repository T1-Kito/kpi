const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
function setup(){
 const source=fs.readFileSync('public/assets/js/features/approvals.js','utf8');
 const fn=source.slice(source.indexOf('async function loadApprovals('),source.indexOf('window.loadApprovals ='));
 const root={isConnected:true};const calls=[],rendered=[];let complete;
 const ctx=vm.createContext({approvalLoadVersion:0,URLSearchParams,document:{querySelector:()=>root},VKApi:{request:path=>{calls.push(path);return new Promise(resolve=>complete=resolve);}},render:data=>rendered.push(data),esc:v=>v});
 vm.runInContext(fn,ctx);
 return {ctx,root,calls,rendered,complete:()=>complete({data:{pending:[]}})};
}
test('PJAX options do not cause approvals to throw and fall back to full page reload',async()=>{
 const s=setup();const pending=s.ctx.loadApprovals({source:'pjax'});
 assert.deepEqual(s.calls,['/approval-center']);s.complete();await pending;
 assert.equal(s.rendered.length,1);
});
test('approval filters remain supported',async()=>{
 const s=setup();const pending=s.ctx.loadApprovals(new URLSearchParams({q:'ABC',type:'quotation'}));
 assert.equal(s.calls[0],'/approval-center?q=ABC&type=quotation');s.complete();await pending;
});
test('late approvals response cannot paint after leaving the page',async()=>{
 const s=setup();const pending=s.ctx.loadApprovals({source:'pjax'});
 s.root.isConnected=false;s.complete();await pending;
 assert.equal(s.rendered.length,0);
});
