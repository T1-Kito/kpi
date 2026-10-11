# Rà soát dữ liệu nền – 11/10/2026

## Đã sửa trong lượt này, chỉ local

- Cơ cấu tổ chức tải đầy đủ các trang phòng ban, chức vụ, nhân sự; không dừng ngầm ở 50/100 bản ghi. Lỗi tải được hiển thị, không coi danh sách thiếu là thành công.
- API cập nhật phòng ban và quản lý trực tiếp chặn vòng lặp trực tiếp/gián tiếp, kể cả ID gửi dưới dạng chuỗi. Cập nhật được khóa theo tenant và ghi audit trong giao dịch.
- Thiết lập KPI dùng danh mục nhân sự/phòng ban/chức vụ cùng tenant, hiện số tài khoản thiếu phòng ban/chức vụ và liên kết đến màn hình quản trị thực tế.
- Phân KPI có tìm người/nhóm và xem trước danh sách nhân viên hoạt động từ API. Cảnh báo nhóm rỗng, thiếu dữ liệu tổ chức, ưu tiên cấu hình riêng và thay đổi thành viên trước khi chốt kỳ.
- API preview chỉ dành cho quyền kpi.lock, không tạo hoặc sửa phân công. Không chấp nhận tài khoản cá nhân đã ngừng hoạt động khi tạo/sửa phân KPI.
- Không tự tạo mục tiêu, vai trò hoặc dữ liệu doanh nghiệp giả. Không seed lại DB local, không push/deploy Railway.

## Bản đồ liên kết đã đối chiếu trong mã

- Nhân sự: Department / Position / User dùng chung bởi quản trị người dùng và KpiAssignment. WorkAssignmentService lọc nhân viên hoạt động, quyền nghiệp vụ và task.view.
- KPI: KpiDefinition là danh mục; KpiAssignment chọn nguồn tính cho từng người/kỳ. Nguồn tính hiện giới hạn 5 metric rõ ràng, không thực thi công thức nhập tự do.
- CRM: SalesMasterDataController đã có giai đoạn cơ hội, nguồn cơ hội, bảng giá, điều khoản thanh toán. Đây không phải bằng chứng tất cả màn hình CRM đã dùng thống nhất; còn phải kiểm tra từng màn hình tạo/sửa và dữ liệu cũ.

## Chưa hoàn chỉnh – cần tiếp tục trước triển khai production

1. Danh mục ngành nghề, lý do mất cơ hội, đơn vị tính, loại công việc và quy tắc ngừng sử dụng: rà luồng lưu/sửa, chứng từ cũ và chọn mới để tránh dữ liệu tự do không đồng nhất.
2. Bộ KPI có phiên bản theo vị trí/phòng ban, sao chép kỳ tiếp theo; chốt kỳ đồng bộ và quy trình điều chỉnh sau chốt.
3. Dashboard cấp công ty phải dùng engine KPI theo nhân viên/kỳ mới, không nhầm với điểm vận hành lũy kế cũ.
4. Các màn hình còn nhúng dữ liệu tenant đầu tiên vào HTML (approval center, stock take, supplier quotation, service desk) cần chuyển sang tải API có xác thực/phạm vi.
5. Kiểm thử tải lớn, đối chiếu toàn luồng CRM→bán hàng→kho→KPI và kết quả xuất Excel; giữ lịch sử người chịu trách nhiệm tại thời điểm sự kiện.

Không coi lượt sửa này là hoàn tất toàn bộ master data hoặc toàn bộ CRM/KPI.
