<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'VK-KPI')</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=20260923-2">
    <link rel="stylesheet" href="/assets/css/components.css?v=20261007-4">
    <link rel="stylesheet" href="/assets/css/pages.css?v=20261008-11">
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

    <script src="/assets/js/services/api.js?v=20261007-2"></script>
    <script src="/assets/js/table.js?v=20261007-1"></script>
    <script src="/assets/js/modal.js?v=20260615-2"></script>
    <script src="/assets/js/record-page.js?v=20261008-8"></script>
    <script src="/assets/js/detail-drawer.js"></script>
    <script src="/assets/js/layout.js?v=20261009-20"></script>
    <script src="/assets/js/notifications.js"></script>
    <script src="/assets/js/app.js"></script>
    @stack('scripts')
</body>
</html>
