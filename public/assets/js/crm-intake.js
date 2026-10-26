(function () {
    const esc = value => VKTable.escapeHtml(String(value ?? ''));
    const labels = { opportunity: 'Tạo cơ hội', customer: 'Chỉ lưu hồ sơ', skip: 'Bỏ qua dòng trùng', error: 'Cần sửa' };

    function previewHtml(data) {
        const counts = {};
        data.rows.forEach(row => { counts[row.action] = (counts[row.action] || 0) + 1; });
        return `<div class="intake-summary">${Object.entries(counts).map(([key, count]) => `<span class="intake-count ${key}"><strong>${count}</strong> ${labels[key]}</span>`).join('')}${data.ignored ? `<span>${data.ignored} dòng chỉ có mã/ngày/công thức được bỏ qua</span>` : ''}</div>
            ${data.error_count ? '<p class="intake-error" role="alert">Chưa lưu dữ liệu. Sửa các dòng báo lỗi rồi chọn lại file; không nhập riêng một phần danh sách.</p>' : '<p class="intake-note">Chưa lưu dữ liệu. Kiểm tra khách sẽ dùng lại, người phụ trách và các lưu ý trước khi xác nhận.</p>'}
            <div class="intake-table"><table><thead><tr><th>Dòng</th><th>Khách hàng</th><th>Kết quả</th><th>Người phụ trách / lịch hẹn</th><th>Kiểm tra / lưu ý</th></tr></thead><tbody>${data.rows.map(row => `<tr class="intake-row-${row.action}"><td>${Number(row.row)}</td><td><strong>${esc(row.customer_name || 'Chưa có tên')}</strong><small>${esc(row.customer_type_label || '')}</small>${row.customer_action ? `<small>${row.customer_action === 'reuse' ? 'Dùng lại khách' : 'Tạo khách mới'}${row.customer_code ? ` · ${esc(row.customer_code)}` : ''}</small>` : ''}${row.values ? `<details><summary>Xem 34 cột trong file</summary><dl>${Object.entries(data.labels || {}).map(([key,label])=>`<div><dt>${esc(label)}</dt><dd>${esc(row.values[key] || '—')}</dd></div>`).join('')}</dl></details>` : ''}</td><td><span class="intake-status ${row.action}">${labels[row.action]}</span>${row.action === 'opportunity' ? `<small>Xác suất ${Number(row.probability)}% · Dự báo ${row.forecast == null ? 'chưa có giá trị' : esc(VKTable.money(row.forecast))}</small>` : ''}</td><td>${esc(row.owner_name || '—')}${row.next_contact ? `<small>${row.action==='skip'?'Không tạo lại việc':'Tạo việc chăm sóc'}: ${esc(row.next_contact)}</small>` : ''}</td><td>${(row.errors || []).map(m => `<p class="intake-error">${esc(m)}</p>`).join('')}${row.warnings?.length ? `<details open><summary>${row.warnings.length} lưu ý cần xem</summary>${row.warnings.map(m => `<p>${esc(m)}</p>`).join('')}</details>` : row.errors?.length ? '' : '<span class="intake-note">Hợp lệ</span>'}</td></tr>`).join('')}</tbody></table></div>
            ${data.can_commit ? '<label class="intake-confirm"><input type="checkbox" name="intake_confirmed" required> Tôi đã kiểm tra kết quả và các lưu ý của từng dòng.</label>' : ''}`;
    }

    function open(onComplete) {
        let token = null, generation = 0;
        VKModal.open('Nhập danh sách khách hàng và nhu cầu', `<div class="intake-flow"><span class="current">1. Chọn file</span><span>2. Kiểm tra từng dòng</span><span>3. Xác nhận nhập</span></div>
            <div class="intake-upload"><div class="field"><label for="intakeSource">Nguồn danh sách</label><input id="intakeSource" name="source_name" maxlength="150" value="Danh sách tổng hợp" required><small>Dùng cùng tên nguồn khi nhập lại để nhận diện các dòng đã nhập.</small></div><div class="field"><label for="intakeFile">File Excel .xlsx · tối đa 300 dòng, 1 MB</label><input id="intakeFile" name="file" type="file" accept=".xlsx" required><small>Đọc trang PHÂN NGUỒN DỮ LIỆU theo cấu trúc 34 cột.</small></div></div>
            <p class="intake-note">Mã KH trong file là mã đối chiếu. Mã khách trong phần mềm tự sinh. Không tự ghi đè hồ sơ cũ, tạo báo giá hoặc xác nhận bán thành công.</p>
            <div class="intake-toolbar"><button type="button" class="btn small" data-intake-history>Lịch sử nhập tổng hợp</button></div><div id="intakePreview" aria-live="polite"></div>`, async form => {
            const preview = document.getElementById('intakePreview');
            if (!token) {
                const version = generation;
                const body = new FormData(); body.append('file', form.elements.file.files[0]); body.append('source_name', form.elements.source_name.value.trim());
                const result = await VKApi.request('/crm-intake/preview', { method: 'POST', body });
                if (version !== generation || document.getElementById('intakePreview') !== preview) return;
                token = result.data.can_commit ? result.data.token : null;
                preview.innerHTML = previewHtml(result.data);
                document.getElementById('modalSubmit').textContent = token ? 'Xác nhận nhập' : 'Kiểm tra lại file';
                return;
            }
            try {
                const result = await VKApi.request('/crm-intake/commit', { method: 'POST', body: { token, confirmed: !!form.elements.intake_confirmed?.checked } });
                const r = result.data;
                VKModal.close(); VKModal.toast(`Đã nhập: ${r.customers_created} khách mới, ${r.opportunities_created} cơ hội, ${r.tasks_created} việc chăm sóc. Bỏ qua ${r.skipped} dòng trùng.`);
                if (onComplete) await onComplete();
            } catch (error) {
                token = null; generation++;
                preview.innerHTML = `<p class="intake-error" role="alert">${esc(error.message || 'Không thể lưu.')} Chưa xác nhận được kết quả. Kiểm tra lại file trước khi thử tiếp; dòng đã lưu sẽ được nhận diện để không tạo trùng.</p>`;
                document.getElementById('modalSubmit').textContent = 'Kiểm tra lại file';
                throw error;
            }
        }, { className: 'crm-intake-modal', submitText: 'Kiểm tra file', cancelText: 'Đóng' });
        const invalidate = () => { token = null; generation++; document.getElementById('intakePreview').replaceChildren(); document.getElementById('modalSubmit').textContent = 'Kiểm tra file'; };
        document.getElementById('intakeSource').addEventListener('input', invalidate);
        document.getElementById('intakeFile').addEventListener('change', invalidate);
        document.querySelector('[data-intake-history]').onclick = () => history(1);
    }

    async function history(page) {
        try {
            const result = await VKApi.request(`/crm-intake/history?page=${page}`, { cache: false });
            const meta = result.meta;
            VKModal.open('Lịch sử nhập tổng hợp', `<div class="intake-history">${result.data.map(row => `<section><header><strong>${esc(row.values.company_name || row.values.person_name)}</strong><small>${esc(row.source)} · ${esc(row.created_at)}</small></header><div class="intake-toolbar"><a class="btn small" href="/customers?open=${Number(row.customer_id)}">Xem khách hàng</a>${row.deal_id ? `<a class="btn small" href="/deals?open=${Number(row.deal_id)}">Xem cơ hội</a>` : '<span>Chỉ lưu hồ sơ</span>'}</div><details><summary>Thông tin gốc từ 34 cột</summary><dl>${Object.entries(meta.labels).map(([key, label]) => `<div><dt>${esc(label)}</dt><dd>${esc(row.values[key] || '—')}</dd></div>`).join('')}</dl></details></section>`).join('') || '<p class="intake-note">Chưa có lần nhập tổng hợp trong phạm vi được xem.</p>'}</div><div class="intake-history-pages"><button type="button" class="btn small" data-intake-prev ${page <= 1 ? 'disabled' : ''}>Trang trước</button><span>Trang ${meta.page} / ${meta.last_page} · ${meta.total} dòng</span><button type="button" class="btn small" data-intake-next ${page >= meta.last_page ? 'disabled' : ''}>Trang sau</button></div>`, null, { hideSubmit: true, cancelText: 'Đóng', className: 'crm-intake-modal' });
            document.querySelector('[data-intake-prev]').onclick = () => history(page - 1);
            document.querySelector('[data-intake-next]').onclick = () => history(page + 1);
        } catch (e) { VKModal.toast(e.message, 'danger'); }
    }

    window.VKCrmIntake = { open, previewHtml };
})();
