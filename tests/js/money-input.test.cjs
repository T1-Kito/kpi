const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const code=fs.readFileSync('public/assets/js/money-input.js','utf8');
function setup(){const handlers={};const context=vm.createContext({window:{},document:{querySelectorAll:()=>[],addEventListener:(key,fn)=>handlers[key]=fn,body:{}},MutationObserver:class{observe(){}}});vm.runInContext(code,context);return {...context.window.VKMoney,handlers};}
test('money formatting preserves amounts, decimals, zero and empty',()=>{const m=setup();for(const [n,text]of [[0,'0'],[1000,'1.000'],[1234567,'1.234.567'],[1234.56,'1.234,56']]){assert.equal(m.format(n),text);assert.equal(m.parse(text),n);}assert.equal(m.format(''),'');assert.equal(m.parse(''),0);});
test('money parser rejects negative, malformed, exponential and unsafe input',()=>{const m=setup();for(const s of ['-100','1e6','12.34','1,234','abc','1.2.3','9007199254740992'])assert.throws(()=>m.parse(s));});
test('input formats before existing calculation handlers, preserving caret',()=>{const m=setup();const input={value:'1234567',dataset:{moneyReady:'1'},selectionStart:7,matches:()=>true,setCustomValidity(s){this.error=s;},setSelectionRange(p){this.caret=p;}};m.handlers.input({target:input});assert.equal(input.value,'1.234.567');assert.equal(input.caret,9);assert.equal(input.error,'');});
test('typing every digit through grouping boundaries updates the parsed price and VAT',()=>{
 const m=setup();const input={value:'',dataset:{moneyReady:'1'},selectionStart:0,matches:()=>true,setCustomValidity(s){this.error=s;},setSelectionRange(p){this.caret=p;}};
 let digits='';for(const digit of '1000000'){
  digits+=digit;input.value+=digit;input.selectionStart=input.value.length;m.handlers.input({target:input});
  assert.equal(m.parse(input.value),Number(digits));assert.equal(input.error,'');
 }
 assert.equal(input.value,'1.000.000');assert.equal(m.parse(input.value)*1.08,1080000);
});
test('delete and paste regroup temporary malformed thousand separators',()=>{
 const m=setup();for(const [value,want]of [['1.000000','1.000.000'],['12.34','1.234'],['1.00','100'],['1000000,50','1.000.000,50']]){
  const i={value,dataset:{moneyReady:'1'},selectionStart:value.length,matches:()=>true,setCustomValidity(s){this.error=s;},setSelectionRange(){}};
  m.handlers.input({target:i});assert.equal(i.value,want);assert.equal(i.error,'');
 }
});
