(function () {
    let data = { definitions: [], activeDefinitions: [], targets: [] };

    window.loadKpiSettings = loadKpiSettings;

    document.addEventListener('click', (event) => {
        if (!document.getElementById('kpiSettingsRoot')) return;

        const createDefinition = event.target.closest('[data-create-kpi-definition]');
        if (createDefinition) {
            openDefinitionModal();
            return;
        }

        const editDefinition = event.target.closest('[data-edit-kpi-definition]');
        if (editDefinition) {
            openDefinitionModal(findDefinition(editDefinition.dataset.editKpiDefinition));
            return;
        }

        const createTarget = event.target.closest('[data-create-kpi-target]');
        if (createTarget) {
            openTargetModal(null, createTarget.dataset.createKpiTarget || '');
            return;
        }

        const editTarget = event.target.closest('[data-edit-kpi-target]');
        if (editTarget) {
            openTargetModal(findTarget(editTarget.dataset.editKpiTarget));
            return;
        }

        const lockTarget = event.target.closest('[data-lock-kpi-target]');
        if (lockTarget) {
            lockKpiTarget(lockTarget.dataset.lockKpiTarget);
        }
    });

    function loadKpiSettings() {
        readData();
    }

    function readData() {
        const node = document.getElementById('kpiSettingsData');
        if (!node) return;
        try {
            data = JSON.parse(node.textContent || '{}');
        } catch (error) {
            data = { definitions: [], activeDefinitions: [], targets: [] };
        }
    }

    function openDefinitionModal(row = null) {
        readData();
        const isEdit = Boolean(row?.id);
        VKModal.open(isEdit ? 'Sửa chỉ tiêu KPI' : 'Tạo chỉ tiêu KPI', `
            <div class="form-grid two">
                ${VKModal.field('name', 'Tên chỉ tiêu', 'text', row?.name || '')}
                ${VKModal.field('source_type', 'Nguồn dữ liệu', 'text', row?.source_type || '')}
                ${VKModal.field('formula', 'Công thức', 'text', row?.formula || '')}
                ${VKModal.field('unit', 'Đơn vị', 'text', row?.unit || '')}
                ${VKModal.field('weight', 'Trọng số', 'number', row?.weight || '1')}
                ${VKModal.select('target_direction', 'Hướng tính', [
                    { value: 'increase', label: 'Tăng là tốt' },
                    { value: 'decrease', label: 'Giảm là tốt' },
                ], row?.target_direction || 'increase')}
                ${VKModal.select('status', 'Trạng thái', [
                    { value: 'active', label: 'Đang dùng' },
                    { value: 'inactive', label: 'Tạm ngưng' },
                ], row?.status || 'active')}
            </div>
            <p class="form-note">Đây là dữ liệu nền của chỉ tiêu. Trọng số và công thức nhập tại đây hiện chưa được áp dụng vào điểm tổng công ty; điểm tổng đang theo bốn nhóm cố định ở màn Tổng quan KPI.</p>
        `, async (form) => {
            const payload = Object.fromEntries(new FormData(form));
            payload.weight = Number(payload.weight || 0);
            await VKApi.request(isEdit ? `/kpi/definitions/${row.id}` : '/kpi/definitions', {
                method: isEdit ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            VKModal.toast(isEdit ? 'Đã cập nhật chỉ tiêu KPI.' : 'Đã tạo chỉ tiêu KPI.');
            window.setTimeout(() => window.location.reload(), 450);
        }, { className: 'wide-modal', submitText: isEdit ? 'Lưu thay đổi' : 'Tạo chỉ tiêu' });
    }

    function openTargetModal(row = null, selectedDefinitionId = '') {
        readData();
        const isEdit = Boolean(row?.id);
        if (!data.activeDefinitions.length) {
            VKModal.toast('Chưa có chỉ tiêu KPI đang dùng để gán mục tiêu.', 'warning');
            return;
        }

        const now = new Date();
        const periodStart = dateInputValue(row?.period_start) || new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
        const periodEnd = dateInputValue(row?.period_end) || new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0, 10);
        const definitionOptions = data.activeDefinitions.map(item => ({
            value: item.id,
            label: `${item.code} - ${item.name}`,
        }));

        VKModal.open(isEdit ? 'Sửa mục tiêu KPI' : 'Thêm mục tiêu KPI', `
            <div class="form-grid two">
                ${VKModal.select('kpi_definition_id', 'Chỉ tiêu', definitionOptions, row?.kpi_definition_id || selectedDefinitionId || definitionOptions[0]?.value)}
                ${VKModal.select('period_type', 'Kỳ tính', [
                    { value: 'month', label: 'Tháng' },
                    { value: 'quarter', label: 'Quý' },
                    { value: 'year', label: 'Năm' },
                    { value: 'week', label: 'Tuần' },
                    { value: 'day', label: 'Ngày' },
                ], row?.period_type || 'month')}
                ${VKModal.field('period_start', 'Từ ngày', 'date', periodStart)}
                ${VKModal.field('period_end', 'Đến ngày', 'date', periodEnd)}
                ${VKModal.field('target_value', 'Mục tiêu', 'number', row?.target_value || '0')}
                ${VKModal.field('actual_value', 'Thực tế', 'number', row?.actual_value || '0')}
                ${VKModal.field('score', 'Điểm', 'number', row?.score || '0')}
                ${VKModal.select('status', 'Trạng thái', [
                    { value: 'draft', label: 'Nháp' },
                    { value: 'active', label: 'Đang theo dõi' },
                ], row?.status === 'locked' ? 'active' : (row?.status || 'active'))}
            </div>
            <p class="form-note">Mục tiêu là số cần đạt trong kỳ. “Thực tế” và “Điểm” nhập tại đây có thể được hệ thống tính lại từ dữ liệu vận hành khi chạy Tính lại KPI; hãy kiểm tra trước khi khóa.</p>
            ${row?.locked_at ? '<p class="form-note danger">Kỳ này đã khóa, hệ thống không cho sửa mục tiêu.</p>' : ''}
        `, async (form) => {
            if (row?.locked_at) {
                VKModal.toast('Kỳ KPI đã khóa, không thể sửa mục tiêu.', 'warning');
                return;
            }

            const payload = Object.fromEntries(new FormData(form));
            payload.kpi_definition_id = Number(payload.kpi_definition_id);
            payload.target_value = Number(payload.target_value || 0);
            payload.actual_value = Number(payload.actual_value || 0);
            payload.score = Number(payload.score || 0);
            await VKApi.request(isEdit ? `/kpi/targets/${row.id}` : '/kpi/targets', {
                method: isEdit ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            VKModal.toast(isEdit ? 'Đã cập nhật mục tiêu KPI.' : 'Đã thêm mục tiêu KPI.');
            window.setTimeout(() => window.location.reload(), 450);
        }, { className: 'wide-modal', submitText: isEdit ? 'Lưu mục tiêu' : 'Thêm mục tiêu' });
    }

    async function lockKpiTarget(id) {
        if (!confirm('Khóa kỳ KPI này? Sau khi khóa, mục tiêu không thể sửa nữa.')) return;

        await VKApi.request(`/kpi/targets/${id}/lock`, { method: 'POST' });
        VKModal.toast('Đã khóa kỳ KPI.');
        window.setTimeout(() => window.location.reload(), 450);
    }

    function findDefinition(id) {
        readData();
        return data.definitions.find(item => String(item.id) === String(id)) || null;
    }

    function findTarget(id) {
        readData();
        return data.targets.find(item => String(item.id) === String(id)) || null;
    }

    function dateInputValue(value) {
        if (!value) return '';
        return String(value).slice(0, 10);
    }
})();
