const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/features/print-templates.js','utf8');
function setup() {
  const context = vm.createContext({document:{addEventListener(){}},window:{},VKTable:{escapeHtml:v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;')}});
  vm.runInContext(source.replace('window.loadPrintTemplates = loadPrintTemplates;', 'window.testApi = {filterRows, renderFieldChips, previewWord, state:templateState};'),context);
  return context;
}
test('template tabs filter by module as well as search and status',()=>{
  const c = setup(), api=c.window.testApi;
  api.state.rows=[{module:'quotation',name:'A',status:'active'},{module:'contract',name:'B',status:'active'}];
  assert.equal(api.filterRows().length,1);
  api.state.module='contract'; assert.equal(api.filterRows()[0].name,'B');
  api.state.q='missing'; assert.equal(api.filterRows().length,0);
});
test('merge catalogue escapes fields, groups entries and removes duplicates',()=>{
  const c=setup(), api=c.window.testApi;
  api.state.fieldSets.quotation=[{key:'ma_hang',label:'<Mã hàng>'},{key:'ma_hang',label:'Duplicate'},{key:'tong_cong',label:'Tổng cộng'}];
  const html=api.renderFieldChips();
  assert.match(html,/Dòng hàng/); assert.match(html,/Thanh toán/); assert.match(html,/&lt;Mã hàng>/);
  assert.equal((html.match(/data-copy-merge-field="ma_hang"/g)||[]).length,1);
  assert.doesNotMatch(html,/onclick=/);
});
test('preview rejects oversized file before loading library',async()=>{
  const c=setup(), root={dataset:{},isConnected:true,textContent:''};
  await c.window.testApi.previewWord({name:'sample.docx',size:6*1024*1024},root);
  assert.match(root.textContent,/5MB/);
});
test('workspace labels original preview honestly and preserves explicit default selection',()=>{
  assert.match(source,/Mẫu gốc · chưa trộn dữ liệu/);
  assert.match(source,/renderAltChunks:false/);
  assert.match(source,/checked \? '1' : '0'/);
});

test('template editor does not crowd the upload form with the merge field catalogue',()=>{
  const modalSource=source.slice(source.indexOf('function openPrintTemplateModal'),source.indexOf('async function copyMergeField'));
  assert.doesNotMatch(modalSource,/Trường trộn mẫu Word|data-template-modal-fields|data-merge-field-search|data-download-current-guide/);
  assert.match(modalSource,/Hệ thống giữ bố cục gốc và trộn dữ liệu khi xuất chứng từ/);
});
