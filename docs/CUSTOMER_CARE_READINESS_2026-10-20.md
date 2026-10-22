# Chăm sóc khách hàng — trạng thái kiểm tra ngày 20/10/2026

## Đã có trong mã nguồn

- Tiếp nhận phản hồi theo loại vấn đề, mức ưu tiên và kênh; liên kết chứng từ cùng khách trong phạm vi được phép.
- Chọn khách theo phạm vi phụ trách, kiểm tra lại ở API; người xử lý phải hoạt động và có quyền xem ticket.
- Nhật ký nội bộ, ghi nhận đã trả lời khách, ảnh minh chứng riêng tư và kiểm tra quyền tải ảnh.
- Phản hồi đầu tiên và hoàn tất xử lý có hai đồng hồ riêng; xanh/vàng/đỏ theo hạn.
- Hoàn tất xử lý cần người phụ trách, mã kết quả và nội dung.
- Đóng cần trả lời khách sau kết quả xử lý mới nhất và ghi nhận khách đồng ý.
- Khách từ chối: xử lý lại hồ sơ chưa đóng, giữ hạn cũ và lịch sử. Không tự đặt lại SLA.
- Tìm kiếm, lọc người xử lý/trạng thái/quá hạn, phân trang và làm mới danh sách có bảo vệ hộp thoại đang nhập.
- Đăng ký/import bảo hành và lịch chăm sóc khách hàng là các chức năng riêng đã có; ticket không tự động điều chỉnh kho/công nợ.

## Chưa được coi là nghiệm thu

Bản đối chiếu nguồn `SOURCE_REQUIREMENTS_REVIEW_2026-10-13.md` ghi nhận tài liệu trước có Service/CSKH: SLA, calendar, pause/resume, escalation, reopen, asset/warranty. Không đồng nghĩa tất cả đã thực hiện.

- Đã bổ sung cấu hình lịch làm việc/ngày nghỉ, pause/resume theo chính sách snapshot và chuyển cấp bằng thông báo nội bộ. Chưa có lịch làm việc/ngày nghỉ/người nhận đã được khách xác nhận; chưa cài và kiểm chứng scheduler trên máy khách. Xem `SERVICE_SLA_POLICY_2026-10-20.md`.
- Chưa có cổng khách tự phản hồi hoặc gửi email/Zalo tự động; nhân viên liên hệ bên ngoài và ghi nhận trong CRM.
- Xác nhận khách đồng ý là bản ghi của nhân viên, không phải chữ ký/xác thực trực tuyến của khách.
- Chưa UAT toàn luồng mới trên trình duyệt và SQL Server. Các bài kiểm tra SQL nâng cấp trước đây không thay thế UAT nghiệp vụ này.
- Thay đổi luồng mới chưa được đóng gói vào bộ cài 0.8.5. Chưa cập nhật database máy khách.
- Hồ sơ resolved cũ thiếu nhật ký `resolved` cần lưu kết quả lại rồi ghi nhận trả lời khách trước khi đóng.

## Điều kiện bàn giao tiếp theo

Chốt lịch SLA và người nhận chuyển cấp; thử luồng đúng/sai quyền bằng dữ liệu thử trên SQL Server; kiểm tra giao diện; sau đó đóng gói và thử nâng cấp từ bản đang cài, đối chiếu dữ liệu/config trước và sau. Không đổi trạng thái nghiệm thu trong tài liệu gốc thay khách.
