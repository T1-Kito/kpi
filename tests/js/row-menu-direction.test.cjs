const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

for(const bottom of [180,740])test(`row menu opens below trigger at ${bottom}, never flips at table boundary`,()=>{
 const handlers={},styles={},classes=new Set(['drop-up']);
 const menu={open:true,matches:()=>true,classList:{remove:c=>classes.delete(c)},style:{setProperty:(k,v)=>styles[k]=v,removeProperty:k=>delete styles[k]},querySelector:s=>s==='summary'?{getBoundingClientRect:()=>({top:bottom-30,bottom,right:990})}:{offsetWidth:132,offsetHeight:150}};
 const context=vm.createContext({window:{innerHeight:800,innerWidth:1000,requestAnimationFrame:fn=>fn()},document:{addEventListener:(event,handler)=>handlers[event]=handler,querySelectorAll:()=>[menu]}});
 vm.runInContext(fs.readFileSync('public/assets/js/table.js','utf8'),context);
 handlers.toggle({target:menu});assert.equal(styles['--row-menu-top'],`${bottom+4}px`);assert.equal(styles['--row-menu-max-height'],`${800-bottom-12}px`);assert.equal(classes.has('drop-up'),false);
 menu.open=false;handlers.toggle({target:menu});assert.equal(styles['--row-menu-max-height'],undefined);
});
