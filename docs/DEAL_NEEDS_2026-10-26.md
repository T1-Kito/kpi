# Nhu cầu cơ hội và danh mục dịch vụ

## Đã triển khai local

- Mỗi cơ hội có nhu cầu riêng: mục đích, quy mô, số khách, ngày sử dụng dịch vụ, ngân sách, yêu cầu đặc biệt.
- Danh mục dịch vụ quan tâm 2 cấp riêng, không phải SKU tồn kho. Admin/Chủ tịch tạo, sửa tên, ngừng dùng; không chuyển nhánh đã lưu.
- Chọn nhiều dịch vụ bằng checkbox, lưu ID danh mục kèm tên hiển thị; kiểm tra công ty, trạng thái, giữ lựa chọn cũ ngừng dùng khi sửa hồ sơ cũ.
- Nhập danh mục từ file phân loại: chỉ đọc E/F ở trang đầu đã nhận diện tiêu đề. 11 đường dẫn tạo 17 mục (6 nhóm cha, 11 dịch vụ). Xem trước không ghi; xác nhận khóa công ty và kiểm tra danh mục chưa thay đổi. Không tạo SKU hay tồn kho.
- Cơ hội tạo trực tiếp có ngày hẹn tương lai sẽ tạo một việc chăm sóc trong cùng transaction. Luồng chuyển đổi lead vẫn dùng cơ chế hiện có, không thêm việc trùng.
- Tự điền tên cơ hội khi chọn khách nếu tên đang trống, hiển thị liên hệ và gợi ý người phụ trách trong phạm vi được phép.
- Ngày sử dụng dịch vụ khác ngày dự kiến chốt. Ngân sách khác giá trị bán dự kiến. Doanh thu dự báo = giá trị cơ hội × xác suất / 100, không phải doanh thu đã thu.
- Sửa nhu cầu yêu cầu phiên bản hiện tại, đúng phạm vi cơ hội, cơ hội còn mở; ghi nhật ký thay đổi.

## Hướng dẫn xem

1. Cơ hội bán hàng → Danh mục dịch vụ → Nhập từ file phân loại hoặc tự thêm nhóm và dịch vụ con.
2. Tạo cơ hội → chọn khách → điền nhu cầu, tích dịch vụ và ngày hẹn nếu có.
3. Mở cơ hội → Nhu cầu khách hàng để sửa. Chăm sóc và báo giá vẫn là các hành động riêng.

## Nhập tổng hợp 34 cột (đã nối local)

