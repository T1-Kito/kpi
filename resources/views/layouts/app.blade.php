<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'VK-KPI')</title>
    <link rel="icon" type="image/x-icon" href="/assets/branding/vk-crm.ico?v=20261022-1">
    <link rel="stylesheet" href="/assets/css/app.css?v=20261024-apps1">
    <link rel="stylesheet" href="/assets/css/components.css?v=20261013-heading1">
    <link rel="stylesheet" href="/assets/css/pages.css?v=20261023-perf1">
    <link rel="stylesheet" href="/assets/css/chat-final.css?v=20261015-sidebar9">
    <link rel="stylesheet" href="/assets/css/app-switcher.css?v=20261024-1">
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

    <script src="/assets/js/services/api.js?v=20261019-safety1"></script>
    <script src="/assets/js/table.js?v=20261020-care-report1"></script>
    <script src="/assets/js/money-input.js?v=20261020-2"></script>
    <script src="/assets/js/modal.js?v=20261022-page1"></script>
    <script src="/assets/js/record-page.js?v=20261009-codes1"></script>
    <script src="/assets/js/detail-drawer.js?v=20261016-cost1"></script>
    <script src="/assets/js/features/directory-import.js?v=20261023-organization1"></script>
    <script src="/assets/js/layout.js?v=20261024-apps1"></script>
    <script src="/assets/js/notifications.js?v=20261023-perf1"></script>
    <script src="/assets/js/app.js"></script>
    <script src="/assets/js/chat.js?v=20261023-perf1"></script>
    @stack('scripts')
</body>
</html>
