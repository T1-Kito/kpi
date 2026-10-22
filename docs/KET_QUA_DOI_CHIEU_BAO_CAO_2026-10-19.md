# Kết quả đối chiếu báo cáo nghiệp vụ

Phạm vi là mã nguồn trong workspace, không phải xác nhận máy khách đã cập nhật. Báo cáo Word gốc được giữ nguyên. Không đóng gói mới trong đợt rà soát này.

## Kết quả mới nhất — 20/10/2026

Mẫu nhập khách đã đổi sang Excel `.xlsx` có tiêu đề tiếng Việt và nhập trực tiếp.
Hồi quy hiện tại đạt 240 bài SQLite/2.189 assertions và 150 bài JavaScript.
Lượt kiểm SQL Server cho thay đổi Excel chưa hoàn tất do timeout, không lấy kết quả
SQL trước đó làm chứng nhận cho bản Excel mới. Xem `IMPORT_KHACH_HANG.md`.

Database local đang chạy đã được sao lưu, khôi phục thử rồi nâng cấp 4 migration còn
thiếu; giữ nguyên số lượng dữ liệu và kiểm tra toàn vẹn đạt. Đăng nhập/đăng xuất và
28 endpoint local đã kiểm HTTP thành công. Hồi quy hiện tại: 237 bài PHP/SQLite,
2.173 assertions; 147 bài JavaScript đạt. Xem `LOCAL_UPGRADE_2026-10-20.md`.

Sau lượt tổng rà soát bên dưới, đã bổ sung nhập khách hàng CSV. Bộ hồi quy SQLite
đạt 237 bài/2.173 assertions; JavaScript đạt 142 bài. SQL Server kiểm chọn lọc phần
import và phạm vi khách hàng đạt 9 bài/65 assertions sau sửa cuối. Chưa chạy lại
toàn bộ SQL Server sau bổ sung tính năng này. Xem `IMPORT_KHACH_HANG.md`.

- Hồi quy cuối trước bổ sung import: SQLite **230 bài / 2.128 assertions đạt**;
  SQL Server local cách ly **229 bài / 2.119 assertions đạt**; JavaScript **140 bài đạt**.
- SQL Server không chạy `BackupBundleTest` vì bài này kiểm backup bundle SQLite,
  không phải kiểm sao lưu/khôi phục SQL Server native.
- Năm script kiểm bộ cài SQL đều đạt: dữ liệu đầu vào, giữ nguyên tên database,
  xác nhận tạo mới, chặn ghi trước khi đầu vào hợp lệ, quyết định schema trống/retry,
  phân loại lỗi và che thông tin bí mật, trường hợp thiếu driver.
- Bằng chứng JUnit: `.tmp/sqlite-full-20261020-verified.xml` và
  `.tmp/sqlserver-full-20261020-verified.xml`. Các lượt lỗi bên dưới là lịch sử trước sửa.
- Đã kiểm hết bộ tự động hiện có, chưa đồng nghĩa toàn bộ yêu cầu trong Word hoàn tất.
  Phần tiếp theo: kiểm đồng thời cấp mã/chống lặp; rà quyền gộp còn lại; UAT giao diện
  nhiều vai trò và thử backup/restore SQL native. SLA ngày nghỉ/chờ khách cần chốt quy tắc.

## Trạng thái từng phát hiện

| Mã | Trạng thái mã nguồn và kiểm chứng | Chưa hoàn tất |
| --- | --- | --- |
| F01 | Có chặn scope, trạng thái cuối và phiên bản khi sửa hàng cơ hội; hồi quy đạt | Kiểm thử tải/đồng thời và UAT SQL đầy đủ |
| F02 | Kích hoạt/nghiệm thu mốc hợp đồng kiểm scope; hồi quy đạt | Tách toàn bộ quyền hành động hợp đồng |
| F03 | Chặn nguồn sai khách/chuỗi và tổng mốc lệch; hồi quy đạt | Rà quy tắc số tiền/làm tròn đầy đủ trên SQL |
| F04 | Chốt thắng cần báo giá được chấp nhận; hồi quy đạt | Nghiệp vụ ngoại lệ và stage tùy chỉnh cần UAT |
| F05 | Che trường giá vốn theo quyền riêng; hồi quy API đạt | Kiểm đủ mọi mẫu in/file xuất và màn hình thực tế |
| F06 | Lead có replay key và xung đột payload; hồi quy đạt | Kiểm đa tiến trình/gián đoạn mạng |
| F07 | Chuẩn hóa MST NCC, kiểm model và unique index | Nâng cấp dữ liệu cũ nếu có trùng cần đối soát |
| F08 | Chặn trùng đăng ký theo tenant/model/serial; batch rollback | Mô hình thiết bị/đổi serial/RMA chưa đầy đủ |
| F09 | Trạng thái tính theo start/end, chưa hiệu lực, hết hạn; hồi quy đạt | UAT ngày cuối tháng/múi giờ trên môi trường khách |
| F10 | Thu cần tham chiếu hoặc key; replay không tăng tiền hai lần | Đối soát ngân hàng/hoàn tiền không nằm trong chứng nhận này |
| F11 | Token version, thu hồi logout bền vững, bảo vệ tự khóa/quản trị cuối | Kiểm restart và tích hợp đa máy đầy đủ |
| F12 | Scope theo vai trò cấp hành động ở middleware; thiếu context chọn scope hạn chế | Rà các endpoint tự kiểm quyền không qua middleware |
| F13 | Đã tách quyền master, trạng thái task/alert, cấu hình/lập/duyệt/khóa KPI | user.manage, hợp đồng và các quyền gộp khác còn cần tách/rà |
| F14 | Bộ đếm có khóa, không tái dùng mã; kiểm hồi quy đạt | Chưa hoàn thành 20–50 request SQL đồng thời |
| F15 | Audit kích hoạt/nhận mốc cùng transaction, retry không nhân đôi | UAT đầy đủ lỗi giữa chừng/quy trình lý do |
| F16 | Có UI chi tiết ticket, worklog/chuyển trạng thái/phân công; sửa trạng thái bảo hành | SLA ngày nghỉ/chờ khách và mở lại ticket chưa chốt, giữ giờ liên tục hiện tại |
| F17 | Sổ điểm khởi tạo qua vk:ready và nút thử lại; kiểm JS đạt | Chưa xác nhận toàn bộ trình duyệt/máy khách thực tế |

