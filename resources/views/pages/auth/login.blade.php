@extends('layouts.auth')

@section('title', 'Đăng nhập VK-KPI')

@section('content')
<main class="login-page">
    <section class="login-panel">
        <div class="brand">
            <span class="brand-mark">VK</span>
            <span>
                <strong>VK-KPI</strong>
                <small>Hệ thống quản trị vận hành</small>
            </span>
        </div>

        <div class="login-title">
            <h1>Đăng nhập hệ thống</h1>
            <div class="subtitle">Dùng tài khoản mẫu để vào bảng điều khiển vận hành.</div>
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
                <button class="btn" type="button" data-demo="sales@vk-kpi.local">Tài khoản kinh doanh</button>
                <button class="btn" type="button" data-demo="warehouse@vk-kpi.local">Tài khoản kho</button>
            </div>
            <div id="loginError" class="error-box"></div>
        </form>

        <div class="demo-note">
            Quản trị: admin@vk-kpi.local / Admin@123<br>
            Có sẵn tài khoản kinh doanh, kho, mua hàng, marketing.
        </div>
    </section>

    <section class="login-side">
        <div class="flow-board">
            <div class="flow-row"><div class="flow-label">Kinh doanh</div><div class="flow-box">Khách hàng tiềm năng → Báo giá → Duyệt biên lợi nhuận thấp</div></div>
            <div class="flow-row"><div class="flow-label">Đơn hàng</div><div class="flow-box">Đơn bán → Kiểm tra tồn kho → Giữ hàng</div></div>
            <div class="flow-row"><div class="flow-label">Kho</div><div class="flow-box">Đủ tồn: tạo công việc xuất kho → Xuất hàng</div></div>
            <div class="flow-row"><div class="flow-label">Mua hàng</div><div class="flow-box">Thiếu tồn: cảnh báo + yêu cầu mua + công việc mua hàng</div></div>
            <div class="flow-row"><div class="flow-label">KPI</div><div class="flow-box">Bảng điều khiển cập nhật công việc, cảnh báo, tồn kho, SLA</div></div>
        </div>
    </section>
</main>
@endsection

@push('scripts')
<script src="/assets/js/features/auth.js"></script>
@endpush
