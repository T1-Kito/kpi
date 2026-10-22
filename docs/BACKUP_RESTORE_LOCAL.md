# Sao lưu và khôi phục local

Phạm vi hiện tại: SQLite, file trong storage/app/public và storage/app/private,
file .env (bao gồm APP_KEY). Không gồm mã nguồn, dịch vụ Windows, database SQL Server,
file trên S3 hoặc file được lưu ngoài hai thư mục này. Gói không thay thế bộ cài.

## Tạo gói

1. Dừng worker, scheduler và các tiến trình tích hợp có thể ghi dữ liệu.
2. Chạy `php artisan down` để chặn yêu cầu web mới. Chờ yêu cầu đang xử lý kết thúc.
3. Chạy `php artisan crm:backup-bundle --writers-stopped`.
4. Nhập mật khẩu riêng ít nhất 16 ký tự, không dùng mật khẩu đăng nhập CRM.
5. Chạy `php artisan up` kể cả khi sao lưu lỗi; khởi động lại các worker đã dừng.

Gói nằm trong storage/app/private/backup-bundles, mã hóa AES-256 bằng mật khẩu
độc lập với APP_KEY. Cất mật khẩu riêng; mất mật khẩu không khôi phục được.
Chép gói đã kiểm chứng sang nơi lưu trữ khác máy chủ, hạn chế quyền truy cập.
Không có tự động xóa bản cũ hoặc tự động sao lưu theo lịch ở phiên bản này.

## Kiểm chứng và khôi phục thử

`php artisan crm:bundle-check TEN_GOI.zip`

`php artisan crm:bundle-check TEN_GOI.zip --restore-test`

Khôi phục thử chỉ tạo thư mục mới, không ghi đè database/file/cấu hình đang chạy.
Thư mục thử có dữ liệu nhạy cảm và .env đã giải mã, phải bảo vệ như dữ liệu thật.
Không đưa vào public hoặc Git. Cần kiểm tra ứng dụng trong môi trường cách ly
trước khi quyết định khôi phục production; hiện chưa có lệnh tự động ghi đè production.

Với hệ thống nhiều công ty, đây là bản sao toàn bộ cài đặt, không phải bản xuất
dữ liệu của một công ty. Chỉ người vận hành máy chủ được sử dụng.

Database snapshot trung gian trong storage/app/private/database-backups chưa mã hóa;
phải giới hạn quyền thư mục này. Không tự xóa snapshot ở phiên bản hiện tại.
Giới hạn: kiểm chứng đọc từng file vào bộ nhớ; từ chối file lớn hơn 64 MB.
Chưa có nghiệm thu mất điện, hết dung lượng, khôi phục trên máy sạch hay SQL Server.