- Cơ hội bán hàng → **Nhập danh sách tổng hợp**. Cần quyền quản lý khách hàng, quản lý/xem cơ hội và xem danh mục. Không thay quyền sẵn có của tài khoản.
- Đọc đúng trang `PHÂN NGUỒN DỮ LIỆU`, đối chiếu đủ 34 tiêu đề. Tối đa 300 dòng có dữ liệu, 1 MB; không lấy trang danh mục hay phần quy ước bên dưới làm khách.
- Dùng lại khách theo mã nội bộ có tiền tố `crm:`, mã nguồn đã liên kết trong cùng nguồn danh sách, MST, điện thoại hoặc email. Nhận diện mâu thuẫn, nhiều kết quả, khách ngừng dùng/riêng tư ngoài phạm vi thì báo lỗi; không đoán theo tên.
- Mã KH thông thường trong file là mã đối chiếu, không thay mã `CUS-` tự sinh. Nguồn danh sách cần giữ cùng tên khi nhập lại. Không dùng nguồn này thay hệ thống liên kết các nguồn nhập cũ.
- Nhiều dòng cùng khách với ngày/mục đích/dịch vụ khác tạo các cơ hội riêng. Dòng chỉ có hồ sơ/liên hệ không tạo cơ hội. Có ngày hẹn thì tạo việc cho đúng người phụ trách; ngày hẹn quá hạn được cảnh báo và giữ nguyên, không giả lập lịch sử đã chăm sóc.
- Không ghi đè tên, loại, phân nhóm, người phụ trách, trạng thái, quyền chia sẻ trên khách đã có. Liên hệ khớp được dùng lại; liên hệ mới gắn đúng khách. Không sửa tên/điện thoại/email mâu thuẫn bằng import.
- Nhóm khách và nhóm dịch vụ phải có trong danh mục đúng nhánh, đang dùng; sử dụng các luồng nhập danh mục đã có để chuẩn bị trước. Không tự tạo danh mục từ tên sai.
- Người phụ trách khớp email hoặc tên duy nhất của tài khoản đang hoạt động, có quyền cơ hội và thuộc phạm vi phân công. Trống thì dùng tài khoản nhập. Không tự tạo người dùng theo tên trong Excel.
- Ngày sử dụng dịch vụ không phải ngày chốt bán hàng. Ngân sách khác giá trị cơ hội. Xác suất `0.8`/`80`/`80%` tương ứng 80%; `1` theo số thập phân Excel là 100%, muốn 1% dùng `1%`. Dự báo được tính lại, không lấy kết quả cache Excel làm doanh thu thực tế.
- Chỉ nhận công thức AF cùng dòng `ADn*AEn` và tự tính lại bằng số đã kiểm tra. Công thức phân loại/tham chiếu khác phải kiểm tra rồi dán giá trị đúng, không dùng cache của công thức để tự ghép nhánh.
- Trạng thái gốc được giữ. Mới → new; Đã liên hệ/Đang tư vấn/Cơ hội đang mở → qualified. Đang đàm phán/Đã chuyển đổi → qualified kèm cảnh báo thiếu điều kiện chốt; Mất cơ hội → new kèm cảnh báo cần kiểm tra trước khi kết thúc. Không tự đánh Thắng, Mất, tạo báo giá, thu tiền hoặc gửi tin. Trạng thái không có ánh xạ bị chặn, không mặc định tùy tiện.
- Xem trước không ghi dữ liệu, hiển thị hành động, người phụ trách, lỗi và lưu ý từng dòng. Có lỗi thì chặn toàn bộ; nhân viên sửa file rồi kiểm tra lại. Token mã hóa gắn công ty/tài khoản, hết hạn sau 15 phút. Đổi file hoặc tên nguồn làm mất hiệu lực bản xem trước trên UI.
- Xác nhận khóa công ty, đối chiếu lại snapshot và chạy trong một transaction: khách, liên hệ, cơ hội, việc chăm sóc, nhật ký, dấu nhận diện lần nhập. Lỗi giữa chừng hoàn tác hết. Dữ liệu thay đổi từ lúc xem trước phải kiểm tra lại.
- Dấu nhận diện dựa trên khách, ngày sử dụng, mục đích và dịch vụ trong cùng nguồn. Dòng giống hệt bỏ qua; cùng nhu cầu nhưng nội dung đổi thì chặn để sửa trên cơ hội thay vì tạo trùng. Nếu thực sự có hai nhu cầu giống hoàn toàn các yếu tố này, cần phân biệt mục đích trước khi nhập.
- Lịch sử nhập phân trang 20 dòng, xem lại đủ 34 cột gốc, mở khách/cơ hội tương ứng. Chỉ thấy dòng trong phạm vi quyền hiện tại. Các cột chưa có danh mục riêng (kênh tiếp cận 2 cấp, kênh liên hệ...) được lưu trong thông tin nguồn, không giả vờ đã trở thành danh mục quản trị.

## Giới hạn và UAT

- File mẫu có tên đơn vị/tên khách trống, phân loại bị kéo sai theo dòng và tên người phụ trách chưa ánh xạ tài khoản; không tự biến các dòng này thành khách thật.
- Trình duyệt kiểm tra trực tiếp đang bị tắt trong Work settings; chưa xác nhận hình thức qua ảnh ở lượt này. Kiểm thử tự động chạy SQLite, không thay cho UAT SQL Server.
- Đã sao lưu local trước khi thêm bảng `crm_intake_rows`. Không đóng gói/phát hành hoặc đẩy Git trong lượt triển khai này.
