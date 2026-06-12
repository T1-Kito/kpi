(function () {
    const publicPages = ['/'];
    const sidebarStateKey = 'vk.sidebar.groups';
    const userCacheKey = 'vk.currentUser';

    const features = {
        dashboard: ['dashboard.js', 'loadDashboard'],
        leads: ['leads.js', 'loadLeads'],
        quotations: ['quotations.js', 'loadQuotations'],
        'sales-orders': ['sales-orders.js', 'loadSalesOrders'],
        customers: ['customers.js', 'loadCustomers'],
        suppliers: ['suppliers.js', 'loadSuppliers'],
        skus: ['skus.js', 'loadSkus'],
        inventory: ['inventory.js', 'loadInventory'],
        warehouses: ['warehouses.js', 'loadWarehouses'],
        'purchase-requests': ['purchase-requests.js', 'loadPurchaseRequests'],
        'purchase-orders': ['purchase-orders.js', 'loadPurchaseOrders'],
        'goods-receipts': ['goods-receipts.js', 'loadGoodsReceipts'],
        'goods-issues': ['goods-issues.js', 'loadGoodsIssues'],
        tasks: ['tasks.js', 'loadTasks'],
        alerts: ['alerts.js', 'loadAlerts'],
        kpi: ['kpi.js', 'loadKpi'],
        users: ['users.js', 'loadUsers'],
        roles: ['roles.js', 'loadRoles'],
        organization: ['organization.js', 'loadOrganization'],
        'audit-logs': ['audit-logs.js', 'loadAuditLogs'],
        settings: ['settings.js', 'loadSettings'],
    };

    let booted = false;
    let pjaxBusy = false;

    function hasPermission(code) {
        return !code || window.VKUser?.permissions?.includes(code);
    }

    async function bootLayout() {
        if (booted || publicPages.includes(window.location.pathname)) return;
        booted = true;

        hydrateUser(readJson(userCacheKey));
        applyStoredSidebarState();
        bindSidebar();
        bindPjax();

        if (!VKApi.token()) {
            window.location.href = '/';
            return;
        }

        try {
            const me = await VKApi.request('/me');
            window.VKUser = me.data;
            writeJson(userCacheKey, me.data);
            hydrateUser(me.data);
            applyNavigation();
            loadSidebarBadges();
            document.dispatchEvent(new CustomEvent('vk:ready', { detail: me.data }));
        } catch (error) {
            clearUserCache();
            VKApi.clearToken();
            window.location.href = '/';
        }
    }

    function hydrateUser(user) {
        if (!user) return;

        setText('tenantName', user.tenant?.name || 'VK-KPI');
        hydrateLogo(user.tenant?.logo_url || '');
        const userLine = document.getElementById('userLine');
        if (!userLine) return;

        const roles = Array.isArray(user.roles) ? user.roles.map(role => VKTable.translateRole(role)).join(', ') : '';
        userLine.title = user.email || '';
        setText('userName', user.name || 'Người dùng');
        setText('userRole', roles || 'Chưa có vai trò');

        const avatar = userLine.querySelector('.user-avatar');
        if (avatar) {
            avatar.textContent = String(user.name || 'U').trim().charAt(0).toUpperCase();
        }
    }

    function hydrateLogo(logoUrl) {
        const image = document.querySelector('[data-brand-logo]');
        const fallback = document.querySelector('.brand-logo');
        if (!image || !fallback) return;

        if (logoUrl) {
            image.src = logoUrl;
            image.classList.remove('hidden');
            fallback.classList.add('hidden');
            return;
        }

        image.removeAttribute('src');
        image.classList.add('hidden');
        fallback.classList.remove('hidden');
    }

    function applyNavigation() {
        document.querySelectorAll('#mainNav a').forEach((link) => {
            const permission = link.dataset.permission || '';
            if (!hasPermission(permission)) {
                link.remove();
            }
        });

        document.querySelectorAll('[data-nav-group]').forEach((group) => {
            const links = group.querySelectorAll('.nav-sub a');
            if (!links.length) {
                group.remove();
            }
        });

        setActiveUrl(window.location.pathname);
    }

    function bindSidebar() {
        document.querySelectorAll('[data-nav-toggle]').forEach((button) => {
            if (button.dataset.bound === '1') return;
            button.dataset.bound = '1';
            button.addEventListener('click', () => {
                const group = button.closest('[data-nav-group]');
                if (!group) return;
                group.classList.toggle('collapsed');
                saveSidebarState();
            });
        });
    }

    function applyStoredSidebarState() {
        const state = readJson(sidebarStateKey);
        if (!state) return;

        document.querySelectorAll('[data-nav-group]').forEach((group) => {
            const key = group.dataset.navKey;
            if (!key || typeof state[key] !== 'boolean') return;
            group.classList.toggle('collapsed', !state[key]);
        });
    }

    function saveSidebarState() {
        const state = {};
        document.querySelectorAll('[data-nav-group]').forEach((group) => {
            if (group.dataset.navKey) {
                state[group.dataset.navKey] = !group.classList.contains('collapsed');
            }
        });
        writeJson(sidebarStateKey, state);
    }

    function bindPjax() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('#mainNav a');
            if (!link || !shouldUsePjax(link)) return;

            event.preventDefault();
            navigatePjax(link.href);
        });

        window.addEventListener('popstate', () => {
            navigatePjax(window.location.href, { push: false });
        });
    }

    function shouldUsePjax(link) {
        const url = new URL(link.href, window.location.origin);
        if (url.origin !== window.location.origin) return false;
        if (link.target || link.hasAttribute('download')) return false;
        if (url.pathname === '/' || url.pathname.startsWith('/api/')) return false;
        return true;
    }

    async function navigatePjax(href, options = {}) {
        const target = new URL(href, window.location.origin);
        const push = options.push !== false;
        if (pjaxBusy) return;
        if (target.pathname === window.location.pathname && target.search === window.location.search && push) return;

        pjaxBusy = true;
        document.body.classList.add('pjax-loading');
        setActiveUrl(target.pathname);
        showContentSkeleton();

        try {
            const response = await fetch(target.href, {
                headers: {
                    'Accept': 'text/html',
                    'X-PJAX': 'true',
                },
            });

            if (!response.ok) throw new Error('Không tải được trang.');
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            swapPage(doc);

            if (push) {
                history.pushState({}, '', target.href);
            }

            setActiveUrl(target.pathname);
            await bootFeature(document.body.dataset.page);
            document.dispatchEvent(new CustomEvent('vk:page:changed', { detail: { page: document.body.dataset.page } }));
        } catch (error) {
            window.location.href = target.href;
        } finally {
            pjaxBusy = false;
            document.body.classList.remove('pjax-loading');
        }
    }

    function swapPage(doc) {
        const title = doc.querySelector('[data-pjax-title]');
        const subtitle = doc.querySelector('[data-pjax-subtitle]');
        const actions = doc.querySelector('[data-pjax-actions]');
        const content = doc.querySelector('[data-pjax-content]');
        const page = doc.body?.dataset.page || '';

        if (!content) throw new Error('Thiếu vùng nội dung.');

        document.title = doc.title || document.title;
        if (page) document.body.dataset.page = page;
        replaceHtml('[data-pjax-title]', title?.innerHTML || '');
        replaceHtml('[data-pjax-subtitle]', subtitle?.innerHTML || '');
        replaceHtml('[data-pjax-actions]', actions?.innerHTML || '');
        replaceHtml('[data-pjax-content]', content.innerHTML);
    }

    function replaceHtml(selector, html) {
        const node = document.querySelector(selector);
        if (node) node.innerHTML = html;
    }

    function showContentSkeleton() {
        replaceHtml('[data-pjax-content]', `
            <section class="pjax-skeleton" aria-label="Đang tải nội dung">
                <div class="skeleton-line wide"></div>
                <div class="skeleton-table">
                    <span></span><span></span><span></span><span></span>
                    <span></span><span></span><span></span><span></span>
                </div>
            </section>
        `);
    }

    function setActiveUrl(pathname) {
        document.querySelectorAll('#mainNav a').forEach((link) => {
            const href = new URL(link.href, window.location.origin);
            const active = normalizePath(href.pathname) === normalizePath(pathname);
            link.classList.toggle('active', active);
            if (active) {
                const group = link.closest('[data-nav-group]');
                if (group) {
                    group.classList.remove('collapsed');
                }
            }
        });
        saveSidebarState();
    }

    async function bootFeature(page) {
        const feature = features[page];
        if (!feature) return;

        const [file, fn] = feature;
        await loadScript(`/assets/js/features/${file}`);
        if (typeof window[fn] === 'function') {
            await window[fn]();
        }
    }

    function loadScript(src) {
        const current = document.querySelector(`script[src="${src}"]`);
        if (current?.dataset.loaded === '1') return Promise.resolve();

        if (current) {
            current.dataset.loaded = '1';
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.dataset.loaded = '1';
            script.addEventListener('load', resolve, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.body.appendChild(script);
        });
    }

    async function loadSidebarBadges() {
        await Promise.allSettled([
            fillTaskBadges(),
            fillAlertBadges(),
        ]);
    }

    async function fillTaskBadges() {
        if (!hasPermission('task.view')) return;
        const tasks = await VKApi.request('/tasks?mine=1&page_size=100');
        const rows = tasks.data || [];
        setBadge('sidebarTaskCount', rows.filter(row => ['new', 'in_progress', 'overdue'].includes(row.status)).length);
    }

    async function fillAlertBadges() {
        if (!hasPermission('alert.view')) return;
        const alerts = await VKApi.request('/alerts?status=open&page_size=1');
        setBadge('sidebarAlertCount', alerts.meta?.total || 0);
    }

    function setBadge(name, value) {
        const node = document.querySelector(`[data-${kebab(name)}]`);
        if (!node) return;
        node.textContent = value > 99 ? '99+' : String(value);
        node.classList.toggle('hidden', Number(value) <= 0);
    }

    function setText(id, value) {
        const node = document.getElementById(id);
        if (node) node.textContent = value;
    }

    function normalizePath(path) {
        return path.replace(/\/+$/, '') || '/';
    }

    function kebab(value) {
        return value.replace(/[A-Z]/g, match => `-${match.toLowerCase()}`);
    }

    function readJson(key) {
        try {
            const raw = localStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }

    function writeJson(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (error) {
            // Bỏ qua khi trình duyệt không cho ghi localStorage.
        }
    }

    function clearUserCache() {
        try {
            localStorage.removeItem(userCacheKey);
        } catch (error) {
            // Bỏ qua khi trình duyệt không cho ghi localStorage.
        }
    }

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-logout]')) return;
        clearUserCache();
        VKApi.clearToken();
        window.location.href = '/';
    });

    window.VKLayout = { bootLayout, hasPermission, hydrateLogo, navigatePjax };
})();
