# VK CRM Portable SQLite UAT 0.5

Đây là bản thử localhost, không phải bộ triển khai doanh nghiệp hay bản SQL Server.
Không đưa dữ liệu khách thật vào. PHP 8.2.12 đi kèm chưa phù hợp phát hành chính thức.
File chưa ký số. Không tắt antivirus/SmartScreen; nếu máy chặn, giữ thông báo để kiểm tra.

## Mang sang máy Windows x64 khác

1. Chép Setup.exe sang máy thử. Chạy dưới tài khoản Windows thông thường.
2. Chọn thư mục mới trống, ví dụ thư mục VK-CRM-UAT trong Documents.
3. Bấm Giải nén bản thử. Chờ hoàn tất; không cần tự mở Explorer.
4. Bỏ qua PHP/Composer nâng cao vì runtime và vendor đã có sẵn.
5. Nhập doanh nghiệp giả, email Admin và mật khẩu riêng >=8 ký tự.
6. Bấm Tạo SQLite UAT riêng. Không dùng lại thư mục đã có .env/database.
7. Bấm Mở CRM thử tại máy này. Chờ kiểm tra sức khỏe rồi trình duyệt mở cổng 8099.
8. Đăng nhập bằng email/mật khẩu vừa nhập. Giữ cửa sổ Setup mở khi sử dụng.
9. Bấm Dừng CRM thử hoặc đóng Setup để dừng tiến trình web mà Setup đã tạo.
10. Lần sau mở Setup, chọn lại đúng thư mục cũ và bấm Mở CRM. KHÔNG giải nén/tạo lại.

Nếu cổng 8099 bận, báo lỗi; không tắt tiến trình khác để chiếm cổng.
Nếu PHP không khởi động được, kiểm tra thông báo thiếu thư viện/Visual C++ runtime;
không tải DLL từ trang không tin cậy. Chưa kiểm thử trên Windows sạch.

## Kiểm tra trên máy đích

- Tạo khách giả, phân công, đặt lịch chăm sóc, ghi kết quả, đóng/mở lại và kiểm tra dữ liệu.
- Tạo người dùng thử với vai trò hạn chế để kiểm tra phạm vi hồ sơ.
- Kiểm tra giao diện ở 100%/125%/150% DPI; xem nút và nhật ký có bị khuất không.
- Thử cổng bận, sai email/mật khẩu, thư mục đã có dữ liệu và runtime bị thiếu file.
- File tải lên công khai qua storage link và thông báo scheduler/worker cần kiểm tra riêng;
  portable chưa đăng ký scheduler/worker/dịch vụ Windows, chưa mở LAN/HTTPS.

## Giới hạn còn lại

SQL Server, production web server, tự khởi động Windows, update/rollback, backup qua giao diện,
restore production, ký số, runtime mới và kiểm thử tải chưa hoàn thành.
Không đổi tên gói này thành bản production. Không gỡ bằng cách xóa database khi còn cần dữ liệu.
