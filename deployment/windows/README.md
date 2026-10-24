# VK CRM — Windows Server UAT package

**Đây là gói mã nguồn thử nghiệm, KHÔNG phải Setup.exe hay bản nghiệm thu production.**
Không chứa PHP, ODBC, Composer, thư viện vendor, dữ liệu khách, .env thật hoặc khóa APP_KEY.
Không sử dụng DatabaseSeeder: seeder hiện có tạo dữ liệu/tài khoản demo.

## Chuẩn bị môi trường cách ly

1. Máy/VM thử riêng: Windows Server có IIS, CGI/FastCGI và URL Rewrite.
2. PHP 8.2+ còn được hỗ trợ và extension theo Composer/preflight; driver SQL Server
   đúng phiên bản PHP, kiến trúc và TS/NTS. Không dùng DLL thử 8.2 TS cho PHP NTS.
3. ODBC chính thức, TLS/chứng chỉ SQL Server hợp lệ. Không tắt xác minh TLS để giao khách.
4. SQL Server: database UAT mới, login UAT riêng, không dùng sa hoặc database nghiệp vụ.
5. Tài khoản migration và tài khoản runtime phải được quản lý riêng; runtime không có quyền sysadmin.
6. Có đủ RAM và dung lượng cho SQL Server + file tải lên + backup; chưa có benchmark tải.

## Triển khai thủ công UAT

- Giải nén vào thư mục mới, không ghi đè bản đang chạy.
- Chạy `composer install --no-dev --prefer-dist --optimize-autoloader` từ composer.lock.
  Cần Internet hoặc kho dependency nội bộ. Kiểm tra `composer check-platform-reqs --no-dev`.
- Copy `deployment/windows/.env.uat.example` thành `.env`, điền thông tin riêng.
  Không copy .env/APP_KEY từ máy phát triển và không chia sẻ mật khẩu qua log/chat.
- Chạy `php artisan key:generate` DUY NHẤT khi cài mới. Không chạy lại khi nâng cấp/restore.
- `php artisan crm:preflight --target=sqlsrv`
- `php artisan crm:sqlserver-check --connect` (chỉ đọc).
- Kiểm tra tên database UAT trước khi `php artisan migrate --force`.
  Chưa nghiệm thu migration SQL Server: nếu lỗi, dừng và giữ log, không đổi sang migrate:fresh.
- Khi migration hoàn tất trên database mới, chạy `php artisan crm:install-admin`.
  Nhập doanh nghiệp/email, mật khẩu riêng tối thiểu 8 ký tự và xác nhận database.
  Chỉ chạy khi site chưa công bố và không có tiến trình khởi tạo khác cùng chạy.
  Lệnh từ chối khi đã có công ty hoặc tài khoản, không đổi mật khẩu hiện có.
  Tạo một Super Admin toàn quyền, không tạo dữ liệu demo; cần cấu hình vai trò
  nhân viên và dữ liệu nền trước khi sử dụng. Không dùng mật khẩu demo để bàn giao.
- IIS physical path phải là thư mục **public**, KHÔNG phải gốc dự án.
  Copy `web.config.example` vào public/web.config sau khi review cấu hình IIS.
- Cấp quyền đọc mã nguồn, chỉ cấp quyền ghi storage và bootstrap/cache cho service account.
  Chặn truy cập HTTP tới .env, storage/app/private, backup và log.
- Tạo storage link, kiểm tra file public/private với từng vai trò.
- Thiết lập worker riêng `php artisan queue:work --tries=3 --timeout=90` và scheduler
  `php artisan schedule:run` mỗi phút bằng service/Task Scheduler với tài khoản hạn chế.
  Ghi nhận restart policy và log; gói này KHÔNG tự đăng ký dịch vụ.
- Sau cấu hình ổn định: `php artisan config:cache`, `php artisan view:cache`.

## Kiểm tra trước khi nghiệm thu

- HTTP/HTTPS, đăng nhập, hết phiên, scope công ty và nhân viên, dữ liệu nhạy cảm.
- Khách hàng → chăm sóc → thông báo → bàn giao, gồm nhân sự nghỉ/chuyển phòng.
- Báo giá → duyệt nhiều cấp/duyệt thay → khách chấp nhận → đơn hàng → kho → công nợ.
- KPI, bộ lọc/phân trang và Excel đối chiếu chứng từ, tiếng Việt và decimal.
- Cập nhật trên bản sao UAT; không thay APP_KEY và không mất file đính kèm.
- Backup SQL Server native + file/config cùng thời điểm nhất quán; restore database
  sang tên khác và file sang thư mục khác, thử ứng dụng trước khi ghi đè hệ thống thật.
  Lệnh bundle hiện tại CHỈ hỗ trợ SQLite; không dùng cho SQL Server.

## Chưa hoàn thành

SQL Server migration/nghiệp vụ/native backup chưa chạy đạt; thiếu bộ cài Setup.exe,
wizard/Server Manager, updater/rollback tự động, sao lưu theo lịch,
chứng nhận máy sạch và kiểm thử tải. Không trình bày gói này là đã hoàn tất.
