const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/features/service-desk.js','utf8');
test('ticket layout moves all existing fields without dropping inputs or handlers',()=>{
    const names=['customer_id','subject','description','category','priority','assignee_id','product_name','serial_number'];
    const selectors=[...names.map(n=>`[name="${n}"]`),'[data-ticket-source]','[data-ticket-deadline]','[data-feedback-document-type]','[data-feedback-document-id]','[data-ticket-document]'];
    function element(){return {children:[],classList:{add(){}},appendChild(node){this.children.push(node);},replaceChildren(node){this.children=[node];}};}
    const fields=new Map(selectors.map(selector=>{const field=element();const input={name:selector.match(/name="([^"]+)/)?.[1],closest:()=>field};field.input=input;return [selector,field];}));
    const modal=element(),body=element();body.closest=()=>modal;body.querySelector=s=>fields.get(s)?.input;
    const labels={modalTitle:{},modalSubmit:{}};
    const context={document:{createElement:element,getElementById:id=>labels[id]}};vm.createContext(context);
    const fn=source.slice(source.indexOf('    function layoutTicketForm('),source.indexOf('    async function openTicket('));
    vm.runInContext(fn+'\nlayoutTicketForm(body);',vm.createContext({...context,body}));
    const sections=body.children[0].children;assert.equal(sections.length,3);
    const moved=sections.flatMap(s=>s.children[1].children);
    assert.equal(moved.length,13);assert.equal(new Set(moved).size,13);
    for(const field of fields.values())assert.ok(moved.includes(field));
    assert.equal(labels.modalSubmit.textContent,'Tạo yêu cầu');
});
test('ticket layout scopes scrolling to body and collapses on mobile',()=>{
    const css=fs.readFileSync('public/assets/css/pages.css','utf8');
    assert.match(css,/\.modal\.ticket-create-modal\{[^}]*1080px/);
    assert.match(css,/\.ticket-create-modal #modalForm\{[^}]*min-height:0/);
    assert.match(css,/\.ticket-create-modal \.modal-body\{[^}]*overflow-y:auto/);
    assert.match(css,/@media\(max-width:640px\)/);
    assert.match(source,/document\.body\.contains\(record\)/);
});
