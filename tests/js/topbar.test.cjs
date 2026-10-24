const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');

test('application launcher is rendered alongside notifications and page actions', () => {
    const layout = read('resources/views/layouts/app.blade.php');
    assert.match(layout, /@include\('partials\.app-switcher'\)/);
    assert.match(layout, /\/assets\/css\/app-switcher\.css\?v=/);
    assert.match(layout, /@include\('partials\.notifications'\)/);
    assert.match(layout, /data-pjax-actions/);
});

test('bell is an inline SVG and retains notification controls and unread badge', () => {
    const markup = read('resources/views/partials/notifications.blade.php');
    assert.match(markup, /<svg class="notification-icon"/);
    assert.match(markup, /stroke="currentColor"/);
    assert.match(markup, /data-notification-toggle[^>]*aria-label="Thông báo"/);
    for (const hook of ['count', 'popover', 'list', 'read-all']) {
        assert.ok(markup.includes(`data-notification-${hook}`));
    }
    const css = read('public/assets/css/components.css');
    assert.match(css, /\.notification-trigger:focus-visible/);
    assert.match(css, /\.notification-trigger\[aria-expanded="true"\]/);
});
