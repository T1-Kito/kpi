(function () {
    let submitHandler = null;
    let toastTimer = null;
    let inlineMount = null;
    const closeIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';

    function open(title, bodyHtml, handler, options = {}) {
        if(inlineMount)close();
        const modal = document.querySelector('#modalBackdrop .modal');
        const submit = document.getElementById('modalSubmit');
        const cancel = document.querySelector('#modalForm [data-modal-close]');
        const closeButton = document.querySelector('#modalBackdrop .modal-head [data-modal-close]');
        modal?.querySelectorAll('.qe-subtitle, .qe-preview-button, .qe-header-icon').forEach(node => node.remove());

        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalBody').innerHTML = bodyHtml;
        document.getElementById('modalBackdrop').classList.add('open');
        document.getElementById('modalBackdrop').setAttribute('aria-hidden', 'false');
        if (closeButton) {
            closeButton.innerHTML = closeIcon;
            closeButton.title = 'Đóng';
            closeButton.setAttribute('aria-label', 'Đóng cửa sổ');
        }

        if (modal) modal.className = `modal ${options.className || ''}`.trim();
        if (submit) {
            submit.textContent = options.submitText || 'Lưu';
            submit.hidden = Boolean(options.hideSubmit);
        }
        if (cancel) cancel.textContent = options.cancelText || 'Hủy';
        submitHandler = handler;
    }

    function close() {
        const onClose = inlineMount?.onClose;
        if(inlineMount){
            inlineMount.modal.setAttribute('role','dialog');inlineMount.modal.setAttribute('aria-modal','true');
            inlineMount.parent.appendChild(inlineMount.modal);
            inlineMount.page.remove();
            inlineMount=null;
        }
        const form = document.getElementById('modalForm');
        const modal = document.querySelector('#modalBackdrop .modal');
        const submit = document.getElementById('modalSubmit');
        const cancel = document.querySelector('#modalForm [data-modal-close]');

        document.getElementById('modalBackdrop').classList.remove('open');
        document.getElementById('modalBackdrop').setAttribute('aria-hidden', 'true');
        if (form) form.reset();
        if (modal) modal.className = 'modal';
        if (submit) {
            submit.textContent = 'Lưu';
            submit.hidden = false;
        }
        if (cancel) cancel.textContent = 'Hủy';
        submitHandler = null;
        if (onClose) onClose();
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
            <button class="toast-close" type="button" data-toast-close aria-label="Đóng thông báo">${closeIcon}</button>
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
        if (event.target.closest('[data-modal-close]') || event.target.id === 'modalBackdrop') {
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

    function mountPage(target, onClose = null) {
        const backdrop=document.getElementById('modalBackdrop');
        const modal=backdrop?.querySelector('.modal');
        if(!target || !modal)return;
        const page=document.createElement('section');page.className='customer-editor-page';
        inlineMount={parent:modal.parentElement,modal,page,onClose};
        page.appendChild(modal);target.replaceChildren(page);
        modal.setAttribute('role','region');modal.removeAttribute('aria-modal');
        backdrop.classList.remove('open');backdrop.setAttribute('aria-hidden','true');
    }
    function confirm({title='Kiểm tra thông tin', message='', details=[], allowContinue=true, confirmText='Vẫn lưu', cancelText='Quay lại kiểm tra'} = {}) {
        return new Promise(resolve => {
            hideToast();
            const previous=document.activeElement;
            const node=document.createElement('div');
            node.className=`toast ${allowContinue ? 'warning' : 'danger'} show`;
            node.setAttribute('role','alert');
            node.style.cssText='z-index:10050;max-width:min(640px,calc(100vw - 32px));';
            const safe=value=>VKTable.escapeHtml(String(value ?? ''));
            node.innerHTML=`<span class="toast-icon" aria-hidden="true">!</span><span class="toast-message"><strong>${safe(title)}</strong><span style="display:block;margin-top:4px;font-weight:400">${safe(message)}</span>${details.length ? `<span style="display:block;margin-top:6px;font-weight:400">${details.map(safe).join(' · ')}</span>`:''}</span><button class="toast-close" type="button" data-confirm-cancel aria-label="Đóng">${closeIcon}</button><span style="display:flex;justify-content:flex-end;gap:8px;grid-column:1 / -1"><button type="button" class="btn small" data-confirm-cancel>${safe(cancelText)}</button>${allowContinue ? `<button type="button" class="btn primary small" data-confirm-accept>${safe(confirmText)}</button>`:''}</span>`;
            function finish(value){document.removeEventListener('keydown',keyboard,true);node.remove();if(previous?.isConnected)previous.focus();resolve(value);}
            function keyboard(event){if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();finish(false);}}
            node.querySelectorAll('[data-confirm-cancel]').forEach(button=>button.addEventListener('click',()=>finish(false)));
            node.querySelector('[data-confirm-accept]')?.addEventListener('click',()=>finish(true));
            document.addEventListener('keydown',keyboard,true);document.body.appendChild(node);
        });
    }
    window.VKModal = { open, close, mountPage, field, select, toast, notice, hideToast, confirm };
    document.addEventListener('click',event=>{if(inlineMount && event.target.closest('a[href]'))close();},true);
})();
