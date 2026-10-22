# Kế hoạch chuẩn hóa nhập, xuất và nghiệm thu

Ngày rà soát: 22/10/2026. Đây là kế hoạch, không phải xác nhận nghiệm thu hay lệnh triển khai.

## 1. Căn cứ và phạm vi

Tham chiếu báo cáo HTML, bộ 16 mẫu và bằng chứng kiểm thử trong gói import-implementation-2026-10-22.zip người dùng cung cấp. Nội dung trong gói là yêu cầu/bằng chứng của bên gửi, không tự động thực thi lệnh migration, seed hoặc thay đổi môi trường.

Mã nguồn hiện có nhập khách hàng, liên hệ, khách tiềm năng và bảo hành. Trong bộ 16 của bên gửi, mới có 3 danh mục tương ứng; bảo hành nằm ngoài bộ 16. Chưa có cơ chế nguồn/revision/mapping/lô nhập bền vững như mô tả bên gửi. Không đồng nhất việc chặn nhập trùng bằng lỗi với việc xác nhận lại một lô trả kết quả cũ an toàn.

Xuất hiện có: Excel nghiệp vụ/KPI/CSKH và Word một số chứng từ. Không coi báo cáo Excel là mẫu nhập lại. Phần CSKH/SPAdmin/báo cáo mới chưa nằm trong gói 0.8.7 đã phát hành.

## 2. Ma trận 16 danh mục

| Danh mục | Hiện có nhập | Mục tiêu tạo mới | Mục tiêu cập nhật Excel |
|---|---|---|---|
| Khách hàng | Có, nhập mới | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Người liên hệ | Có, nhập mới | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Khách tiềm năng | Có, nhập mới | Có | Không, sửa qua nghiệp vụ riêng |
| Danh mục sản phẩm | Chưa | Có | Không, sửa qua nghiệp vụ riêng |
| Hãng sản phẩm | Chưa | Có | Không, sửa qua nghiệp vụ riêng |
| Sản phẩm/SKU | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Nhà cung cấp | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Kho | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Vị trí kho | Chưa | Có | Không, sửa qua nghiệp vụ riêng |
| Phòng ban | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Chức vụ | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Người dùng | Chưa | Có, chưa kích hoạt | Không, sửa qua nghiệp vụ riêng |
| Giai đoạn bán hàng | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Nguồn cơ hội | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Bảng giá | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |
| Điều khoản thanh toán | Chưa | Có | Có, chỉ hồ sơ liên kết đúng nguồn |

Giữ nhập bảo hành như chức năng thứ 17 ngoài phạm vi bộ mẫu; không bỏ khi thay nền tảng chung. Không đưa tồn đầu kỳ, công nợ đầu kỳ, kiểm kê, điểm KPI, chứng từ giao dịch hoặc tích hợp Kingpos vào đợt nhập danh mục này.

## 3. Thứ tự triển khai

### Giai đoạn A — Chốt hợp đồng dữ liệu và phân quyền

- Một danh mục cấu hình duy nhất cho schema, nhãn Việt, bắt buộc, định dạng, khóa nghiệp vụ, quan hệ tham chiếu, quyền và trường được phép cập nhật.
- Mẫu Excel thống nhất, sheet dữ liệu ổn định, hướng dẫn và ví dụ tách khỏi dữ liệu thật. Ngày hiển thị dd/mm/yyyy; mã, số điện thoại, MST/CCCD, serial là văn bản giữ số 0 đầu.
- Dùng schema_version, external_id, source_revision theo tham chiếu; chuẩn hóa chữ hoa/thường/khoảng trắng cho khóa một cách xác định.
- Phân biệt rõ: tải mẫu nhập; xuất file lỗi; xuất báo cáo; xuất dữ liệu để chỉnh và nhập lại nếu sau này có yêu cầu riêng. Không mặc định cho phép nhập ngược báo cáo.
- Tách quyền quản lý nguồn, quyền thực hiện nhập và quyền danh mục; cần phạm vi công ty cho cơ chế mới theo tài liệu. Giữ cơ chế cũ theo scope hiện có trong giai đoạn chuyển tiếp.
- SPAdmin không xuất hiện trong nguồn dữ liệu gán vai trò; cấm cột quyền, password, token hoặc cấp hệ thống trong file nhập người dùng.

