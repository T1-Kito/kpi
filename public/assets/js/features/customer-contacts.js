(function () {
    let rows = [], page = 1, query = '', timer, sequence = 0;
    const esc = value => VKTable.escapeHtml(value || '');
    window.loadCustomerContacts = async function () {
        const root = document.getElementById('customerContactsRoot');
        if (!root) return;
        const version = ++sequence;
        if (!root.querySelector('[data-contact-search]')) {
            root.innerHTML = VKTable.fullList({title:'Người liên hệ',subtitle:'Một khách hàng có thể có nhiều đầu mối; liên hệ chính được đánh dấu riêng.',meta:'Đang tải…',filters:`<div class="field"><input data-contact-search placeholder="Tìm tên, điện thoại, email hoặc khách hàng…" value="${esc(query)}"></div>`,table:'<div data-contact-results></div>'}) + '<div data-contact-pagination></div>';
        }
        try {
            const response = await VKApi.request(`/customer-contacts?page=${page}&q=${encodeURIComponent(query)}`);
            if (version !== sequence || document.getElementById('customerContactsRoot') !== root) return;
            rows = response.data;
            root.querySelector('.list-meta').textContent = `${VKTable.money(response.meta.total)} người liên hệ`;
            root.querySelector('[data-contact-results]').innerHTML = VKTable.renderTable([
                {label:'Họ tên',render:row => `<span class="customer-name-accent">${esc(row.name) || '—'}</span>`},
                {label:'Loại liên hệ',render:row => row.is_primary ? '<span class="badge success">Liên hệ chính</span>' : 'Thông thường'},
                {label:'Mã khách hàng',render:row => `<a class="mono customer-code-accent" href="/customers?open=${encodeURIComponent(row.customer_id)}">${esc(row.customer?.code)}</a>`},
                {label:'Tên khách hàng',render:row => `<a class="customer-name-accent" href="/customers?open=${encodeURIComponent(row.customer_id)}">${esc(row.customer?.name)}</a>`},
                {label:'Chức vụ',render:row => esc(row.position) || '—'},
                {label:'Điện thoại',render:row => esc(row.phone) || '—'},
                {label:'Email',render:row => esc(row.email) || '—'},
                {label:'',render:row => VKLayout.hasPermission('master.manage') ? `<button class="btn secondary small" data-edit-contact="${row.id}">Chỉnh sửa</button>` : ''}
            ],rows,'Chưa có người liên hệ phù hợp.');
            root.querySelector('[data-contact-pagination]').innerHTML = `<div class="button-row" style="margin-top:16px"><button class="btn secondary small" data-contact-page="${page-1}" ${page <= 1 ? 'disabled' : ''}>Trước</button><span>Trang ${page} / ${response.meta.last_page}</span><button class="btn secondary small" data-contact-page="${page+1}" ${page >= response.meta.last_page ? 'disabled' : ''}>Sau</button></div>`;
        } catch(error) {
            if (version !== sequence || document.getElementById('customerContactsRoot') !== root) return;
            root.querySelector('.list-meta').textContent = 'Không tải được kết quả';
            root.querySelector('[data-contact-results]').innerHTML = `<div class="empty"><strong>Không tải được người liên hệ</strong><span>${esc(error.message)}</span><button class="btn secondary" data-contact-retry>Thử lại</button></div>`;
            root.querySelector('[data-contact-pagination]').innerHTML = '';
        }
    };
    document.addEventListener('input', event => {
        if (!event.target.matches('[data-contact-search]')) return;
        query = event.target.value; page = 1; clearTimeout(timer);
        ++sequence;
        timer = setTimeout(window.loadCustomerContacts,400);
    });
    document.addEventListener('click', event => {
        if (!document.getElementById('customerContactsRoot')) return;
        if (event.target.closest('[data-create-contact]')) openContact();
        const edit = event.target.closest('[data-edit-contact]');
        if (edit) openContact(rows.find(row => String(row.id) === edit.dataset.editContact));
        const pager = event.target.closest('[data-contact-page]');
        if (pager && !pager.disabled) { page = Number(pager.dataset.contactPage); window.loadCustomerContacts(); }
        if (event.target.closest('[data-contact-retry]')) window.loadCustomerContacts();
    });
    async function openContact(contact = null) {
        let customers = [];
        if (!contact) {
            let current = 1, total;
            do {
                const response = await VKApi.request(`/customers?page_size=100&page=${current++}`);
                customers.push(...response.data.filter(row => row.customer_type === 'organization'));
                total = response.meta.total;
            } while ((current-1)*100 < total);
        }
        const customerField = `<div class="field contact-customer-combo"><label for="contactCustomerName">Tên khách hàng *</label><input id="contactCustomerName" name="customer_name" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="contactCustomerSuggestions" placeholder="Tìm khách đã có hoặc nhập tên mới" value="${esc(contact?.customer?.name)}" ${contact ? 'readonly' : ''}><input type="hidden" name="customer_id" value="${contact?.customer_id || ''}"><div id="contactCustomerSuggestions" class="contact-customer-suggestions" hidden></div><small data-customer-hint>${contact ? 'Khách hàng đang liên kết' : 'Chọn khách có sẵn hoặc nhập tên để tạo khách mới khi lưu.'}</small></div>`;
        VKModal.open(contact ? 'Chỉnh sửa người liên hệ' : 'Thêm người liên hệ', `<div class="form-grid two"><div class="field"><label for="contactCustomerCode">Mã khách hàng</label><input id="contactCustomerCode" type="text" value="${esc(contact?.customer?.code)}" readonly placeholder="Tự điền khi chọn hoặc tạo khách hàng"></div>${customerField}${VKModal.field('name','Họ tên *','text',contact?.name || '')}${VKModal.field('position','Chức vụ','text',contact?.position || '')}${VKModal.field('phone','Điện thoại','text',contact?.phone || '')}${VKModal.field('email','Email','email',contact?.email || '')}</div><label class="contact-primary-choice"><input type="checkbox" name="is_primary" ${contact?.is_primary ? 'checked' : ''}><span>Liên hệ chính</span></label>`, async form => {
            const values = Object.fromEntries(new FormData(form));
            if (!values.customer_name.trim() || !values.name.trim()) throw new Error('Nhập tên khách hàng và họ tên người liên hệ.');
            await VKApi.request(contact ? `/customers/${contact.customer_id}/contacts/${contact.id}` : '/customer-contacts',{method:contact?'PUT':'POST',body:JSON.stringify({...values,customer_id:values.customer_id ? Number(values.customer_id) : null,customer_name:values.customer_name.trim(),is_primary:Boolean(values.is_primary)})});
            VKModal.toast('Đã lưu người liên hệ.');
            window.loadCustomerContacts();
        },{className:'contact-editor-modal',submitText:'Lưu người liên hệ'});
        if (!contact) {
            const input = document.getElementById('contactCustomerName');
            const hidden = document.querySelector('.contact-editor-modal [name="customer_id"]');
            const box = document.getElementById('contactCustomerSuggestions');
            const code = document.getElementById('contactCustomerCode');
            const hint = document.querySelector('[data-customer-hint]');
            const show = () => {
                const term = input.value.trim().toLocaleLowerCase('vi');
                const matches = customers.filter(row => `${row.name} ${row.code}`.toLocaleLowerCase('vi').includes(term)).slice(0,12);
                box.innerHTML = matches.map(row => `<button type="button" data-choose-customer="${row.id}"><span>${esc(row.name)}</span><small>${esc(row.code)}</small></button>`).join('') + (term && !customers.some(row => row.name.toLocaleLowerCase('vi') === term) ? `<div class="contact-new-customer">Khách mới: ${esc(input.value.trim())}<small>Sẽ tạo khách hàng và người liên hệ khi bấm Lưu.</small></div>` : '');
                box.hidden = !box.innerHTML;
                input.setAttribute('aria-expanded',String(!box.hidden));
            };
            input.addEventListener('focus',show);
            input.addEventListener('input', () => { hidden.value = ''; code.value = ''; hint.textContent = 'Chọn từ gợi ý hoặc lưu tên mới để tạo khách hàng.'; show(); });
            input.addEventListener('keydown', event => { if (event.key === 'Escape') { event.stopPropagation(); box.hidden = true; input.setAttribute('aria-expanded','false'); } });
            box.addEventListener('click', event => {
                const button = event.target.closest('[data-choose-customer]');
                if (!button) return;
                const customer = customers.find(row => String(row.id) === button.dataset.chooseCustomer);
                input.value = customer.name; hidden.value = customer.id; code.value = customer.code;
                hint.textContent = 'Đã chọn khách hàng có sẵn.'; box.hidden = true; input.setAttribute('aria-expanded','false');
            });
            const modal = input.closest('.contact-editor-modal');
            modal.addEventListener('click', event => { if (!event.target.closest('.contact-customer-combo')) { box.hidden = true; input.setAttribute('aria-expanded','false'); } });
        }
    }
    document.addEventListener('vk:ready',window.loadCustomerContacts);
})();
