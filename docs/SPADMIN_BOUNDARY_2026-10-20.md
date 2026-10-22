# Phân tầng Chủ tịch / SPAdmin và Admin doanh nghiệp

## Quy tắc

- Chủ tịch là danh tính hệ thống đặc biệt, nhận diện bằng role code cố định ROLE-SPADMIN thuộc đúng tenant. Không suy ra từ tên người dùng, chức vụ, tên vai trò hiển thị hoặc một quyền gán tùy ý.
- Vai trò này chỉ được khởi tạo bằng quy trình quản trị máy chủ, không có trong API chọn/tạo/gán vai trò. Quyền dashboard.chairman.view không được cấp qua API vai trò thông thường.
- User::hasPermission và payload login/me chỉ coi quyền tổng quan Chủ tịch có hiệu lực với danh tính SPAdmin, kể cả dữ liệu cũ lỡ gán quyền này cho vai trò Admin.
- Admin vẫn quản lý người dùng/vai trò thường trong cùng doanh nghiệp theo quyền được cấp, không được cấp/gỡ role SPAdmin, chỉnh tổ chức, khóa hoặc bàn giao tài khoản Chủ tịch. Tài khoản và role hệ thống bị loại khỏi danh sách quản trị thông thường. Không tạo API reset mật khẩu SPAdmin.
- Tổng quan Chủ tịch đã có trong dashboard.js; chỉ SPAdmin nhận quyền để kích hoạt giao diện đó. Admin/Ban giám đốc có tổng quan theo quyền thông thường, không trở thành Chủ tịch.
- Các quyền nghiệp vụ thường vẫn là RBAC + data scope; không dùng kiểm tra tên đăng nhập để bỏ qua quyền API hoặc tenant.

## Khởi tạo và đăng nhập

Đăng nhập admin ánh xạ tới tài khoản duy nhất có ROLE-SPADMIN. Nếu không có hoặc nhiều hơn một tài khoản thì từ chối thay vì đoán tenant. Email cũ của tài khoản vẫn đăng nhập được. Không dùng username admin để tìm tài khoản Admin thường.

Đã xác nhận database local workspace SQLite, có một SPAdmin sẵn nên không tạo tài khoản thứ hai. Sao lưu trước rồi đặt lại mật khẩu tài khoản đó theo yêu cầu người dùng; không ghi mật khẩu vào tài liệu. Kiểm tra HTTP kernel đăng nhập admin trả 200, is_spadmin=true và quyền tổng quan Chủ tịch.

Lệnh crm:provision-chairman dùng cho doanh nghiệp đã có nhưng chưa có tài khoản Chủ tịch: chỉ operator chạy CLI, xác nhận database/tenant, nhập mật khẩu bằng secret. Không ghi đè tài khoản cũ, không tự chạy khi cập nhật khách. InstallAdmin vẫn chỉ khởi tạo bản cài hoàn toàn mới.

## Bằng chứng / giới hạn

Kiểm thử chặn Admin tự nâng cấp, tạo người dùng kèm reserved role, tạo role kèm quyền Chủ tịch, sửa role hệ thống, khóa/thay vai trò/tổ chức/bàn giao Chủ tịch. Có kiểm tra quyền Chủ tịch cũ trên role thường không xuất hiện trong login payload.

Chưa UAT giao diện tổng quan bằng trình duyệt hoặc cập nhật máy khách cho thay đổi này. Không coi các kiểm thử này là chứng nhận mọi quyền nghiệp vụ đều đúng; cần rà thêm theo từng vai trò/chứng từ. Chưa đóng gói sau 0.8.7. Không đồng bộ mật khẩu local vào máy khách hay nhúng mật khẩu chung trong bộ cài.
