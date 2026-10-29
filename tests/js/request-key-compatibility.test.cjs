const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/services/api.js','utf8');
const uuid=/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
test('HTTP LAN without randomUUID still generates valid request keys',()=>{
 const ctx={window:{crypto:{getRandomValues:require('node:crypto').webcrypto.getRandomValues.bind(require('node:crypto').webcrypto)}}};
 vm.runInNewContext(source,ctx);
 const ids=Array.from({length:100},()=>ctx.window.VKApi.newRequestKey());
 ids.forEach(id=>assert.match(id,uuid));assert.equal(new Set(ids).size,100);
});
test('modern browsers use native UUID while older clients also open forms',()=>{
 const modern={window:{crypto:{randomUUID:()=> 'native-id'}}};vm.runInNewContext(source,modern);
 assert.equal(modern.window.VKApi.newRequestKey(),'native-id');
 const legacy={window:{}};vm.runInNewContext(source,legacy);
 assert.match(legacy.window.VKApi.newRequestKey(),uuid);
});
