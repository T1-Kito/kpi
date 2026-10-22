const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/kpi-settings.js'), 'utf8');
function setup(permissions, request) {
    const root = {innerHTML:''};
    const context = vm.createContext({window:{VKUser:{permissions}},data:{definitions:[],targets:[],activeDefinitions:[]},loadVersion:0,
        document:{getElementById:()=>root},VKApi:{request},VKTable:{escapeHtml:String,statusBadge:String,money:String,renderTable:(cols,rows)=>rows.map(row=>cols.map(col=>col.render(row)).join(' ')).join('\n')}});
    vm.runInContext(source.slice(source.indexOf('    async function loadKpiSettings('),source.indexOf('    function openDefinitionModal(')),context);
    vm.runInContext(source.slice(source.indexOf('    function dateInputValue('),source.lastIndexOf('})();')),context);
    return {context,root};
}
test('settings deny UI without permission and do not request configuration',async()=>{
    let requests=0;
    const {context,root}=setup([],async()=>{requests++;});
    await context.loadKpiSettings();
    assert.equal(requests,0);
    assert.match(root.innerHTML,/chưa được cấp quyền/);
    assert.doesNotMatch(root.innerHTML,/data-create-kpi/);
});
test('authorized settings render escaped records and no editing of locked targets',async()=>{
    const {context,root}=setup(['kpi.configure'],async url=>url.includes('definitions') ? {data:[{id:1,code:'KPI-1',name:'<unsafe>',status:'active'}]} : {data:[{id:2,definition:{code:'KPI-1'},status:'locked',locked_at:'2026-10-09'}]});
    context.VKTable.escapeHtml=value=>String(value??'').replaceAll('<','&lt;');
    await context.loadKpiSettings();
    assert.match(root.innerHTML,/&lt;unsafe>/);
    assert.match(root.innerHTML,/Đã khóa/);
    assert.doesNotMatch(root.innerHTML,/data-edit-kpi-target="2"/);
    assert.match(root.innerHTML,/data-create-kpi-definition/);
});
test('settings request failure is not represented as an empty successful list',async()=>{
    const {context,root}=setup(['kpi.configure'],async()=>{throw new Error('Đã thu hồi quyền');});
    await context.loadKpiSettings();
    assert.match(root.innerHTML,/Đã thu hồi quyền/);
    assert.doesNotMatch(root.innerHTML,/data-create-kpi/);
});
