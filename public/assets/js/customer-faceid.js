(() => {
    const escape = value => VKTable.escapeHtml(String(value ?? '—'));
    const request = (path, options) => VKApi.request(path, {cache: false, timeout: 60000, ...options});
    const date = value => value ? String(value).replace('T', ' ').slice(0, 16) : '—';
    const time = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(value)
        ? Date.parse(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(value) ? value : `${value}+07:00`) : NaN;

    function validity(right, checkedAt) {
        const start = time(right.starts_at);
        const end = time(right.expires_at);
        const now = time(checkedAt);
        if (Number.isFinite(end) && end <= now) return 'Hết hạn';
        if (Number.isFinite(start) && start > now) return 'Chưa đến giờ';
        return Number.isFinite(start) && end > now ? 'Còn hạn theo thời gian' : 'Chưa rõ thời hạn';
    }

    function rightsTable(rights, checkedAt) {
        if (!rights.length) return '<p class="form-note">Nguồn không trả quyền thiết bị cho khách này.</p>';
        return `<div class="table-wrap"><table class="list-table"><thead><tr><th>Thiết bị</th><th>Bắt đầu</th><th>Hết hạn</th><th>Gửi đến thiết bị</th><th></th></tr></thead><tbody>${rights.map(right => `<tr>
            <td><strong>${escape(right.name || right.serial)}</strong>${right.name ? `<br><small>${escape(right.serial)}</small>` : ''}</td>
            <td>${escape(date(right.starts_at))}</td><td>${escape(date(right.expires_at))}<br><small>${validity(right, checkedAt)}</small></td>
            <td><span class="badge ${right.sent === true ? 'info' : 'warning'}">${right.sent === true ? 'Nguồn báo đã gửi' : right.sent === false ? 'Chưa gửi' : 'Chưa có dữ liệu'}</span></td>
            <td>${right.serial ? `<button type="button" class="btn small" data-faceid-history="${escape(right.serial)}">Xem lần quét</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
    }

    function mount(root, customerId, allowLink = true) {
        const base = `/customers/${Number(customerId)}/faceid`;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn';
        button.textContent = allowLink ? 'Lưu trú & FaceID' : 'Tra cứu FaceID';
        button.setAttribute('aria-expanded', 'false');
        const panel = document.createElement('section');
        panel.className = 'order-service-card customer-faceid-panel';
        panel.hidden = true;
        root.append(panel);
        let loaded = false;
        let selection = 0;

        const fail = (target, error) => {
            target.textContent = `Chưa tra cứu được. ${error.message || 'Vui lòng thử lại.'}`;
        };
        const load = async () => {
            panel.innerHTML = '<header><h3>Lưu trú & FaceID</h3></header><div data-faceid-content aria-live="polite">Đang tải…</div>';
            const content = panel.querySelector('[data-faceid-content]');
            try {
                const {data} = await request(base);
                if (!data.configured) {
                    content.textContent = 'Chưa cấu hình kết nối FaceID cho công ty này. Khi có kết nối và đã liên kết khách, dữ liệu thiết bị sẽ hiển thị tại đây.';
                    return;
                }
                loaded = true;
                const editable = allowLink && data.can_link;
                content.innerHTML = `${data.guests.length ? `<div class="customer-faceid-toolbar"><label>Khách bên FaceID <select data-faceid-guest>${data.guests.map(guest => `<option value="${escape(guest.guest_id)}">${escape(guest.guest_name)} · #${escape(guest.guest_id)}</option>`).join('')}</select></label><button type="button" class="btn" data-faceid-refresh>Tải lại quyền</button>${editable ? '<button type="button" class="btn" data-faceid-unlink>Gỡ liên kết</button>' : ''}</div>` : '<p class="form-note">Hồ sơ chưa liên kết với khách bên FaceID.</p>'}
                    ${data.guests.length ? '<details><summary>Tra lượt lưu trú</summary><div class="customer-faceid-toolbar"><label>Mã lượt lưu trú tại nguồn <input data-stay-id inputmode="numeric"></label><label>Ngày thuộc lượt ở <input type="date" data-stay-day></label><button type="button" class="btn" data-stay-find>Tra lượt ở</button></div><div data-stay-result aria-live="polite"></div></details>' : ''}
                    <div data-faceid-rights aria-live="polite"></div>
                    ${editable ? '<details><summary>Liên kết khách bên FaceID</summary><div class="customer-faceid-toolbar"><label>Tên hoặc mã PIN <input type="search" data-faceid-search maxlength="100"></label><button type="button" class="btn" data-faceid-find>Tìm khách</button></div><div data-faceid-results aria-live="polite"></div></details>' : ''}`;
                const guest = content.querySelector('[data-faceid-guest]');
                const rights = content.querySelector('[data-faceid-rights]');
                const loadRights = async () => {
                    const current = ++selection;
                    const path = `${base}/guests/${guest.value}`;
                    rights.textContent = 'Đang đọc quyền thiết bị…';
                    try {
                        const {data: access} = await request(`${path}/access`);
                        if (selection !== current) return;
                        rights.innerHTML = `<p class="form-note">Đã đọc lúc ${escape(new Date(access.checked_at).toLocaleString('vi-VN', {timeZone: 'Asia/Ho_Chi_Minh'}))}. Trạng thái gửi cần được đối chiếu với phản hồi của thiết bị.</p>${rightsTable(access.rights, access.checked_at)}<div data-faceid-scans aria-live="polite"></div>`;
                        let scanRequest = 0;
                        rights.querySelectorAll('[data-faceid-history]').forEach(control => control.onclick = () => {
                            const scans = rights.querySelector('[data-faceid-scans]');
                            const show = async (page = 1) => {
                                const scan = ++scanRequest;
                                scans.textContent = 'Đang đọc nhật ký quét…';
                                try {
                                    const params = new URLSearchParams({serial: control.dataset.faceidHistory, page});
                                    const {data: history} = await request(`${path}/history?${params}`);
                                    if (!scans.isConnected || scan !== scanRequest) return;
                                    scans.innerHTML = `<h4>Nhật ký quét 7 ngày · ${escape(control.dataset.faceidHistory)}</h4>${history.items.length ? `<ul>${history.items.map(row => `<li>${escape(date(row.punchTime))} · ${escape(row.event)} · ${escape(row.status)}</li>`).join('')}</ul>` : '<p>Không có lượt quét trên trang này.</p>'}<div class="customer-faceid-toolbar"><button type="button" class="btn small" data-prev ${page === 1 ? 'disabled' : ''}>Trang trước</button><span>Trang ${page}</span><button type="button" class="btn small" data-next ${(history.total == null ? history.items.length < 20 : page * 20 >= history.total) ? 'disabled' : ''}>Trang sau</button></div>`;
                                    scans.querySelector('[data-prev]').onclick = () => show(page - 1);
                                    scans.querySelector('[data-next]').onclick = () => show(page + 1);
                                } catch (error) { if (scan === scanRequest) fail(scans, error); }
                            };
                            show();
                        });
                    } catch (error) { if (selection === current) fail(rights, error); }
                };
                if (guest) {
                    const stayResult = content.querySelector('[data-stay-result]');
                    const stayButton = content.querySelector('[data-stay-find]');
                    const findStay = async (stayId, day, page = 1) => {
                        const selectedGuest = guest.value;
                        stayResult.textContent = 'Đang tra lượt lưu trú…';
                        stayButton.disabled = true;
                        try {
                            const query = new URLSearchParams({stay_id: stayId, day, page});
                            const {data: result} = await request(`${base}/guests/${selectedGuest}/stay?${query}`);
                            if (guest.value !== selectedGuest) return;
                            const stay = result.stay;
                            if (stay) {
                                stayResult.innerHTML = `<p><strong>${escape(stay.group || `Lượt #${stay.id}`)}</strong> · ${escape(stay.booking_code)} · Phòng ${escape(stay.room)}<br>Đến ${escape(date(stay.check_in))} · Đi ${escape(date(stay.check_out))}</p>`;
                            } else if (result.more) {
                                stayResult.innerHTML = '<p>Chưa thấy mã lượt lưu trú trên trang này.</p><button type="button" class="btn small">Tra trang tiếp theo</button>';
                                stayResult.querySelector('button').onclick = () => findStay(stayId, day, page + 1);
                            } else stayResult.textContent = 'Không tìm thấy lượt lưu trú. Kiểm tra mã và ngày đã chọn.';
                        } catch (error) { if (guest.value === selectedGuest) fail(stayResult, error); }
                        finally { stayButton.disabled = false; }
                    };
                    stayButton.onclick = () => {
                        const id = content.querySelector('[data-stay-id]').value.trim();
                        const day = content.querySelector('[data-stay-day]').value;
                        if (!/^[1-9][0-9]*$/.test(id) || !day) { stayResult.textContent = 'Nhập mã lượt lưu trú và chọn ngày thuộc lượt ở.'; return; }
                        findStay(id, day);
                    };
                    guest.onchange = () => {
                        stayResult.textContent = '';
                        const unlinkControl = content.querySelector('[data-faceid-unlink]');
                        if (unlinkControl) { delete unlinkControl.dataset.confirm; unlinkControl.textContent = 'Gỡ liên kết'; }
                        loadRights();
                    };
                    content.querySelector('[data-faceid-refresh]').onclick = loadRights;
                    loadRights();
                }
                const unlink = content.querySelector('[data-faceid-unlink]');
                if (unlink) unlink.onclick = async () => {
                    if (!unlink.dataset.confirm) {
                        unlink.dataset.confirm = '1';
                        unlink.textContent = 'Xác nhận gỡ liên kết';
                        return;
                    }
                    unlink.disabled = true;
                    try { await request(`${base}/guests/${guest.value}`, {method: 'DELETE'}); await load(); }
                    catch (error) { fail(rights, error); unlink.disabled = false; }
                };
                const find = content.querySelector('[data-faceid-find]');
                if (!find) return;
                const input = content.querySelector('[data-faceid-search]');
                const results = content.querySelector('[data-faceid-results]');
                const search = async (term, page = 1) => {
                    if (!term) { results.textContent = 'Nhập tên hoặc mã PIN để tìm khách.'; return; }
                    results.textContent = 'Đang tìm khách bên FaceID…';
                    find.disabled = true;
                    try {
                        const params = new URLSearchParams({search: term, page});
                        const {data: matches} = await request(`${base}/guests?${params}`);
                        results.innerHTML = matches.items.length ? `<div class="table-wrap"><table class="list-table"><thead><tr><th>Khách</th><th>Mã PIN</th><th>Điện thoại</th><th></th></tr></thead><tbody>${matches.items.map(row => `<tr><td>${escape(row.fullName)}</td><td>${escape(row.pin ?? row.PIN)}</td><td>${escape(row.phoneNumber)}</td><td><button type="button" class="btn small" data-link-guest="${escape(row.id)}">Liên kết</button></td></tr>`).join('')}</tbody></table></div>` : '<p>Không tìm thấy khách phù hợp.</p>';
                        if (page > 1 || matches.total > page * matches.pageSize) {
                            results.insertAdjacentHTML('beforeend', `<div class="customer-faceid-toolbar"><button type="button" class="btn small" data-prev ${page === 1 ? 'disabled' : ''}>Trang trước</button><span>Trang ${page}</span><button type="button" class="btn small" data-next ${matches.total <= page * matches.pageSize ? 'disabled' : ''}>Trang sau</button></div>`);
                            results.querySelector('[data-prev]').onclick = () => search(term, page - 1);
                            results.querySelector('[data-next]').onclick = () => search(term, page + 1);
                        }
                        results.querySelectorAll('[data-link-guest]').forEach(control => control.onclick = async () => {
                            if (!control.dataset.confirm) {
                                results.querySelectorAll('[data-link-guest]').forEach(other => { delete other.dataset.confirm; other.textContent = 'Liên kết'; });
                                control.dataset.confirm = '1';
                                control.textContent = 'Xác nhận liên kết';
                                return;
                            }
                            control.disabled = true;
                            try {
                                await request(`${base}/guests`, {method: 'POST', body: JSON.stringify({guest_id: Number(control.dataset.linkGuest)})});
                                await load();
                            } catch (error) { fail(results, error); }
                        });
                    } catch (error) { fail(results, error); }
                    finally { find.disabled = false; }
                };
                find.onclick = () => search(input.value.trim());
                input.onkeydown = event => { if (event.key === 'Enter') { event.preventDefault(); if (!find.disabled) find.click(); } };
            } catch (error) { fail(content, error); }
        };

        button.onclick = () => {
            panel.hidden = !panel.hidden;
            button.setAttribute('aria-expanded', String(!panel.hidden));
            if (!panel.hidden) {
                if (!loaded) load();
                panel.scrollIntoView({block: 'nearest'});
            }
        };
        return {button, panel};
    }

    window.VKCustomerFaceId = {mount};
})();
