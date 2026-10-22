# SQL updater 0.8.5

## Kết quả mới thay thế chốt “SQL chưa UAT” của 0.8.1–0.8.4

20/10/2026 đã chạy thành công scripts/test-native-update-sqlserver.php trên named instance local riêng:

- VKCRM_UpdateTrial_20261020_071722_9f93c5: database tổng hợp mới.
- Native BACKUP COPY_ONLY CHECKSUM và VERIFYONLY đạt.
- Tiêm lỗi sau tạo bảng ticket_attachments: hoàn tác cả cột, bảng và sổ migration đạt.
- Nâng cấp thật fixture, giữ dữ liệu cũ và hết pending đạt.
- RESTORE sang VKCRM_UpdateRestore_20261020_071722_9f93c5: kiểm tra dữ liệu cũ và schema trước nâng cấp đạt.
- Backup và hai database được giữ lại để kiểm tra, không xóa dữ liệu hoặc restart dịch vụ SQL.

Bridge PHP thật của updater chạy trên VKCRM_BridgeTrial_20261020_071724_e039ab: 13 kiểm tra PASS. Có kiểm tra đọc cấu hình riêng, baseline, chặn migration chưa duyệt, yêu cầu maintenance, backup receipt, thêm bảng/cột, giữ dữ liệu, không đổi .env và nhận biết đã cập nhật.

## Phạm vi hỗ trợ

Cho phép cả SQL/SQLite chạy đúng ba bước trong AdditiveDatabaseUpgrade::STEPS: 4 cột nullable và bảng ticket_attachments. Không chạy file migration tùy ý. Lịch sử cũ khác, cột trùng nhưng ledger thiếu hoặc database hệ thống sẽ dừng. Có native backup và VERIFY trước DDL, metadata backup riêng; thiếu quyền thì fail closed, không nâng quyền.

C# chờ backup/upgrade tối đa 300 giây, PDO query timeout 120 giây. Nếu timeout không nói chắc “database chưa thay đổi”: giữ backup/nhật ký và kiểm tra trạng thái. Những bổ sung đều tương thích ngược, nên khi code fail sau commit chỉ khôi phục code/launcher, giữ cột/bảng mới, không xóa dữ liệu/restore SQL tự động. Crash trước hoặc sau commit vẫn giữ database theo trạng thái transaction; lần mở updater sau phục hồi code từ backup. Đây không phải cơ chế rollback schema tổng quát.

Xóa môi trường kế thừa SQLSERVER_* trong process PHP, ngoài DB_*/APP_* để không kiểm tra nhầm kết nối của máy xây dựng. Cấu hình thật từ .env của thư mục khách vẫn được giữ và dùng lại.

Không thay đổi hoặc truy cập database khách để chạy các bài trên. Chưa UAT trọn desktop và baseline cài thật của khách; chưa nghiệm thu mọi nghiệp vụ, chưa ký số. Test trên database riêng trước khi triển khai thật. Native thử dùng Windows Authentication local; không đồng nghĩa tài khoản SQL db_owner của khách đủ quyền VERIFY/backup.
