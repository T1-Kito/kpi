# Cấp bản quyền VK CRM

Phần CRM vẫn là PHP; trình quản lý máy chủ C# và backend PHP kiểm tra chữ ký RSA-SHA256 (PKCS#1 v1.5). License vĩnh viễn gắn với Windows MachineGuid của máy chủ; máy con không cần kích hoạt. Cài lại Windows hoặc chuyển máy cần cấp lại. Máy ảo sao chép cùng MachineGuid không được phân biệt ở phiên bản này.

## Khóa của nhà cung cấp

Khóa riêng nằm ngoài repository tại `%USERPROFILE%\.vk-crm-license\private.pem`. Sao lưu riêng, không gửi cho khách và không đặt vào thư mục bộ cài. Khóa công khai `license-public.pem` được nhúng trong Setup. Không tạo lại khóa sau khi đã cấp license; mất khóa riêng sẽ không cấp tiếp được license tương thích.

Khởi tạo chỉ một lần bằng PowerShell 7:

```powershell
pwsh -File scripts/issue-license.ps1 -Action Initialize
```

## Cấp license

Khách bấm Khởi động và gửi mã máy trong cửa sổ kích hoạt. Nhà cung cấp chạy trong repository:

```powershell
pwsh -File scripts/issue-license.ps1 -Company 'Tên công ty khách' -MachineCode 'MA_64_KY_TU_KHACH_GUI' -OutputFile 'C:\DuongDanRieng\KhachHang.vkcrm'
```

Chỉ gửi file `.vkcrm` cho khách. Khách bấm Chọn file bản quyền. File lưu tại thư mục cài, không sửa tài khoản hay database. Khi cập nhật giữ nguyên file này. Khi đổi máy cấp file mới; bản offline không tự thu hồi được file máy cũ.

## Phạm vi bảo vệ hiện tại

Khi đóng gói với `--protect-source`, bộ đóng gói loại bình luận, thu gọn PHP và chuyển chuỗi đơn sang chuỗi byte hex; bật kiểm tra license backend bằng khóa công khai được nhúng. Không đổi tên class, method, biến hay dùng eval để tránh phá Laravel. Blade, JavaScript, thư viện vendor và cấu trúc tên file vẫn đọc được. Đây là làm rối cơ bản, KHÔNG phải mã hóa; người có quyền sửa file vẫn có thể gỡ kiểm tra. Bộ cấp license và khóa riêng không được đóng gói cho khách. Bản 0.8.16 đã phát hành trước đó chưa có chức năng này. License thử RSA-PSS trước đó cần được cấp lại bằng công cụ PKCS#1 hiện tại.
