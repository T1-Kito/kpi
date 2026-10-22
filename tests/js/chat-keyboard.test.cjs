const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync(require('node:path').join(__dirname,'../../public/assets/js/chat.js'),'utf8');
function setup(){
    const listeners={};let sent=0,prevented=0;
    const button={disabled:false};
    const form={hidden:false,querySelector:()=>button,requestSubmit(){sent++;}};
    const target={value:'Xin chào',matches:s=>s==='#chatBody',closest:()=>form};
    vm.runInNewContext(source,{document:{addEventListener:(type,fn)=>{(listeners[type]??=[]).push(fn);}},setInterval(){},Map});
    return {button,form,target,sent:()=>sent,prevented:()=>prevented,emit:(type,extra={})=>(listeners[type]||[]).forEach(fn=>fn({target,key:'Enter',preventDefault(){prevented++;},...extra}))};
}
test('Enter submits once and blocks repeated keydown',()=>{const s=setup();s.emit('keydown');s.emit('keydown',{repeat:true});assert.equal(s.sent(),1);assert.equal(s.prevented(),2);});
test('Shift Enter preserves native newline',()=>{const s=setup();s.emit('keydown',{shiftKey:true});assert.equal(s.sent(),0);assert.equal(s.prevented(),0);});
test('Vietnamese IME composition never sends accidentally',()=>{const s=setup();s.emit('compositionstart');s.emit('keydown');s.emit('compositionend');s.emit('keydown',{isComposing:true});s.emit('keydown',{keyCode:229});assert.equal(s.sent(),0);s.emit('keydown');assert.equal(s.sent(),1);});
test('blank text busy submission and hidden composer cannot send',()=>{const s=setup();s.target.value='   ';s.emit('keydown');s.target.value='Hello';s.button.disabled=true;s.emit('keydown');s.button.disabled=false;s.form.hidden=true;s.emit('keydown');assert.equal(s.sent(),0);});
