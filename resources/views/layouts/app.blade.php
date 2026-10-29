<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'VK-KPI')</title>
    <link rel="icon" type="image/x-icon" href="/assets/branding/vk-crm.ico?v=20261022-1">
    <link rel="stylesheet" href="/assets/css/app.css?v=20261024-apps1">
    <link rel="stylesheet" href="/assets/css/components.css?v=20261027-contact-payment3">
    <link rel="stylesheet" href="/assets/css/pages.css?v=20261029-service-list2">
    <link rel="stylesheet" href="/assets/css/chat-final.css?v=20261015-sidebar9">
    <link rel="stylesheet" href="/assets/css/app-switcher.css?v=20261024-1">
    <link rel="stylesheet" href="/assets/css/catalog-sharing.css?v=20261024-1">
    <link rel="stylesheet" href="/assets/css/customer-groups.css?v=20261025-4">
    <link rel="stylesheet" href="/assets/css/deal-needs.css?v=20261026-2">
    <link rel="stylesheet" href="/assets/css/crm-intake.css?v=20261025-1">
    <link rel="stylesheet" href="/assets/css/directory-import.css?v=20261026-1">
    <link rel="stylesheet" href="/assets/css/customer-editor.css?v=20261028-address6">
    <link rel="stylesheet" href="/assets/css/deal-overview.css?v=20261028-spacing2">
    <link rel="stylesheet" href="/assets/css/quotation-editor.css?v=20261028-credit3">
    <link rel="stylesheet" href="/assets/css/sales-approval-settings.css?v=20261029-approval-unified1">
</head>
<body data-page="@yield('page', 'dashboard')">
    <main class="app-shell">
        @include('partials.sidebar')

        <section class="main">
            <div class="topbar">
                <div data-pjax-module-nav>
                    @include('partials.module-toolbar')
                </div>
                <div class="page-title">
                    <h1 data-pjax-title>@yield('page_title')</h1>
                    <div class="subtitle" data-pjax-subtitle>@yield('page_subtitle')</div>
                </div>
                <div class="toolbar">
                    @include('partials.app-switcher')
                    @include('partials.notifications')
                    <a href="/chat" class="btn secondary">Tin nhắn <span data-chat-badge hidden></span></a>
                    <span data-pjax-actions>
                        @yield('page_actions')
                    </span>
                </div>
            </div>

            <div data-pjax-content>
                @yield('content')
            </div>
        </section>
    </main>

    @include('components.modal')
    @include('components.detail-drawer')
    <div id="toast" class="toast" role="status"></div>

    <script src="/assets/js/services/api.js?v=20261028-http-uuid1"></script>
    <script src="/assets/js/table.js?v=20261029-activity-vi1"></script>
    <script src="/assets/js/catalog-sharing.js?v=20261024-2"></script>
    <script src="/assets/js/customer-groups.js?v=20261028-perf2"></script>
    <script src="/assets/js/deal-services.js?v=20261028-catalog4"></script>
    <script src="/assets/js/crm-intake.js?v=20261025-1"></script>
    <script src="/assets/js/money-input.js?v=20261020-2"></script>
    <script src="/assets/js/word-preview.js?v=20261028-loader2"></script>
    <script src="/assets/js/document-export.js?v=20261028-picker2"></script>
    <script src="/assets/js/modal.js?v=20261028-toast-confirm2"></script>
    <script src="/assets/js/record-page.js?v=20261028-workspace1"></script>
    <script src="/assets/js/detail-drawer.js?v=20261016-cost1"></script>
    <script src="/assets/js/features/directory-import.js?v=20261026-1"></script>
    <script src="/assets/js/layout.js?v=20261029-service-list2"></script>
    <script src="/assets/js/notifications.js?v=20261023-perf1"></script>
    <script src="/assets/js/app.js"></script>
    <script src="/assets/js/chat.js?v=20261028-http-uuid1"></script>
    @stack('scripts')
</body>
</html>
