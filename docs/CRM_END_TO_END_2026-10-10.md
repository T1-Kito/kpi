# Rà soát luồng ngày 10/10/2026 — LOCAL, chưa triển khai

## Đã thực hiện trong lượt này

- Mẫu Word: chọn hồ sơ được API cho phép xem, trộn thử mẫu đã lưu qua API xuất chứng từ hiện hành. Báo giá đã phát hành trả bản lưu, không tạo lại bản lịch sử theo mẫu mới.
- Kiểm tra cấu trúc DOCX và trường không hỗ trợ khi tải lên. Kiểm tra kích thước giải nén, từ chối macro và khai báo thực thể XML.
- Trộn trường bị Word tách thành nhiều đoạn; xử lý đầu/chân trang và lặp dòng hàng. Dừng xuất và xóa file tạo dở nếu còn trường chưa được thay.
- Service kiểm tra công ty, loại chứng từ và trạng thái mẫu. File mẫu mới lưu riêng; API tải mẫu gốc yêu cầu quyền quản lý và kiểm tra công ty.
- Giữ hỗ trợ file mẫu cũ ở public; CHƯA di chuyển hoặc xóa file cũ. Đây vẫn là phần cần xử lý trước triển khai.
- Bổ sung trường đại diện pháp luật/chức vụ cho báo giá/hợp đồng, điều khoản và hết hiệu lực báo giá; bổ sung mã đơn bán/giao hàng cho đơn bán.
- Kiểm thử dùng chung hoàn tất báo giá đã thay giả lập Word bằng tài liệu DOCX có cấu trúc thật. Các luồng bán hàng và mua/kho sử dụng helper này được kiểm tra lại.

## Bằng chứng kiểm thử

- Bộ PHP: 137 bài, 1.035 assertions đạt sau bổ sung trường và lưu mẫu riêng.
- Bộ JavaScript: 110 bài đạt. Chưa kiểm tra bố cục bằng trình duyệt trong lượt này.
- Các kiểm thử tích hợp bao gồm duyệt theo lượt, chống chuyển đơn trùng, đặt giữ hàng/thiếu hàng, nhập/xuất kho và thu tiền lặp cùng tham chiếu.
- Những kết quả này là kiểm thử phía ứng dụng, không phải xác nhận đã UAT toàn bộ giao diện hay thử đồng thời trên MySQL Railway.

## Chưa được coi là hoàn tất doanh nghiệp

1. Các Web controller phê duyệt, kiểm kê, báo giá nhà cung cấp và dịch vụ còn lấy công ty đầu tiên trước xác thực. Phải chuyển sang tải dữ liệu đã xác thực và kiểm thử phạm vi quyền; đây là vấn đề cần chặn trước triển khai.
2. Chốt nguồn doanh thu KPI: hóa đơn phát hành, đơn xác nhận hay thực thu. Không tự thay chính sách điểm/thưởng khi chưa được chọn.
3. KPI tổng quan hiện lũy kế, không thay thế tính điểm theo kỳ/mục tiêu/trọng số đã cấu hình. Cần đối chiếu kỳ, khóa kỳ và nhật ký điều chỉnh.
4. SLA nâng cao: lịch làm việc, chuyển cấp, P1/P2, CSAT và cấu hình worker/queue Railway chưa được xác nhận vận hành.
5. Mẫu: chưa có lịch sử phiên bản mẫu độc lập; chưa có cấu hình bắt buộc dữ liệu theo từng mẫu; chưa đủ dữ liệu doanh nghiệp phát hành để tự điền mọi trường của bên bán. Không được nhầm `ten_cong_ty` hiện hành (khách hàng) thành bên bán.
6. UAT màn hình, Word nhiều trang, font/ảnh, phân trang/PDF và thử cạnh tranh trên MySQL còn phải thực hiện.

Không đẩy Git/Railway, không seed lại dữ liệu thật, không tự duyệt chứng từ hoặc gửi khách trong lượt này.
# Bổ sung 11/10/2026

- Trang kiểm kê kho đã chuyển thành shell không nhúng dữ liệu doanh nghiệp trước xác thực. Danh sách, kho và mã hàng tải qua API đúng tenant/quyền.
- Tạo phiếu và xác nhận kiểm kê cập nhật danh sách tại chỗ, không còn `location.reload()`.
- Chi tiết phiếu được lấy lại từ API khi mở, tránh dùng bản dữ liệu cũ trên giao diện.

