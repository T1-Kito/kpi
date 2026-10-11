const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../../public/assets/js/features/kpi-reports.js'),'utf8');
const tick = () => new Promise(resolve=>setImmediate(resolve));
const row = {user_id:1,name:'Employee',department:'Sales',is_active:true,tasks_created:2,tasks_completed:1,tasks_overdue:0,tasks_not_started:1,net_points:0,bonus_points:0,penalty_points:0,pending_adjustments:0,kpi_score:null,kpi_indicators:0,kpi_status:'unassigned',kpi_details:[]};
const result = () => ({data:[{...row}],employees:[],meta:{period_start:'2026-10-01',period_end:'2026-10-31',as_of:'2026-10-11T10:00:00Z',scope:'company',task_basis:'Tasks',points_basis:'Points'}});
function setup(request) {
    const listeners = {}, events = {}, fields = {};
    for (const key of ['date','period_type','department_id','user_id']) fields[key]={value:key==='period_type'?'month':'',innerHTML:''};
    const form = {elements:fields,dataset:{},addEventListener:(type,fn)=>{events[type]=fn;}};
    let writes=0, content='';
    const root = {dataset:{},querySelectorAll:()=>[],get innerHTML(){return content;},set innerHTML(value){writes++;content=value;}};
    const button={disabled:true,textContent:'Xuất Excel',addEventListener(){}};
    const sync={textContent:''};
    let interval, active=true;
    const window={addEventListener:(type,fn)=>{listeners[type]=fn;}};
    const context=vm.createContext({window,document:{hidden:false,addEventListener:(type,fn)=>{listeners[type]=fn;},getElementById:id=>!active?null:({kpiReportFilters:form,kpiReportRoot:root,kpiReportSync:sync})[id],querySelector:()=>button},
        VKApi:{request},VKTable:{escapeHtml:value=>String(value).replaceAll('<','&lt;')},Date,Intl,URLSearchParams,
        FormData:class {constructor(current){this.current=current;} *[Symbol.iterator](){for(const [key,field] of Object.entries(this.current.elements))yield [key,field.value];}},
        setInterval:fn=>{interval=fn;return 1;},clearInterval(){},
    });
    vm.runInContext(source,context);
    return {window,root,form,sync,events,listeners,button,poll:()=>interval(),writes:()=>writes,leave:()=>{active=false;listeners['vk:page:changed']();}};
}
test('report polls without repainting unchanged content or reloading the page',async()=>{
    let requests=0;
    const ui=setup(async()=>{requests++;return result();});
    ui.window.loadKpiReports();await tick();
    const writes=ui.writes();
    ui.poll();await tick();
    assert.equal(requests,2);assert.equal(ui.writes(),writes);
    assert.match(ui.root.innerHTML,/Chưa được phân KPI/);
    assert.match(ui.sync.textContent,/30 giây/);
});
test('poll failure retains last report and shows freshness warning',async()=>{
    let requests=0;
    const ui=setup(async()=>{if(++requests>1)throw new Error('Offline');return result();});
    ui.window.loadKpiReports();await tick();
    const content=ui.root.innerHTML;
    ui.poll();await tick();
    assert.equal(ui.root.innerHTML,content);
    assert.match(ui.sync.textContent,/giữ số liệu lần gần nhất/);
});
test('filter changes reload automatically and stale requests cannot replace the new selection',async()=>{
    const pending=[];
    const ui=setup(()=>new Promise(resolve=>pending.push(resolve)));
    ui.window.loadKpiReports();
    ui.form.elements.date.value='2026-09-01';
    ui.events.change({target:{name:'date'}});
    const updated=result();updated.data[0].name='September';
    pending[1](updated);await tick();
    pending[0](result());await tick();
    assert.match(ui.root.innerHTML,/September/);
    assert.equal(ui.button.disabled,false);
});
test('pending poll ignores results after leaving report page',async()=>{
    let resolve;
    const ui=setup(()=>new Promise(done=>resolve=done));
    ui.window.loadKpiReports();ui.leave();
    const writes=ui.writes();resolve(result());await tick();
    assert.equal(ui.writes(),writes);
});
