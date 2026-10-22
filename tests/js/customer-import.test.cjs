const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/features/customers.js','utf8');

function setup() {
    let handler,change,html,modalOptions;
    const input={files:[{name:'customers.csv'}],addEventListener:(_type,fn)=>change=fn};
    const result={innerHTML:'',textContent:''},button={textContent:''},calls=[];
    const form={querySelector:selector=>selector.includes('customer_import_file')?input:result};
    let response={data:{total:1,imported:0,can_import:true,errors:[],preview:[{row:2,name:'<script>unsafe</script>'}]}};
    const context=vm.createContext({
        document:{querySelector:selector=>selector.includes('customer_import_file')?input:result,getElementById:()=>button},
        VKModal:{open:(_title,body,fn,options)=>{handler=fn;html=body;modalOptions=options;}},VKTable:{escapeHtml:value=>String(value).replaceAll('<','&lt;')},
        FormData:class {constructor(){this.values={};}append(key,value){this.values[key]=value;}},
        VKApi:{request:async(url,options)=>{calls.push({url,options});return response;}}
    });
    vm.runInContext(source.slice(source.indexOf('function openCustomerImport('),source.indexOf('async function loadCustomers(')),context);
    context.openCustomerImport();
    return {input,result,button,calls,html,modalOptions,submit:()=>handler(form),change:()=>change(),response:value=>response={data:value}};
}

test('import dialog keeps technical guidance collapsed and primary controls concise',()=>{
    const state=setup();
    assert.match(state.html,/<details class="customer-import-guide">/);
    assert.doesNotMatch(state.html,/<details[^>]*\bopen\b/);
    const primary=state.html.slice(0,state.html.indexOf('<details'));
    assert.doesNotMatch(primary,/customer_type|organization|identity_number|tax_code/);
    assert.match(primary,/data-customer-import-template/);
    assert.match(primary,/customer_import_file/);
    assert.match(primary,/300 khách/);
    assert.match(primary,/\.xlsx/);
    assert.match(primary,/Excel hoặc CSV/);
    assert.equal(state.modalOptions.className,'customer-import-modal');
});

test('template download retains the Excel extension instead of forcing CSV',()=>{
    assert.match(source,/link\.download='mau-khach-hang\.xlsx'/);
});

test('file chooser overrides generic input height so its button and filename are not clipped',()=>{
    const css=fs.readFileSync('public/assets/css/pages.css','utf8');
    const rule=css.match(/\.customer-import-file input\[type=file\]\{([^}]+)\}/)[1];
    assert.match(rule,/height:auto;/);
    assert.match(rule,/min-height:64px;/);
    assert.match(rule,/box-sizing:border-box;/);
    assert.match(css,/\.customer-import-file input\[type=file\]::file-selector-button\{/);
});

test('customer import previews first, escapes file data, then explicitly confirms same file',async()=>{
    const state=setup();
    await state.submit();
    assert.equal(state.calls[0].options.body.values.confirm,'0');
    assert.match(state.result.innerHTML,/&lt;script>/);
    assert.doesNotMatch(state.result.innerHTML,/<script>/);
    assert.equal(state.button.textContent,'Xác nhận nhập');
    await state.submit();
    assert.equal(state.calls[1].options.body.values.confirm,'1');
});

test('changing file invalidates confirmation and invalid rows remain in preview mode',async()=>{
    const state=setup();
    await state.submit();
    state.input.files=[{name:'replacement.csv'}];state.change();
    assert.equal(state.button.textContent,'Kiểm tra file');
    state.response({total:1,imported:0,can_import:false,errors:[{row:2,message:'<bad>'}],preview:[]});
    await state.submit();await state.submit();
    assert.equal(state.calls[1].options.body.values.confirm,'0');
    assert.equal(state.calls[2].options.body.values.confirm,'0');
    assert.match(state.result.innerHTML,/Dòng 2/);
    assert.doesNotMatch(state.result.innerHTML,/<bad>/);
});
