const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
test('deal codes retain the shared business-code accent without coloring ordinary fields',()=>{
 const source=fs.readFileSync('public/assets/js/record-page.js','utf8');
 const fn=source.slice(source.indexOf('    function renderDealInformation('),source.indexOf('    function renderInfo('));
 const ctx=vm.createContext({infoFields:()=>[['Mã','DEAL-00003'],['Khách hàng','<Khách>']],escape:v=>String(v).replaceAll('<','&lt;').replaceAll('>','&gt;')});
 vm.runInContext(fn,ctx);const html=ctx.renderDealInformation({});
 assert.match(html,/<dd class="record-code-text">DEAL-00003<\/dd>/);
 assert.match(html,/<dd class="">&lt;Khách&gt;<\/dd>/);
});
