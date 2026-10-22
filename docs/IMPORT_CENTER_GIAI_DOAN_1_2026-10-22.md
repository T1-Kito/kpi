# Quản lý nguồn và lô nhập — giai đoạn 1

## Đã triển khai

Đường dẫn /import-center, nút Quản lý nhập tại Khách hàng, Người liên hệ, Khách hàng tiềm năng. Nhãn, hướng dẫn, trạng thái và lỗi nghiệp vụ mới dùng tiếng Việt. Chức năng Nhập file cũ vẫn giữ nguyên.

- Nguồn có mã cố định, tên, trạng thái và danh sách người được dùng. Người quản lý cần master.manage, role.manage và phạm vi công ty. Quản trị không tự có quyền dùng nguồn nếu chưa có tên trong danh sách.
- Nhập theo nguồn cần quyền danh mục, master.view, phạm vi công ty và membership của nguồn đang hoạt động. Chưa thêm permission import riêng ở giai đoạn này.
- Hỗ trợ nhập MỚI khách hàng, liên hệ, khách tiềm năng bằng importer nghiệp vụ hiện có: giữ kiểm tra trùng, phạm vi, audit, tạo liên hệ/lead và follow-up hiện hữu. Tối đa 300 dòng/1 MB như cũ, chưa nâng lên 1.000 dòng/5 MB.
- Upload kiểm tra tạo lô bền vững, chưa ghi hồ sơ nghiệp vụ. Ghi lại kết quả, người nhập, nguồn, danh mục và hash; file hợp lệ chờ xác nhận được mã hóa trong DB, không trả payload hoặc hash trong API chi tiết.
- Xác nhận theo ID lô đã kiểm tra, cần tích đồng ý; không nhận nội dung file thay thế ở bước này. Kiểm tra lại nguồn, membership, quyền, hạn hai giờ và dữ liệu trùng dưới khóa tenant trong transaction.
- Cùng lô đã nhập trả kết quả trước, không chạy lại nghiệp vụ. Hai bản xem trước cùng file/nguồn/danh mục không được ghi hai lần. Một nguồn khác không tự ghép hồ sơ đã tồn tại.
- Lỗi lúc ghi không đánh dấu thành công và không giữ hồ sơ nửa lô. File chờ được xóa khỏi lô sau thành công. Lệnh imports:prune hủy payload hết hạn và dọn chi tiết sau 30 ngày, giữ metadata/tổng hợp; cần scheduler thực sự chạy.
- Lịch sử chỉ của người đang đăng nhập. Truy cập trực tiếp lô của người khác trả 404; nguồn/quyền đã thu hồi chặn xem chi tiết và xác nhận.

## Database / kiểm thử

Migration 2026_10_22_100000_create_import_batch_tables.php thêm import_sources, import_source_users, import_batches. Không tự down xóa lịch sử. Chưa thêm mapping external_id/revision.

7 bài ImportBatchTest / 72 assertions: xem trước không ghi, xác nhận/replay, hai lô cùng file, mã hóa và ẩn payload, thu hồi membership, hết hạn, thay đổi dữ liệu giữa hai bước, owner/scope, mã nguồn bất biến, adapter contacts/leads, chặn nguồn/thành viên khác tenant, nguồn ngừng hoạt động và dọn payload. JavaScript thêm ba bài kiểm tra nhãn Việt, escape dữ liệu và xác nhận chủ động. Toàn bộ 167 bài JavaScript đạt; nhóm nhập cũ + mới 24 bài PHP/193 assertions đạt. Lần chạy toàn phần trước ca tenant bổ sung: 276 bài PHP/2.547 assertions đạt.

Kiểm tra SQL Server trên database tổng hợp mới VKCRM_ImportTrial_20261022_012431_72d1fb: tạo đủ ba bảng, unique thành viên, round-trip payload mã hóa lớn và rollback trạng thái lô đạt. Đây chỉ là smoke test schema/storage, chưa phải UAT nghiệp vụ hoặc kiểm thử đồng thời SQL Server.

Database phát triển local đã sao lưu, thử migration trên bản khôi phục riêng rồi mới áp dụng. Backup trước áp dụng: storage/app/private/database-backups/crm-20261022-012602-cf14b496.sqlite. Có thêm ba migration CSKH trước đó đang pending được áp dụng cùng; kiểm tra số lượng bản ghi nghiệp vụ, integrity, khóa ngoại và xác minh phiên đăng nhập đạt. Không truy cập/đổi database máy khách.

## Còn thiếu so với kế hoạch tổng thể

- Mapping theo external_id/source_revision, cập nhật trước/sau và phát hiện sửa tay/xung đột theo từng dòng; hiện chỉ nhập mới và nhận diện cùng FILE.
- Mẫu version mới thống nhất, preview đầy đủ từng dòng, file lỗi XLSX, nâng giới hạn và 13 danh mục còn lại.
- Quyền import riêng thay vì ghép quyền quản trị/danh mục hiện có.
- UAT trình duyệt, đầy đủ SQL Server, kiểm thử tải/đồng thời và scheduler tại máy vận hành.
- Bộ cập nhật hiện có không chạy tùy ý migration mới. Chưa bổ sung migration này vào danh sách nâng cấp đã duyệt và chưa đóng gói. Không đem bộ cài cũ cập nhật rồi kỳ vọng có trung tâm nhập mới.

Không đánh dấu đã hoàn thành bộ 16 danh mục. Không dùng file danh mục để nhập số dư kho/công nợ, điểm KPI, password/role hoặc SPAdmin.
