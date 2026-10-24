# Nhập khách hàng theo mẫu chuẩn và rút gọn phân nhóm

## Cập nhật trải nghiệm nhập liệu 23/10

### Khách hàng tiềm năng

- Nút Nhập file chuyển sang lead-preview rồi xác nhận bằng ID lô bất biến, không gửi lại file để tự xác nhận. Đổi file xóa xác nhận cũ.
- Mẫu hiển thị 8 cột tiếng Việt: Mã ngoài, Tên khách hàng tiềm năng, Số điện thoại, Email, Nguồn, Mã chiến dịch, Người phụ trách, Mã khách hàng. Hai cột kỹ thuật được xử lý nội bộ.
- Mã ngoài tùy chọn khi thêm mới. Người phụ trách dùng email người đang hoạt động cùng công ty, bỏ trống giao người nhập. Mã khách hàng là tham chiếu crm:/ext: tùy chọn và cần quyền khách hàng.
- Chỉ thêm mới hoặc bỏ qua hồ sơ nhập lặp không đổi, không cập nhật hồ sơ đang chăm sóc qua Excel. Tạo công việc theo luồng LeadController; lỗi giữa chừng hoàn tác toàn lô.
- Tên tải là Mẫu khách hàng tiềm năng.xlsx, không cache. Mẫu có khung dòng, hướng dẫn và ví dụ riêng đồng bộ hai mẫu trước.
- Đã chạy hồi quy 26 kiểm thử tích hợp/240 xác nhận và chạy lại 8 kiểm thử lead/73 xác nhận sau khi thêm ca khóa người phụ trách sau xem trước; 13 JavaScript đạt. SQL Server tổng hợp riêng VKCRM_CustomerImport_20261023_073041_28cdc5 đạt hồi quy ba danh mục. Chưa UAT trình duyệt và chưa đóng gói. Xổ xuống động khách hàng/người phụ trách còn ở bước tiếp theo.

- File tải hiện tại tên `Mẫu khách hàng.xlsx` và `Mẫu người liên hệ.xlsx`, tiêu đề cột tiếng Việt, có khung dòng, trang hướng dẫn và ví dụ riêng.
- Không yêu cầu nhân viên điền phiên bản mẫu/lần cập nhật: hai cột kỹ thuật đã bỏ khỏi mẫu khách hàng và người liên hệ. Backend vẫn nhận mẫu kỹ thuật cũ.
- Mã ngoài được để trống khi thêm mới khách hàng/người liên hệ. Khách hàng vẫn nhận mã CRM tự sinh; không tạo liên kết nguồn với mã trống. Không dùng tên hoặc số điện thoại để tự ghép/cập nhật hồ sơ. Kiểm tra trùng vẫn hoạt động.
- Mẫu có xổ xuống cho Loại khách hàng, Trạng thái và Liên hệ chính. Chưa có xổ xuống động theo danh sách khách hàng/người phụ trách thực tế.
- Kiểm thử khách hàng, người liên hệ và thông báo tiếng Việt: 20 kiểm thử, 177 xác nhận đạt (chạy nhóm 19 kiểm thử rồi chạy lại toàn bộ 10 kiểm thử người liên hệ sau khi thêm ca mã ngoài trống).
- Chưa nghiệm thu toàn bộ 16 danh mục, chưa đóng gói bản cài mới và chưa kiểm tra giao diện bằng trình duyệt thực tế. Các ghi chú hợp đồng kỹ thuật bên dưới mô tả cấu trúc nội bộ, không phải các cột nhân viên phải nhập.

- Nút Nhập file trong Khách hàng tải mẫu nguyên bản `Mau-import-customers-v1.xlsx` từ bộ ZIP người dùng cung cấp; tên tải trong phần mềm vẫn là mau-khach-hang.xlsx.
- Hợp đồng 16 cột theo đúng thứ tự: schema_version, external_id, source_revision, name, customer_type, tax_code, identity_number, phone, email, address, billing_address, legal_representative, representative_position, credit_limit, status, sales_owner_ref.
- Các cột hóa đơn/đại diện/hạn mức/trạng thái được kiểm tra và lưu. Người phụ trách dùng crm:email, phải là người dùng active cùng tenant và qua kiểm tra gán phụ trách của controller khách hàng.
- Endpoint customer-preview chọn nguồn nội bộ cố định CRM-CUSTOMER-DEFAULT trong tenant. Chỉ người có master.view, customer.manage và scope company được dùng. Không cấp thêm quyền vai trò; không tự mở lại nguồn đã bị ngừng. Xác nhận vẫn theo ID lô đã xem trước, owner lô và kiểm tra lại dữ liệu.
- Khi cập nhật ô trống giữ nguyên; không đổi loại hồ sơ, không tự ghép khách trùng. Không tạo/sửa công nợ thực tế; credit_limit chỉ là hạn mức.
- Form tạo/sửa chỉ còn customer_group tùy chọn: Gia đình, Trường học, Doanh nghiệp, Đại lý/Đối tác, Khác. Giá trị nhóm cũ ngoài danh sách vẫn hiển thị/giữ được. business_segment và customer_audience không gửi từ form, không xóa dữ liệu đã có.
- Chưa thay đổi giới hạn 300 dòng/1 MB/2 giờ; tài liệu gốc trong mẫu ghi 1000 dòng/5 MB/30 phút là phạm vi bên gửi, chưa phải năng lực đã kiểm định của bản này. UI nêu giới hạn thực tế. Các mẫu 11 cột theo nguồn cũ không dùng cho chế độ 16 cột mới; API nhập cũ vẫn riêng, không bị xóa.
- Kiểm thử JS 5 đạt, nhóm PHP chuẩn/phân nhóm 8 kiểm thử/78 xác nhận đạt. SQL riêng VKCRM_CustomerImport_20261023_003436_cbeea7 đạt luồng create/update/blank/replay/conflict và hồi quy contacts/leads. Không chạm database khách, không thay migration local trong lần này, chưa đóng gói và chưa UAT trình duyệt thực tế.