## Kết quả đã quan sát trong đợt kiểm tra này

- Cập nhật 20/10: toàn bộ bộ kiểm thử SQLite hiện có đạt 229 bài, 2.058 assertions sau bản sửa kiểu ID SQL Server.
- JavaScript: 140 bài đạt.
- Toàn bộ migration đã chạy thực tế trên SQL Server local với schema thử có tiền tố và rollback xác nhận không còn bảng thử.
- Đã tạo database thử riêng `VKCRM_Trial_20261019_084007_68c6ae` trên `localhost\MSSQLSERVER01`, không thay .env và không truy cập database khách. Database thử được giữ để kiểm tra, không tự xóa.
- Lượt nghiệp vụ SQL đầu sau sửa NULL keys: 46 bài, 210 assertions, 23 lỗi kiểm tra; không coi đây là đạt. Đã xác định nguyên nhân kiểu số PDO và SLA dạng chuỗi; đang chạy lại sau sửa. Một số assertions so sánh ID kiểu chuỗi/số và lỗi 422/500 liên quan cần kiểm chứng bằng lượt sau.
- Bản sửa bổ sung: filtered unique cho MST khách hàng tùy chọn và direct_key chat; không đổi ràng buộc số dư kho; bật PDO_SQLSRV numeric fetch, cast thời lượng SLA và đồng bộ cấu hình khởi tạo SQL trong bộ cài.
- Đã bổ sung cast các khóa định danh trên model, sửa dữ liệu thử không phụ thuộc ID luôn bằng 1. Lượt SQL nghiệp vụ v3 còn 9 errors và 4 failures (46 bài, 335 assertions), không được coi là đạt toàn bộ.
- Lượt SQL v5 dừng ở timeout chuẩn bị dữ liệu phòng ban: 18 bài, 311 assertions, 1 error. Lượt chạy toàn bộ sau đó đã chủ động dừng vì thiếu RAM; không có kết quả đạt toàn bộ SQL Server.
- Theo lựa chọn của người dùng, chuyển sang nhóm nhỏ chạy tuần tự. Nhóm SQL Server khách chấp nhận báo giá → đơn bán và thiếu hàng → yêu cầu mua → đơn mua → nhập kho đạt 2 bài, 97 assertions (20/10). Không thay dữ liệu máy khách.
- Nhóm SQL Server thu tiền có khóa chống lặp và người duyệt độc lập chỉ được quyết định một lần đạt 2 bài, 11 assertions. Tổng hai nhóm nhỏ: 4 bài, 108 assertions; đây là kết quả chọn lọc, không thay cho toàn bộ bộ kiểm thử SQL.

## Kết luận hiện tại

### Lượt tổng rà soát ngày 20/10

- Chạy hết SQL Server: 228 bài, 1.909 assertions; 3 errors và 10 failures, không phải đạt.
  Không chạy bài backup bundle SQLite trên SQL Server vì đó không phải quy trình backup native SQL.
- Đã sửa migration thêm quyền dùng `insertOrIgnore` không được grammar SQL Server hỗ trợ,
  và cast ID cho model dùng guarded thay vì fillable (bộ KPI bị từ chối nhầm tenant khi phát hành).
- TaskService trả về thời hạn đã đọc lại từ database, tránh lệch độ chính xác datetime giữa
  công việc và ngày chăm sóc cơ hội. Bài kiểm dữ liệu mẫu dùng ID thật, xóa lịch sử công việc
  trước công việc trên schema NO ACTION, so sánh chính xác giá trị decimal thay vì kiểu PDO.
- Chạy lại toàn bộ các lớp có lỗi: 46 bài, 459 assertions đạt trên SQL Server.
- Bổ sung lọc phạm vi cho hàng chờ/lịch sử duyệt KPI theo chính quyền duyệt tương ứng,
  không lấy quyền xem toàn công ty của vai trò không liên quan. Có bài hồi quy riêng.
- Hồi quy toàn bộ sau thay đổi cuối đã đạt: SQLite 230/2.128 và SQL Server 229/2.119.
  Không thay database khách, không đóng gói.

Chưa hoàn tất toàn bộ báo cáo và chưa đủ cơ sở chứng nhận mọi nghiệp vụ. Danh sách QA01–QA30 trong Word là phạm vi cần kiểm, không phải 30 kết quả đã đạt. Chưa hoàn thành toàn bộ UAT trình duyệt, tải đồng thời, sao lưu/khôi phục SQL Server và cấu hình quyền nhân viên thật. Chưa áp dụng migration mới trên máy khách. Chưa đóng gói bản cuối.
