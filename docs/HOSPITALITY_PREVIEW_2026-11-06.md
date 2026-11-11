# Bản thử Resort và FaceID

Mở `/integrations/hospitality`, đăng nhập tài khoản có quyền `user.manage`.

## Dùng thử

Trang mặc định hiển thị dữ liệu nguồn khi đã cấu hình kết nối. Khi chưa cấu
hình, trang hiển thị dữ liệu mẫu ghi rõ DEMO. Nút **Thử đồng bộ mẫu** lưu
dữ liệu minh họa riêng với dữ liệu thật và hồ sơ khách hàng CRM.

## Đọc sandbox

Kỹ thuật cấu hình trên máy chủ, không nhập mật khẩu vào trình duyệt:

```dotenv
HOSPITALITY_BASE_URL=https://sandbox-do-nha-cung-cap-cap.example
HOSPITALITY_USERNAME=
HOSPITALITY_PASSWORD=
HOSPITALITY_TENANT_ID=
```

BASE_URL không kèm `/api/v1`. TENANT_ID phải đúng công ty được phép truy cập nguồn này.
Chạy `php artisan config:clear` sau khi chỉnh cấu hình. Không commit thông tin thật.
Adapter giữ cookie bằng CookieJar trong bộ nhớ của từng lần đọc; lấy CSRF, đăng nhập,
lấy lại CSRF, GET nghiệp vụ và đăng xuất. Không tự retry POST login/lệnh ghi.
HTTPS giữ kiểm chứng chứng chỉ; không theo redirect sang host khác.

Đọc phòng, khách, lượt lưu trú, thiết bị và lệnh. Khách/lưu trú/lệnh có phân trang nguồn.
Lọc tên/PIN chỉ áp dụng khách; ngày chỉ áp dụng lưu trú. Phòng/thiết bị theo danh sách
nguồn chưa có hợp đồng phân trang, cần xác nhận khi dữ liệu thực lớn.
Chỉ trả các trường cho phép hiển thị, không trả ảnh/CCCD/payload lệnh thô/credential.

## Tra cứu từ hồ sơ CRM

Mở chi tiết khách hàng và chọn **Lưu trú & FaceID**. Sau khi cấu hình kết nối cho
công ty, quản trị viên tìm khách theo tên hoặc PIN và xác nhận liên kết. Một hồ sơ
CRM có thể liên kết nhiều khách FaceID. Mỗi ID khách ở cùng nguồn chỉ thuộc một
hồ sơ CRM. Nhân viên cần quyền xem khách để đọc quyền thiết bị và nhật ký quét.
Ticket đã gắn khách dùng cùng màn hình tra cứu.

Mục tra lưu trú dùng ID lượt và ngày thuộc lượt ở, rồi xác minh danh sách khách
của lượt trước khi trả thông tin phòng và đoàn. API trong tài liệu chưa mô tả bộ
lọc lượt lưu trú theo khách. Nhật ký quét tải theo thiết bị trong 7 ngày gần nhất.
Cờ `isSent` hiển thị là nguồn báo đã gửi. Cần xác nhận bảng mã kết quả và cách nối
lệnh với quyền tương ứng trước khi hiển thị thiết bị đã xác nhận.

## Đồng bộ danh sách FaceID

Trong **FaceID → Tổng quan kết nối**, chọn **Thử đồng bộ mẫu** để lưu một bộ dữ
liệu thử riêng theo công ty. Xem lại trong từng danh sách ở chế độ **Dữ liệu mẫu**.
Chạy lại sẽ cập nhật theo ID nguồn. Sau khi cấu hình nguồn, chọn **Đồng bộ ngay**
hoặc chạy `php artisan faceid:sync` để kéo dữ liệu thật.

Lịch `faceid:sync` chạy mỗi 15 phút khi máy chủ chạy Laravel Scheduler. Trên Windows,
dùng cấu hình chạy `php artisan schedule:run` mỗi phút theo hướng dẫn triển khai.
Phòng, thiết bị và khách được đọc theo danh sách. Lượt lưu trú được đọc lại từ
30 ngày trước đến hết 30 ngày sau ngày hiện tại (giờ Việt Nam). Dữ liệu cũ đã lưu
vẫn tra được; bản ghi vắng mặt trong một lần đọc không bị tự xóa. Lần đồng bộ lỗi
giữ lại dữ liệu đã lưu từ lần thành công gần nhất.

Mục **Khách lưu trú** có bộ lọc **Chờ liên kết**. Quyền thiết bị và nhật ký quét
trên hồ sơ vẫn đọc trực tiếp từ nguồn khi tra cứu. Đồng bộ danh sách không tự tạo
hồ sơ khách hàng CRM.

## Phần chưa triển khai

Chưa tự động ghi vào master khách hàng CRM, chưa lưu checkpoint, chưa ghi
lưu trú, ảnh hoặc quyền lên hệ thống nguồn, chưa có webhook. Chưa có bảng ánh xạ trạng thái
và mã phản hồi thiết bị. Chưa có sandbox/credential thật nên mới kiểm thử HTTP giả lập.
Tài liệu API.docx chưa xác nhận mọi response schema; adapter báo lỗi nếu cấu trúc
không phải danh sách hoặc object items. Không biến lỗi nguồn thành danh sách rỗng.

Tuần 3 cần nhà cung cấp xác nhận endpoint/schema, sandbox, scope, quyền AdminOnly,
date semantics, thiết bị, giới hạn và đầu mối. Chỉ mở lệnh ghi sau khi thống nhất
idempotency, tra kết quả sau timeout, quyền truy cập và kiểm thử thiết bị thật.
