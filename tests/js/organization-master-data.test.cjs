const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/assets/js/features/organization.js'), 'utf8');
const helper = source.slice(source.indexOf('async function loadAllOrganizationRows'), source.indexOf('async function loadOrganization()'));

test('organization fetches all master data pages instead of silently truncating', async () => {
    const urls = [];
    const context = vm.createContext({VKApi:{request:async (url, options) => {
        urls.push(url);
        assert.equal(options.cache, false);
        return {data:url.includes('page=1') ? [{id:1},{id:2}] : [{id:3}],meta:{total:3}};
    }}});
    vm.runInContext(helper, context);
    const rows = await context.loadAllOrganizationRows('/departments');
    assert.equal(rows.length,3);
    assert.deepEqual(urls, ['/departments?page_size=100&page=1','/departments?page_size=100&page=2']);
});

test('organization incomplete response raises an error instead of reporting full success', async () => {
    const context = vm.createContext({VKApi:{request:async()=>({data:[],meta:{total:1}})}});
    vm.runInContext(helper,context);
    await assert.rejects(context.loadAllOrganizationRows('/positions'), /đầy đủ/);
});
