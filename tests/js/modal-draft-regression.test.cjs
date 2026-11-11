const {test}=require('node:test');const assert=require('node:assert/strict');const vm=require('node:vm');const fs=require('node:fs');
test('clicking backdrop preserves a dirty modal instead of resetting its form',()=>{
 const listeners={};const doc={addEventListener:(key,fn)=>(listeners[key]??=[]).push(fn),getElementById(){throw new Error('Unexpected close or form reset');}};
 vm.runInNewContext(fs.readFileSync('public/assets/js/modal.js','utf8'),{document:doc,window:{},VKTable:{}});
 const target={tagName:'TEXTAREA',closest:selector=>selector==='#modalForm'?{}:null};for(const fn of listeners.input)fn({target});
 const backdrop={id:'modalBackdrop',closest:()=>null};for(const fn of listeners.click)fn({target:backdrop});assert.ok(true);
});
