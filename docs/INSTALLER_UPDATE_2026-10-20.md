# Bộ cài chung và cập nhật phần mềm

## Đã triển khai trong mã nguồn

- Bộ cài chính có lựa chọn **Cập nhật bản đã cài** tại màn hình đầu, bên cạnh luồng cài mới. Không yêu cầu nhập lại SQL.
- Chỉ nhận thư mục có dấu nhận dạng VK CRM, `.env`, runtime, vendor và manifest hợp lệ; không nhận thư mục tùy ý.
- Gói cập nhật lấy từ tài nguyên nhúng của chính bộ cài. Kiểm tra SHA-256 từng tệp, đường dẫn, tệp trùng, dung lượng; không chép `.env`, runtime, database, ảnh hoặc thư mục storage từ gói.
- Kiểm tra kết nối và danh sách migration bằng mã nguồn mới trong tiến trình PHP riêng, kể cả Composer class map tối ưu. Không chạy migration, seed hoặc đổi tài khoản.
- Thay đổi thư viện Composer, migration cũ, mã nguồn chỉnh riêng, cấu hình đã cache hoặc migration còn thiếu sẽ bị chặn trước khi công bố mã nguồn mới.
- Sao lưu các tệp mã nguồn sẽ thay đổi, nhật ký ghi bền vững, công bố từng tệp qua rename; bật bảo trì. Khi kiểm tra thất bại sẽ khôi phục mã nguồn. Chạy lại sau gián đoạn sẽ khôi phục từ bản sao đã kiểm tra. Bản sao các lần hoàn tất được giữ lại.
- Không thay launcher `VK CRM.exe` đang cài; khách dùng bộ cài tải về để thực hiện cập nhật. Không tự động tải bản cập nhật qua Internet.

## Kết quả đã chạy

- `dotnet run --project deployment/installer-tests/InstallerUpdateTests.csproj -c Release`: 19 kiểm thử PASS trên fixture giả lập, bao gồm thử lại sau lỗi chuẩn bị gói và chặn nhật ký phục hồi nhắm vào cấu hình.
- `php scripts/test-update-readonly-check.php`: 5 kiểm thử PASS trên SQLite tổng hợp cách ly. Xác nhận database và `.env` không đổi byte; migration chờ không được thực thi; thông tin exception không bị lộ.
- Build bộ cài Windows: không cảnh báo, không lỗi.

## Chưa đủ điều kiện phát hành cho khách SQL Server

Đây là tích hợp cập nhật **mã nguồn trên schema đã tương thích**, chưa phải quy trình nâng cấp database hoàn chỉnh. Khách đang dùng schema cũ có thể bị chặn; không được hướng dẫn bỏ chốt an toàn hoặc sửa tay manifest để vượt qua.

Cần hoàn tất trước khi giao bản phát hành:

1. Sao lưu SQL Server native, kiểm chứng phục hồi trên database cách ly; quyền backup phải được xác nhận, không tự cấp sysadmin.
2. Nâng cấp migration theo phiên bản cùng quy trình phục hồi nhất quán mã nguồn/database khi mất điện hoặc lỗi sau commit. Hiện không có database rollback tự động.
3. Kiểm thử SQL Server và UAT trên bản sao cấu hình triển khai khách. Các kiểm thử SQLite/fixture không thay thế bước này.
4. Ký số bộ cài khi có chứng thư của đơn vị phát hành. Hash gói nhúng không phải chữ ký nhà phát hành.
5. Kiểm tra runtime hỗ trợ/bảo mật, các nghiệp vụ và tải đồng thời còn mở trong báo cáo đối chiếu.

Không chạy cập nhật lên thư mục `VK CRM9`, database workspace hoặc database khách trong đợt kiểm thử này. Không phát hành EXE giao khách khi các điều kiện trên chưa hoàn tất.
