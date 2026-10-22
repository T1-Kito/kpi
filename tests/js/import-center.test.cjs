const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

function setup(){
    let modal;const calls=[];
    const context=vm.createContext({window:{},document:{addEventListener(){},getElementById(){return null;}},Date,VKTable:{escapeHtml:value=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')},VKModal:{open:(title,html,handler,options)=>{modal={title,html,handler,options};},close(){},toast(){}},VKApi:{request:async(...args)=>{calls.push(args);return {data:{id:1,catalog:'customers',status:'committed',result:{total:1,imported:1}}};}}});
    const source=fs.readFileSync('public/assets/js/features/import-center.js','utf8').replace('window.loadImportCenter=load;','window.loadImportCenter=load;window.testBatch=showBatch;');
    vm.runInContext(source,context);return {context,calls,get modal(){return modal;}};
}
test('batch details use Vietnamese labels and escape imported text',async()=>{
    const state=setup();await state.context.window.testBatch({id:1,catalog:'customers',original_name:'<img src=x>.xlsx',status:'ready',expires_at:'2099-01-01T00:00:00Z',result:{total:2,errors:[]}});
    assert.match(state.modal.html,/Khách hàng/);assert.match(state.modal.html,/Đã kiểm tra/);assert.match(state.modal.html,/Tôi đã kiểm tra/);
    assert.doesNotMatch(state.modal.html,/<img/);assert.match(state.modal.html,/&lt;img/);assert.equal(state.modal.options.hideSubmit,false);
});
test('confirm is opt-in and only immutable batch ID is sent',async()=>{
    const state=setup();await state.context.window.testBatch({id:9,catalog:'leads',status:'ready',expires_at:'2099-01-01T00:00:00Z',result:{total:1}});
    const handler=state.modal.handler;
    await assert.rejects(handler({elements:{confirmed:{checked:false}}}),/Vui lòng xác nhận/);assert.equal(state.calls.length,0);
    await handler({elements:{confirmed:{checked:true}}});assert.equal(state.calls[0][0],'/import-center/batches/9/commit');
    assert.deepEqual(JSON.parse(state.calls[0][1].body),{confirmed:true});
});
test('invalid and expired batches cannot offer a confirmation button',async()=>{
    const state=setup();
    for(const status of ['invalid','expired','committed']){
        await state.context.window.testBatch({id:1,catalog:'contacts',status,result:{errors:[{row:5,message:'<script>Không đúng dữ liệu</script>'}]}});
        assert.equal(state.modal.options.hideSubmit,true);assert.doesNotMatch(state.modal.html,/<script>/);assert.match(state.modal.html,/Dòng 5/);
    }
});
