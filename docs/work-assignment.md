# Phân công tự động — giai đoạn 1

Trong Thiết lập → Tự động phân công, người có quyền `user.manage` chọn nhóm nhân sự một lần và bật chức năng. Mặc định tắt; không tự chọn Admin.

- Lead mới chưa có người phụ trách được chia luân phiên trong nhóm chăm sóc, rồi sinh việc chăm sóc và thông báo.
- Việc xuất kho và xử lý yêu cầu mua được tạo từ nghiệp vụ sẽ chia luân phiên cho nhóm tương ứng.
- Lựa chọn người nhận trực tiếp luôn được giữ nguyên.
- Nhân sự phải cùng công ty, đang hoạt động, có quyền xem công việc và quyền chức năng tương ứng. Kiểm tra lại lúc phân công, không chỉ khi lưu cấu hình.
- Nhóm trống hoặc không còn nhân sự hợp lệ: để chưa phân công, không giao nhầm người.
- Chỉ áp dụng dữ liệu mới; không tự chuyển chủ các hồ sơ hoặc công việc cũ.
- Với chăm sóc lead, xuất kho và xử lý yêu cầu mua: cùng chứng từ, loại việc và công ty chỉ có một việc tự sinh đang mở. Sau khi kết thúc có thể tạo một việc mới khi nghiệp vụ thực sự yêu cầu. Việc nhập thủ công và các loại việc khác không dùng cơ chế này.

Quyền chức năng: chăm sóc lead `sales.lead.manage`, xuất kho `inventory.issue.confirm`, xử lý yêu cầu mua `procurement.pr.approve`. Phân công không cấp thêm quyền, không tự duyệt hay xác nhận kho/tiền. Có nhật ký thay đổi cấu hình.

Chưa nằm trong giai đoạn này: chạy và kiểm chứng bộ lịch trên máy vận hành, thực thi lại sự kiện lỗi, tự chuyển owner khách hàng/cơ hội, và điều phối toàn bộ loại công việc. Không xem việc đánh dấu sự kiện đã xử lý là bằng chứng đã thực thi nghiệp vụ.
