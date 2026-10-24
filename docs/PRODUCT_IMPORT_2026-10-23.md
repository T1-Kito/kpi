# Nhập danh mục sản phẩm, hãng và SKU

## Phạm vi

- Theo ba mẫu categories/brands/skus trong bộ 16 mẫu tham chiếu người dùng gửi. Danh mục/hãng chỉ thêm mới; SKU được cập nhật hồ sơ đã liên kết cùng nguồn.
- Mẫu nhân viên dùng tiếng Việt, có Danh sách/Hướng dẫn/Ví dụ, khung dòng, dropdown trạng thái. Bỏ hai cột phiên bản kỹ thuật khỏi mẫu hiển thị nhưng vẫn nhận mẫu kỹ thuật gốc. Mã ngoài tùy chọn khi thêm mới theo trải nghiệm đã thống nhất; không mã ngoài thì không tự liên kết để cập nhật sau.
- SKU giữ đủ 15 trường nghiệp vụ của mẫu gốc; cộng Mã ngoài là 16 cột hiển thị. Mã sản phẩm/mã hàng để trống được tự sinh. Danh mục/hãng chọn crm:Tên chính xác trong dropdown hoặc ext:Mã-ngoài cùng nguồn. Không tự tạo cha khi nhập SKU.
- Giá/tồn không âm; tồn tối đa phải lớn hơn hoặc bằng tồn tối thiểu. Không tạo tồn kho thực tế từ các ngưỡng. Mỗi dòng SKU đi qua SkuController, tạo một Product và một SKU theo luồng hiện hành.

## Kiểm soát

- Quyền master.view + product.manage và phạm vi company. SKU còn cần master.cost.view ở tải mẫu, xem trước, xác nhận và tải kết quả/lỗi theo nguồn; không làm lộ giá vốn qua nhập.
- Preview không ghi dữ liệu nghiệp vụ; commit đúng ID lô, kiểm tra lại quyền/dữ liệu, một giao dịch cho toàn lô. Sửa tay sau nhập hoặc thay đổi dữ liệu sau xem trước sẽ chặn, không tự ghi đè.
- Chống trùng tên danh mục/hãng, mã sản phẩm/mã hàng, Mã ngoài trong lô; SKU không mã hàng còn kiểm tra trùng tên+đơn vị. Không nhận hồ sơ đã có làm mapping chỉ vì trùng tên/mã.
- Giữ giới hạn đã kiểm định 300 dòng/1 MB/2 giờ; khác giới hạn 1.000 dòng/5 MB/30 phút trong tài liệu tham chiếu. Không khẳng định đáp ứng tải lớn của tài liệu.
- Dùng bảng liên kết/lô nhập đã có, không thêm migration trong đợt này. Chưa đóng gói và chưa nghiệm thu trình duyệt/Excel desktop.

## Kiểm thử

- Hồi quy cuối: 45 kiểm thử PHP/481 xác nhận và 14 kiểm thử JavaScript đạt. Đã kiểm tra hình render cả ba mẫu, hướng dẫn và ví dụ; chưa mở Microsoft Excel desktop hoặc chạy trình duyệt thực tế.

- Đã đạt 9 kiểm thử tích hợp sản phẩm: mới/replay, lỗi Việt/chặn toàn lô, cập nhật giữ trống/xung đột, cha thiếu/ngừng, rollback Product+SKU+mapping, mẫu tiếng Việt/dropdown, thu hồi quyền giá vốn, mẫu kỹ thuật gốc và crm:Tên, tự sinh mã.
- SQL tổng hợp riêng VKCRM_CustomerImport_20261023_080237_df18e0 đạt thêm danh mục/hãng, SKU không ghi lúc xem trước, liên kết cha, cập nhật giữ giá vốn khi trống và replay không nhân bản. Không dùng database khách.
- Các phần còn lại: nhà cung cấp, kho/vị trí, tổ chức/nhân sự và danh mục bán hàng. Không coi toàn bộ 16 mẫu đã hoàn tất.
