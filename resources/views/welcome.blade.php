{{-- Prototype UI reference only.

Không phát triển tiếp hệ thống trong file này.
Source UI chính đã được tách sang:
- resources/views/layouts
- resources/views/components
- resources/views/pages
- public/assets/css
- public/assets/js/features
--}}

@extends('layouts.auth')

@section('title', 'Prototype VK-KPI')

@section('content')
<main class="login-page">
    <section class="login-panel">
        <div class="brand">
            <span class="brand-mark">VK</span>
            <span>
                <strong>VK-KPI</strong>
                <small>Prototype reference</small>
            </span>
        </div>
        <div class="login-title">
            <h1>Prototype UI</h1>
            <div class="subtitle">File này chỉ giữ vai trò tham chiếu, không còn là source chính.</div>
        </div>
        <div class="login-actions">
            <a class="btn primary" href="/">Về trang đăng nhập</a>
            <a class="btn" href="/dashboard">Mở bảng điều khiển</a>
        </div>
    </section>
</main>
@endsection
