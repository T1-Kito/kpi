const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/table.js'), 'utf8');
const handler = source.slice(source.indexOf('    function updateList('), source.indexOf('    function rowActions('));

class Element {
    constructor(className, children = []) {
        this.className = className;
        this.children = [];
        children.forEach(child => this.insertBefore(child, null));
    }
    querySelector(selector) {
        for (const child of this.children) {
            if (child.className === selector.slice(1)) return child;
            const match = child.querySelector(selector);
            if (match) return match;
        }
        return null;
    }
    remove() {
        this.removed = true;
        if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1);
        this.parent = null;
    }
    insertBefore(child, reference) {
        if (child.parent) child.remove();
        const index = reference ? this.children.indexOf(reference) : this.children.length;
        this.children.splice(index, 0, child);
        child.parent = this;
    }
}

test('list refresh updates results and surrounding widgets without detaching filters', () => {
    const input = new Element('input');
    input.value = 'Khách hàng';
    input.selectionStart = 4;
    const filter = new Element('list-filter', [input]);
    const oldTable = new Element('old-table');
    const list = new Element('list-page', [new Element('old-header'), filter, oldTable]);
    const root = new Element('root', [new Element('old-metrics'), list, new Element('old-pagination')]);
    const newTable = new Element('new-table');
    const incoming = new Element('list-page', [new Element('new-header'), new Element('list-filter'), newTable]);
    const content = new Element('fragment', [new Element('new-metrics'), incoming, new Element('new-pagination')]);
    const context = vm.createContext({ document: { createElement: () => ({ content }) } });
    vm.runInContext(handler, context);
    context.updateList(root, 'new html');
    assert.equal(root.querySelector('.list-filter'), filter);
    assert.equal(input.removed, undefined);
    assert.equal(filter.removed, undefined);
    assert.equal(input.value, 'Khách hàng');
    assert.equal(input.selectionStart, 4);
    assert.equal(oldTable.removed, true);
    assert.deepEqual(root.children.map(node => node.className), ['new-metrics', 'list-page', 'new-pagination']);
    assert.deepEqual(list.children.map(node => node.className), ['new-header', 'list-filter', 'new-table']);
});

test('initial render falls back to full markup when no filter exists', () => {
    const root = new Element('root');
    const context = vm.createContext({ document: { createElement: () => ({ content: new Element('fragment') }) } });
    vm.runInContext(handler, context);
    context.updateList(root, 'initial markup');
    assert.equal(root.innerHTML, 'initial markup');
});
