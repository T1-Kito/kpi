# Phản hồi khách hàng: thời hạn và nhập file

Đã sửa mã nguồn, chưa đóng gói vào Setup:

- Ticket trả về SLA phản hồi và SLA xử lý, dùng thời gian máy chủ. Xanh khi còn trên 20%, vàng khi còn tối đa 20%, đỏ khi quá hạn. Chính xác tại hạn vẫn vàng; sau hạn chuyển đỏ.
- Ticket đã xử lý giữ kết quả đúng/trễ hạn theo mốc hoàn tất, không tiếp tục đếm. Hủy hiển thị trung tính; dữ liệu cũ thiếu mốc không giả định đúng hạn.
- Tạo ticket có hạn xử lý tùy chọn, để trống giữ định mức ưu tiên hiện có (4/8/24/72 giờ). Không được nhập hạn quá khứ. Hạn phản hồi không muộn hơn hạn xử lý.
- Chặn đóng trước khi xử lý; không làm mới resolved_at khi lưu lại. Mở lại ticket chưa hỗ trợ vì quy tắc SLA chưa chốt.
- Màu kèm chữ, thời gian còn lại/quá hạn và ngày giờ hạn trên danh sách, trong chi tiết. Danh sách nhận lại trạng thái khi tải dữ liệu; chưa tự cập nhật mỗi phút.

Kiểm thử: TicketDeadlineTest + ServiceDeskApiTest: 4 tests, 21 assertions PASS (SQLite cách ly). JavaScript syntax check PASS. Chưa kiểm thử UI trình duyệt và SQL Server cho thay đổi này.

Nhập file:

- Khách hàng: đã có Excel/CSV, kiểm tra trước nhập, xác nhận, chống trùng, phân quyền.
- Sản phẩm/SKU, nhà cung cấp, liên hệ, đăng ký bảo hành: cần rà từng schema, quyền và danh tính trước khi bổ sung. Chưa triển khai import cho các mục này trong lượt sửa SLA.
- Không tự cho import đơn hàng, xuất nhập kho hoặc thanh toán bằng cách ghi trực tiếp bảng: phải bảo toàn quy trình duyệt và sổ phát sinh.
- Chưa bổ sung các loại khiếu nại, liên kết chứng từ và ảnh minh chứng xuyên suốt đơn/kho/giao hàng; ticket hiện có vẫn là nền tảng hỗ trợ/bảo hành.
