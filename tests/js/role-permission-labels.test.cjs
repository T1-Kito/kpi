const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

function setup(){
    const context=vm.createContext({window:{},document:{addEventListener(){}}});
    vm.runInContext(fs.readFileSync('public/assets/js/table.js','utf8'),context);
    context.VKTable=context.window.VKTable;
    const source=fs.readFileSync('public/assets/js/features/roles.js','utf8').replace('window.loadRoles = loadRoles;','window.testPermissions = (rows, selected) => { roleState.permissions=rows; return renderPermissionTree(selected); };');
    vm.runInContext(source,context);
    return context.window;
}

test('every seeded permission has a Vietnamese display label',()=>{
    const {VKTable}=setup();
    const codes=new Set();
    for(const name of ['DatabaseSeeder.php','AdminOnlySeeder.php']) {
        const source=fs.readFileSync('database/seeders/'+name,'utf8');
        for(const match of source.matchAll(/'([a-z_]+(?:\.[a-z_]+)+)'\s*=>\s*'/g))codes.add(match[1]);
    }
    assert.ok(codes.size>40);
    for(const code of codes){
        const label=VKTable.translatePermission(code);
        assert.notEqual(label,code,code);
        assert.doesNotMatch(label,/dashboard|ticket|marketing/i,code);
    }
});

test('permission choices include the business object, preserve IDs and split warehouse operations',()=>{
    const app=setup();
    const codes=['inventory.receipt.view','inventory.receipt.create','inventory.issue.view','inventory.issue.create','inventory.stocktake.view','inventory.stocktake.create','inventory.report.export'];
    const html=app.testPermissions(codes.map((code,i)=>({id:i+1,code,name:code})),[2,5]);
    for(const code of codes)assert.ok(html.includes('>'+app.VKTable.translatePermission(code)+'</span>'),code);
    assert.doesNotMatch(html,/<span>(Xem|Tạo|Sửa|Xóa)<\/span>/);
    for(const label of ['Nhập kho','Xuất kho','Kiểm kê kho','Báo cáo kho'])assert.ok(html.includes('<strong>'+label+'</strong>'));
    assert.equal((html.match(/name="permission_ids"/g)||[]).length,codes.length);
    assert.match(html,/value="2" checked/);assert.match(html,/value="5" checked/);
    assert.doesNotMatch(html,/<small>inventory\./);
});
