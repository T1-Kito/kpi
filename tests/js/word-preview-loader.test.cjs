const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
test('opening quote preview directly loads Word tools once in dependency order',async()=>{
 const calls=[];const window={};
 const context=vm.createContext({window,document:{createElement:()=>({remove(){}}),head:{appendChild(script){calls.push(script.src);if(script.src.includes('jszip'))window.JSZip={};else window.docx={renderAsync(){}};script.onload();}}}});
 vm.runInContext(fs.readFileSync('public/assets/js/word-preview.js','utf8'),context);
 await Promise.all([window.VKWordPreview.ensureReady(),window.VKWordPreview.ensureReady()]);
 assert.deepEqual(calls,['/assets/vendor/jszip.min.js','/assets/vendor/docx-preview.min.js']);
 await window.VKWordPreview.ensureReady();assert.equal(calls.length,2);
});
