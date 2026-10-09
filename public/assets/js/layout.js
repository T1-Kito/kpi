(function () {
    const publicPages = ['/'];
    const sidebarStateKey = 'vk.sidebar.groups';
    const sidebarIconKey = 'vk.sidebar.icons.v1';
    const userCacheKey = 'vk.currentUser.v3';
    const moduleLabels = {
        work: 'Công việc', sales: 'Kinh doanh', customer: 'Khách hàng', product: 'Sản phẩm', procurement: 'Mua hàng',
        inventory: 'Kho vận', finance: 'Tài chính', service: 'Dịch vụ khách hàng', kpi: 'KPI', admin: 'Quản trị',
    };
    const moduleRoutes = {
        work: ['dashboard', 'tasks', 'approvals', 'alerts'],
        sales: ['deals', 'quotations', 'sales-orders', 'contracts', 'deliveries'],
        customer: ['customers', 'leads', 'customer-contacts'],
        product: ['skus', 'product-categories', 'product-brands'],
        procurement: ['purchase-requests', 'supplier-quotations', 'purchase-orders', 'suppliers'],
        inventory: ['inventory', 'inventory-transactions', 'warehouses', 'goods-receipts', 'goods-issues', 'stock-takes'],
        finance: ['sales-invoices', 'customer-receivables', 'customer-payments'],
        service: ['service-tickets', 'warranty-claims'],
        kpi: ['kpi', 'kpi-adjustments', 'kpi-settings'],
        admin: ['users', 'roles', 'organization', 'workflows', 'print-templates', 'audit-logs', 'settings', 'sales-master-data'],
    };
    const defaultSidebarIcons = {
        dashboard: 'home',
        tasks: 'tasks',
        approval: 'approval',
        business: 'business',
        warehouse: 'warehouse',
        purchase: 'purchase',
        kpi: 'kpi',
        alerts: 'alert',
        admin: 'admin',
        print: 'print',
        workflow: 'workflow',
    };

    const features = {
        dashboard: ['dashboard.js?v=20261008-2', 'loadDashboard'],
        leads: ['leads.js?v=20261009-flow1', 'loadLeads'],
        deals: ['deals.js?v=20261009-care1', 'loadDeals'],
        'sales-master-data': ['sales-master-data.js?v=20260923-1', 'loadSalesMasterData'],
        contracts: ['contracts.js?v=20260923-6', 'loadContracts'],
        quotations: ['quotations.js?v=20261009-flow1', 'loadQuotations'],
        'sales-orders': ['sales-orders.js?v=20261008-menu1', 'loadSalesOrders'],
        deliveries: ['sales-fulfillment.js?v=20260618-1', 'loadDeliveries'],
        'sales-invoices': ['sales-fulfillment.js?v=20260618-1', 'loadSalesInvoices'],
        'customer-receivables': ['customer-receivables.js?v=20260617-1', 'loadCustomerReceivables'],
        'customer-payments': ['sales-fulfillment.js?v=20260618-1', 'loadCustomerPayments'],
        customers: ['customers.js?v=20261009-contacts1', 'loadCustomers'],
        'customer-contacts': ['customer-contacts.js?v=20261009-1', 'loadCustomerContacts'],
        suppliers: ['suppliers.js?v=20261008-menu1', 'loadSuppliers'],
        skus: ['skus.js?v=20261008-menu1', 'loadSkus'],
        'product-categories': ['product-catalog.js?v=20260921-1', 'loadProductCatalog'],
        'product-brands': ['product-catalog.js?v=20260921-1', 'loadProductCatalog'],
        inventory: ['inventory.js?v=20260615-3', 'loadInventory'],
        'inventory-transactions': ['inventory.js?v=20260615-3', 'loadInventory'],
        warehouses: ['warehouses.js', 'loadWarehouses'],
        'purchase-requests': ['purchase-requests.js?v=20261008-menu1', 'loadPurchaseRequests'],
        'supplier-quotations': ['supplier-quotations.js?v=20261008-3', 'loadSupplierQuotations'],
        'purchase-orders': ['purchase-orders.js?v=20261008-menu1', 'loadPurchaseOrders'],
        'goods-receipts': ['goods-receipts.js?v=20261008-menu1', 'loadGoodsReceipts'],
        'goods-issues': ['goods-issues.js?v=20261008-menu1', 'loadGoodsIssues'],
        'stock-takes': ['stock-takes.js?v=20260619-1', 'loadStockTakes'],
        tasks: ['tasks.js?v=20261008-menu1', 'loadTasks'],
        approvals: ['approvals.js?v=20260619-1', 'loadApprovals'],
        alerts: ['alerts.js?v=20260615-2', 'loadAlerts'],
        kpi: ['kpi.js?v=20261005-1', 'loadKpi'],
        'kpi-adjustments': ['kpi-adjustments.js?v=20260619-1', 'loadKpiAdjustments'],
        'kpi-settings': ['kpi-settings.js?v=20261005-1', 'loadKpiSettings'],
        workflows: ['workflows.js?v=20260618-2', 'loadWorkflows'],
        users: ['users.js?v=20261008-menu1', 'loadUsers'],
        roles: ['roles.js?v=20261008-1', 'loadRoles'],
        organization: ['organization.js?v=20260921-1', 'loadOrganization'],
        'print-templates': ['print-templates.js?v=20260923-5', 'loadPrintTemplates'],
        'audit-logs': ['audit-logs.js', 'loadAuditLogs'],
        'service-tickets': ['service-desk.js', 'loadServiceTickets'],
        'warranty-claims': ['service-desk.js', 'loadWarrantyClaims'],
        settings: ['settings.js?v=20261008-5', 'loadSettings'],
    };
    const routePrefetch = {
        '/dashboard': ['dashboard', [
            '/tasks?mine=1&page_size=100',
            '/alerts?mine=1&status=open&page_size=100',
            '/leads?page_size=100',
            '/quotations?page_size=100',
            '/quotations?status=pending_approval&page_size=100',
            '/sales-orders?page_size=100',
            '/inventory-balances?page_size=100',
            '/purchase-requests?page_size=100',
            '/purchase-orders?page_size=100',
            '/goods-receipts?page_size=100',
            '/goods-issues?page_size=100',
            '/notifications?page_size=5',
        ]],
        '/leads': ['leads', ['/leads']],
        '/deals': ['deals', ['/deals', '/customers?page_size=100', '/leads?page_size=100']],
        '/quotations': ['quotations', ['/quotations']],
        '/sales-orders': ['sales-orders', ['/sales-orders']],
        '/deliveries': ['deliveries', ['/deliveries?page_size=100']],
        '/sales-invoices': ['sales-invoices', ['/sales-invoices?page_size=100']],
        '/customer-receivables': ['customer-receivables', ['/customer-receivables?page_size=100']],
        '/customer-payments': ['customer-payments', ['/customer-payments?page_size=100']],
        '/customers': ['customers', ['/customers']],
        '/inventory': ['inventory', ['/inventory-balances', '/inventory-transactions']],
        '/warehouses': ['warehouses', ['/warehouses']],
        '/skus': ['skus', ['/skus', '/product-categories', '/product-brands']],
        '/product-categories': ['product-categories', ['/product-categories']],
        '/product-brands': ['product-brands', ['/product-brands']],
        '/goods-receipts': ['goods-receipts', ['/goods-receipts']],
        '/goods-issues': ['goods-issues', ['/goods-issues']],
        '/stock-takes': ['stock-takes', ['/stock-takes', '/warehouses', '/skus']],
        '/purchase-requests': ['purchase-requests', ['/purchase-requests']],
        '/supplier-quotations': ['supplier-quotations', ['/supplier-quotations', '/purchase-requests', '/suppliers', '/skus']],
        '/purchase-orders': ['purchase-orders', ['/purchase-orders']],
        '/suppliers': ['suppliers', ['/suppliers']],
        '/tasks': ['tasks', ['/tasks?page_size=100']],
        '/approvals': ['approvals', []],
        '/alerts': ['alerts', ['/alerts']],
        '/workflows': ['workflows', []],
        '/kpi': ['kpi', [
            '/kpi/overview',
            '/leads?page_size=100',
            '/quotations?page_size=100',
            '/sales-orders?page_size=100',
            '/purchase-requests?page_size=100',
            '/purchase-orders?page_size=100',
            '/inventory-balances?page_size=100',
            '/goods-receipts?page_size=100',
            '/goods-issues?page_size=100',
            '/tasks?page_size=100',
            '/alerts?page_size=100',
        ]],
        '/kpi-adjustments': ['kpi-adjustments', [
            '/kpi/adjustments?page_size=100&period_type=month',
            '/kpi/adjustments/summary?period_type=month',
            '/lookups/users',
        ]],
        '/kpi-settings': ['kpi-settings', [
            '/kpi/definitions',
            '/kpi/targets',
        ]],
        '/users': ['users', ['/users']],
        '/roles': ['roles', ['/roles']],
        '/organization': ['organization', ['/departments', '/positions', '/users?page_size=100']],
        '/print-templates': ['print-templates', ['/print-templates?page_size=100']],
        '/audit-logs': ['audit-logs', ['/audit-logs?page_size=100', '/lookups/users']],
        '/settings': ['settings', ['/me', '/users', '/roles', '/customers', '/suppliers', '/skus', '/warehouses']],
        '/service-tickets': ['service-tickets', ['/service-tickets', '/customers', '/users']],
        '/warranty-claims': ['warranty-claims', ['/warranty-claims', '/customers']],
    };

    let booted = false;
    let pjaxBusy = false;
    const pageCache = new Map();
    const prefetchingPages = new Set();

    function hasPermission(code) {
        return !code || window.VKUser?.permissions?.includes(code);
    }

    async function bootLayout() {
        if (booted || publicPages.includes(window.location.pathname)) return;
        booted = true;

        const cachedUser = readJson(userCacheKey);
        const canUseCachedUser = cachedUser
            && Array.isArray(cachedUser.permissions)
            && cachedUser.permissions.length > 0
            && typeof cachedUser.data_scope === 'string';
        if (canUseCachedUser) hydrateUser(cachedUser);
        resolveCurrentPage();
        applyStoredSidebarState();
        applySidebarIcons();
        bindSidebar();
        bindAppSwitcher();
        bindPjax();

        if (!VKApi.token()) {
            window.location.href = '/';
            return;
        }

        if (canUseCachedUser) {
            window.VKUser = cachedUser;
            applyNavigation();
            document.dispatchEvent(new CustomEvent('vk:ready', { detail: cachedUser }));
        }

        try {
            const me = await VKApi.request('/me');
            window.VKUser = me.data;
            writeJson(userCacheKey, me.data);
            hydrateUser(me.data);
            applyNavigation();
            if (!canUseCachedUser) {
                document.dispatchEvent(new CustomEvent('vk:ready', { detail: me.data }));
            }
            window.setTimeout(() => loadSidebarBadges(), 600);
            warmFeatureScripts();
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

        const url = String(logoUrl || '').trim();
        if (image.dataset.requestedLogo === url) return;
        image.dataset.requestedLogo = url;
        image.onerror = null;

        if (!url) {
            image.removeAttribute('src');
            image.classList.add('hidden');
            fallback.classList.remove('hidden');
            return;
        }

        // Keep the reserved brand area quiet until the saved image is ready.
        fallback.classList.add('hidden');
        const pending = new Image();
        pending.onload = () => {
            if (image.dataset.requestedLogo !== url) return;
            image.src = url;
            image.classList.remove('hidden');
            fallback.classList.add('hidden');
        };
        pending.onerror = () => {
            if (image.dataset.requestedLogo !== url) return;
            delete image.dataset.requestedLogo;
            image.removeAttribute('src');
            image.classList.add('hidden');
            fallback.classList.remove('hidden');
        };
        pending.src = url;
    }

    function applyNavigation() {
        document.querySelectorAll('#mainNav a, [data-module-toolbar] a, [data-module-card]').forEach((link) => {
            const permission = link.dataset.permission || '';
            if (!hasPermission(permission)) {
                link.remove();
            }
        });

        document.querySelectorAll('button[data-permission]').forEach((button) => {
            if (!hasPermission(button.dataset.permission || '')) {
                button.remove();
            }
        });

        document.querySelectorAll('[data-nav-group]').forEach((group) => {
            const links = group.querySelectorAll('.nav-sub a');
            if (!links.length) {
                group.remove();
            }
        });

        document.querySelectorAll('[data-module-card]').forEach((card) => {
            const section = document.querySelector(`[data-module-section="${card.dataset.moduleCard}"]`);
            const firstLink = section?.querySelector('a[href]');
            if (!firstLink) {
                card.remove();
                return;
            }
            card.href = firstLink.href;
        });

        setActiveUrl(window.location.pathname);
        applySidebarIcons();
    }

    function bindAppSwitcher() {
        const root = document.querySelector('[data-app-switcher]');
        const trigger = root?.querySelector('[data-app-switcher-trigger]');
        const panel = root?.querySelector('[data-app-switcher-panel]');
        const search = root?.querySelector('[data-app-switcher-search]');
        if (!root || !trigger || !panel || trigger.dataset.bound === '1') return;
        trigger.dataset.bound = '1';

        const close = () => {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            if (search) search.value = '';
            root.querySelectorAll('[data-module-card]').forEach(card => card.hidden = false);
        };
        const open = () => {
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            window.setTimeout(() => search?.focus(), 0);
        };

        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            panel.hidden ? open() : close();
        });
        root.querySelector('[data-app-switcher-close]')?.addEventListener('click', close);
        search?.addEventListener('input', () => {
            const keyword = search.value.trim().toLocaleLowerCase('vi');
            root.querySelectorAll('[data-module-card]').forEach((card) => {
                card.hidden = keyword !== '' && !String(card.dataset.moduleSearch || '').includes(keyword);
            });
        });
        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) close();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') close();
        });
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

    function applySidebarIcons(icons = null) {
        const config = icons || sidebarIconsFromStorageOrTenant();
        const customIcons = sidebarCustomIconsFromTenant();
        document.querySelectorAll('[data-sidebar-icon]').forEach((node) => {
            const key = node.dataset.sidebarIcon;
            const icon = config[key] || defaultSidebarIcons[key] || '';
            const customUrl = customIcons[key] || '';
            node.className = `nav-icon ${icon}`.trim();
            if (icon === 'custom' && customUrl) {
                node.style.setProperty('--nav-custom-icon', `url("${customUrl}")`);
            } else {
                node.style.removeProperty('--nav-custom-icon');
            }
        });
    }

    function saveSidebarIcons(icons) {
        const clean = {};
        Object.keys(defaultSidebarIcons).forEach((key) => {
            const value = icons?.[key];
            if (value && value !== defaultSidebarIcons[key]) {
                clean[key] = value;
            }
        });
        writeJson(sidebarIconKey, clean);
        applySidebarIcons(clean);
    }

    function resetSidebarIcons() {
        try {
            localStorage.removeItem(sidebarIconKey);
        } catch (error) {
            // Bỏ qua khi trình duyệt không cho ghi localStorage.
        }
        applySidebarIcons({});
    }

    function getSidebarIconConfig() {
        return {
            defaults: { ...defaultSidebarIcons },
            current: { ...sidebarIconsFromStorageOrTenant() },
            custom: { ...sidebarCustomIconsFromTenant() },
        };
    }

    function sidebarIconsFromStorageOrTenant() {
        return readJson(sidebarIconKey) || window.VKUser?.tenant?.ui_settings?.sidebar_icons || {};
    }

    function sidebarCustomIconsFromTenant() {
        return window.VKUser?.tenant?.ui_settings?.custom_sidebar_icons || {};
    }

    function bindPjax() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            if (!link || !shouldUsePjax(link)) return;

            event.preventDefault();
            const settingsUrl = new URL(link.href, window.location.origin);
            if (settingsUrl.pathname === '/settings' && !settingsUrl.search && document.getElementById('settingsRoot')) {
                document.dispatchEvent(new CustomEvent('vk:settings-menu'));
                return;
            }
            navigatePjax(link.href);
        });

        document.addEventListener('mouseover', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || !shouldUsePjax(link)) return;
            prefetchPage(link.href);
        });

        window.addEventListener('popstate', () => {
            navigatePjax(window.location.href, { push: false });
        });
    }

    function shouldUsePjax(link) {
        const url = new URL(link.href, window.location.origin);
        if (url.origin !== window.location.origin) return false;
        if (link.target || link.hasAttribute('download')) return false;
        if (link.hasAttribute('data-no-pjax') || url.hash) return false;
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
        closeTransientUi();
        setActiveUrl(target.pathname);

        try {
            const html = await fetchPageHtml(target.href);
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const page = swapPage(doc, target.pathname);

            if (push) {
                history.pushState({}, '', target.href);
            }

            setActiveUrl(target.pathname);
            await bootFeature(page);
            document.dispatchEvent(new CustomEvent('vk:page:changed', { detail: { page } }));
        } catch (error) {
            window.location.href = target.href;
        } finally {
            pjaxBusy = false;
            document.body.classList.remove('pjax-loading');
        }
    }

    async function fetchPageHtml(href) {
        if (pageCache.has(href)) return pageCache.get(href);

        const response = await fetch(href, {
            headers: {
                'Accept': 'text/html',
                'X-PJAX': 'true',
            },
        });

        if (!response.ok) throw new Error('Không tải được trang.');
        const html = await response.text();
        rememberPage(href, html);
        return html;
    }

    function rememberPage(href, html) {
        if (pageCache.size >= 20) {
            pageCache.delete(pageCache.keys().next().value);
        }
        pageCache.set(href, html);
    }

    function prefetchPage(href) {
        const target = new URL(href, window.location.origin);
        if (target.pathname === window.location.pathname && target.search === window.location.search) return;
        if (prefetchingPages.has(target.href)) return;

        prefetchingPages.add(target.href);
        Promise.allSettled([
            fetchPageHtml(target.href),
            prefetchFeature(target.pathname),
        ])
            .catch(() => {})
            .finally(() => prefetchingPages.delete(target.href));
    }

    function prefetchFeature(pathname) {
        const config = routePrefetch[normalizePath(pathname)];
        if (!config || !window.VKUser) return Promise.resolve();

        const [page] = config;
        const feature = features[page];
        const script = feature ? loadScript(`/assets/js/features/${feature[0]}`) : Promise.resolve();
        return Promise.resolve(script);
    }

    function swapPage(doc, pathname = window.location.pathname) {
        const title = doc.querySelector('[data-pjax-title]');
        const subtitle = doc.querySelector('[data-pjax-subtitle]');
        const actions = doc.querySelector('[data-pjax-actions]');
        const moduleNav = doc.querySelector('[data-pjax-module-nav]');
        const content = doc.querySelector('[data-pjax-content]');
        const docPage = doc.body?.dataset.page || '';
        const page = resolvePageKey(pathname, docPage);

        if (!content) throw new Error('Thiếu vùng nội dung.');

        document.title = doc.title || document.title;
        document.body.dataset.page = page;
        replaceHtml('[data-pjax-title]', title?.innerHTML || '');
        replaceHtml('[data-pjax-subtitle]', subtitle?.innerHTML || '');
        replaceHtml('[data-pjax-actions]', actions?.innerHTML || '');
        replaceHtml('[data-pjax-module-nav]', moduleNav?.innerHTML || '');
        replaceHtml('[data-pjax-content]', content.innerHTML);
        applyNavigation();
        return page;
    }

    function resolveCurrentPage() {
        document.body.dataset.page = resolvePageKey(window.location.pathname, document.body.dataset.page || '');
    }

    function resolvePageKey(pathname, preferred = '') {
        const fromPath = pageKeyFromPath(pathname);
        if (!preferred || (preferred === 'dashboard' && fromPath !== 'dashboard')) {
            return fromPath;
        }
        return features[preferred] ? preferred : fromPath;
    }

    function pageKeyFromPath(pathname) {
        const current = normalizePath(pathname).replace(/^\//, '');
        if (!current || current === 'dashboard') return 'dashboard';

        const keys = Object.keys(features).sort((a, b) => b.length - a.length);
        return keys.find(key => current === key || current.startsWith(`${key}/`)) || current.split('/')[0] || 'dashboard';
    }

    function replaceHtml(selector, html) {
        const node = document.querySelector(selector);
        if (node) node.innerHTML = html;
    }

    function closeTransientUi() {
        if (window.VKModal?.close) window.VKModal.close();
        if (window.VKDetailDrawer?.close) window.VKDetailDrawer.close();
    }

    function setActiveUrl(pathname) {
        applyModuleForPath(pathname);
        document.querySelectorAll('#mainNav a, [data-module-toolbar] a').forEach((link) => {
            const href = new URL(link.href, window.location.origin);
            const active = linkMatchesPath(link, href.pathname, pathname);
            link.classList.toggle('active', active);
        });
        saveSidebarState();
    }

    function applyModuleForPath(pathname) {
        const path = normalizePath(pathname).replace(/^\//, '') || 'dashboard';
        const module = Object.entries(moduleRoutes).find(([, routes]) => routes.some(route => path === route || path.startsWith(`${route}/`)))?.[0] || 'work';
        document.querySelectorAll('[data-module-section]').forEach((section) => {
            section.hidden = true;
        });
        document.querySelectorAll('[data-dashboard-shortcuts]').forEach((section) => {
            section.hidden = false;
        });
        document.querySelectorAll('[data-module-card]').forEach((card) => {
            card.classList.toggle('active', card.dataset.moduleCard === module);
        });
        document.querySelectorAll('[data-current-module-label]').forEach((node) => {
            node.textContent = moduleLabels[module] || 'Công việc';
        });
    }

    function linkMatchesPath(link, hrefPath, currentPath) {
        const current = normalizePath(currentPath).replace(/^\//, '');
        const target = normalizePath(hrefPath);
        const matches = (link.dataset.navMatch || '').split(',').map(item => item.trim()).filter(Boolean);
        if (matches.length) {
            return matches.some(prefix => current === prefix || current.startsWith(`${prefix}/`));
        }
        return normalizePath(target) === normalizePath(currentPath);
    }

    async function bootFeature(page) {
        const feature = features[page];
        if (!feature) return;

        const [file, fn] = feature;
        await loadScript(`/assets/js/features/${file}`);
        if (typeof window[fn] === 'function') {
            await window[fn]({ source: 'pjax' });
            return;
        }

        document.dispatchEvent(new CustomEvent('vk:ready', { detail: window.VKUser || null }));
    }

    const scriptLoaders = new Map();

    function loadScript(src) {
        const existing = scriptLoaders.get(src);
        if (existing) return existing;

        const current = document.querySelector(`script[src="${src}"]`);
        if (current?.dataset.loaded === '1') {
            const ready = Promise.resolve();
            scriptLoaders.set(src, ready);
            return ready;
        }
        if (current) {
            current.dataset.loaded = '1';
            const ready = Promise.resolve();
            scriptLoaders.set(src, ready);
            return ready;
        }

        const promise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.addEventListener('load', () => {
                script.dataset.loaded = '1';
                resolve();
            }, { once: true });
            script.addEventListener('error', (event) => {
                scriptLoaders.delete(src);
                reject(event);
            }, { once: true });
            document.body.appendChild(script);
        });

        scriptLoaders.set(src, promise);
        return promise;
    }

    function warmFeatureScripts() {
        // Bỏ warm-load 22 feature script: tốn băng thông và gây race với PJAX.
        // Feature script giờ chỉ tải khi user thực sự chuyển vào trang đó.
    }

    async function loadSidebarBadges() {
        await Promise.allSettled([
            fillTaskBadges(),
            fillAlertBadges(),
        ]);
    }

    async function fillTaskBadges() {
        if (!hasPermission('task.view')) return;
        const tasks = await VKApi.request('/tasks?mine=1&page_size=25');
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

    window.VKLayout = { bootLayout, hasPermission, hydrateLogo, navigatePjax, applySidebarIcons, saveSidebarIcons, resetSidebarIcons, getSidebarIconConfig };
})();
