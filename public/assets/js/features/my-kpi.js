(function () {
    let poll = null, version = 0, pending = false;
    const escape = (value) => VKTable.escapeHtml(String(value ?? ''));
    const number = (value) => new Intl.NumberFormat('vi-VN', {maximumFractionDigits: 2}).format(value);
    window.loadMyKpi = initialize;
    document.addEventListener('vk:ready', initialize);
    document.addEventListener('vk:page:changed', () => { if (!document.getElementById('myKpiRoot')) { clearInterval(poll); poll = null; version++; } });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(true); });
    window.addEventListener('focus', () => refresh(true));
    document.addEventListener('vk:kpi:changed', () => refresh(true));
    document.addEventListener('click', (event) => {
        if (!document.getElementById('myKpiRoot')) return;
        const acknowledge = event.target.closest('[data-kpi-acknowledge]');
        const dispute = event.target.closest('[data-kpi-dispute]');
        if (acknowledge) respond(acknowledge.dataset.kpiAcknowledge, 'acknowledge', '');
        if (dispute) openDisputeModal(dispute.dataset.kpiDispute);
    });
    function initialize() {
        const date = document.getElementById('myKpiDate');
        if (!date || date.dataset.bound) return;
        date.dataset.bound = '1';
        const now = new Date();
        date.value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
        date.addEventListener('change', () => refresh(false));
        refresh(false);
        clearInterval(poll);
        poll = setInterval(() => { if (!document.hidden) refresh(true); }, 30000);
    }
    async function refresh(silent) {
        const root = document.getElementById('myKpiRoot');
        const date = document.getElementById('myKpiDate');
        if (!root || !date?.value) return;
        if (pending && silent) return;
        const current = ++version;
        pending = true;
        if (!silent) { delete root.dataset.content; root.innerHTML = '<p class="form-note">Đang tải chỉ tiêu…</p>'; }
        try {
            const [result, confirmations] = await Promise.all([
                VKApi.request('/kpi/mine?date=' + date.value, {cache: false}),
                VKApi.request('/kpi/period-closures/mine', {cache: false}).catch(() => ({data: []})),
            ]);
            if (current !== version || document.getElementById('myKpiRoot') !== root) return;
            if (!Array.isArray(result.data)) throw new Error('Không nhận được KPI được phân.');
            const html = result.data.length ? `<div class="my-kpi-grid">${result.data.map((row) => `<article class="my-kpi-card"><div class="my-kpi-code">${escape(row.code)}</div><h3>${escape(row.name)}</h3><p class="form-note">${escape(row.period_start)} — ${escape(row.period_end)} · ${row.status === 'locked' ? 'Đã chốt kỳ' : 'Đang theo dõi'}</p><strong>${number(row.actual)} <small>/ ${number(row.target)} ${escape(row.unit)}</small></strong><div class="my-kpi-progress"><span style="width:${Math.max(0,Math.min(100,Number(row.score)))}%"></span></div><p>Điểm chỉ tiêu: <b>${number(row.score)}/100</b> · Trọng số: ${number(row.weight)}</p><p class="form-note">${escape(row.description)}</p></article>`).join('')}</div>` : '<p class="form-note">Bạn chưa được phân KPI trong kỳ này. Khi admin phân cho bạn, phòng ban hoặc chức vụ của bạn, chỉ tiêu sẽ tự xuất hiện.</p>';
            const summary = `<div class="kpi-report-summary" style="padding:20px 20px 0">${(result.meta.summaries || []).map(item => `<div><small>${escape(({month:'Tháng',quarter:'Quý',year:'Năm'})[item.period_type])}: ${escape(item.period_start)} — ${escape(item.period_end)}</small><strong>${number(item.score)}/100</strong><small>${item.indicators} chỉ tiêu · ${item.locked ? 'Đã chốt' : 'Đang theo dõi'}</small></div>`).join('')}</div>`;
            const review = renderConfirmations((confirmations.data || []).filter(item => item.closure?.period_start?.slice(0,10) <= date.value && item.closure?.period_end?.slice(0,10) >= date.value));
            const content = review + summary + html;
            if (root.dataset.content !== content) { root.innerHTML = content; root.dataset.content = content; }
            root.querySelector('[data-my-kpi-error]')?.remove();
        } catch (error) {
            if (current !== version || document.getElementById('myKpiRoot') !== root) return;
            if (silent) {
                root.querySelector('[data-my-kpi-error]')?.remove();
                root.insertAdjacentHTML('beforeend', `<p class="form-note" data-my-kpi-error>Chưa cập nhật được số liệu mới: ${escape(error.message)}</p>`);
            } else { root.innerHTML = `<p class="form-note">${escape(error.message)}</p>`; delete root.dataset.content; }
        } finally { if (current === version) pending = false; }
    }

    function renderConfirmations(rows) {
        if (!rows.length) return '';
        const labels={pending:'Chờ bạn xác nhận',acknowledged:'Đã đồng ý',disputed:'Đã gửi phản hồi',resolved:'Phản hồi đã xử lý'};
        return `<section class="kpi-review-panel"><h3>Xác nhận kết quả kỳ</h3>${rows.map(row=>`<article><div><strong>${escape(row.closure?.bundle?.name || 'Bộ KPI')} · v${escape(row.closure?.bundle?.version || '')}</strong><p class="form-note">${escape(row.closure?.period_start?.slice(0,10))} — ${escape(row.closure?.period_end?.slice(0,10))} · ${escape(labels[row.status] || row.status)}</p>${row.employee_note?`<p>Phản hồi: ${escape(row.employee_note)}</p>`:''}${row.review_note?`<p>Kết quả xử lý: ${escape(row.review_note)}</p>`:''}</div>${row.status==='pending'?`<div class="row-actions"><button class="btn primary small" data-kpi-acknowledge="${row.closure.id}">Tôi đồng ý</button><button class="btn secondary small" data-kpi-dispute="${row.closure.id}">Phản hồi sai lệch</button></div>`:''}</article>`).join('')}</section>`;
    }

    async function respond(closureId, action, note) {
        try {
            await VKApi.request('/kpi/period-closures/'+closureId+'/respond',{method:'POST',body:JSON.stringify({action,note})});
            VKModal.toast(action==='acknowledge'?'Đã xác nhận kết quả KPI.':'Đã gửi phản hồi KPI.');
            document.dispatchEvent(new CustomEvent('vk:kpi:changed'));
        } catch(error) { VKModal.toast(error.message,'danger'); }
    }

    function openDisputeModal(closureId) {
        VKModal.open('Phản hồi sai lệch KPI', `<div class="field"><label for="note">Nội dung cần kiểm tra</label><textarea id="note" name="note" rows="5" minlength="10" maxlength="2000" required placeholder="Nêu chỉ tiêu, số liệu hoặc chứng từ cần kiểm tra..."></textarea></div><p class="form-note">Quản lý cần xử lý phản hồi trước khi chốt cuối kỳ.</p>`, async form=>{
            await respond(closureId,'dispute',form.elements.note.value.trim());
            VKModal.close();
        },{submitText:'Gửi phản hồi'});
    }
})();
