# Bản sửa kiểm soát nghiệp vụ ngày 19 tháng 10 năm 2026

## Phạm vi đã sửa

- Lỗi máy khách tạo/nhân bản/tạo phiên bản báo giá bị 500: nhật ký xác định unique index `quotations_revision_root_id_revision_number_unique` chặn `(NULL, 1)` trên SQL Server. Migration `2026_10_07_000006_fix_sqlserver_quotation_revision_unique` thay bằng filtered unique index chỉ áp dụng khi `revision_root_id IS NOT NULL`, cùng transaction, không sửa/xóa chứng từ. Áp dụng cho cả cài mới và nâng cấp database đã có migration gốc. Downgrade tự động về index lỗi bị chặn.
- Đã tái hiện lỗi NULL-root bằng bảng tạm phiên làm việc trên SQL Server local; index mới cho nhiều báo giá gốc và vẫn chặn trùng phiên bản cùng họ. Transaction được rollback, không truy cập bảng khách hàng. 10 bài kiểm thử phiên bản/workflow đạt (72 assertions). Chưa chạy migration trên database máy khách.

- F07: chuẩn hóa mã số thuế NCC; kiểm trùng ở model và unique index theo doanh nghiệp. NCC chưa có MST vẫn được tạo riêng.
- F08: chặn đăng ký bảo hành trùng theo doanh nghiệp, tên sản phẩm/model và serial đã chuẩn hóa. Một thiết bị vẫn có thể có nhiều ticket hỗ trợ. Không gộp hoặc xóa dữ liệu cũ.
- F09: trạng thái bảo hành tính lại theo ngày bắt đầu/kết thúc ở API; màn hình phân biệt chưa hiệu lực. Cộng tháng không tràn sang tháng kế tiếp.
- F10: thu tiền cần mã tham chiếu hoặc Idempotency-Key. Form giữ cùng key qua lần gửi lại; cùng key khác dữ liệu trả 409. Hai khoản thu thực tế dùng hai key riêng.
- F11: token_version tăng khi đổi mật khẩu hoặc khóa/mở user; phiên cũ không sống lại. Logout thu hồi token hiện tại bằng bản ghi bền vững trong DB. Kiểm issuer, loại JWT, tenant và token version. Bảo vệ tự khóa/quản trị cuối.
- F12: middleware xác định scope từ vai trò cấp đúng quyền hành động; vai trò company cho báo cáo không nâng quyền Sales. Không có ngữ cảnh hành động thì chọn scope hạn chế nhất; thiếu owner/quan hệ không mặc nhiên mở quyền.
- F14: bộ đếm có khóa trong DB thay cho tìm lỗ trống; mã được giữ trước khi trả về và không tái dùng sau xóa. Chưa xác nhận kiểm thử tải nhiều tiến trình SQL Server.
- F15: kích hoạt hợp đồng và nghiệm thu mốc ghi audit cùng transaction, có actor/trước/sau. Retry nghiệm thu không tạo audit lặp.
- F16 một phần: giao diện mở ticket, xem lịch sử, ghi worklog, chuyển trạng thái/phân công qua API có sẵn; thống nhất nhãn Ngày mua. Giữ SLA giờ liên tục hiện hành và hiển thị giới hạn rõ.
- F17: khởi tạo Sổ điểm khi nhận vk:ready ở lần mở trực tiếp; có nút thử lại khi tải lỗi.

F01–F06 giữ các kiểm thử hồi quy đã đạt. Đây không phải chứng nhận toàn hệ thống hoặc chứng nhận SQL Server.

## Phần cần chốt nghiệp vụ hoặc kiểm chứng tiếp

Kết quả kiểm tra sau bản sửa: 218 bài backend với 2.001 assertions, 140 bài JavaScript đạt. Bộ cài 0.7.2 build và self-test đạt. Smoke test SQLite portable độc lập qua đăng nhập, tạo khách/chăm sóc và đối chiếu dữ liệu sau khởi động lại đạt. Không thay kết quả này cho kiểm thử SQL Server thực tế.

- F13 cập nhật theo xác nhận người dùng: tách chỉnh sửa khách/NCC/sản phẩm/kho/thiết lập bán hàng; tách trạng thái công việc và xử lý cảnh báo; tách cấu hình, lập phiếu, duyệt phiếu/ngoại lệ và khóa KPI. Migration giữ các thao tác mà role cũ đã có, không cấp cho role khác; quyền mới có thể gỡ độc lập. Quyền cũ không tự suy ra quyền mới tại thời điểm gọi API. Các quyền gộp còn lại như user.manage và thao tác hợp đồng/bán hàng cần tiếp tục đối chiếu trước khi kết luận F13 hoàn tất toàn bộ.
- KPI đã chặn người lập tự duyệt phiếu/ngoại lệ ở server, có transaction/lock; duyệt lại quyết định đã xử lý bị từ chối. Đã đồng bộ nút phiếu điểm và hàng chờ duyệt. Quy tắc phân nhiệm ở các luồng duyệt khác vẫn cần rà soát riêng, chưa tuyên bố toàn hệ thống đã chống tự duyệt.
- F16: chưa tự đặt lịch làm việc/ngày nghỉ, cách dừng SLA khi chờ khách, hay quy tắc mở lại ticket đã đóng. Không tự thay cam kết dịch vụ.
- Cần kiểm thử UAT đầy đủ trên trình duyệt và kiểm thử đồng thời trên SQL Server giống môi trường triển khai.
- PHP trong portable hiện vẫn là runtime cũ; chưa coi gói này là bản đã chứng nhận sản xuất.

## Nâng cấp bản đang dùng

1. Dừng ghi giao dịch và sao lưu database bằng công cụ đúng hệ quản trị; xác minh khả năng phục hồi. Sao lưu file ứng dụng, .env và thư mục uploads riêng. Không đưa .env vào gói phát hành.
2. Chạy scripts/report-safety-preflight.php bằng runtime của bản đang dùng để kiểm tra kết nối và hồ sơ trùng. Công cụ chỉ đọc, không xuất mật khẩu. Nếu có trùng, cần đối soát/gộp có phê duyệt trước; không xóa dữ liệu để vượt kiểm tra.
3. Áp dụng mã và chạy `php artisan migrate --force` trong cửa sổ bảo trì có sao lưu. Migration mới kiểm tra trùng trước mọi thay đổi schema. Kiểm tra migration trên bản sao database trước khi nâng cấp thật.
4. Xóa cache view/config theo quy trình triển khai. Đăng nhập mới; token trước nâng cấp bị từ chối. Kiểm tra thu tiền, bảo hành, NCC, quyền người dùng và audit bằng tài khoản phù hợp.
5. Khi cần khôi phục, phục hồi đồng bộ bản sao ứng dụng và database. Không dùng migrate:fresh hoặc rollback tùy tiện trên dữ liệu giao dịch.

Trong lượt sửa này, SQL Server 192.168.1.20:1433 không kết nối được từ máy hiện tại. Chưa sao lưu/chưa chạy migration/chưa thay mã của bản VK CRM9 đang hoạt động. Không khẳng định bản đang chạy đã được nâng cấp.

Theo yêu cầu mới, dừng đóng gói trong lúc sửa tiếp. Bộ cài 0.7.2 và 0.7.3 trước đó không chứa đợt tách quyền này và không phải bản bàn giao cuối. Không build thêm cho đến khi chốt phạm vi sửa và kiểm thử.
