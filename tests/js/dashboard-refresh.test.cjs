const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../../public/assets/js/features/dashboard.js'),'utf8');
const load=source.slice(source.indexOf('async function loadDashboard('),source.indexOf('function withDashboardDefaults('));
function setup(request) {
    const root={innerHTML:'old',querySelector:()=>null,insertAdjacentHTML(_p,text){this.innerHTML+=text;}};
    let active=root;
    const context=vm.createContext({dashboardLoadVersion:0,document:{getElementById:()=>active,querySelector:selector=>selector==='[data-executive-month]'?{value:'2026-10'}:null},window:{VKUser:{permissions:[]}},Date,encodeURIComponent,safeRequest:request,getDashboardProfile:()=>({type:'admin'}),withDashboardDefaults:value=>value,renderExecutiveDashboard:value=>value.text,renderDashboard:()=>'',renderDashboardLoading:()=>''});
    vm.runInContext(load,context);
    return {context,root,leave:()=>{active=null;}};
}
test('silent dashboard failure preserves last data and warns',async()=>{
    const {context,root}=setup(async()=>null);
    await context.loadDashboard({source:'pjax',silent:true});
    assert.match(root.innerHTML,/^old/);assert.match(root.innerHTML,/data-dashboard-stale/);
});
test('initial dashboard failure offers retry instead of fabricated zero metrics',async()=>{
    const {context,root}=setup(async()=>null);
    await context.loadDashboard();
    assert.match(root.innerHTML,/data-dashboard-retry/);
    assert.match(root.innerHTML,/không phải số liệu bằng 0/);
    assert.doesNotMatch(root.innerHTML,/chairman-kpis|dashboard-kpi-row/);
});
test('dashboard ignores older responses and preserves selected month',async()=>{
    const pending=[];
    const {context,root}=setup(url=>{assert.match(url,/month=2026-10/);return new Promise(resolve=>pending.push(resolve));});
    const first=context.loadDashboard({source:'pjax'}),second=context.loadDashboard({source:'pjax'});
    pending[1]({data:{executive:{text:'new'}}});await second;
    pending[0]({data:{executive:{text:'stale'}}});await first;
    assert.equal(root.innerHTML,'new');
});
test('dashboard does not write to a detached page',async()=>{
    let resolve;
    const {context,root,leave}=setup(()=>new Promise(done=>resolve=done));
    const result=context.loadDashboard({source:'pjax'});leave();resolve({data:{executive:{text:'new'}}});await result;
    assert.equal(root.innerHTML,'old');
});
test('chairman dashboard separates period money from current care and links reports to selected month',()=>{
    const table={escapeHtml:value=>String(value ?? '').replaceAll('<','&lt;'),money:value=>String(value),renderTable:()=>'<table></table>',statusBadge:String};
    const context=vm.createContext({VKTable:table,Date,Math});
    vm.runInContext(source.slice(source.indexOf('function renderChairmanDashboard('),source.indexOf('function welcomeTitle(')),context);
    const data={period:'2030-02',generated_at:'2030-02-01T00:00:00Z',revenue:100,collections:40,orders:1,receivables:60,overdue_tasks:0,governance:{},purchase:{},operations:{},funnel:{},monthly_revenue:[{label:'02/2030',revenue:100}],receivable_aging:{days_1_30:0,days_31_60:0,over_60:0},recent_orders:[],employee_work:[]};
    const html=context.renderChairmanDashboard(data);
    assert.match(html,/Chưa có số liệu/);
    assert.match(html,/from=2030-02-01&amp;to=2030-02-28|from=2030-02-01&to=2030-02-28/);
    assert.match(html,/care_gaps/);assert.match(html,/care=overdue/);
    assert.match(html,/Doanh thu không đồng nghĩa tiền đã thu/);
    assert.match(html,/Thu tiền & công nợ/);
});
