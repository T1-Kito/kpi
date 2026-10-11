(function () {
const labels = { quotation: 'Báo giá', purchase_request: 'Yêu cầu mua', purchase_order: 'Đơn mua', kpi_adjustment: 'Phiếu điểm', kpi_exception: 'Ngoại lệ KPI' };
const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-approval-decision]');
    if (!button || !document.querySelector('.approval-center')) return;
    openDecisionModal(button.dataset);
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-approval-filter]');
    if (!form) return;
    event.preventDefault();
    loadApprovals(new FormData(form));
});

document.addEventListener('reset', (event) => {
    if (!event.target.matches('[data-approval-filter]')) return;
    setTimeout(() => loadApprovals(), 0);
});

function openDecisionModal(data) {
    const approved = data.status === 'approved';
    const title = approved ? `Duyệt ${data.code}` : `Từ chối ${data.code}`;
    const guidance = approved
        ? 'Xác nhận hồ sơ đã được kiểm tra đủ điều kiện nghiệp vụ.'
        : 'Lý do từ chối là bắt buộc và sẽ được ghi vào nhật ký hệ thống.';

    VKModal.open(title, `
        <div class="approval-decision-note ${approved ? 'approved' : 'rejected'}">
            <strong>${approved ? 'Xác nhận phê duyệt' : 'Xác nhận từ chối'}</strong>
            <span>${guidance}</span>
        </div>
        <div class="field">
            <label for="approval_reason">Lý do / ghi chú ${approved ? '' : '*'}</label>
            <textarea id="approval_reason" name="reason" rows="4" ${approved ? '' : 'required'} placeholder="Nhập nội dung để người tạo hồ sơ biết kết quả xử lý..."></textarea>
        </div>
    `, async (form) => {
        const reason = String(new FormData(form).get('reason') || '').trim();
        if (!approved && !reason) {
            throw new Error('Vui lòng nhập lý do từ chối.');
        }

        const request = decisionRequest(data.type, data.id, data.status, reason);
        await VKApi.request(request.path, {
            method: 'POST',
            body: JSON.stringify(request.body),
        });
        VKModal.close();
        VKModal.toast(approved ? `Đã duyệt ${data.code}.` : `Đã từ chối ${data.code}.`);
        await loadApprovals(new FormData(document.querySelector('[data-approval-filter]')));
    }, {
        className: 'approval-decision-modal',
        submitText: approved ? 'Xác nhận duyệt' : 'Xác nhận từ chối',
    });
}

function decisionRequest(type, id, status, reason) {
    if (type === 'quotation') {
        return { path: `/quotations/${id}/approve`, body: { status, reason } };
    }
    if (type === 'purchase_request') {
        return { path: `/purchase-requests/${id}/${status === 'approved' ? 'approve' : 'reject'}`, body: { reason } };
    }
    if (type === 'purchase_order') {
        return { path: `/purchase-orders/${id}/${status === 'approved' ? 'approve' : 'reject'}`, body: { reason } };
    }
    if (type === 'kpi_adjustment') {
        return { path: `/kpi/adjustments/${id}/review`, body: { status, review_note: reason } };
    }
    if (type === 'kpi_exception') {
        return { path: `/kpi/exceptions/${id}/review`, body: { status, review_note: reason } };
    }
    throw new Error('Loại hồ sơ chưa được hỗ trợ.');
}

function age(row) {
    const hours = Math.max(0, Math.floor((Date.now() - new Date(row.created_at).getTime()) / 3600000));
    return hours >= 24 ? { label: `Quá hạn ${Math.floor(hours / 24)} ngày`, tone: 'danger' } : { label: `Còn ${Math.max(1, 24 - hours)} giờ`, tone: hours >= 18 ? 'warning' : 'info' };
}

function dateTime(value) {
    return value ? new Intl.DateTimeFormat('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(value)) : '—';
}

function render(data) {
    Object.entries(data.metrics || {}).forEach(([key, value]) => {
        const node = document.querySelector(`[data-metric="${key}"]`);
        if (node) node.textContent = value;
    });
    document.querySelector('[data-approval-count]').textContent = `${(data.pending || []).length} hồ sơ`;
    const list = document.querySelector('[data-approval-list]');
    list.innerHTML = data.pending?.length ? `<div class="approval-list">${data.pending.map((row) => {
        const timer = age(row);
        return `<article class="approval-item ${row.risk === 'high' ? 'risk' : ''}">
            <div class="approval-kind ${esc(row.type)}" aria-hidden="true"></div>
            <div class="approval-body"><div class="approval-title-line"><div><span class="approval-type">${esc(labels[row.type] || row.type)}</span><h3>${esc(row.title)}</h3></div><span class="badge ${timer.tone}">${esc(timer.label)}</span></div>
            <div class="approval-code-line"><a class="record-code-text" href="${esc(row.path)}">${esc(row.code)}</a><span>${esc(row.owner)}</span><span>${dateTime(row.created_at)}</span></div><p>${esc(row.summary)}</p><small>${esc(row.detail)}</small></div>
            <div class="approval-actions"><a class="btn small" href="${esc(row.path)}">Mở hồ sơ</a><button class="btn small danger" type="button" data-approval-decision data-type="${esc(row.type)}" data-id="${row.id}" data-code="${esc(row.code)}" data-status="rejected">Từ chối</button><button class="btn small primary" type="button" data-approval-decision data-type="${esc(row.type)}" data-id="${row.id}" data-code="${esc(row.code)}" data-status="approved">Duyệt</button></div>
        </article>`;
    }).join('')}</div>` : '<div class="empty-state approval-empty"><strong>Không có hồ sơ đang chờ</strong><span>Hàng chờ hiện tại đã được xử lý hoặc không khớp bộ lọc.</span></div>';

    const history = document.querySelector('[data-approval-history]');
    history.innerHTML = data.recent?.length ? data.recent.map((row) => `<div class="approval-history-row"><span class="approval-history-mark ${esc(row.status)}"></span><div><strong class="record-code-text">${esc(row.code)}</strong><small>${esc(labels[row.type] || row.type)} · ${esc(row.owner)}</small></div><span class="badge ${row.status === 'approved' ? 'success' : 'danger'}">${row.status === 'approved' ? 'Đã duyệt' : 'Từ chối'}</span><time>${dateTime(row.decided_at)}</time><a href="${esc(row.path)}">Xem</a></div>`).join('') : '<div class="empty-state"><strong>Chưa có quyết định gần đây</strong></div>';
}

async function loadApprovals(formData) {
    if (!document.querySelector('.approval-center')) return;
    const params = new URLSearchParams();
    if (formData) for (const [key, value] of formData.entries()) if (value) params.set(key, value);
    try {
        const response = await VKApi.request(`/approval-center${params.size ? `?${params}` : ''}`);
        render(response.data || response);
    } catch (error) {
        document.querySelector('[data-approval-list]').innerHTML = `<div class="empty-state"><strong>Không tải được hàng chờ</strong><span>${esc(error.message || 'Vui lòng thử lại.')}</span><button class="btn small" type="button" onclick="loadApprovals()">Thử lại</button></div>`;
    }
}

window.loadApprovals = loadApprovals;
loadApprovals();
})();
