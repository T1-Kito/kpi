<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'VK-KPI')</title>
    <link rel="icon" type="image/x-icon" href="/assets/branding/vk-crm.ico?v=20261022-1">
    <link rel="stylesheet" href="/assets/css/app.css?v=20260618-1">
    <link rel="stylesheet" href="/assets/css/components.css?v=20260618-6">
    <link rel="stylesheet" href="/assets/css/pages.css?v=20260618-9">
</head>
<body>
    @yield('content')

    <div id="toast" class="toast" role="status"></div>

    <script src="/assets/js/services/api.js?v=20261028-http-uuid1"></script>
    <script src="/assets/js/modal.js?v=20260615-2"></script>
    <script src="/assets/js/app.js"></script>
    @stack('scripts')
</body>
</html>
