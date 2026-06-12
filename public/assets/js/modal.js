(function () {
    let submitHandler = null;

    function open(title, bodyHtml, handler) {
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalBody').innerHTML = bodyHtml;
        document.getElementById('modalBackdrop').classList.add('open');
        submitHandler = handler;
    }

    function close() {
        const form = document.getElementById('modalForm');
        document.getElementById('modalBackdrop').classList.remove('open');
        if (form) form.reset();
        submitHandler = null;
    }

    function field(name, label, type = 'text', value = '') {
        return `<div class="field"><label for="${name}">${label}</label><input id="${name}" name="${name}" type="${type}" value="${VKTable.escapeHtml(value)}"></div>`;
    }

    function select(name, label, options, selected = '') {
        return `<div class="field"><label for="${name}">${label}</label><select id="${name}" name="${name}">${options.map((item) => `<option value="${item.value}" ${String(item.value) === String(selected) ? 'selected' : ''}>${VKTable.escapeHtml(item.label)}</option>`).join('')}</select></div>`;
    }

    function toast(message) {
        const node = document.getElementById('toast');
        node.textContent = message;
        node.classList.add('show');
        setTimeout(() => node.classList.remove('show'), 2600);
    }

    document.addEventListener('click', (event) => {
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
            toast(error.message);
        } finally {
            submit.disabled = false;
        }
    });

    window.VKModal = { open, close, field, select, toast };
})();
