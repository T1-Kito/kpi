# Bản xem thử cập nhật VK CRM

Mở `VK-CRM-Update-Demo.exe`, bấm **Cập nhật**. Phiên bản chương trình mẫu đổi
từ 1.0 sang 1.1; cấu hình SQL, danh sách khách/tài khoản và ảnh mẫu được kiểm
checksum để xác nhận giữ nguyên. Không cần nhập thông tin SQL.

Đây là bản minh họa luồng thao tác, **không phải gói cập nhật để gửi khách**.
Không truy cập bản VK CRM đang cài, database local của workspace hoặc SQL Server.
Chỉ tạo dữ liệu giả dưới `%TEMP%/VkCrmUpdateDemo/<mã riêng>`; thư mục đó được giữ lại
để kiểm chứng. Không có chức năng chọn bản cài thật hoặc tải gói qua Internet.

## Đã triển khai trong mẫu

- Kiểm manifest, SHA-256, phiên bản nguồn/đích và danh sách file cho phép.
- Chặn đường dẫn vượt thư mục, liên kết thư mục, file trùng và nội dung nén vượt giới hạn.
- Không cho gói đè `.env`, database, dữ liệu tải lên hoặc runtime.
- Chuẩn bị bản sao code trước khi thay; khôi phục code/version nếu thay file lỗi.
- Khóa không cho chạy hai lượt cập nhật cùng thư mục minh họa một lúc.
- Có kiểm thử tự động lỗi giữa chừng, checksum sai và cố đè dữ liệu.

## Để phát hành bản cập nhật thật còn phải làm

Gói phát hành ký số, nhận diện bản cài/phiên bản, kiểm tương thích runtime và thư viện,
dừng dịch vụ có kiểm soát, kế hoạch migration/backup/restore SQL theo phiên bản,
khả năng khôi phục khi mất điện hoặc tiến trình bị tắt, và UAT máy đích.
Checksum chỉ phát hiện nội dung bị đổi; không thay thế chữ ký xác thực nhà phát hành.
Rollback code trong mẫu không chứng minh rollback database đã được thực hiện.

Build riêng để xem thử, không đóng gói lại bộ cài CRM chính:

```powershell
dotnet publish deployment/update-demo/VkCrmUpdateDemo.csproj -c Release -r win-x64 -o outputs/update-demo-20261020
```

Tự kiểm tra bằng `--self-test <đường dẫn file báo cáo>`; không mở giao diện.
