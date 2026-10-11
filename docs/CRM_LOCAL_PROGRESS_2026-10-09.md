# Hoàn thiện CRM trên local — 09/10/2026

## Đã triển khai và kiểm thử trong đợt này

- Đơn mua mẫu `PO-DEMO-APPROVAL` có kho nhận hàng trong seeder; bản ghi local thiếu kho được bổ sung đúng kho `WH-HCM`, không tự duyệt và không chạy lại toàn bộ seeder.
- Nút duyệt đơn mua chặn bấm lặp khi đang xử lý, thông báo lỗi từ máy chủ và khôi phục nút sau khi lỗi.
- Tổng quan KPI chỉ tải API tổng quan và lịch sử; hiển thị tổng quan trước, không chờ lịch sử. Lỗi tổng quan hiển thị lỗi/thử lại, không giả lập điểm 0.
- KPI tổng hợp trực tiếp trong cơ sở dữ liệu, không tải toàn bộ chứng từ và dòng hàng vào bộ nhớ. Giữ công thức hiện hành, thêm số dòng tồn vào dữ liệu tổng hợp.
- Bỏ so sánh tăng/giảm với các mốc cố định không có dữ liệu kỳ trước. Đổi nhãn tỷ lệ hoàn thành, không gọi tỷ lệ hoàn thành công việc là tỷ lệ đạt SLA.
- Biểu tượng các nhóm KPI dùng SVG thống nhất; mã nghiệp vụ giữ màu hồng, số tiền giữ màu đen.
- Thay đổi trạng thái công việc được khóa bản ghi và thao tác lặp cùng trạng thái không tạo thêm lịch sử/sự kiện.
- Hoàn thành hoặc hủy việc tự giải quyết cảnh báo quá hạn và đọc thông báo quá hạn liên quan.
- Hoàn thành/hủy việc chăm sóc từ màn công việc cũng đồng bộ lịch chăm sóc gần nhất của cơ hội.
- Chốt thắng/mất cơ hội dừng các việc `deal_follow_up` đang chờ của riêng cơ hội đó; không dừng việc chứng từ hay việc của cơ hội khác. Không cho mở lại việc chăm sóc của cơ hội đã đóng.

## Chưa xác nhận hoàn tất toàn bộ CRM theo tài liệu nghiệp vụ

- KPI hiện vẫn là điểm vận hành lũy kế toàn công ty, không phải kết quả theo kỳ/nhân viên để tính thưởng. Chưa thay chính sách doanh thu, trọng số hay ngày ghi nhận bằng giả định.
- Cần xác định ngày ghi nhận và trạng thái hợp lệ cho từng chỉ tiêu, triển khai lọc kỳ/nhân viên, nguồn truy vết và đối soát trước khi dùng trả thưởng.
- Chưa hoàn thiện phân lead theo nguồn/địa bàn/nhóm khách, primary/backup, các pipeline riêng và điều kiện vào/ra theo bộ tài liệu.
- SLA nhắc quá hạn đã có tiến trình tùy chọn; không tự bật worker Railway. Chưa hoàn thiện escalation, lịch làm việc, P1/P2 và CSAT.
- Chưa hoàn thiện consent, chiến dịch marketing và tích hợp booking/POS/payment theo bộ tài liệu.
- Kiểm thử mã nguồn không thay thế UAT và kiểm tra giao diện bằng trình duyệt với từng vai trò.

## Triển khai

## Đợt siết cấu hình và phân quyền tiếp theo

- Gán vai trò khi tạo tài khoản hoặc qua API gán vai trò cần thêm quyền `role.manage`; riêng `user.manage` không tự cấp quyền quản trị.
- Không cấp mật khẩu chung mặc định cho tài khoản mới; API bắt buộc mật khẩu riêng.
- Danh sách và tổng hợp sổ điểm KPI áp dụng phạm vi cá nhân/phòng ban/toàn công ty. Phòng ban chưa gán chỉ xem bản thân; bộ lọc ID người khác không vượt phạm vi.
- Trang sổ điểm và trang Thiết lập KPI chỉ trả khung HTML, không nhúng dữ liệu của công ty đầu tiên khi chưa đăng nhập.
- API đọc danh mục chỉ tiêu và mục tiêu KPI yêu cầu quyền `kpi.lock` giống nhóm quản lý KPI hiện hành. Giao diện không hiển thị công cụ tạo/sửa/khóa cho người thiếu quyền.
- Kiểm thử cấu hình luồng duyệt từ chối người khác công ty, bị khóa, thiếu quyền và người trùng trong một luồng; yêu cầu không hợp lệ không làm thay đổi cấu hình đã lưu.
- Kiểm thử token cũ không truy cập được sau khi tài khoản bị khóa.

Chưa phải xác nhận toàn bộ ma trận quyền đã hoàn tất: còn phải rà các màn dựng dữ liệu phía máy chủ khác (duyệt tập trung, kiểm kê, báo giá NCC, hỗ trợ khách hàng), tách chi tiết quyền xem/sửa/duyệt cấu hình và UAT từng vai trò. Không tự sửa quyền tài khoản vận hành.

Chỉ local. Không commit/push, không đổi quyền tài khoản, không tự duyệt tài chính/kho, không sửa dữ liệu Railway. Các thay đổi local từ những yêu cầu giao diện trước vẫn được giữ nguyên.
