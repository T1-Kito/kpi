(function () {
    const escape = value => VKTable.escapeHtml(String(value));
    function button(row, catalog) {
        if (!row.can_manage_sharing) return `<span class="catalog-sharing-label ${row.is_shared ? 'is-shared' : ''}">${row.is_shared ? 'Dùng chung' : 'Riêng tư'}</span>`;
        return `<button type="button" class="catalog-sharing-button" role="switch" aria-checked="${Boolean(row.is_shared)}" aria-label="Dùng chung ${escape(row.name || '')}" title="${row.is_shared ? 'Tắt dùng chung' : 'Bật dùng chung'}" data-catalog-sharing="${catalog}" data-catalog-id="${Number(row.id)}"><span class="catalog-switch-track" aria-hidden="true"></span><span>${row.is_shared ? 'Dùng chung' : 'Riêng tư'}</span></button>`;
    }
    function field(row = {}, readOnly = false) {
        const disabled = readOnly || (row.id && !row.can_manage_sharing);
        return `<label class="catalog-sharing-field"><span class="catalog-sharing-copy"><strong>Dùng chung</strong><small>Bật để các tài khoản khác trong công ty thấy và chọn sử dụng.</small></span><span class="catalog-switch-input"><input type="checkbox" name="is_shared" value="1" ${row.is_shared ? 'checked' : ''} ${disabled ? 'disabled' : ''} role="switch" aria-label="Dùng chung"><span class="catalog-switch-track" aria-hidden="true"></span></span></label>`;
    }
    document.addEventListener('click', async event => {
        const target = event.target.closest('[data-catalog-sharing]');
        if (!target) return;
        event.preventDefault(); event.stopImmediatePropagation();
        if (target.disabled) return;
        target.disabled = true;
        const catalog = target.dataset.catalogSharing;
        try {
            const isShared = target.getAttribute('aria-checked') !== 'true';
            await VKApi.request(`/catalog-sharing/${catalog}/${Number(target.dataset.catalogId)}`, { method: 'PUT', body: JSON.stringify({is_shared: isShared}) });
            VKModal.toast(isShared ? 'Đã bật dùng chung trong công ty.' : 'Đã chuyển về riêng tư. Admin và Chủ tịch vẫn được xem.');
            if (catalog === 'customers') await window.loadCustomers?.();
            else await window.loadSkus?.();
        } catch (error) { VKModal.toast(error.message || 'Chưa thay đổi được dùng chung.', 'danger'); }
        finally { if (target.isConnected) target.disabled = false; }
    }, true);
    window.VKCatalogSharing = {button, field};
})();
