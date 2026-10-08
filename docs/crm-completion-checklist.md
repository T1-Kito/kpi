# Kiểm tra hoàn thiện CRM doanh nghiệp

Không dùng việc có giao diện làm bằng chứng nghiệp vụ đã hoàn tất. Mỗi phần cần kiểm thử thành công, bị từ chối, thao tác lặp và cách ly công ty/phạm vi dữ liệu.

## Thứ tự triển khai

1. Phân quyền: quyền chức năng và phạm vi dữ liệu áp dụng cả API; quyền xem không tự cấp quyền sửa/duyệt. Không đổi quyền tài khoản thật khi chưa xác nhận người nhận.
2. Khách hàng: khách hàng, liên hệ và lead dùng liên kết chuẩn; chuyển đổi giữ lịch sử, tránh bản sao và giữ contact chính đồng bộ.
3. Báo giá bán/NCC: tách nghiệp vụ; nội dung, tệp, phiên bản, quy trình duyệt và kế thừa sang đơn hàng.
4. Yêu cầu mua: duyệt, lấy báo giá, lựa chọn được duyệt, đơn mua, nhập kho; không tạo trùng khi gửi lại.
5. Công việc: giao đúng người, hạn, thông báo, nhắc và leo thang chạy thật; không tự xác nhận chứng từ tài chính/kho.
6. KPI: công thức, dữ liệu nguồn, kỳ và mục tiêu, điều chỉnh được duyệt, khóa kỳ; chưa kiểm chứng thì không dùng chốt thưởng.
7. Chủ tịch: tài khoản riêng, quyền xem toàn công ty và duyệt theo ủy quyền; các chỉ số đúng kỳ, drill-down, nguồn dữ liệu và thời điểm cập nhật. Không đưa quản trị hệ thống vào quyền Chủ tịch mặc định.

## Đã kiểm tra/sửa ở đợt phân quyền hiện tại

- API cập nhật trạng thái công việc áp dụng cùng phạm vi dữ liệu với API xem chi tiết.
- Tài khoản phạm vi phòng ban chưa gán phòng ban chỉ dùng quan hệ sở hữu cá nhân; không coi mọi bản ghi chưa có phòng ban là cùng nhóm.
- Hàng đợi xuất kho không dùng loại việc để mở toàn bộ việc đã giao cho phòng ban khác; việc xuất kho chưa phân công vẫn nằm trong hàng đợi hiện hành.
- Kiểm thử nhân viên không thể thay trạng thái việc của Admin qua ID và xác nhận trạng thái không bị thay đổi.
- Kiểm thử dữ liệu khách hàng/việc khi thiếu phòng ban và hàng đợi xuất kho.

## Phần cần kiểm chứng tiếp

- Gắn hàng đợi việc kho chưa phân công với đúng kho/chứng từ nguồn, thay vì hàng đợi chung.
- Ma trận từng vai trò thực tế: Chủ tịch, quản lý, bán hàng, mua hàng, kho, kế toán; các tài khoản thử không thay tài khoản vận hành.
- Kiểm thử xuyên suốt báo giá → đơn bán → thiếu hàng → yêu cầu mua → báo giá NCC → đơn mua → nhập/xuất kho → thu tiền.
- Bộ lịch trên máy vận hành và thực thi lại sự kiện lỗi, không chỉ thay nhãn xử lý.
- Kiểm tra giao diện ở tài khoản Chủ tịch và nhiều kích thước màn hình.

Đây là checklist triển khai, không phải xác nhận toàn hệ thống đã đạt chuẩn hoặc đã kiểm tra giao diện thực tế.