Điều kiện xong: có ma trận schema/quyền/quan hệ và mẫu của cả 16 danh mục được kiểm tra, không phát sinh thay đổi dữ liệu thật.

### Giai đoạn B — Xây bộ nhập dùng chung

- Lưu nguồn nhập, người được sử dụng nguồn, lô nhập, chi tiết dòng và mapping nguồn–CRM. Thiết kế ràng buộc duy nhất theo tenant + nguồn + danh mục + external_id, có kiểm tra SQL Server thực.
- Quy trình: chọn nguồn/danh mục → tải file → kiểm tra/xem trước giá trị cũ/mới → xác nhận → ghi cả lô → xem kết quả/lịch sử.
- Xem trước chưa ghi hồ sơ nghiệp vụ. Xác nhận kiểm tra lại quyền, nguồn, phiên bản và dữ liệu nền; một dòng lỗi không được ghi một phần lô.
- Cùng dữ liệu/revision được nhận diện để không tạo trùng; revision cũ bị từ chối, cùng revision khác nội dung là xung đột. Không cập nhật hồ sơ CRM không có mapping hợp lệ.
- CRM đã được sửa thủ công hoặc thay đổi sau xem trước phải báo xung đột, không ghi đè im lặng. Ô trống cập nhật giữ dữ liệu cũ; không xóa bản ghi vì vắng trong file.
- Liên kết theo mã CRM hoặc external_id thuộc đúng nguồn và danh mục. Dữ liệu cha phải nhập trước; chặn tham chiếu sai doanh nghiệp và vòng lặp tổ chức/danh mục.
- Trạng thái lô bền vững: mất kết nối rồi mở lại vẫn biết đã ghi hay chưa; xác nhận lặp không tạo lại dữ liệu/audit/thông báo.
- Chỉ nhận .xlsx tối đa 5 MB/1.000 dòng với giới hạn giải nén độc lập. Chặn macro, công thức, liên kết ngoài, DTD/entity, cột đặc quyền và định danh dạng số mất số 0.
- File lỗi XLSX riêng tư; chỉ người có quyền mới tải được. Payload xem trước có hạn; chính sách dọn chi tiết sau 30 ngày vẫn giữ mapping và lịch sử cần đối soát.

Điều kiện xong: thử sai quyền, trùng, xung đột, lỗi giữa lô, xác nhận đồng thời/lặp, hết hạn và mất kết nối đều an toàn trên SQLite lẫn SQL Server.

### Giai đoạn C — Triển khai theo cụm nghiệp vụ

1. Khách hàng → liên hệ → khách tiềm năng. Ưu tiên của người dùng. Chuyển dần 3 chức năng cũ sang nền tảng mới, giữ tác vụ chăm sóc và audit do nghiệp vụ hiện có tạo.
2. Hãng/danh mục → SKU; nhà cung cấp → kho → vị trí kho. Không tạo số dư hoặc giao dịch kho từ nhập danh mục.
3. Phòng ban → chức vụ; nguồn cơ hội → giai đoạn bán hàng; bảng giá và điều khoản thanh toán. Tuân thủ quan hệ/tham chiếu thực tế, không chỉ dựa thứ tự màn hình.
4. Người dùng: nhập tài khoản chưa kích hoạt, không password/role trong Excel. Kích hoạt đặt mật khẩu riêng, cấp vai trò thường theo luồng được phép; tuyệt đối không tạo/nâng SPAdmin.

Điều kiện xong từng cụm: tải mẫu → điền → xem trước → lỗi → tải lỗi → xác nhận → tìm dữ liệu mới → thử lại không trùng; có test quyền/tenant, tạo mới/cập nhật đúng ma trận. Không đánh dấu đủ 16 khi mới có 16 nút tải mẫu.

