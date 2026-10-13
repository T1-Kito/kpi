# Quyền xuất báo cáo và duyệt báo giá bán

## Quyền xuất Excel

Quyền xem và quyền xuất độc lập; xuất vẫn phải đạt quyền xem và phạm vi dữ liệu của báo cáo.

| Báo cáo | Quyền xuất |
|---|---|
| Đơn bán | sales.report.export |
| Hóa đơn, công nợ | finance.report.export |
| Đơn mua | procurement.report.export |
| Tồn kho, giao dịch kho | inventory.report.export |
| KPI nhân viên | kpi.report.export |

Migration thêm quyền vào danh mục và vai trò quản trị hệ thống mặc định. Các vai trò khác không tự nhận quyền xuất theo quyền xem. Admin cấp trong Vai trò & phân quyền theo phạm vi đã thống nhất. Không tự cấp quyền cho tài khoản có tên Chủ tịch; quyền dashboard và quyền xuất độc lập.

API trả can_export để màn hình khóa nút đúng trạng thái. Gọi trực tiếp API không có quyền bị chặn. Nhật ký export_business_report lưu người, bộ lọc, phạm vi, số dòng và cột; export_kpi_report lưu kỳ, phạm vi và số nhân viên/chỉ tiêu/công việc/phiếu điểm. Không ghi toàn bộ nội dung báo cáo vào audit. Nếu không ghi được audit, file không được trả và tệp tạm được dọn.

File tiếp tục dùng download có xác thực, Cache-Control private/no-store, không có URL public hoặc đường dẫn tệp trả trong JSON. Export lớn hiện vẫn giới hạn 10.000 dòng; xuất async/link hết hạn là phần chưa triển khai, không tự đánh dấu đạt toàn bộ tiêu chí tài liệu.

## Duyệt báo giá bán

Thiết lập → Duyệt báo giá bán có các cấp tuần tự (tối đa 10 cấp chính và cấp ngoại lệ), cấu hình người lập tự duyệt và danh sách người thay.

- Cho phép tự duyệt: vẫn cần được phân công và đúng lượt.
- Chặn tự duyệt: người lập không được duyệt, kể cả được đăng ký làm người thay; một người không quyết định nhiều cấp trong cùng luồng khi bật chính sách này.
- Cấu hình tự duyệt và các cấp được lưu trong snapshot lúc submit. Thay cấu hình không làm thay chính sách báo giá đã gửi.
- Nếu chỉ có người lập ở một cấp và không có người độc lập hợp lệ, submit bị chặn và báo giá vẫn nháp.

Người thay cho báo giá bán được quản trị bởi tài khoản có user.manage và role.manage, gồm người chính, người thay, bắt đầu, kết thúc và lý do. Cùng người chính không có thời hạn chồng nhau. Người thay thuộc cùng tenant, đang hoạt động và có sales.margin.approve. Các thời điểm lưu UTC; input hiển thị theo múi giờ máy người dùng.

Ủy quyền có hiệu lực ngay cho các bước hiện tại hoặc phát sinh trong thời hạn; không ghi đè tên người chính. Người chính vẫn có thể xử lý nếu hợp lệ. Không truyền quyền qua chuỗi người thay. Hết hạn hoặc mất quyền thì backend ngừng cho duyệt và ngừng mở thêm hồ sơ từ quyền thay. Dữ liệu đã hiển thị trước đó không thể bị thu hồi khỏi trình duyệt, nhưng API kiểm tra lại từng lần.

Hàng đợi, danh sách, chi tiết và API quyết định dùng cùng kiểm tra. Người thay không vượt cấp, không xử lý bước đã quyết định. Lịch sử bước và audit lưu người thực sự quyết định, người chính, trạng thái thay, thời điểm và lý do quyết định. Danh sách cấp gốc giữ nguyên.

Phần ủy quyền trong đợt này áp dụng cho báo giá bán. Đơn mua, báo giá NCC và phiếu nhập giữ cơ chế đúng người/đúng lượt đã triển khai; chưa có ủy quyền có thời hạn cho các chứng từ đó.

## Kiểm thử

- ReportExportPermissionTest: có quyền xem nhưng không xuất; thêm quyền xuất vẫn không vượt scope; mất quyền xem thì xuất bị chặn; audit chỉ tạo khi xuất thành công.
- SalesApprovalPolicyTest: người thay đúng hạn; trước/sau hết hạn; không vượt cấp; mất quyền; không tự duyệt; cấu hình snapshot; thao tác lặp; cấu hình sai và thiếu quyền quản trị.
- Các test quy trình bán hàng, duyệt nhiều cấp, kho và báo cáo được chạy regression. Không dùng test tự động để thay cho UAT có người nghiệm thu và bằng chứng theo checklist nguồn.
