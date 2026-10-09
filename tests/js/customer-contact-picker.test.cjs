const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/customers.js'), 'utf8');
function setup() {
    const element = () => ({ value: '', attrs: {}, listeners: {},
        setAttribute(key, value) { this.attrs[key] = value; },
        addEventListener(key, callback) { this.listeners[key] = callback; }, focus() {} });
    const input = element(), id = element(), toggle = element(), options = element();
    options.querySelectorAll = () => [];
    const fields = { phone: element(), email: element() };
    const combo = element();
    combo.querySelector = selector => ({ '[name="contact_name"]': input, '[name="primary_contact_id"]': id,
        '.customer-contact-toggle': toggle, '.customer-contact-options': options })[selector];
    combo.contains = target => [input, id, toggle, options].includes(target);
    const body = element();
    body.querySelector = selector => selector === '[data-primary-contact-picker]' ? combo : fields[selector.match(/name="(.*?)"/)[1]];
    const context = vm.createContext({ VKTable: { escapeHtml: String }, document: { activeElement: input } });
    vm.runInContext(source.slice(source.indexOf('function bindCustomerPrimaryContactPicker('), source.indexOf('async function openCustomerTransactions(')), context);
    context.bindCustomerPrimaryContactPicker(body, [
        { id: 1, name: 'Lan', phone: '090123', email: 'lan@example.com' },
        { id: 2, name: 'Lan', phone: '098456', email: 'other@example.com' },
    ], false);
    return { input, id, toggle, options, fields, combo };
}
test('legal representative precedes customer name and appears once in the form', () => {
    const start = source.indexOf('async function openCustomerModal(');
    const form = source.slice(start, source.indexOf('function bindCustomerPrimaryContactPicker('));
    assert.ok(form.indexOf("customerField('legal_representative'") < form.indexOf("customerField('name'"));
    assert.equal((form.match(/customerField\('legal_representative'/g) || []).length, 1);
    assert.ok(!form.includes('Gán người liên hệ có sẵn'));
});

test('representative position appears once in the upper customer section', () => {
    const form = source.slice(source.indexOf('async function openCustomerModal('), source.indexOf('function syncCustomerBusinessFields('));
    assert.equal((form.match(/customerField\('representative_position'/g) || []).length, 1);
    assert.ok(form.indexOf("customerField('representative_position'") < form.indexOf('Thông tin xuất hóa đơn'));
    assert.match(form, /Cá nhân \/ khách lẻ \/ vãng lai/);
});

test('business fields are hidden and excluded from submission for individual customers', () => {
    const inputs = [{ value: 'Giám đốc' }, { value: 'Nguyễn An' }];
    const fields = inputs.map(input => ({ querySelectorAll: () => [input] }));
    const body = { querySelectorAll: () => fields };
    const context = vm.createContext({});
    vm.runInContext(source.slice(source.indexOf('function syncCustomerBusinessFields('), source.indexOf('function bindCustomerPrimaryContactPicker(')), context);
    context.syncCustomerBusinessFields(body, true, false);
    assert.ok(fields.every(field => field.hidden));
    assert.ok(inputs.every(input => input.disabled));
    context.syncCustomerBusinessFields(body, false, false);
    assert.ok(fields.every(field => !field.hidden));
    assert.ok(inputs.every(input => !input.disabled));
    assert.equal(inputs[0].value, 'Giám đốc');
    context.syncCustomerBusinessFields(body, false, true);
    assert.ok(inputs.every(input => input.disabled));
});
test('dropdown opens all contacts and filters by phone or email', () => {
    const s = setup();
    s.input.listeners.focus();
    assert.equal(s.options.hidden, false);
    assert.match(s.options.innerHTML, /data-primary-contact-option="1"/);
    assert.match(s.options.innerHTML, /data-primary-contact-option="2"/);
    for (const term of ['098456', 'other@example.com']) {
        s.input.value = term;
        s.input.listeners.input();
        assert.match(s.options.innerHTML, /data-primary-contact-option="2"/);
        assert.doesNotMatch(s.options.innerHTML, /data-primary-contact-option="1"/);
    }
});
test('selection uses contact ID even with duplicate names, then typing clears it', () => {
    const s = setup();
    s.options.listeners.click({ target: { closest: () => ({ dataset: { primaryContactOption: '2' } }) } });
    assert.equal(s.id.value, '2');
    assert.equal(s.input.value, 'Lan');
    assert.equal(s.fields.phone.value, '098456');
    assert.equal(s.fields.email.readOnly, true);
    assert.equal(s.options.hidden, true);
    s.input.value = 'Tên mới';
    s.input.listeners.input();
    assert.equal(s.id.value, '');
    assert.equal(s.fields.email.readOnly, false);
});
test('moving focus within dropdown does not dismiss it', () => {
    const s = setup();
    s.input.listeners.focus();
    s.combo.listeners.focusout({ relatedTarget: s.toggle });
    assert.equal(s.options.hidden, false);
    s.combo.listeners.focusout({ relatedTarget: null });
    assert.equal(s.options.hidden, true);
});
