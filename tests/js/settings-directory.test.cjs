const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/features/settings.js'), 'utf8');

function menu() {
    const context = vm.createContext({});
    vm.runInContext(source.slice(source.indexOf('function settingsIcon('), source.indexOf('function toggleSettingsLauncher(')), context);
    vm.runInContext(source.slice(source.indexOf('function renderSettingsMenu('), source.indexOf('function renderSettings(data)')), context);
    return context.renderSettingsMenu();
}

test('compact icon menu preserves all sections and links without introductory text', () => {
    const html = menu();
    assert.match(html, /settings-icon-menu/);
    assert.doesNotMatch(html, /settings-directory-copy|settings-directory-group|<h2|<h3|<small|<footer|QUẢN TRỊ HỆ THỐNG/);
    for (const section of ['company', 'appearance', 'assignment', 'approval', 'salesApproval', 'sla']) {
        assert.equal(html.split(`data-settings-nav="${section}"`).length - 1, 1);
    }
    for (const href of ['/organization', '/users', '/roles', '/workflows', '/sales-master-data', '/kpi-settings', '/audit-logs', '/print-templates']) {
        assert.equal(html.split(`href="${href}"`).length - 1, 1);
    }
    assert.match(html, /cần đối soát trước khi dùng tính thưởng/);
    assert.match(html, /role="dialog" aria-modal="true" aria-label="Menu thiết lập"/);
    assert.doesNotMatch(source, /data-settings-admin-extra/);
});

test('section selection preserves URL navigation and active state with the new groups', () => {
    const titles = {};
    const sections = ['company','appearance','assignment','approval','salesApproval','sla'].map(key => ({ dataset: {settingsSection:key} }));
    const buttons = sections.map(node => ({dataset:{settingsNav:node.dataset.settingsSection},classList:{toggle(name,value){this.active=value;}},setAttribute(name,value){this[name]=value;}}));
    let newUrl;
    const context = vm.createContext({URL, location:{href:'https://example.test/settings'}, history:{state:null,replaceState(state,title,url){newUrl=url;}},
        document:{getElementById(){return {querySelector(){return titles;},querySelectorAll(selector){return selector === '[data-settings-section]' ? sections : buttons;}};}}});
    vm.runInContext(source.slice(source.indexOf('function showSettingsSection('), source.indexOf('function renderLegacySettings(')), context);
    context.showSettingsSection('assignment',true);
    assert.equal(titles.textContent,'Tự động phân công');
    assert.equal(newUrl,'/settings?section=assignment');
    assert.equal(sections.filter(node => !node.hidden)[0].dataset.settingsSection,'assignment');
    assert.equal(buttons.filter(node => node.classList.active)[0].dataset.settingsNav,'assignment');
    context.showSettingsSection('unknown');
    assert.equal(sections.filter(node => !node.hidden)[0].dataset.settingsSection,'company');
});
