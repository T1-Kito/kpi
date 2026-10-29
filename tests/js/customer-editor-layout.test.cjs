const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const code=fs.readFileSync('public/assets/js/features/customers.js','utf8');

test('personal identity moves to the first customer field without clearing its value',()=>{
 const input={value:'012345678901'};
 const identity={parentElement:null,querySelector:()=>input};
 const grid=()=>({prepend(node){node.parentElement=this;}});
 const invoiceGrid=grid(),paymentGrid=grid(),mainGrid=grid();identity.parentElement=invoiceGrid;
 const invoice={hidden:false,querySelector:()=>invoiceGrid},payment={querySelector:()=>paymentGrid};
 const body={querySelector:s=>s==='[data-customer-invoice-section]'?invoice:s==='[data-customer-payment-section]'?payment:s==='[data-customer-main-section]'?{querySelector:()=>mainGrid}:{classList:{toggle(){}}}};
 const section=code.slice(code.indexOf("        const body=document.getElementById('modalBody');",code.indexOf('identityInput.pattern')),code.indexOf('    };',code.indexOf('identityInput.pattern')));
 const ctx=vm.createContext({document:{getElementById:()=>body},identityField:identity,person:true});vm.runInContext(section,ctx);
 assert.equal(identity.parentElement,mainGrid);assert.equal(invoice.hidden,true);assert.equal(input.value,'012345678901');
 ctx.person=false;vm.runInContext(`{${section}}`,ctx);assert.equal(identity.parentElement,invoiceGrid);assert.equal(invoice.hidden,false);assert.equal(input.value,'012345678901');
});

test('shared form keeps classification visible and sharing in the section heading',()=>{
 assert.match(code,/customer-section-heading[\s\S]*?VKCatalogSharing.field/);
 assert.match(code,/mainGrid.append\(group\)/);
 const css=fs.readFileSync('public/assets/css/customer-editor.css','utf8');
 assert.match(css,/grid-template-columns:repeat\(4/);assert.match(css,/is-person[\s\S]*?repeat\(3/);
 assert.match(fs.readFileSync('resources/views/layouts/app.blade.php','utf8'),/customer-editor.css/);
});
