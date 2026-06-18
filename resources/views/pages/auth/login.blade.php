@extends('layouts.auth')

@section('title', 'Đăng nhập VK-KPI')

@section('content')
@php
    $loginTenant = \App\Models\Tenant::query()
        ->where('status', 'active')
        ->orderBy('id')
        ->first();
    $loginLogoUrl = $loginTenant?->logo_path ? \Illuminate\Support\Facades\Storage::url($loginTenant->logo_path) : null;
@endphp
<main class="login-page">
    <section class="login-panel">
        <div class="brand login-brand">
            @if ($loginLogoUrl)
                <img class="login-brand-image" src="{{ $loginLogoUrl }}" alt="{{ $loginTenant?->name ?? 'Logo hệ thống' }}">
            @else
                <span class="brand-mark">VK</span>
                <span>
                    <strong>{{ $loginTenant?->name ?? 'VK-KPI' }}</strong>
                    <small>Hệ thống quản trị vận hành</small>
                </span>
            @endif
        </div>

        <div class="login-title">
            <h1>Đăng nhập</h1>
            <div class="subtitle">Nhập tài khoản của bạn để truy cập hệ thống.</div>
        </div>

        <form id="loginForm">
            <div class="field">
                <label for="email">Thư điện tử</label>
                <input id="email" type="email" value="admin@vk-kpi.local" autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Mật khẩu</label>
                <input id="password" type="password" value="Admin@123" autocomplete="current-password">
            </div>
            <div class="login-actions">
                <button id="loginBtn" class="btn primary" type="submit">Đăng nhập</button>
            </div>
            <div id="loginError" class="error-box"></div>
        </form>
    </section>
    <footer class="login-credit">
        <strong>Giải pháp phát triển bởi Công ty Cổ phần Vigilance Việt Nam</strong>
        <span>Trao toàn quyền quản trị và vận hành hệ thống</span>
    </footer>
</main>
@endsection

@push('scripts')
<script src="/assets/js/features/auth.js?v=20260618-1"></script>
@endpush
