# SQL Server: điều kiện trước khi bàn giao

Chưa được chứng nhận chạy SQL Server. Không thay .env của local/production để thử.

## Cập nhật kiểm chứng ngày 20/10/2026

Các ghi nhận ngày 17/10 bên dưới là lịch sử, không phải trạng thái driver hiện tại.
PHP PDO_SQLSRV và ODBC đã kết nối được SQL Server local. Toàn bộ migration đã được
kiểm tra trên schema cách ly và database thử riêng. Lượt hồi quy cuối SQL Server
đã đạt 229 bài/2.119 assertions (không gồm bài backup bundle chỉ dành cho SQLite).
SQLite đạt lại 230 bài/2.128 assertions, JavaScript đạt 140 bài. Các kết quả này
không thay thế UAT máy khách, kiểm đồng thời và thử khôi phục backup SQL native.
Xem `KET_QUA_DOI_CHIEU_BAO_CAO_2026-10-19.md` để theo dõi kết quả mới nhất.

## Sự cố tài nguyên đã xác minh

Ngày 17/10/2026: SQL Server Application Event Log có lỗi 17300/17312 liên quan
khởi tạo system task/Parallel Redo và thiếu tài nguyên bộ nhớ hoặc giới hạn sessions.
Ổ C có thời điểm chỉ còn khoảng 237 MB; không tiếp tục chạy migration trong điều kiện này.
Event Log xác nhận database thử `VKCRM_Trial_20261017_062418_5107e5` đã được tạo
và recovery hoàn tất. Chưa xác nhận migration CRM hoàn tất; chưa xóa database thử.
Script migration trial nay chặn nếu workspace còn dưới 5 GiB, trước khi mở kết nối.
Không tự đổi max server memory, bật TCP hay restart dịch vụ dùng chung khi chưa thống nhất.

## Kết quả chuẩn bị trên máy phát triển

- Đã phát hiện SQL Server 2022, kết nối Windows bằng .NET đọc được phiên bản.
- PHP hiện tại: 8.2 x64 Thread Safe. Driver thử Microsoft 5.12 tải từ release chính thức,
  chữ ký Authenticode hợp lệ; nạp tạm bằng `php -d`, không sửa php.ini/XAMPP.
- `scripts/Test-SqlServerRuntime.ps1` kiểm tra kiến trúc và chữ ký trước khi nạp.
  Driver nằm trong `.tmp/sqlserver-runtime`, không nằm trong gói phát hành.
- Chưa có Microsoft ODBC Driver 17/18 phù hợp: chưa kiểm thử kết nối PHP tới SQL Server.
- Đã chuẩn bị `.tmp/sqlserver-runtime/msodbcsql18-x64.msi` từ đường dẫn Microsoft,
  chữ ký hợp lệ. Cài hệ thống cần quyền Administrator; phiên agent không có quyền này.
  Người vận hành có thể mở MSI và xác nhận UAC, hoặc chạy
  `scripts/Install-SqlServerOdbc.ps1` từ PowerShell Administrator.
  Script không tự nâng quyền, chấp nhận giấy phép hay khởi động lại Windows.
- Driver thử này không phải lựa chọn phiên bản cuối cùng để phát hành; phải rà lại
  bản vá và ma trận hỗ trợ khi chốt PHP/runtime cho khách.

## Kiểm tra không ghi dữ liệu

- `php artisan crm:sqlserver-check`: chỉ kiểm tra driver PHP.
- Trong môi trường cách ly đã cấu hình kết nối sqlsrv, dùng
  `php artisan crm:sqlserver-check --connect` để kiểm tra kết nối và Unicode.
- Không truyền mật khẩu trên dòng lệnh; không commit .env hoặc chuỗi kết nối.
- Lệnh không tự cài driver, tạo database, chạy migration hoặc đổi kết nối mặc định.

## Các điều kiện còn phải nghiệm thu

1. Chốt phiên bản Windows, PHP, SQL Server và driver ODBC/PHP tương thích.
2. Tạo database thử riêng, tài khoản thử riêng, TLS/chứng chỉ hợp lệ.
3. Chạy migration trên database trống và nâng cấp từ phiên bản cũ trong môi trường thử.
4. Kiểm tra khóa ngoại, cascade, unique nullable, index, decimal và Unicode.
5. Chạy kiểm thử nghiệp vụ, báo cáo, Excel, scope công ty, chốt KPI và duyệt nhiều cấp.
6. Kiểm tra deadlock, sửa đồng thời, retry và transaction rollback.
7. Sao lưu SQL Server bằng quy trình native riêng, khôi phục thử database khác.
8. Đóng gói driver và dịch vụ web production; không dùng artisan serve để bàn giao.

Lệnh backup SQLite hiện tại sẽ từ chối SQL Server, không giả lập thành công.
Không chạy migrate:fresh trên dữ liệu khách hàng.
