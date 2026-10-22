# Tổng quan SPAdmin và báo cáo CSKH — thay đổi trong mã nguồn

## Tổng quan

Ranh giới tháng cho timestamp theo Asia/Ho_Chi_Minh, chuyển UTC để truy vấn >= đầu tháng và < đầu tháng sau. Ngày hóa đơn là date-only theo lịch, không lấy phần ngày UTC bị lùi một ngày. Công nợ quá hạn/tuổi nợ dựa trên ngày Việt Nam.

Lỗi tải lần đầu hiển thị không có số liệu xác nhận và nút Thử lại, không gom API lỗi thành số 0. Làm mới lỗi giữ dữ liệu cũ với cảnh báo. SPAdmin dùng tiêu đề Điều hành Chủ tịch, ẩn KPI cá nhân, giữ KPI nhân viên trong kỳ. Màn này vẫn dùng danh tính SPAdmin đã bảo vệ, không thêm khả năng gán quyền Chủ tịch qua role.

Thêm theo dõi chăm sóc hiện tại: việc hôm nay/quá hạn, khách chưa có việc tiếp theo, phản hồi đầu tiên/xử lý quá hạn (loại hồ sơ tạm dừng), chờ khách xác nhận. Resolved vẫn là hồ sơ chưa đóng, không bị mất khỏi tổng số chưa kết thúc.

Có biểu đồ doanh thu hóa đơn 10 tháng, tiền thu trong tháng, công nợ/tuổi nợ hiện tại, cảnh báo và hợp đồng. Chỉ tiêu theo tháng và hiện tại được ghi rõ; không gọi doanh thu hóa đơn là tiền đã thu. Các mục theo tháng mở báo cáo CSKH với ngày tương ứng; khách chưa có lịch mở báo cáo hiện tại không lọc lịch sử.

## Báo cáo mới và quyền

- care: lịch chăm sóc tạo trong khoảng ngày, trạng thái/hẹn/kết quả gần nhất và thời điểm ghi kết quả.
- service: phản hồi tạo trong khoảng ngày, loại vấn đề/ưu tiên, hai hạn SLA, các mốc hoàn tất, kết quả và xác nhận khách.
- care_gaps: khách hoạt động chưa gộp không có lịch chăm sóc mở, kèm trạng thái người phụ trách. Đây KHÔNG phải danh sách khách chưa từng liên hệ và không hỗ trợ lọc ngày lịch sử.

Giữ report envelope/phân trang 50 dòng, xuất toàn bộ tập lọc tối đa 10.000 dòng, sheet Tổng hợp/Chi tiết, ngày/giờ Excel typed theo Việt Nam, chuỗi không thực thi công thức, audit xuất và private no-store. Vượt giới hạn yêu cầu lọc nhỏ lại, chưa có export async.

Quyền xem care/care_gaps dùng master.view và scope khách; service dùng service.ticket.view và TicketAccess (người tạo/phụ trách hoặc company scope). Quyền xuất dùng quyền hiện hữu sales.report.export, nhãn UI đổi thành Xuất báo cáo bán hàng và chăm sóc khách hàng; chỉ có quyền xuất không bỏ qua quyền xem/scope. Chưa tách một permission xuất CSKH độc lập; nếu cần phân công người chỉ xuất bán hàng nhưng không xuất CSKH phải chốt/tách quyền trong bước tiếp theo.

Các trạng thái/SLA là ảnh chụp tại thời điểm xem/xuất của hồ sơ tạo trong kỳ, KHÔNG phải lịch sử cuối kỳ. Không suy ra tỷ lệ chuyển đổi hoặc tỷ lệ SLA cả kỳ từ snapshot này.

## Kiểm thử và giới hạn bàn giao

Có kiểm thử giao dịch sát đầu/cuối tháng, đối chiếu tổng quan với báo cáo đơn hàng; report scope, quyền xuất riêng, file XLSX thực, ngày typed; số khách chưa có lịch khớp tổng quan và biến mất khỏi nhóm sau khi tạo lịch; từ chối bộ lọc ngày lịch sử cho care_gaps. JavaScript kiểm tra lỗi tải không tạo số 0, giữ dữ liệu cũ, chống response cũ và dựng nội dung Chủ tịch theo tháng.

Không thêm migration. Chưa đóng gói sau 0.8.7. Chưa kiểm thử trực quan toàn màn hình/Excel desktop, chưa chạy nghiệp vụ mới trên SQL Server máy khách và chưa kiểm chứng scheduler ở máy khách. Không đánh dấu nghiệm thu thay người dùng.
