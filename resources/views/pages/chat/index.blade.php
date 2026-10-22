@extends('layouts.app')
@section('title', 'Tin nhắn nội bộ')
@section('page', 'chat')
@section('page_title', 'Tin nhắn nội bộ')
@section('page_subtitle', 'Trao đổi cùng đồng nghiệp trong doanh nghiệp.')
@section('content')
<div class="chat-workspace info-closed" id="chatRoot">
    <aside class="chat-sidebar">
        <header><div><h2>Tin nhắn <span data-chat-count>0</span></h2></div><button class="chat-compose-button" data-chat-new aria-label="Tin nhắn mới" title="Tin nhắn mới"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2v-9M16 3l5 5M10 14l3-1 8-8-4-4-8 8-1 5Z"/></svg></button></header>
        <input type="search" aria-label="Tìm hội thoại" placeholder="Tìm trong hội thoại…" data-chat-search>
        <nav class="chat-filters" aria-label="Lọc hội thoại"><button data-chat-filter="all" aria-pressed="true">Tất cả</button><button data-chat-filter="unread" aria-pressed="false">Chưa đọc</button><button data-chat-filter="direct" aria-pressed="false">Cá nhân</button><button data-chat-filter="groups" aria-pressed="false">Nhóm</button></nav>
        <div data-chat-list></div>
    </aside>
    <section class="chat-main"><header><span class="chat-header-dot"></span><div><strong data-chat-title>Tin nhắn nội bộ</strong><small data-chat-subtitle></small></div><button class="chat-info-toggle" data-chat-info-toggle aria-label="Thông tin hội thoại" title="Thông tin hội thoại" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M15 4v16"/></svg></button></header>
        <div class="chat-history" data-chat-messages><div class="chat-welcome"><svg class="chat-empty-icon" viewBox="0 0 48 48" aria-hidden="true"><path d="M40 23a15 15 0 0 1-15 15H14l-8 5 3-11a15 15 0 0 1-2-9A15 15 0 0 1 22 8h3a15 15 0 0 1 15 15Z"/><path d="M16 21h16M16 28h10"/></svg><h3>Bắt đầu trò chuyện</h3><button class="btn primary" data-chat-new><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Tin nhắn mới</button></div></div>
        <form data-chat-compose hidden><label class="sr-only" for="chatBody">Tin nhắn</label><textarea id="chatBody" rows="2" maxlength="5000" placeholder="Viết tin nhắn…" title="Enter để gửi · Shift + Enter để xuống dòng" aria-keyshortcuts="Enter Shift+Enter" required></textarea><button class="btn primary" type="submit" aria-label="Gửi tin" title="Gửi tin (Enter)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 3-7 18-4-7-7-4 18-7ZM10 14 21 3"/></svg></button></form><p class="chat-status" data-chat-status role="status"></p>
    </section>
    <aside class="chat-info" data-chat-info>
        <header><h3>Thông tin hội thoại</h3><button data-chat-info-toggle aria-label="Đóng thông tin hội thoại"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
        <div class="chat-info-identity"><span class="chat-info-avatar" data-chat-info-avatar>CRM</span><h3 data-chat-info-name>Không gian nội bộ</h3><p data-chat-info-description>Nhắn riêng hoặc trao đổi nhóm</p></div>
        <section><h4>Thành viên</h4><div data-chat-members><p class="chat-info-empty">Chọn hội thoại để xem thành viên.</p></div></section>
    </aside>
</div>
@endsection
