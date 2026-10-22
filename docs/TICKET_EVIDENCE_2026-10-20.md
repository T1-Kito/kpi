# Ảnh minh chứng chăm sóc khách hàng

Trong chi tiết ticket: chọn ảnh → Tải ảnh lên. Hồ sơ vẫn giữ nội dung xử lý đang gõ sau khi danh sách ảnh tải lại. Nút tên ảnh tải file qua API có JWT; không đưa token vào URL và không công khai đường dẫn storage.

JPG/PNG/WebP, tối đa 5 MB/ảnh, 6000×6000 pixel, 10 ảnh/hồ sơ. Server kiểm tra file thực tế, không chỉ phần mở rộng; chặn SVG. Tên file lưu ngẫu nhiên trong local private storage dưới tenant/ticket. Metadata lưu trong bảng ticket_attachments mới, gồm người tải, tên, dung lượng và hash. Không có API xóa minh chứng để tránh mất lịch sử.

Lưu ảnh trong transaction có khóa ticket, kiểm tra lại quyền và trạng thái, chặn trùng SHA-256. Lỗi DB/audit thì xóa riêng file vừa lưu để không để file mồ côi. Không thêm vào ticket đã đóng/hủy. Ghi audit và worklog khi thêm ảnh. Download kiểm tra quyền view, tenant, ticket chứa attachment, đường dẫn và hash; dùng attachment disposition, no-store và nosniff.

Phạm vi ticket: quyền công ty xem trong tenant; phạm vi khác chỉ xem hồ sơ tự tiếp nhận hoặc được giao. Áp dụng cho danh sách, chi tiết, nhật ký, chuyển trạng thái và ảnh. Chưa thiết kế queue theo phòng ban riêng.

Bộ nâng cấp SQLite đã duyệt được mở rộng thêm đúng bảng ticket_attachments bằng cùng định nghĩa schema dùng trong migration. Transaction bao cả cột, bảng mới và sổ migration. Kiểm thử tiêm lỗi sau tạo bảng bảo đảm rollback toàn bộ. SQL vẫn bị chặn cho đến khi kiểm chứng restore/baseline hoàn tất; không tự mở chốt chỉ vì có bảng mới.

Chưa chạy migration lên database workspace/khách; chưa đóng gói sau 0.8.1. Cần kiểm thử trình duyệt và SQL Server trước nghiệm thu. File Setup 0.8.1 đã gửi trước đó chưa có ảnh minh chứng.
