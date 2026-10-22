const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=name=>fs.readFileSync(`public/assets/js/${name}`,'utf8');
const flush=()=>new Promise(resolve=>setImmediate(resolve));

test('direct KPI ledger initializes on authenticated readiness and offers retry after failure',async()=>{
    const listeners={}; const root={innerHTML:''}; let calls=0;
    const context=vm.createContext({window:{location:{search:''},VKUser:{permissions:[]}},document:{addEventListener:(type,fn)=>listeners[type]=fn,getElementById:id=>id==='kpiAdjustmentsRoot'?root:null},URLSearchParams,VKTable:{escapeHtml:String},VKApi:{request:async()=>{calls++;throw new Error('Offline');}}});
    vm.runInContext(source('features/kpi-adjustments.js'),context);
    listeners['vk:ready'](); await flush();
    assert.equal(calls,2);
    assert.match(root.innerHTML,/data-kpi-ledger-retry/);
    listeners.click({target:{closest:selector=>selector==='[data-kpi-ledger-retry]'?{}:null}}); await flush();
    assert.equal(calls,4);
});

function serviceSetup(permissions){
    const listeners={}; const calls=[]; let modal;
    const ticket={id:7,code:'TKT-SAFE',subject:'<Unsafe>',description:'Support',status:'open',worklogs:[{user:{name:'Agent'},content:'<script>bad</script>'}]};
    const context=vm.createContext({window:{VKUser:{permissions}},document:{querySelectorAll:()=>[],querySelector:()=>null,addEventListener:(type,fn)=>listeners[type]=fn},FormData:class{constructor(form){this.values=form;}get(key){return this.values[key];}},VKTable:{escapeHtml:value=>String(value).replaceAll('<','&lt;')},VKModal:{select:()=>'<select></select>',field:()=>'<input>',open:(_title,html,handler,options)=>modal={html,handler,options},close(){},toast(){}},VKApi:{request:async(url,options)=>{calls.push({url,options});return {data:ticket};}}});
    // Browser timers are explicit stubs: do not start real intervals in the VM.
    context.setInterval=(_callback,delay)=>{assert.equal(delay,60000);return 1;};
    context.clearInterval=()=>{};
    vm.runInContext(source('features/service-desk.js'),context);
    return {listeners,calls,getModal:()=>modal};
}

test('ticket UI exposes history safely and submits worklogs through the existing API',async()=>{
    const state=serviceSetup(['service.ticket.manage']);
    state.listeners.click({target:{closest:selector=>selector==='[data-ticket-open]'?{dataset:{ticketOpen:'7'}}:null}}); await flush();
    const modal=state.getModal();
    assert.match(modal.html,/&lt;Unsafe>/); assert.doesNotMatch(modal.html,/<script>/);
    assert.equal(modal.options.hideSubmit,false);
    await modal.handler({operation:'worklog',content:'Investigated',visibility:'internal'});
    assert.equal(state.calls[1].url,'/service-tickets/7/worklogs');
    assert.equal(JSON.parse(state.calls[1].options.body).content,'Investigated');
});

test('ticket viewer cannot see edit controls',async()=>{
    const state=serviceSetup(['service.ticket.view']);
    state.listeners.click({target:{closest:selector=>selector==='[data-ticket-open]'?{dataset:{ticketOpen:'7'}}:null}}); await flush();
    assert.equal(state.getModal().options.hideSubmit,true);
    assert.doesNotMatch(state.getModal().html,/name="content"/);
});

test('payment form keeps its idempotency key outside the submit callback',()=>{
    const text=source('features/sales-fulfillment.js');
    const start=text.indexOf('async function openPaymentModal(');
    const form=text.slice(start,text.indexOf('function deliveryActions(',start));
    assert.ok(form.indexOf('const paymentRequestKey')<form.indexOf('async (form)'));
    assert.match(form,/'Idempotency-Key': paymentRequestKey/);
});
