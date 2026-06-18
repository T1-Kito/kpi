(function () {
    let submitHandler = null;
    let toastTimer = null;

    function open(title, bodyHtml, handler, options = {}) {
        const modal = document.querySelector('#modalBackdrop .modal');
        const submit = document.getElementById('modalSubmit');
        const cancel = document.querySelector('#modalForm [data-modal-close]');

        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalBody').innerHTML = bodyHtml;
        document.getElementById('modalBackdrop').classList.add('open');

        if (modal) modal.className = `modal ${options.className || ''}`.trim();
        if (submit) {
            submit.textContent = options.submitText || 'Lưu';
            submit.hidden = Boolean(options.hideSubmit);
        }
        if (cancel) cancel.textContent = options.cancelText || 'Hủy';
        submitHandler = handler;
    }

    function close() {
        const form = document.getElementById('modalForm');
        const modal = document.querySelector('#modalBackdrop .modal');
        const submit = document.getElementById('modalSubmit');
        const cancel = document.querySelector('#modalForm [data-modal-close]');

        document.getElementById('modalBackdrop').classList.remove('open');
        if (form) form.reset();
        if (modal) modal.className = 'modal';
        if (submit) {
            submit.textContent = 'Lưu';
            submit.hidden = false;
        }
        if (cancel) cancel.textContent = 'Hủy';
        submitHandler = null;
    }

    function field(name, label, type = 'text', value = '') {
        return `<div class="field"><label for="${name}">${label}</label><input id="${name}" name="${name}" type="${type}" value="${VKTable.escapeHtml(value)}"></div>`;
    }

    function select(name, label, options, selected = '') {
        return `<div class="field"><label for="${name}">${label}</label><select id="${name}" name="${name}">${options.map((item) => `<option value="${item.value}" ${String(item.value) === String(selected) ? 'selected' : ''}>${VKTable.escapeHtml(item.label)}</option>`).join('')}</select></div>`;
    }

    function toast(message, type = 'auto', options = {}) {
        const node = document.getElementById('toast');
        if (!node) return;

        window.clearTimeout(toastTimer);
        const tone = type === 'auto' ? inferToastType(message) : type;
        const icon = tone === 'danger' || tone === 'warning' ? '!' : '✓';
        const duration = Number(options.duration || (tone === 'danger' ? 6000 : 3600));

        node.className = `toast ${tone}`;
        node.innerHTML = `
            <span class="toast-icon" aria-hidden="true">${icon}</span>
            <span class="toast-message">${VKTable.escapeHtml(message)}</span>
            <button class="toast-close" type="button" data-toast-close aria-label="Đóng">×</button>
        `;
        node.classList.add('show');
        toastTimer = window.setTimeout(hideToast, duration);
    }

    function inferToastType(message) {
        const text = String(message || '').toLowerCase();
        if (text.includes('lỗi') || text.includes('không thể') || text.includes('thất bại')) return 'danger';
        if (text.startsWith('chưa ') || text.startsWith('bạn ') || text.includes('vui lòng')) return 'warning';
        return 'success';
    }

    function hideToast() {
        const node = document.getElementById('toast');
        if (!node) return;

        node.classList.remove('show');
        window.clearTimeout(toastTimer);
        toastTimer = null;
    }

    function notice({ type = 'success', title = 'Thông báo', message = '', details = [] } = {}) {
        const detailText = details.length ? ` ${details.join(' · ')}` : '';
        toast(`${message || title}${detailText}`, type, {
            duration: type === 'danger' ? 7000 : 4500,
        });
    }

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-toast-close]')) {
            hideToast();
            return;
        }
        if (event.target.matches('[data-modal-close]') || event.target.id === 'modalBackdrop') {
            close();
        }
    });

    document.addEventListener('submit', async (event) => {
        if (event.target.id !== 'modalForm' || !submitHandler) return;

        event.preventDefault();
        const submit = document.getElementById('modalSubmit');
        submit.disabled = true;
        try {
            await submitHandler(event.target);
        } catch (error) {
            toast(error.message || 'Không thể thực hiện thao tác.', 'danger');
        } finally {
            submit.disabled = false;
        }
    });

    window.VKModal = { open, close, field, select, toast, notice, hideToast };
})();
