(function () {
    const state = {
        notifications: [],
        unreadTotal: 0,
        loading: false,
        booted: false,
        poller: null,
    };

    function qs(selector) {
        return document.querySelector(selector);
    }

    function escapeHtml(value) {
        return window.VKTable?.escapeHtml(String(value ?? '')) || String(value ?? '');
    }

    function els() {
        return {
            center: qs('[data-notification-center]'),
            toggle: qs('[data-notification-toggle]'),
            popover: qs('[data-notification-popover]'),
            count: qs('[data-notification-count]'),
            summary: qs('[data-notification-summary]'),
            list: qs('[data-notification-list]'),
            readAll: qs('[data-notification-read-all]'),
        };
    }

    async function boot() {
        if (state.booted) return;
        const dom = els();
        if (!dom.center || !window.VKApi?.token()) return;

        state.booted = true;
        bindEvents();
        await loadNotifications();
        state.poller = window.setInterval(loadNotifications, 45000);
    }

    function bindEvents() {
        const dom = els();

        dom.toggle?.addEventListener('click', () => {
            const isOpen = dom.popover.classList.toggle('open');
            dom.toggle.classList.toggle('active', isOpen);
            dom.toggle.setAttribute('aria-expanded', String(isOpen));
            if (isOpen) {
                loadNotifications();
            }
        });

        dom.readAll?.addEventListener('click', async () => {
            const unread = state.notifications.filter((item) => item.status === 'unread');
            if (!unread.length || state.loading) return;

            dom.readAll.disabled = true;
            try {
                await Promise.all(unread.map((item) => markRead(item.id, false)));
                await loadNotifications();
                showToast('Đã đánh dấu tất cả thông báo là đã đọc.');
            } catch (error) {
                showToast(error.message || 'Không thể cập nhật thông báo.');
            } finally {
                dom.readAll.disabled = false;
            }
        });

        document.addEventListener('click', (event) => {
            const current = els();
            if (!current.center || current.center.contains(event.target)) return;
            closePopover();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closePopover();
            }
        });
    }

    async function loadNotifications() {
        if (state.loading || !window.VKApi?.token()) return;
        state.loading = true;

        try {
            const [latest, unread] = await Promise.all([
                VKApi.request('/notifications?page_size=8'),
                VKApi.request('/notifications?status=unread&page_size=1'),
            ]);

            state.notifications = latest.data || [];
            state.unreadTotal = unread.meta?.total || 0;
            render();
        } catch (error) {
            renderError(error.message || 'Không tải được thông báo.');
        } finally {
            state.loading = false;
        }
    }

    function render() {
        const dom = els();
        if (!dom.list) return;

        renderCount();
        renderSummary();
        dom.readAll.disabled = state.unreadTotal === 0;

        if (!state.notifications.length) {
            dom.list.innerHTML = '<div class="notification-empty">Chưa có thông báo.</div>';
            return;
        }

        dom.list.innerHTML = state.notifications.map(renderItem).join('');
        dom.list.querySelectorAll('[data-notification-item]').forEach((button) => {
            button.addEventListener('click', () => openNotification(Number(button.dataset.notificationItem)));
        });
    }

    function renderCount() {
        const dom = els();
        if (!dom.count) return;

        if (state.unreadTotal <= 0) {
            dom.count.classList.add('hidden');
            dom.count.textContent = '0';
            return;
        }

        dom.count.textContent = state.unreadTotal > 99 ? '99+' : String(state.unreadTotal);
        dom.count.classList.remove('hidden');
    }

    function renderSummary() {
        const dom = els();
        if (!dom.summary) return;

        dom.summary.textContent = state.unreadTotal > 0
            ? `${state.unreadTotal} thông báo chưa đọc`
            : 'Không có thông báo mới';
    }

    function renderItem(item) {
        const unread = item.status === 'unread';
        const createdAt = formatDate(item.created_at);
        const source = sourceLabel(item.source_type);

        return `
            <button class="notification-item ${unread ? 'unread' : ''}" type="button" data-notification-item="${item.id}">
                <span class="notification-title">
                    <span>${escapeHtml(item.title || 'Thông báo')}</span>
                    ${unread ? '<span class="notification-dot" aria-label="Chưa đọc"></span>' : ''}
                </span>
                <span class="notification-message">${escapeHtml(item.message || '')}</span>
                <span class="notification-meta">
                    <span>${escapeHtml(source)}</span>
                    <span>${escapeHtml(createdAt)}</span>
                </span>
            </button>
        `;
    }

    async function openNotification(id) {
        const item = state.notifications.find((row) => row.id === id);
        if (!item) return;

        try {
            if (item.status === 'unread') {
                await markRead(id, true);
            }

            if (item.action_url) {
                window.location.href = item.action_url;
                return;
            }

            await loadNotifications();
        } catch (error) {
            showToast(error.message || 'Không thể mở thông báo.');
        }
    }

    async function markRead(id, refresh = true) {
        await VKApi.request(`/notifications/${id}/read`, { method: 'POST' });
        const item = state.notifications.find((row) => row.id === id);
        if (item) {
            item.status = 'read';
        }
        if (refresh) {
            await loadNotifications();
        }
    }

    function renderError(message) {
        const dom = els();
        if (!dom.list) return;
        dom.list.innerHTML = `<div class="notification-empty">${escapeHtml(message)}</div>`;
        if (dom.summary) {
            dom.summary.textContent = 'Không tải được thông báo';
        }
    }

    function closePopover() {
        const dom = els();
        dom.popover?.classList.remove('open');
        dom.toggle?.classList.remove('active');
        dom.toggle?.setAttribute('aria-expanded', 'false');
    }

    function showToast(message) {
        if (window.VKTable?.toast) {
            window.VKTable.toast(message);
            return;
        }

        const toast = qs('#toast');
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
        window.setTimeout(() => toast.classList.remove('show'), 2500);
    }

    function sourceLabel(source) {
        return VKTable.translateEntity(source || 'System');
    }

    function formatDate(value) {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';

        return new Intl.DateTimeFormat('vi-VN', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        }).format(date);
    }

    document.addEventListener('vk:ready', boot);
    window.VKNotifications = { boot, loadNotifications };
})();
