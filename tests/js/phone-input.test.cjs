const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
test('phone letters remain visible and invalid rather than becoming another number',()=>{
 const source=fs.readFileSync('public/assets/js/modal.js','utf8'),listeners={};vm.runInNewContext(source.slice(0,source.indexOf('    let submitHandler'))+'})();',{document:{addEventListener:(name,fn)=>listeners[name]=fn}});
 const input={tagName:'INPUT',type:'text',name:'phone',value:'09abc87654',setCustomValidity(v){this.error=v;}};listeners.focusin({target:input});listeners.input({target:input});assert.equal(input.value,'09abc87654');assert.ok(input.error);input.value='+84 901 234 567';listeners.input({target:input});assert.equal(input.error,'');
 const search={...input,type:'search',name:'q',value:'Nguyễn 090'};listeners.input({target:search});assert.equal(search.value,'Nguyễn 090');
});
