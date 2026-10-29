const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function setup(templates) {
    const state = {requests:[], exports:[], value:'2', listeners:{}, notices:[]};
    const select = {get value(){return state.value;}, addEventListener(type, fn){state.listeners[type]=fn;}};
    const button = {addEventListener(type, fn){state.preview=fn;}};
    const form = {querySelector(selector){return selector.includes('template') ? select : button;}};
    const host = {closest(){return form;}, replaceChildren(){state.cleared=true;}, isConnected:true};
    const context = {
        window:{}, VKTable:{escapeHtml: v=>String(v).replaceAll('<','&lt;')},
        VKApi:{token:()=> 'test-token', request:async path=>{state.requests.push(path); return {data:templates};}},
        VKModal:{open(title, html, submit){Object.assign(state,{title,html,submit});},notice:n=>state.notices.push(n),toast:()=>{}},
        document:{querySelector:()=>host,body:{appendChild(){}},createElement:()=>({click(){},remove(){}})},
        URL:{createObjectURL:()=> 'blob:test',revokeObjectURL(){}}, setTimeout:()=>{},
        fetch:async (url, options)=>{state.exports.push({url,options});return {ok:true,blob:async()=>({})};},
    };
    vm.runInNewContext(fs.readFileSync('public/assets/js/document-export.js','utf8'),context);
    return {state, open:context.window.VKDocumentExport.open, form};
}
const choices = [{id:1,name:'Mẫu cũ',file_path:'one.docx'}, {id:2,name:'Mẫu mặc định',file_path:'two.docx',is_default:true}];
test('template picker defaults correctly and exports the selected template, including after changing selection', async()=>{
    const {state,open,form}=setup(choices);
    await open({module:'contract',endpoint:'/api/v1/contracts/7/word',title:'hợp đồng',filename:'hd.docx'});
    assert.match(state.html, /value="2" selected/);
    await state.submit(form);
    assert.equal(state.exports[0].url,'/api/v1/contracts/7/word?template_id=2');
    assert.equal(state.exports[0].options.headers.Authorization,'Bearer test-token');
    state.value='1'; state.listeners.change(); await state.submit(form);
    assert.equal(state.exports[1].url,'/api/v1/contracts/7/word?template_id=1');
});
test('issued quotation downloads the archived file without selecting a new template', async()=>{
    const {state,open,form}=setup(choices);
    await open({module:'quotation',endpoint:'/api/v1/quotations/4/word',title:'báo giá',filename:'q.docx',archived:true});
    assert.equal(state.requests.length,0);
    await state.submit(form);
    assert.equal(state.exports[0].url,'/api/v1/quotations/4/word');
});
test('no uploaded Word template gives a Vietnamese notice instead of a fake export', async()=>{
    const {state,open}=setup([{id:1,name:'HTML only',file_path:null}]);
    await open({module:'goods_issue',endpoint:'/word',title:'phiếu xuất kho',filename:'p.docx'});
    assert.equal(state.exports.length,0);
    assert.match(state.notices[0].message,/tải file .docx lên/);
});
