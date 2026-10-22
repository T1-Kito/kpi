@extends('layouts.auth')

@section('title', 'Đăng nhập VK-KPI')

@section('content')
@php
    $loginTenant = \App\Models\Tenant::query()
        ->where('status', 'active')
        ->orderBy('id')
        ->first();
    $loginLogoUrl = $loginTenant?->logo_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($loginTenant->logo_path)
        : asset('assets/branding/vk-software-logo.png');
@endphp
<main class="login-page">
    <section class="login-panel">
        <div class="brand login-brand">
            <img class="login-brand-image" src="{{ $loginLogoUrl }}" alt="{{ $loginTenant?->logo_path ? $loginTenant->name : 'VK Software' }}">
        </div>

        <div class="login-title">
            <h1>Đăng nhập</h1>
            <div class="subtitle">Nhập tài khoản của bạn để truy cập hệ thống.</div>
        </div>

        <form id="loginForm">
            <div class="field">
                <label for="email">Tài khoản</label>
                <input id="email" type="text" autocomplete="username" placeholder="Tên đăng nhập hoặc email">
            </div>
            <div class="field">
                <label for="password">Mật khẩu</label>
                <input id="password" type="password" autocomplete="current-password">
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
