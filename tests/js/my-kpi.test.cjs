const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../../public/assets/js/features/my-kpi.js'),'utf8');
function setup(request) {
    const listeners = {};
    let intervals = [];
    const root = {innerHTML:'',dataset:{},closest:()=>null,querySelector:()=>null,insertAdjacentHTML(_p,html){this.innerHTML += html;}};
    const date = {value:'',dataset:{},addEventListener(type,fn){listeners['date-'+type]=fn;}};
    const context = vm.createContext({window:{addEventListener(type,fn){listeners['window-'+type]=fn;}},document:{hidden:false,getElementById:id=>id === 'myKpiRoot' ? root : date,addEventListener(type,fn){listeners[type]=fn;}},
        VKApi:{request},VKTable:{escapeHtml:value=>String(value).replaceAll('<','&lt;')},setInterval(fn,ms){intervals.push({fn,ms});return intervals.length;},clearInterval(){},Intl,Date});
    vm.runInContext(source,context);
    return {context,root,date,listeners,intervals};
}
const report = (actual=1) => ({data:[{id:1,code:'KPI-X',name:'<Test>',actual,target:2,score:actual/2*100,weight:1,period_type:'month',status:'active',period_start:'2026-10-01',period_end:'2026-10-31',unit:'việc',description:'Theo lịch sử'}],meta:{summaries:[]}});
const flush = () => new Promise(resolve=>setImmediate(resolve));
test('personal KPI polls without page reload and replaces changed data safely',async()=>{
    let calls=0;
    const state=setup(async(url)=>url.includes('period-closures')?{data:[]}:report(++calls));
    state.context.window.loadMyKpi(); await flush();
    assert.match(state.root.innerHTML,/&lt;Test>/);
    assert.equal(state.intervals[0].ms,30000);
    state.intervals[0].fn();await flush();
    assert.equal(calls,2);
    assert.match(state.root.innerHTML,/aria-valuenow="100"/);
    assert.match(state.root.innerHTML,/<strong>2<small> \/ 2 việc/);
    assert.doesNotMatch(source,/location\.reload/);
});
test('selecting another date with identical results does not leave loading markup',async()=>{
    const state=setup(async(url)=>url.includes('period-closures')?{data:[]}:report());
    state.context.window.loadMyKpi();await flush();
    state.date.value='2026-10-15';state.listeners['date-change']();await flush();
    assert.match(state.root.innerHTML,/KPI-X/);
    assert.doesNotMatch(state.root.innerHTML,/Đang tải chỉ tiêu/);
});
test('silent refresh failure retains last result and exposes stale data warning',async()=>{
    let calls=0;
    const state=setup(async(url)=>{if(url.includes('period-closures')) return {data:[]};if(++calls>1) throw new Error('Offline');return report();});
    state.context.window.loadMyKpi();await flush();
    state.intervals[0].fn();await flush();
    assert.match(state.root.innerHTML,/KPI-X/);
    assert.match(state.root.innerHTML,/Chưa cập nhật được.*Offline/);
});
