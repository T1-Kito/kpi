(function () {
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-approval-decision]');
    if (!button || !document.querySelector('.approval-center')) return;
    openDecisionModal(button.dataset);
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
        window.setTimeout(() => window.location.reload(), 450);
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

window.loadApprovals = async function loadApprovals() {
    // Nội dung chính được render bằng Laravel Blade; JS chỉ xử lý quyết định.
};
})();
