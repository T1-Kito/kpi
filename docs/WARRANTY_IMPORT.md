# Nhập đăng ký bảo hành

Tại Bảo hành chọn Nhập file → Tải mẫu Excel → điền từ dòng 5 → Kiểm tra file → Xác nhận nhập. Nhận .xlsx và CSV cũ. Không dùng để nhập yêu cầu sửa chữa hoặc kết quả xử lý khiếu nại.

Mỗi dòng một serial; sáu cột: Mã khách hàng, Serial, Sản phẩm, Ngày mua, Ngày bắt đầu, Số tháng. Mã khách phải có sẵn, đang hoạt động và thuộc phạm vi phụ trách. Ngày hiển thị dd/mm/yyyy, ngày mua có thể trống; số tháng 1–120. Mẫu đặt mã/serial dạng văn bản, ngày dạng ngày Excel, số tháng dạng số. Chấp nhận ngày dd/mm/yyyy dạng chữ và ngày số Excel (1900/1904). CSV cũ yyyy-mm-dd vẫn đọc được, không suy đoán mm/dd/yyyy.

Giới hạn 1 MB, 300 serial/lần. Kiểm tra ngày và trùng danh tính sản phẩm/serial cả trong file và trong tenant. Xem trước không ghi dữ liệu; xác nhận kiểm tra lại, giữ khóa tenant và lưu toàn bộ trong transaction. Không ghi đè đăng ký đã có, không tự tạo khách mới. Dùng ServiceDeskService nên ngày hết hạn, audit và sự kiện thống nhất với nhập tay. Quyền service.ticket.manage áp dụng cho tải mẫu và nhập.

Mẫu Excel đồng bộ giao diện mẫu khách hàng: Arial, tiêu đề xanh, bảng xanh xen kẽ, đóng băng 4 hàng đầu, 300 dòng nhập. Đã render kiểm tra không cắt chữ. Nhóm WarrantyImportTest và CustomerImportTest: 14 bài, 92 assertions PASS, bao gồm nhập mẫu Excel thực tế với ngày chữ 05/10/2026 và ngày số Excel, serial 000123. Chưa kiểm chứng trực tiếp bằng Microsoft Excel. Bộ đọc OOXML dùng chung vẫn chặn công thức, liên kết ngoài và mã nhận diện dạng số.

Chưa đóng gói vào Setup và chưa kiểm thử giao diện trình duyệt/SQL Server.
