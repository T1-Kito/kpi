# SQL Server — trạng thái kiểm chứng

## Đã thực hiện

- Bộ cài có kiểm tra kết nối độc lập, chỉ đọc, không tải Laravel hoặc thay `.env`.
- Hỗ trợ SQL Authentication và Windows Authentication theo danh tính chạy bộ cài.
- Mật khẩu truyền qua stdin; không nằm trong dòng lệnh hoặc thông báo lỗi.
- Luôn bật mã hóa; kiểm chứng chứng chỉ mặc định. Tin cậy chứng chỉ chỉ khi người dùng chủ động bật để thử nội bộ.
- Kiểm tra Unicode tiếng Việt, thời gian đăng nhập 5 giây, giới hạn tổng tiến trình 20 giây.
- Runtime kiểm tra được giải nén riêng trong thư mục tạm UUID, kiểm checksum payload và dọn sau khi dùng.
- PDO driver x64 TS 8.2 đi kèm đã kiểm chữ ký Microsoft hợp lệ trên máy build.

## Kết quả trên máy phát triển

- `php scripts/test-installer-sql-check.php --local-readonly`: đạt kiểm tra đầu vào, thiếu driver, JSON/exit code, không lộ mật khẩu; kết nối localhost/master bằng Windows và Unicode đạt.
- Thử migration riêng đã dừng trước CREATE DATABASE vì ổ đĩa không đạt điều kiện tối thiểu 5 GiB trống. Lần thử này không tạo database.

## Chưa được coi là hoàn tất

- Chưa nghiệm thu toàn bộ migration, khóa ngoại, báo cáo, nghiệp vụ và backup/restore trên SQL Server.
- Nút Cài đặt bằng SQL Server vẫn bị chặn; kết nối thành công không bật cài đặt tự động.
- Chưa kiểm chứng UI trực tiếp và máy Windows sạch.

## Chuẩn bị máy thử

1. Windows x64 có đủ dung lượng cho ứng dụng, database và bản sao lưu; không dùng dữ liệu khách thật.
2. SQL Server đang chạy; cung cấp tên máy chủ/instance hoặc host,cổng và database thử riêng do quản trị chuẩn bị.
3. Cài Microsoft ODBC Driver 18 và Visual C++ x64 nếu máy thiếu. Bộ cài có liên kết Microsoft.
4. Chọn Windows Authentication hoặc nhập tài khoản SQL vào giao diện; không gửi mật khẩu qua chat.
5. Với server khác máy: quản trị cấu hình TCP/IP, firewall và chứng chỉ hợp lệ. Không tự mở cổng hoặc tắt TLS.
6. Migration cần tài khoản triển khai có quyền tạo bảng trong database thử. Tài khoản vận hành sau đó phải được giới hạn quyền, không dùng sa/sysadmin mặc định.
7. Chỉ nghiệm thu sau khi tạo bảng, khởi tạo Admin, đăng nhập, CRUD khách hàng/nhân sự, phân quyền, tiếng Việt, báo cáo và phục hồi bản sao lưu đều đạt.

Không chạy migrate:fresh, xóa bảng, đổi chế độ xác thực hoặc cấp quyền trên database của khách để xử lý lỗi.
