# Nhập danh sách khách hàng

Chức năng nằm trong **Khách hàng → Nhập file**. Cần quyền `customer.manage`.
Đã bổ sung vào mã nguồn; chưa đóng gói hoặc cập nhật bộ cài trên máy khách.

1. Bấm **Tải mẫu** để lấy file Excel `.xlsx`, mở bằng Excel.
2. Giữ nguyên hàng tiêu đề tiếng Việt ở dòng 4. Điền khách từ dòng 5,
   chọn **Doanh nghiệp** hoặc **Cá nhân** trong cột Loại khách hàng.
   Bỏ trống loại sẽ mặc định doanh nghiệp.
3. Điền tên và ít nhất một thông tin nhận diện: điện thoại, email, MST doanh nghiệp
   hoặc CCCD cá nhân (12 chữ số). Những cột còn lại có thể bỏ trống.
4. Mẫu đã định dạng văn bản cho các ô nhập để giữ số 0 đầu. Lưu **.xlsx** và nhập
   trực tiếp; không cần đổi sang CSV. Không dùng công thức trong các ô dữ liệu.
5. Chọn file, bấm **Kiểm tra file**. Xem số khách và tên 20 khách đầu.
   Nếu có lỗi, sửa dòng được báo rồi chọn lại file. Chưa khách nào được lưu ở bước này.
6. Bấm **Xác nhận nhập**. Hệ thống kiểm tra lại trước khi lưu cả danh sách.

Giới hạn mỗi file: 300 khách, 1 MB. File Excel đọc trang đầu tiên theo mẫu.
CSV UTF-8 cũ vẫn hỗ trợ tên cột kỹ thuật hoặc tiếng Việt và dấu phẩy/chấm phẩy.
Không nhận `.xls`, macro, liên kết ngoài hoặc nội dung nén vượt giới hạn.
Những khách nhập mới được phân công cho chính tài khoản nhập.

Không tự sửa hoặc ghi đè khách có sẵn. Trùng điện thoại (có chuẩn hóa +84), email,
MST doanh nghiệp hoặc CCCD cá nhân trong file hay trong cùng công ty sẽ chặn toàn file.
Thông báo trùng không tiết lộ thông tin hồ sơ ngoài phạm vi người dùng.
Nếu cần hợp nhất hoặc cập nhật khách cũ, thực hiện qua quy trình riêng.

Mỗi khách được tạo qua logic tạo khách hiện có: mã tự sinh, phân công, liên hệ chính,
nhật ký và sự kiện nghiệp vụ. Tất cả cùng transaction, lỗi giữa chừng hoàn tác toàn file.
Không có migration mới và không thay cấu hình kết nối database.

## Kiểm chứng ngày 20/10/2026

- Sau bổ sung mẫu Excel: toàn bộ SQLite 240 bài/2.189 assertions đạt; JavaScript
  150 bài đạt. Riêng import: 10 bài/61 assertions đạt, gồm nhập file Excel mẫu thực,
  preview, giữ số 0 đầu và chặn công thức/ô số ở cột định danh/file nén vượt giới hạn.
- Mẫu Excel đã render và kiểm bố cục; file xuất có định dạng văn bản, freeze dòng 4,
  bảng dữ liệu và danh sách chọn loại khách. Nút tải mẫu local trả file `.xlsx` đúng.
  Kiểm HTTP preview file trống trả 422 đúng thông báo, không tạo dữ liệu.
- Lượt SQL Server sau thêm Excel đã dừng vì timeout; truy vấn chẩn đoán chỉ đọc cũng
  timeout. Không có kết quả đạt SQL cho thay đổi Excel này, không cập nhật máy khách.

### Lịch sử trước khi bổ sung Excel

- Bộ hồi quy SQLite sau bổ sung import: 237 bài, 2.173 assertions đạt.
- Sau bổ sung giữ IP/user-agent gốc trong nhật ký: 7 bài import, 45 assertions đạt.
- SQL Server cách ly, nhập file và phạm vi khách hàng: 9 bài, 65 assertions đạt
  sau thay đổi cuối. Không chạy lại toàn bộ SQL Server trong đợt tính năng này.
- JavaScript: toàn bộ 142 bài đạt; kiểm cú pháp file khách hàng đạt.
- Đã thử lỗi giữa lần tạo thứ hai để xác nhận rollback cả khách, liên hệ và audit;
  nhập lại không nhân đôi; preview không ghi; sai quyền, file quá nhiều dòng,
  sai encoding, cột không cho phép và trùng thông tin bị chặn.
- Chưa kiểm thao tác giao diện trực tiếp trên máy khách hoặc tải 300 dòng đồng thời.
