const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/features/print-templates.js', 'utf8');
const fn = source.slice(source.indexOf('async function downloadMergeFieldGuide()'), source.indexOf('async function downloadMergeTemplate('));

test('the complete guide downloads with authentication and restores its button', async () => {
    const button = {disabled: false}, calls = [];
    const ctx = vm.createContext({
        document: {querySelector: () => button}, VKApi: {token: () => 'test-token'},
        fetch: async (url, options) => { calls.push([url, options.headers.Authorization]); return {ok: true, blob: async () => 'docx'}; },
        downloadBlobFile: (name, blob) => calls.push([name, blob]),
        VKModal: {toast() { assert.fail('Unexpected error'); }},
    });
    vm.runInContext(fn, ctx);
    await ctx.downloadMergeFieldGuide();
    assert.deepEqual(calls, [['/api/v1/print-templates/merge-field-guide', 'Bearer test-token'], ['bo-truong-tron.docx', 'docx']]);
    assert.equal(button.disabled, false);
});

test('failed guide download shows a Vietnamese error without saving an error response as Word', async () => {
    const button = {disabled: false}, messages = [];
    const ctx = vm.createContext({
        document: {querySelector: () => button}, VKApi: {token: () => 'test-token'},
        fetch: async () => ({ok: false}), downloadBlobFile() {assert.fail('Must not download');},
        VKModal: {toast: message => messages.push(message)},
    });
    vm.runInContext(fn, ctx);
    await ctx.downloadMergeFieldGuide();
    assert.equal(button.disabled, false);
    assert.match(messages[0], /Không tải được bộ trường trộn/);
});
