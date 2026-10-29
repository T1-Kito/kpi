const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
function presentation(){
    const context={window:{},document:{addEventListener(){}},VKTable:{translateAction:v=>v,translateEntity:v=>({customer:'Khách hàng',quotation:'Báo giá',user:'Người dùng'}[v]||v)}};
    vm.runInNewContext(fs.readFileSync('public/assets/js/features/audit-logs.js','utf8'),context);
    return context.window.VKAuditPresentation;
}
test('Vietnamese audit explains actions and snapshot references',()=>{
    const view=presentation();
    assert.equal(view.action('update_customer'),'Cập nhật khách hàng');
    assert.equal(view.action('qualify_lead'),'Chuyển khách tiềm năng thành khách hàng và cơ hội');
    assert.equal(view.reference({new_value:{code:'CUS-01',name:'Công ty A'}}),'CUS-01 · Công ty A');
    assert.equal(view.entity('customer_contact'),'Người liên hệ');
});
test('Audit comparison includes cleared values, skips unchanged and sensitive fields',()=>{
    const rows=presentation().changes({old_value:{phone:'0900000000',email:'a@example.com',status:'active',is_shared:false,password:'old',updated_at:'yesterday'},new_value:{phone:null,email:'a@example.com',status:'inactive',is_shared:true,password:'new',updated_at:'today'}});
    assert.equal(rows.length,3);
    assert.equal(rows[0].label,'Số điện thoại');assert.equal(rows[0].after,'Chưa có');
    assert.equal(rows[1].before,'Đang hoạt động');assert.equal(rows[1].after,'Ngừng hoạt động');
    assert.equal(rows[2].before,'Không');assert.equal(rows[2].after,'Có');
    assert.ok(!JSON.stringify(rows).includes('password'));
});
