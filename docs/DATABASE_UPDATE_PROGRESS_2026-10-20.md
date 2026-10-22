# Nâng cấp database trong updater: tiến độ và giới hạn

Đã thêm AdditiveDatabaseUpgrade cho đúng hai migration ngày 20/10: related_document, customer_confirmation, document_type và document_id trong service_tickets. Đây là các cột nullable, không xóa/đổi dữ liệu. Không thực thi mã migration tùy ý từ gói. Nếu thiếu migration lịch sử khác hoặc cột đã tồn tại nhưng sổ migration chưa ghi nhận thì dừng để kiểm tra baseline.

Luồng bộ cài: kiểm tra chỉ đọc → sao lưu mã nguồn → bảo trì → sao lưu database và nâng cấp phần bổ sung đã duyệt → công bố mã nguồn → kiểm tra lại. SQLite VACUUM INTO được kiểm tra integrity và SHA-256 trước DDL; DDL và sổ migration nằm trong một transaction. Nếu mã nguồn thất bại sau nâng cấp, phục hồi mã nguồn nhưng giữ các cột nullable bổ sung tương thích với bản cũ. Đây không phải cơ chế tự restore toàn bộ database. Cấu hình, khóa APP_KEY và tài khoản không đổi.

Đã chạy: 3 bài PHPUnit/10 assertions cho backup, đọc bản backup, bảo toàn dữ liệu, rollback DDL/ledger và chặn migration chưa duyệt; 11 kiểm tra bridge PHP gồm maintenance, backup, nâng cấp và đọc lại; 21 kiểm tra engine cập nhật gồm callback database trước công bố mã nguồn, từ chối nâng cấp không thay code. Build Windows trước lượt kiểm thử cuối thành công.

## SQL Server CHƯA PHÊ DUYỆT

Backend đã có lệnh BACKUP COPY_ONLY CHECKSUM, VERIFYONLY và script thử restore sang database tổng hợp thứ hai, dùng Windows Authentication trên localhost\\MSSQLSERVER01. Không sử dụng cấu hình SQL khách. Quyền backup thiếu thì phải dừng, không tự cấp quyền.

Thử nghiệm 20/10: kết nối native-update tới master timeout Shared Memory Provider 258; chưa chạy được backup/restore. Lượt probe schema tạm trước đó gặp multiple cascade paths trong migration đầu tiên và timeout khi truy vấn sau rollback. Đây là lỗi kiểm thử, KHÔNG phải kết quả đạt. Không restart dịch vụ SQL, không thay cấu hình máy chủ hoặc database khách.

Bridge chủ động trả UPGRADE_SQL_UAT_REQUIRED trước bất kỳ thao tác backup/DDL trên SQL. Chỉ được bỏ chốt sau khi kiểm chứng native backup/restore, transactional DDL và baseline thực tế của bản khách trên môi trường cách ly hoạt động. Các gói cũ có migration lịch sử đã chỉnh khác hash cũng còn bị bộ kiểm tra mã nguồn chặn, cần xử lý tương thích riêng trước phát hành.

Chưa build/publish Setup giao khách mới. Setup cũ trong outputs/installer-review-20261020 không chứa tính năng mới. Không dùng kết quả SQLite để tuyên bố cập nhật SQL Server đã hoàn chỉnh.

## Kiểm tra tiếp ngày 20/10

- Hai instance SQL đang chạy; RAM trống chỉ khoảng 385–615 MB. Không thay đổi cấu hình max memory hoặc restart dịch vụ.
- Đã phân biệt được cổng loopback 17366 của named instance là DAC: backup không được phép chạy thao tác song song ở đây. Không coi kết nối DAC thành công là kết nối ứng dụng đạt.
- Kết nối local shared memory PDO trước bootstrap và tái sử dụng một phiên cho fixture đã chạy được bài thử SQL native. Chỉ tạo database tổng hợp có tiền tố VKCRM_UpdateTrial/UpdateRestore, không dùng database khách.
- Phát hiện và sửa việc PDO đóng cursor trước khi đọc hết kết quả BACKUP/RESTORE: dùng prepare/execute, nextRowset đến hết rồi closeCursor.
- Kết quả SQL mới: PASS native BACKUP + RESTORE VERIFYONLY; PASS rollback DDL/sổ migration khi tiêm lỗi; PASS thêm 4 cột nullable, giữ dữ liệu giả và nhận biết không còn pending.
- Restore sang database tổng hợp thứ hai chưa xác nhận hoàn tất: tiến trình kéo dài, kết nối chẩn đoán cũng timeout. Dừng riêng client PHP thử PID 13184 sau khi xác minh command line. Không dừng dịch vụ SQL, không xóa database/bản backup; cần kiểm tra trạng thái fixture trước khi thử tiếp.
- Hồi quy SQLite vẫn PASS: PHPUnit 3 bài/10 assertions; bridge PHP 11 kiểm tra.

Giữ nguyên chốt UPGRADE_SQL_UAT_REQUIRED. Chưa phê duyệt cập nhật SQL khách. Cần môi trường SQL đủ tài nguyên và xác nhận restore thành công, cùng đối chiếu baseline bản đã cài, trước khi tạo bản cập nhật giao khách.