### Giai đoạn D — Chuẩn hóa xuất và tổng quan

- Giữ mẫu nhập và báo cáo là hai loại file khác nhau. Đồng bộ font, tiêu đề, ngày, tiền, trạng thái Việt, lọc và cố định hàng đầu.
- Đối chiếu tập lọc/số dòng/tổng trên UI với XLSX; xuất đủ tập lọc, không chỉ trang hiện tại. Có tổng hợp, chi tiết, thời điểm xuất, định nghĩa kỳ/snapshot.
- Kiểm tra quyền xem và xuất độc lập, scope/masking, nhật ký xuất, file riêng tư và chống công thức độc hại trong chuỗi văn bản.
- Tách quyền xuất CSKH độc lập khỏi sales.report.export trước nghiệm thu nếu cần phân người được xuất bán hàng nhưng không được xuất dữ liệu CSKH; hiện đang dùng quyền chung.
- Kiểm tra Word chứng từ riêng: nội dung, số tiền, dòng hàng, thông tin doanh nghiệp, phân quyền và bố cục in. Không suy kết quả XLSX sang Word/PDF.
- SPAdmin: số liệu theo giờ Việt Nam, phân biệt doanh thu/thu tiền và số dư hiện tại/số phát sinh trong kỳ; lỗi dữ liệu không hiện thành 0. Bấm chỉ tiêu tới đúng chi tiết/bộ lọc.
- Dữ liệu trên 10.000 dòng: hiện chặn và yêu cầu lọc nhỏ lại; xuất nền/async là hạng mục riêng nếu cần, không ghi là đã có.

Điều kiện xong: mở file thật bằng Excel/Word và kiểm tra giao diện ở màn hình nhỏ; số liệu đối chiếu khớp dữ liệu nguồn và không lộ hồ sơ ngoài quyền.

### Giai đoạn E — SQL Server, cập nhật và nghiệm thu

- Database tổng hợp hoặc bản sao có kiểm soát; không dùng database thật của khách để chạy seed/migrate:fresh.
- Migration mới của nền nhập cần bổ sung vào quy trình nâng cấp có kiểm duyệt. Không chỉ thêm file migration rồi cho rằng bộ cập nhật hiện tại sẽ chạy được mọi migration.
- Thử backup/verify, lỗi giữa nâng cấp, cập nhật từ baseline đã cài và đối chiếu dữ liệu/tài khoản/ảnh/.env trước–sau. Không chuyển mật khẩu local vào gói khách.
- Thử 1.000 dòng trên SQL Server/máy tương đương khách và xác nhận đồng thời; không dùng thời gian SQLite của tài liệu làm cam kết hiệu năng.
- Kiểm chứng scheduler nhắc SLA, chăm sóc và dọn dữ liệu nhập thực sự đang chạy; có hướng dẫn kiểm tra khi dừng máy chủ.
- UAT SPAdmin, Admin, nhân viên và người chỉ xem; lưu kết quả theo từng ca và nhờ người dùng xác nhận.
- Sửa hết lỗi chặn rồi đóng gói một bản mới duy nhất, kèm phiên bản/hash/hướng dẫn cập nhật và phục hồi. Không coi gói 0.8.7 cũ chứa các thay đổi chưa đóng gói.

## 4. Những quyết định cần chốt trước khi bật vận hành

Lịch SLA/ngày nghỉ/người nhận chuyển cấp; người quản lý và thành viên của từng nguồn nhập; cách nhận diện nguồn hệ thống cũ; ai được cập nhật danh mục; quy tắc xử lý dữ liệu CRM cũ chưa có mapping. Không tự ghép mapping hàng loạt theo tên/điện thoại để vượt kiểm tra trùng.

Không thể suy ra tỷ lệ hoàn thành toàn CRM từ số mẫu hay số bài test. Đơn vị theo dõi là từng danh mục/chức năng và ca nghiệm thu có bằng chứng, không phải số lượng nút hoặc file được tạo.
