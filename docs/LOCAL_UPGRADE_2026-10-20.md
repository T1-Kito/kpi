# Khắc phục đăng nhập local — 20/10/2026

## Nguyên nhân đã xác minh

Database SQLite đang chạy tại `database/database.sqlite` chưa được nâng cấp cùng mã nguồn.
Thiếu cột `users.token_version` và bảng `revoked_access_tokens`. Đăng nhập cấp JWT
nhưng bước xác thực `/api/v1/me` không đọc được bảng thu hồi token, nên trả 401.
Giao diện xóa token và quay lại đăng nhập.

## Đã thực hiện trên local

- Sao lưu SQLite bằng VACUUM INTO, kiểm checksum/toàn vẹn và khôi phục thử sang file khác.
- Chạy thử các migration còn thiếu trên bản khôi phục trước khi cập nhật database đang chạy.
- Đưa local vào bảo trì tạm thời, sao lưu lại và áp dụng 4 migration còn thiếu.
- So số lượng 18 bảng nghiệp vụ trước/sau: không đổi; integrity_check và foreign_key_check đạt.
- Kết thúc bảo trì, không đổi `.env`, không cập nhật bản VK CRM9 hoặc SQL Server máy khách.
- Đổi xử lý khởi tạo giao diện: chỉ 401 mới xóa phiên; lỗi mạng/5xx giữ token và có nút thử lại.
  Không hiển thị nguyên văn lỗi máy chủ ra giao diện.

Bản sao lưu trước áp dụng:
`storage/app/private/database-backups/crm-20261020-020218-5986e786.sqlite`
(có manifest checksum kèm theo). Bản sao và bản khôi phục thử được giữ lại, chưa xóa.
Script giới hạn chính xác đường dẫn workspace và SQLite local: `scripts/upgrade-workspace-local.php`.

## Kiểm tra trực tiếp máy chủ local

- Đăng nhập quản trị thành công, kiểm `/me` nhiều lần vẫn 200.
- 28 endpoint chính trả 200, gồm dashboard, khách hàng/liên hệ, lead/cơ hội,
  báo giá, đơn bán, hợp đồng, hóa đơn/thu tiền, mua hàng, kho, công việc/cảnh báo,
  duyệt tập trung, KPI, người dùng/quyền và mẫu nhập khách.
- Đăng xuất trả 200; token vừa đăng xuất bị từ chối 401; đăng nhập mới và `/me` đạt.
- HTML khách hàng có nút import và nhận đúng phiên bản asset xử lý phiên mới.
- Hồi quy sau sửa: 237 bài PHP/SQLite, 2.173 assertions đạt; 147 bài JavaScript đạt.
  JUnit: `.tmp/local-release-regression-20261020.xml`.
- Đây là kiểm HTTP thực tế, không phải xác nhận đã thao tác giao diện trình duyệt:
  công cụ điều khiển trình duyệt trong phiên hiện tại không kết nối được.

## Trạng thái bàn giao

Lỗi đăng nhập local đã được xử lý. Chưa có bộ cài mới được đóng gói hoặc chuyển cho khách.
Không dùng kết quả HTTP 200 làm bằng chứng mọi thao tác nghiệp vụ đã nghiệm thu.
Trước bàn giao còn cần UAT trên máy đích, kiểm đồng thời và kiểm sao lưu/khôi phục SQL native,
chốt runtime phát hành và các mục nghiệp vụ còn mở trong báo cáo đối chiếu.
Không đưa mật khẩu hoặc token vào tài liệu và bộ cài.
