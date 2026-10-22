# Luồng phản hồi khách hàng

Phạm vi hiện tại: nhân viên tiếp nhận qua điện thoại/Zalo/email/trực tiếp rồi ghi nhận trong CRM. Không có cổng khách tự gửi; nhật ký “đã trả lời khách” không tự gửi thông báo ra ngoài.

1. Tiếp nhận: chọn khách, loại vấn đề, kênh, nội dung, ưu tiên và hạn xử lý. Có thể chọn liên kết báo giá/đơn hàng/phiếu giao: API chỉ trả chứng từ đúng khách, tenant và phạm vi sở hữu, có quyền xem module tương ứng; 30 chứng từ/trang, tải thêm. Khi lưu kiểm tra lại và lấy mã chuẩn từ chứng từ, không tin mã người dùng gửi. Văn bản tham chiếu tự do cũ vẫn giữ nhưng không được coi là liên kết đã xác thực.
2. Phân công: người phụ trách phải thuộc doanh nghiệp và đang hoạt động. Chưa phân công thì không chuyển sang đang xử lý/chờ khách/hoàn tất.
3. Xử lý: lưu nhật ký và phương án. Không tự sửa chứng từ bán hàng, số lượng kho hoặc công nợ từ phản hồi.
4. Hoàn tất xử lý: bắt buộc mã kết quả, nội dung kết quả và người phụ trách. Giữ nguyên mốc hoàn tất khi lưu lại, không làm mới SLA.
5. Trả lời khách: gọi/nhắn/email bên ngoài rồi ghi nhật ký “đã trả lời khách”. Được ghi thêm trao đổi sau khi đã xử lý nhưng trước khi đóng.
6. Đóng: phải đang ở trạng thái đã xử lý, có nhật ký trả lời khách SAU lần ghi nhận kết quả xử lý mới nhất, nhân viên xác nhận khách đồng ý và ghi chú. Nhật ký cũ trước lần xử lý lại không đủ điều kiện đóng. Đây là xác nhận do nhân viên ghi nhận, không phải bằng chứng khách xác thực trực tuyến.
7. Hủy: bắt buộc lý do. Hồ sơ đã đóng/hủy không được sửa tiếp.

8. Khách chưa đồng ý khi hồ sơ đã xử lý: chọn xử lý lại, ghi nhận khách từ chối và lý do. Giữ nguyên hạn SLA ban đầu, xóa mốc hoàn tất hiện hành để tiếp tục theo dõi; lịch sử giữ kết quả cũ và mốc hoàn tất trước đó. Không mở lại hồ sơ đã đóng/hủy.

SLA mặc định theo giờ liên tục; đã có cấu hình lịch làm việc/ngày nghỉ, tạm dừng có lý do và nhắc hạn cho người nhận cấu hình. Không tự tạm dừng chỉ vì chờ khách. Hồ sơ mới giữ snapshot, hồ sơ cũ không tính lại. Chưa cấu hình thì màu vàng ở 20% thời lượng còn lại; có chính sách thì theo ngưỡng phút cảnh báo. Xem `SERVICE_SLA_POLICY_2026-10-20.md` về cấu hình, scheduler bắt buộc và giới hạn kiểm thử.

Danh sách hỗ trợ tìm kiếm, lọc trạng thái/người phụ trách/quá hạn, phân trang 20 hồ sơ và làm mới mỗi phút khi không mở hộp thoại hoặc thao tác trong danh sách. Ảnh minh chứng lưu riêng tư, tải qua kiểm tra quyền; không dùng đường dẫn ảnh công khai.

Kiểm thử bao gồm mở lại khi khách từ chối, giữ hạn SLA, bắt buộc phản hồi mới trước khi đóng, lọc/phân trang và quyền ảnh minh chứng. Thay đổi luồng ngày 20/10 chưa có trong bộ cài 0.8.5. Cần UAT trình duyệt và SQL Server với dữ liệu thử trước khi nghiệm thu; không coi kiểm thử tự động SQLite là chứng nhận toàn bộ nghiệp vụ.
