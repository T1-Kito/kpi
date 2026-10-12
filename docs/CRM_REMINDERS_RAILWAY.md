# Nhắc quá hạn CRM trên Railway

## Bật trên service kpi

- Giữ Custom Start Command trống để Railpack sử dụng `start-container.sh` trong repository.
- Giữ pre-deploy command `php artisan migrate --force` và `RAILPACK_SKIP_MIGRATIONS=true` như hiện tại.
- Bộ lập lịch doanh nghiệp mặc định được bật bằng `AUTOMATION_SCHEDULER_ENABLED=true`. Nó chạy nhắc việc, cảnh báo đơn mua trễ, retry sự kiện, tính KPI và phát hành KPI định kỳ.
- Chỉ dùng `CRM_REMINDERS_ENABLED=true` như chế độ tương thích cũ khi đã chủ động đặt `AUTOMATION_SCHEDULER_ENABLED=false`.
- Giữ service hoạt động liên tục; nếu bật chế độ ngủ thì không bảo đảm nhắc hạn khi ứng dụng ngủ.
- Không cần tạo thêm service hoặc chạy Console mỗi ngày.

Scheduler chỉ chạy các lịch đã khai báo trong `routes/console.php`. Nó không gửi email, SMS hoặc Zalo, không tự gọi khách và không tự phê duyệt.

## Xác minh

Deploy logs phải có dòng `Business automation scheduler enabled.`. Chạy `php artisan schedule:list` để kiểm tra đủ lịch. Tạo một công việc thử có hạn trong tương lai gần, chờ quá hạn rồi kiểm tra cảnh báo bằng tài khoản người phụ trách. Chạy nhiều lần không được tạo lặp lịch sử/thông báo.

## Tắt hoặc khôi phục

Đặt `AUTOMATION_SCHEDULER_ENABLED=false` và `CRM_REMINDERS_ENABLED=false` rồi deploy để tắt toàn bộ lịch nền, không xóa lịch sử đã có. Sự kiện lỗi được giữ lại và thử lại theo thời gian chờ tăng dần; không đánh dấu thành công khi handler lỗi.

Tham khảo: https://railpack.com/languages/php (hỗ trợ custom `start-container.sh`). Việc chạy nền trên Railway chỉ được xác nhận sau khi quan sát log và thử quá hạn trên bản triển khai thực tế.
