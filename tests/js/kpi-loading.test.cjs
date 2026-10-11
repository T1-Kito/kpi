const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/kpi.js'), 'utf8');

function setup(request) {
    const chart = {innerHTML: ''};
    const root = {innerHTML: '', querySelector: selector => selector === '[data-kpi-history]' ? chart : {addEventListener(){}}};
    const context = vm.createContext({kpiLoadVersion: 0, document: {getElementById: () => root},
        VKApi: {request}, VKTable: {escapeHtml: String}, renderLoading: () => 'loading',
        renderKpi: data => `summary:${data.leads.meta.total}`, trendChart: data => `history:${data.length}`,
        openRequestedKpiException() {}});
    vm.runInContext(source.slice(source.indexOf('async function loadKpi('), source.indexOf('function renderKpi(')), context);
    return {context, root, chart};
}

test('KPI renders summary before history and makes only two requests', async () => {
    const calls = [];
    let finishHistory;
    const {context, root, chart} = setup(async (url, options) => {
        calls.push({url, options});
        if (url === '/kpi/overview') return {data:{metrics:{counts:{leads:250}}, exceptions:[]}};
        return new Promise(resolve => {finishHistory = resolve;});
    });
    const pending = context.loadKpi({fresh:true});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(root.innerHTML, 'summary:250');
    assert.equal(calls.length, 2);
    assert.ok(calls.every(call => call.options.cache === false));
    finishHistory({data:[{id:1}]});
    await pending;
    assert.equal(chart.innerHTML, 'history:1');
    assert.equal(root.innerHTML, 'summary:250');
});

test('overview failure shows an error instead of fabricated zero metrics', async () => {
    const {context, root} = setup(async () => {throw new Error('Không có quyền');});
    await context.loadKpi();
    assert.match(root.innerHTML, /Không có quyền/);
    assert.match(root.innerHTML, /data-kpi-retry/);
    assert.doesNotMatch(root.innerHTML, /summary:/);
});

test('stale overview does not overwrite a later load', async () => {
    let finishOld;
    let count = 0;
    const {context, root} = setup(async url => {
        if (url !== '/kpi/overview') return {data:[]};
        if (++count === 1) return new Promise(resolve => {finishOld = resolve;});
        return {data:{metrics:{counts:{leads:99}}, exceptions:[]}};
    });
    const old = context.loadKpi();
    await context.loadKpi();
    finishOld({data:{metrics:{counts:{leads:1}}, exceptions:[]}});
    await old;
    assert.equal(root.innerHTML, 'summary:99');
});

test('KPI cards use real SVGs and no arbitrary historical comparison', () => {
    const render = source.slice(source.indexOf('function renderKpi('), source.indexOf('function buildMetrics('));
    assert.doesNotMatch(render, /delta\(/);
    assert.match(source, /<svg viewBox="0 0 24 24"/);
    assert.match(source, /Tỷ lệ hoàn thành/);
});
