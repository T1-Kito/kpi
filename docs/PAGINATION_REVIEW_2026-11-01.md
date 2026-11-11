# Phân trang và tra cứu danh sách — 01/11/2026

## Lỗi đã xác định
Một số màn hình gọi API lấy trang đầu (20, 50 hoặc 100 dòng), sau đó tìm kiếm/lọc ở trình duyệt. Vì vậy dữ liệu vẫn tồn tại nhưng không tìm được nếu nằm ở trang sau. Nhật ký hệ thống cũng bị tình trạng này.

## Thay đổi
- Công việc, Cơ hội, Hợp đồng, Báo giá, Đơn bán, Yêu cầu mua, Đơn mua, Nhập kho, Xuất kho, Cảnh báo, Sản phẩm, Nhà cung cấp, Người dùng: gửi tìm kiếm/bộ lọc/trang tới máy chủ, hiển thị nút chuyển trang.
- Giao hàng, Hóa đơn, Thu tiền, Công nợ, Tồn kho, Bảo hành, Kiểm kê, Mẫu in, Sổ điểm KPI: bổ sung phân trang; các bộ lọc liên quan áp dụng trước khi lấy trang dữ liệu.
- Phê duyệt: bỏ cắt 50 hồ sơ theo loại và 12 quyết định trước khi tìm; phân trang riêng hàng chờ và lịch sử; số thống kê tính từ toàn bộ tập hồ sơ được phép xử lý.
- Nhật ký: tìm trên toàn bộ dữ liệu được quyền xem; các lựa chọn hành động/đối tượng không còn phụ thuộc trang đầu; lọc ngày theo giờ Việt Nam; phần trình tự thao tác chia 60 bước/trang và có thể xem các trang tiếp theo.
- Kho và Vai trò: nạp đầy đủ các trang danh mục rồi chia trang hiển thị; số tổng không lấy riêng trang đầu.
- Hàng chờ khách trùng: chuyển trang theo API hiện có.
- Ô chọn khách hàng, sản phẩm, chứng từ và mẫu in: dùng cơ chế nạp đủ các trang thay vì coi 100 dòng đầu là toàn bộ danh sách.
- Các thẻ thống kê tài chính, giao hàng và bảo hành lấy số toàn bộ tập dữ liệu, không đổi theo trang đang xem.
- Tăng phiên bản tài nguyên cho cả truy cập trực tiếp và PJAX để tránh trình duyệt dùng JavaScript cũ.

## Các giới hạn có mục đích vẫn giữ
- Số dòng trên mỗi trang là giới hạn kích thước phản hồi, không phải giới hạn tổng hồ sơ.
- Tổng quan, bảng xếp hạng và biểu đồ vẫn hiển thị các mục gần nhất/top theo nhãn; danh sách nghiệp vụ riêng có thể chuyển trang.
- Trình tự trong chi tiết một nhật ký là ngữ cảnh 15 phút của người thao tác; toàn bộ lịch sử các ngày khác nằm ở danh sách Nhật ký, có lọc ngày và phân trang.
- Không tự thay đổi quyền xem, quy tắc duyệt, giới hạn dung lượng Excel/ảnh, tốc độ yêu cầu hoặc giới hạn kích thước thao tác ghi dữ liệu hàng loạt.

## Kiểm thử
- Kiểm tra bản ghi nằm sau hơn 100 hồ sơ: Công việc từ lead, Cơ hội, Hợp đồng, Báo giá, Đơn bán, Nhà cung cấp, Sản phẩm.
- 125 nhật ký: tìm hồ sơ cũ, lựa chọn hành động không mất, trình tự có đủ các trang không chồng bước.
- 75 yêu cầu mua: tìm hồ sơ cũ trong hàng chờ và lịch sử phê duyệt.
- 105 hóa đơn/phiếu thu: tìm qua toàn bộ danh sách; tổng số tiền không phụ thuộc kích thước trang; lọc phương thức thu tiền.
- Kiểm tra phân quyền hiện có qua bộ kiểm thử hồi quy; chưa xác nhận giao diện trực quan trên máy khách và chưa đóng gói bản cập nhật mới.

Kết quả chạy: bộ PHP toàn hệ thống có 444 bài đạt, 4 bài bỏ qua theo điều kiện môi trường; bộ JavaScript có 243 bài đạt. Bộ CompletePaginationTest sau khi bổ sung kiểm tra tìm kiếm chứng từ/tồn kho có 5 bài đạt (71 assertions).
