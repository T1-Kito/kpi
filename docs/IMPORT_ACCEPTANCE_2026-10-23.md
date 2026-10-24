# Kiểm tra nhóm khách hàng và kế hoạch nhập liệu

## Tiến độ mới nhất: Chức vụ, Phòng ban, Người dùng

Đã đối chiếu ZIP gốc và bổ sung ba adapter, mẫu Việt hóa, danh sách chọn và bước kích hoạt tài khoản ngoài Excel. Chi tiết `ORGANIZATION_IMPORT_2026-10-23.md`. Hiện 12/16 adapter trong mã nguồn; còn Giai đoạn bán hàng, Nguồn cơ hội, Bảng giá, Điều khoản thanh toán. Các mốc 9/16 và 6/16 phía dưới là lịch sử. Chưa nghiệm thu thao tác trực tiếp toàn bộ và chưa đóng gói.

## Tiến độ mới nhất: Nhà cung cấp, Kho, Vị trí kho

Đã triển khai thêm ba danh mục (chi tiết `PROCUREMENT_IMPORT_2026-10-23.md`): hiện 9/16 adapter chuẩn trong mã nguồn, còn 7 danh mục. Các số 6/16 hoặc 13 mục phía dưới là mốc lịch sử. Chưa nghiệm thu toàn bộ giao diện/Excel và chưa đóng gói.

## Tiến độ bổ sung sản phẩm

Đã triển khai thêm Danh mục sản phẩm, Hãng và SKU (chi tiết `PRODUCT_IMPORT_2026-10-23.md`). Như vậy có 6/16 danh mục có adapter chuẩn trong mã nguồn; 10 danh mục còn lại chưa hoàn tất. Các danh sách 13 mục và kế hoạch bên dưới là hiện trạng trước đợt bổ sung, không phải kết luận mới nhất. Chưa nghiệm thu toàn bộ và chưa đóng gói.

## Phần đã xử lý trong mã nguồn

| Danh mục | Luồng hiện tại |
| --- | --- |
| Khách hàng | Tạo/cập nhật đúng liên kết nguồn, kiểm tra trùng, giữ ô trống khi cập nhật, không tự ghép hồ sơ; phụ trách, hóa đơn, hạn mức. |
| Người liên hệ | Thuộc đúng khách doanh nghiệp, có Liên hệ chính, cập nhật không chuyển khách; nút nhập dùng contact-preview và xác nhận ID lô. |
| Khách tiềm năng | Có người phụ trách và khách liên kết tùy chọn; chỉ thêm mới, chống lặp, tạo công việc; nút nhập dùng lead-preview và xác nhận ID lô. |

Mã ngoài không bắt buộc khi thêm mới cả ba danh mục. Phiên bản kỹ thuật không bắt nhân viên điền trong mẫu mới. Các mẫu kỹ thuật cũ vẫn được đọc.

## Bổ sung lần rà này

- Danh sách người liên hệ và khách tiềm năng dùng cùng kiểu avatar, tên nổi bật, màu mã trung tính và bảng cuộn riêng; phân trang ở đáy vùng danh sách. Giữ bảng thống kê/phân công/quá hạn của khách tiềm năng.
- Sửa nhấn Sửa khách hàng không chạy tiếp sang hành động mở chi tiết của dòng. Vô hiệu hóa phân trang không gửi yêu cầu. Gõ tìm kiếm hủy hiệu lực phản hồi cũ trước khi debounce.
- Mẫu tải qua API được sao chép tạm theo công ty: xổ xuống email người phụ trách đang hoạt động và mã khách doanh nghiệp đang hoạt động/chưa gộp. Có trang Danh mục tham chiếu để đối chiếu mã với tên; không sửa mẫu dùng chung hoặc database khi tải.
- Tên, điện thoại, email liên hệ và địa chỉ nhập tự do; không biến mọi ô thành danh sách lựa chọn. Danh sách tham chiếu là ảnh chụp lúc tải, server kiểm tra lại khi xác nhận. Khóa người phụ trách hoặc thay dữ liệu sau xem trước sẽ chặn lô.
- Không đưa khách/người dùng công ty khác vào mẫu. Giới hạn 10.000 dòng danh mục tham chiếu, 300 dòng nhập/1 MB vẫn giữ nguyên.

## Phần chưa được nghiệm thu

Kiểm thử tự động lần rà: 44 kiểm thử PHP/400 xác nhận đạt; 27 kiểm thử JavaScript đạt trước khi bổ sung riêng ca hồi quy nhấn Sửa. Phạm vi gồm nhập/xác nhận/rollback, tải mẫu có tham chiếu, tìm kiếm/phân trang/phạm vi, chăm sóc, liên hệ chính và phân công khách tiềm năng.

- Chưa kiểm tra bằng trình duyệt thật và Microsoft Excel desktop; kiểm thử mã không thay thế nghiệm thu trực quan hay mở file trong Excel.
- Chưa đóng gói cập nhật và chưa bổ sung allowlist migration cho record links/phân loại vào updater.
- 13 danh mục còn lại trong bộ 16 mẫu chưa có adapter nhập chuẩn: nhóm sản phẩm, hãng, SKU, nhà cung cấp, kho, vị trí kho, phòng ban, chức vụ, người dùng, giai đoạn bán hàng, nguồn cơ hội, bảng giá, điều khoản thanh toán.

## Thứ tự tiếp tục

1. Nhóm sản phẩm và hãng trước SKU, sau đó nhà cung cấp.
2. Kho trước vị trí kho.
3. Chức vụ/phòng ban trước người dùng; không mật khẩu hoặc vai trò trong Excel, người dùng nhập mới chưa kích hoạt.
4. Nguồn/giai đoạn bán hàng, bảng giá, điều khoản thanh toán.
5. Mỗi nhóm cần xem trước, xác nhận ID lô, quyền/phạm vi, chống trùng, rollback, thông báo Việt và mẫu đồng bộ; sau đó SQL Server, kiểm tra UI, thử cập nhật bản cài.
