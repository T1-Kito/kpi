# Nhắc quá hạn CRM trên Railway

## Bật trên service kpi

- Giữ Custom Start Command trống để Railpack sử dụng `start-container.sh` trong repository.
- Giữ pre-deploy command `php artisan migrate --force` và `RAILPACK_SKIP_MIGRATIONS=true` như hiện tại.
- Thêm biến `CRM_REMINDERS_ENABLED=true` rồi deploy. Mặc định nhắc hạn tắt, không tự thay đổi cấu hình hạ tầng của người dùng.
- Giữ service hoạt động liên tục; nếu bật chế độ ngủ thì không bảo đảm nhắc hạn khi ứng dụng ngủ.
- Không cần tạo thêm service hoặc chạy Console mỗi ngày.

Worker kiểm tra mỗi 5 phút và tạo cảnh báo/thông báo trong ứng dụng cho người được giao. Không gửi email, SMS hoặc Zalo. Không tự gọi khách, không tự phê duyệt và không chạy tính KPI.

## Xác minh

Deploy logs phải có dòng `CRM overdue reminders enabled: checking every five minutes.` và các dòng `Marked ... task(s) as overdue.` lặp lại mỗi 5 phút. Tạo một công việc thử có hạn trong tương lai gần, chờ quá hạn rồi kiểm tra cảnh báo bằng tài khoản người phụ trách. Chạy nhiều lần không được tạo lặp lịch sử/thông báo.

## Tắt hoặc khôi phục

Đặt `CRM_REMINDERS_ENABLED=false` rồi deploy để tắt worker, không xóa lịch sử đã có. Worker lỗi sẽ ghi log và thử lại sau 5 phút; không tuyên bố đã xử lý thành công khi có lỗi.

Tham khảo: https://railpack.com/languages/php (hỗ trợ custom `start-container.sh`). Việc chạy nền trên Railway chỉ được xác nhận sau khi quan sát log và thử quá hạn trên bản triển khai thực tế.
