const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
const src=fs.readFileSync('public/assets/js/features/supplier-quotations.js','utf8');
const code=src.slice(src.indexOf('    function formatPurchaseMoney('),src.indexOf("    document.addEventListener('input'"));
const ctx=vm.createContext({});vm.runInContext(code,ctx);
test('purchase money groups thousands and preserves integer payload',()=>{
 for(const [raw,want,number] of [['1000000','1.000.000',1000000],['100000','100.000',100000],['0','0',0],['000123','123',123],['1.000.000','1.000.000',1000000]]){
  const input={value:raw,selectionStart:raw.length,setSelectionRange(a){this.caret=a;}};ctx.formatPurchaseMoney(input);assert.equal(input.value,want);assert.equal(ctx.parsePurchaseMoney(input.value),number);
 }
});
test('invalid and unsafe amounts cannot be submitted',()=>{for(const raw of ['-1','1e6','12.34','abc','9007199254740992'])assert.throws(()=>ctx.parsePurchaseMoney(raw));});
test('editing in middle retains digit-relative caret',()=>{const i={value:'12.034.567',selectionStart:4,setSelectionRange(a){this.caret=a;}};ctx.formatPurchaseMoney(i);assert.equal(i.caret,4);});
