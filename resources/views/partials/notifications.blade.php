<div class="notification-center" data-notification-center>
    <button class="icon-btn notification-trigger" type="button" data-notification-toggle aria-label="Thông báo" title="Thông báo" aria-expanded="false">
        <svg class="notification-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
            <path d="M10 21h4" />
        </svg>
        <span class="notification-count hidden" data-notification-count>0</span>
    </button>

    <section class="notification-popover" data-notification-popover aria-label="Danh sách thông báo">
        <header class="notification-head">
            <div>
                <strong>Thông báo</strong>
                <span data-notification-summary>Đang tải...</span>
            </div>
            <button class="btn compact" type="button" data-notification-read-all>Đánh dấu đã đọc</button>
        </header>

        <div class="notification-list" data-notification-list>
            <div class="notification-empty">Chưa có thông báo.</div>
        </div>

        <footer class="notification-foot">
            <a href="/alerts">Xem cảnh báo</a>
            <a href="/tasks">Xem công việc</a>
        </footer>
    </section>
</div>
