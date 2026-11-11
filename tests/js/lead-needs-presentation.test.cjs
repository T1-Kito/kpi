const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/features/leads.js','utf8');
const escapeHtml=v=>String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
const context={VKTable:{escapeHtml}};
vm.runInNewContext(source.slice(source.indexOf('function leadNeedsSummary('),source.indexOf('function leadWorkflowEntry(')),context);
test('Current needs summary changes date and guest count without rewriting free text',()=>{
    const row={guest_count:50,expected_service_date:'2026-11-22',interested_services:'Vé + ăn trưa',region:'Biên Hòa',needs:'Đoàn 50 người, ngày 22/11'};
    assert.match(context.leadNeedsSummary(row),/50 khách.*22\/11\/2026/);
    row.guest_count=65;row.expected_service_date='2026-11-29';
    const result=context.leadNeedsSummary(row);
    assert.match(result,/65 khách.*29\/11\/2026/);assert.ok(!result.includes('50 khách'));
    assert.equal(row.needs,'Đoàn 50 người, ngày 22/11');
});
test('Responsibility distinguishes pending accepted and returned handovers and escapes names',()=>{
    const row={assignee:{name:'Marketing <A>'},handover_recipient:'Sales B',handover_status:'pending'};
    assert.match(context.leadResponsibility(row),/Marketing &lt;A&gt; tiếp tục phụ trách.*Sales B tiếp nhận/);
    row.handover_status='accepted';row.assignee.name='Sales B';
    assert.match(context.leadResponsibility(row),/Sales B đã tiếp nhận/);
    row.handover_status='returned';assert.match(context.leadResponsibility(row),/Sales đã trả lại/);
});
