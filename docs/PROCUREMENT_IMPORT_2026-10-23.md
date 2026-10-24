# Nhập Nhà cung cấp, Kho và Vị trí kho

## Hợp đồng và giao diện

- Đã đối chiếu cả header và hướng dẫn suppliers/warehouses/locations trong ZIP 16 mẫu gốc.
- Nhà cung cấp: 11 cột kỹ thuật gốc, 9 cột hiển thị (Mã ngoài + 8 trường nghiệp vụ). Kho: 7 cột gốc, 5 cột hiển thị. Vị trí: 7 cột gốc, 5 cột hiển thị. Hai cột phiên bản được xử lý nội bộ; vẫn nhận mẫu gốc có phiên bản.
- Tên file tải, cột, hướng dẫn, ví dụ và thông báo lỗi tiếng Việt. Có dòng kẻ, dải nền, khóa hàng tiêu đề và dropdown Trạng thái. Mẫu Kho tải kèm email người quản lý đang hoạt động; mẫu Vị trí tải kèm mã/tên kho đang hoạt động cùng công ty.
- Nút Nhà cung cấp → Nhập file; Kho và vị trí → Nhập kho / Nhập vị trí kho. Sau nhập tải lại dữ liệu, không giữ cache danh sách cũ.
- Giới hạn hiện tại 300 dòng/1 MB/2 giờ; không khẳng định giới hạn 1.000 dòng/5 MB/30 phút của tài liệu tham chiếu.

## Luồng nghiệp vụ

- Nhà cung cấp và Kho được thêm mới/cập nhật chỉ khi Mã ngoài đã liên kết cùng nguồn. Ô trống giữ nguyên, không xóa; hồ sơ CRM sửa tay sau lần nhập báo xung đột. Không tự ghép dựa tên/điện thoại/MST/email.
- Mã ngoài không phải mã CRM. Nhà cung cấp và Kho luôn tự sinh SUP-/WH-. Mã vị trí để trống tự sinh LOC-, tự nhập thì kiểm tra trùng trong đúng kho.
- Vị trí chỉ thêm mới hoặc bỏ qua dòng không đổi; không cập nhật/di chuyển sang kho khác. Kho phải nhập trước, dùng crm:Mã-kho hoặc ext:Mã-ngoài cùng nguồn; chỉ kho đang hoạt động cùng công ty.
- Không tạo công nợ, thanh toán, tồn kho hoặc phân quyền qua Excel. Người quản lý phải đang hoạt động cùng công ty. Không tự tạo kho cha.
- Preview không ghi nghiệp vụ. Commit đúng ID lô bất biến, kiểm tra lại quyền, tham chiếu, xung đột và trùng. Một dòng lỗi chặn toàn bộ; lỗi ghi giữa chừng hoàn tác nghiệp vụ/mapping/trạng thái lô.
- Quyền master.view + supplier.manage hoặc warehouse.manage, phạm vi company. Không mở rộng quyền nhân viên hoặc dùng dữ liệu công ty khác.

## Kiểm chứng và phần còn lại

- Lượt hồi quy: 27 kiểm thử PHP/342 xác nhận (nhóm mới, mẫu, sản phẩm, lô nhập); lượt sau thêm nhãn tham chiếu: 36 kiểm thử/353 xác nhận (nhóm mới và ba nhóm khách hàng). Toàn bộ 183 kiểm thử JavaScript đạt; sau bổ sung bảng đối chiếu trong preview, 10 kiểm thử nhập/giao diện kết quả chạy lại đạt.

- Kiểm thử riêng: tạo/cập nhật/replay, giữ trống, lỗi Việt, khóa quản lý/kho sau preview, tham chiếu ngoại công ty, vị trí không chuyển kho, chống trùng đúng kho, rollback hai dòng giữa chừng, mẫu/dropdown, thu hồi quyền, tự sinh mã và mẫu kỹ thuật cũ.
- SQL Server riêng VKCRM_CustomerImport_20261023_085653_7f8a49 đạt hồi quy 9 danh mục: thêm/cập nhật NCC, giữ điện thoại khi trống, quản lý kho, giữ quản lý khi trống, vị trí đúng kho, tự sinh mã, replay an toàn và chặn cập nhật vị trí.
- Đã render kiểm tra cả ba trang trong mỗi mẫu. Chưa nghiệm thu Microsoft Excel desktop/trình duyệt thật và chưa đóng gói bản cập nhật. Không sửa database khách hoặc thêm migration.
- Có 9/16 danh mục có adapter chuẩn trong code. Còn 7: phòng ban, chức vụ, người dùng, giai đoạn bán hàng, nguồn cơ hội, bảng giá, điều khoản thanh toán. Không gọi toàn bộ phần import đã hoàn tất.
