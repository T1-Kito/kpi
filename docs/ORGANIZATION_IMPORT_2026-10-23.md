# Nhập Chức vụ, Phòng ban và Người dùng

## Đối chiếu tài liệu gốc

Đã đọc trực tiếp ba mẫu `positions`, `departments`, `users` trong ZIP người dùng gửi ngày 23/10/2026. Cấu trúc nghiệp vụ giữ nguyên: chức vụ/phòng ban được cập nhật theo liên kết nguồn; người dùng chỉ thêm mới. Mẫu cũ với schema_version/external_id/source_revision vẫn đọc được. Mẫu mới hiển thị tiếng Việt, ẩn hai phiên bản kỹ thuật; mã ngoài tùy chọn khi thêm mới theo thống nhất trước đó. Giới hạn hiện hành của ứng dụng vẫn là 300 dòng/1 MB, không phải 1.000 dòng/5 MB ghi trong hướng dẫn gốc.

## Thao tác trong phần mềm

1. Quản trị → Phòng ban và chức vụ → Nhập chức vụ, Nhập phòng ban.
2. Nhập đơn vị cha trước đơn vị con. Mã tham chiếu là crm:mã quản lý hoặc ext:mã ngoài đã nhập cùng nguồn. Không tự tạo hồ sơ được tham chiếu.
3. Quản trị → Người dùng → Nhập người dùng. Chọn phòng ban, chức vụ, người quản lý trong mẫu tải từ ứng dụng.
4. Xem trước và xác nhận đúng ID lô đã kiểm tra. Một dòng lỗi không ghi toàn bộ lô.
5. Người dùng mới chưa kích hoạt/chưa vai trò, mật khẩu ngẫu nhiên không xuất ra. Quản trị cấp vai trò thông thường, sau đó bấm Kích hoạt để đặt mật khẩu riêng (8–128 ký tự, nhập lại xác nhận). Không có mật khẩu hay quyền trong Excel, không tự gửi mật khẩu cho nhân viên.

## Quy tắc và quyền

- Mã ngoài không thay thế mã CRM. Chức vụ sinh POS-, phòng ban sinh DEP-; đăng nhập người dùng bằng email.
- Chức vụ/phòng ban chỉ cập nhật bản ghi đã liên kết đúng nguồn, ô trống giữ nguyên. Sửa tay hoặc thay dữ liệu sau xem trước sẽ chặn xác nhận.
- Người dùng chỉ tạo mới; đổi nội dung dòng đã liên kết bị chặn. Email trùng (kể cả ngoài công ty) chỉ báo trùng, không lộ tên/tổ chức khác.
- Toàn bộ đồ thị phòng ban sau lô được kiểm tra vòng lặp, không chỉ từng dòng. Cha đã lỗi vòng lặp cũng không được dùng cho phòng ban mới.
- Tham chiếu người quản lý cần đang hoạt động, cùng công ty, không dùng tài khoản Chủ tịch. Tham chiếu tổ chức không vượt công ty/nguồn.
- Cần master.view, user.manage và phạm vi toàn công ty khi nhập/tải mẫu. Cấp vai trò vẫn cần role.manage. Kích hoạt cần user.manage và phạm vi công ty, chặn Chủ tịch, không cho kích hoạt lại qua chức năng này.
- Mọi ghi dữ liệu, mapping và nhật ký nằm trong giao dịch lô. Thu hồi quyền sau xem trước chặn xác nhận.
- Theo dõi chờ kích hoạt bằng nhật ký import_inactive_user/activate_imported_user có sẵn, không thêm cột/bảng. Không ghi mật khẩu vào response hay audit. Đường Mở tài khoản thường không được bỏ qua bước thiết lập mật khẩu của tài khoản nhập.

## Kiểm chứng

- 25 kiểm thử PHP/323 assertions: tổ chức (7), procurement (9), template options (1), batch (8).
- Sau bổ sung kiểm tra cha đã vòng lặp và rollback: 44 kiểm thử PHP/482 assertions gồm tổ chức (8), khách hàng (9), liên hệ (10), lead (8), sản phẩm (9).
- Toàn bộ 184 kiểm thử JavaScript đã qua, gồm luồng nhập tổ chức mới.
- 23 kiểm thử PHP/232 assertions bổ sung về phân quyền hành động, quản lý vai trò người dùng, tổ chức/KPI và quyền theo request đã qua (có 8 bài tổ chức trùng với nhóm trên; không cộng thành số bài độc lập).
- SQL Server thử nghiệm tách biệt: VKCRM_CustomerImport_20261023_091640_38863d. Tạo/cập nhật chức vụ/phòng ban, giữ ô trống, tạo người dùng chưa kích hoạt/chưa vai trò, liên kết đúng tổ chức, replay và chặn cập nhật người dùng đã qua. Không sửa database vận hành.
- Ba mẫu được dựng/render kiểm tra cả Danh sách, Hướng dẫn, Ví dụ. Danh sách chọn thực tế được gắn theo công ty tại lúc tải API; đã kiểm tra XML vùng validation.
- Chưa kiểm tra thao tác trực tiếp bằng Excel desktop hoặc trình duyệt; chưa chứng nhận tải đồng thời và chưa đóng gói bộ cài.

Hiện 12/16 adapter trong mã nguồn. Còn Giai đoạn bán hàng, Nguồn cơ hội, Bảng giá, Điều khoản thanh toán. Đây là tiến độ triển khai, không phải nghiệm thu toàn bộ.
