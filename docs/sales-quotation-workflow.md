# Luồng báo giá bán hàng

## Áp dụng

Báo giá tạo mới dùng workflow 2. Báo giá cũ giữ nguyên trạng thái và lịch sử (workflow 1), không tự coi là đã duyệt. Nhân bản báo giá cũ để sử dụng quy trình mới.

## Quy trình

Nháp → Gửi duyệt → Duyệt lần lượt → Đã duyệt → Ghi nhận phát hành → Khách đồng ý → Tạo đơn hàng.

- Nháp kế thừa khách hàng, người liên hệ chính, hàng hóa từ cơ hội, chính sách giá và điều khoản thanh toán. Mã hàng, tên, đơn vị, giá bán và giá vốn được lưu trên từng dòng.
- Chiết khấu lấy từ chính sách giá; thuế tính trên giá sau chiết khấu. Máy chủ tính bằng số thập phân, làm tròn mỗi dòng đến hai chữ số. Đơn hàng kế thừa giá đã chốt, không tra giá mới.
- Gửi duyệt yêu cầu giá bán đầy đủ, tổng tiền dương và ngày hiệu lực hợp lệ. Người lập được duyệt khi có quyền, nằm trong luồng và đến đúng lượt; không tự bỏ qua các cấp khác. Thiếu người duyệt hợp lệ thì giữ nháp và báo rõ lỗi.
- Thiết lập tại **Thiết lập → Duyệt báo giá bán**: tối đa ba người duyệt lần lượt và một người duyệt ngoại lệ. Biên lợi nhuận thấp, chiết khấu cao, giá trị lớn, thiếu giá vốn hoặc điều khoản khác mặc định sẽ thêm người duyệt ngoại lệ nếu được cấu hình. Nếu không cấu hình, chỉ dùng quản lý trực tiếp có quyền duyệt; không tự chọn Admin.
- Nội dung, thông tin khách và cấu hình duyệt được chụp lại lúc gửi duyệt. Đổi master data hoặc cấu hình sau đó không đổi phiên bản đang duyệt.
- Từ chối hoặc yêu cầu sửa cần lý do. Sửa bản đã gửi duyệt/phát hành bằng phiên bản mới. Bản cũ và lượt duyệt được giữ, nhưng không còn dùng để chốt đơn. Không được xóa nháp phiên bản sửa đổi.
- Phát hành lưu một bản Word riêng trong kho lưu trữ nội bộ, tên mẫu, mã SHA-256, người thực hiện, thời điểm, người nhận, kênh gửi và bằng chứng. Xem trước/tải lại bản phát hành sử dụng file lưu này; không trộn lại theo mẫu mới.
- Phản hồi khách phải có bằng chứng. Báo giá chưa được khách đồng ý hoặc đã hết hiệu lực không tạo đơn hàng mới theo workflow 2. Không tạo hai đơn hoạt động từ cùng một báo giá.

## Giới hạn hiện tại

- Phát hành là ghi nhận việc gửi qua kênh bên ngoài, chưa tự gửi email/Zalo. Không có cấu hình kết nối gửi thư trong phần triển khai này.
- Bản lưu cố định hiện là Word, chưa có bộ chuyển đổi PDF phía máy chủ. Bản xem trước Word trong trình duyệt có thể phân trang khác Microsoft Word.
- Bằng chứng gửi/khách đồng ý hiện nhập nội dung hoặc đường dẫn chứng từ, chưa phải cổng ký điện tử hoặc xác nhận tự động từ khách.
- Người phụ trách hệ thống cần chọn người duyệt thực tế một lần. Không tự thay đổi phân quyền hay tự duyệt chứng từ thật khi triển khai.

## Kiểm thử

`php artisan test --compact`

Kiểm thử bao gồm khóa nháp sau gửi duyệt, phân quyền đọc theo lượt được giao, thứ tự duyệt, cấu hình được giữ cố định, từ chối/yêu cầu sửa, lịch sử phiên bản, tính giá chiết khấu/VAT, lưu file phát hành và hash, thiếu mẫu, báo giá hết hạn, phản hồi khách, chặn tạo đơn sớm và chặn tạo đơn trùng.
