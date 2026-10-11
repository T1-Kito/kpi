const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../../public/assets/js/features/kpi-settings.js'),'utf8');
const modal = source.slice(source.indexOf('    function openAssignmentModal('),source.indexOf('    async function lockAssignment('));
const tick = () => new Promise(resolve => setImmediate(resolve));

function setup(request) {
    const root = {innerHTML:'',textContent:''};
    const field = {innerHTML:''};
    const elements = {
        audience_type:{value:'company',addEventListener(){}},
        metric:{value:'tasks_completed',addEventListener(){}},
    };
    const form = {elements,querySelector:id=>id==='#kpiAudiencePreview' ? root : field};
    let current = form;
    const context = vm.createContext({
        data:{activeDefinitions:[{id:1,code:'KPI-WORKFLOW',name:'Work'}],assignmentMeta:{metrics:{tasks_completed:{label:'Work',description:'Evidence'}}}},
        document:{getElementById:id=>id==='modalForm' ? current : {textContent:''}},
        VKApi:{request},VKTable:{escapeHtml:value=>String(value).replaceAll('<','&lt;').replaceAll('>','&gt;')},
        VKModal:{open(){},select(){return '';},field(){return '';},toast(){}},Date,
        dateInputValue:value=>value || '',
    });
    vm.runInContext(modal,context);
    return {context,root,close:()=>{current=null;}};
}

test('KPI audience preview renders escaped employees and missing master data warnings',async()=>{
    const {context,root} = setup(async(url,options)=>{
        assert.equal(url,'/kpi/assignments/preview');
        assert.deepEqual(JSON.parse(options.body),{audience_type:'company',audience_id:0});
        return {data:[{id:1,name:'<script>',department:null,position:null}],meta:{missing_department:1,missing_position:1}};
    });
    context.openAssignmentModal();
    await tick();
    assert.match(root.innerHTML,/&lt;script&gt;/);
    assert.match(root.innerHTML,/1 chưa gắn phòng ban/);
    assert.match(root.innerHTML,/ưu tiên cao hơn/);
});

test('closed KPI modal ignores pending preview results',async()=>{
    let resolve;
    const {context,root,close} = setup(()=>new Promise(done=>{resolve=done;}));
    context.openAssignmentModal();
    close();
    resolve({data:[],meta:{missing_department:0,missing_position:0}});
    await tick();
    assert.equal(root.innerHTML,'');
});

test('empty KPI group is reported explicitly, not as a successful employee assignment',async()=>{
    const {context,root} = setup(async()=>({data:[],meta:{missing_department:0,missing_position:0}}));
    context.openAssignmentModal();
    await tick();
    assert.match(root.innerHTML,/không giao KPI cho ai/);
});
