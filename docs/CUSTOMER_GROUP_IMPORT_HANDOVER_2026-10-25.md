# Nhập khách hàng có phân nhóm — hướng dẫn kiểm tra ngày 25/10/2026

## Đã nối trong bản local

- Danh mục nhóm 3 cấp → mẫu khách hàng tải từ `/api/v1/import-center/customer-template`.
- Ba cột O/P/Q: Nhóm khách hàng cấp 1, Nhóm khách hàng cấp 2, Nhóm khách hàng cấp 3.
- Có danh sách xổ xuống theo từng cấp, lấy nhóm đang hoạt động của đúng công ty. Kiểm tra đúng quan hệ cha/con ở máy chủ khi nhập. Excel chưa xổ xuống phụ thuộc theo nhánh.
- Xem trước có đường dẫn phân nhóm và tên trường thay đổi bằng tiếng Việt.
- Xác nhận nhập lưu `customer_group_id`; trang xem/sửa và lọc theo nhóm cha dùng cùng liên kết.
- Cả 3 cột trống khi cập nhật: giữ nhóm cũ. Muốn đổi nhóm: nhập lại đường dẫn đầy đủ từ cấp 1. Không dùng Excel để xóa nhóm hiện tại.
- Nhóm sai nhánh, chưa tồn tại, công ty khác hoặc ngừng dùng bị chặn. Có lỗi ở một dòng thì chưa ghi lô nhập.
- Mẫu khách hàng cũ không có nhóm vẫn được nhận; mã ngoài và cơ chế cập nhật theo nguồn giữ nguyên.
- Tại trang Nhóm khách hàng có thể chọn nguyên file `NHẬP LIỆU - PHÂN LOẠI (1).xlsx`: bộ đọc nhận diện riêng A4:C4, chỉ lấy bảng phân nhóm A:C ở trang đầu. Đã thử đủ 25 đường dẫn → 52 nhóm, không tạo hồ sơ khách hàng mẫu.

## Các bước cho người dùng

1. Đăng nhập Admin/Chủ tịch, mở Khách hàng → Nhóm khách hàng → Nhập Excel.
2. Chọn file phân loại khách gửi, xem trước 25 đường dẫn và số nhóm mới; chỉ xác nhận sau khi soát tên. File có lỗi chính tả “Daonh nghiệp trong nước”, cần thống nhất tên trước khi dùng chính thức. Không tự sửa dữ liệu mẫu của khách.
3. Vào Danh sách khách hàng → Nhập file → Tải mẫu mới. Không dùng bản đã tải trước khi bổ sung 3 cột nhóm.
4. Điền hồ sơ khách thật, loại Cá nhân/Doanh nghiệp, ít nhất một thông tin nhận diện; điền phân nhóm tùy chọn. Email người phụ trách phải là tài khoản đang hoạt động trong công ty.
5. Kiểm tra trước 3–5 khách, xác nhận, mở hồ sơ và lọc nhóm để đối chiếu. Sau đó mới nhập số lượng lớn. Giới hạn 300 dòng/1 MB mỗi lần.
6. Khi cập nhật, giữ mã ngoài và dùng cùng nguồn nhập. Không có mã ngoài thì không tự nhận hồ sơ cũ để cập nhật.
7. Khách do Admin nhập mặc định là dữ liệu riêng của người nhập. Chỉ gán người phụ trách không tự bật dùng chung. Nếu muốn các tài khoản khác nhìn thấy/chọn khách, người tạo hoặc Admin/Chủ tịch cần bật Dùng chung.

## Giới hạn cần nói rõ khi demo

Sheet PHÂN NGUỒN DỮ LIỆU của file gốc là bảng tổng hợp 34 cột, KHÔNG phải mẫu khách hàng hiện hành. Chưa có thao tác nhập một lần để tạo đồng thời khách hàng, người liên hệ, cơ hội và công việc từ sheet này. Không được chọn sheet này rồi kỳ vọng toàn bộ thông tin được nhập.

| Nhóm cột trong file gốc | Nơi lưu/nghiệp vụ | Tình trạng |
| --- | --- | --- |
| Mã KH, ngày tạo | Mã nội bộ, thời gian tạo hệ thống | Tự sinh; mã cũ nếu cần đối chiếu đưa vào Mã ngoài |
| Đối tượng cấp 1–3 | Danh mục nhóm và khách hàng | Đã nối qua 3 cột nhóm của mẫu khách hàng |
| Tên khách hàng, tên đơn vị, MST, điện thoại, email | Hồ sơ khách | Mẫu hiện tại nhận tên hồ sơ, MST, điện thoại, email; cần chọn tên cá nhân/tổ chức rõ ràng |
| Người liên hệ, chức danh | Người liên hệ thuộc khách | Có luồng nhập liên hệ riêng; mẫu khách tiêu chuẩn chưa nhận chức danh liên hệ |
| Tỉnh/TP | Địa chỉ khách | Chưa có ánh xạ tỉnh/thành riêng từ sheet tổng hợp |
| Nhóm SP cấp 1–2, sản phẩm quan tâm | Danh mục dịch vụ và nhu cầu cơ hội | Chưa nối tự động từ sheet tổng hợp |
| Kênh tiếp cận cấp 1–2, nguồn khách, kênh liên hệ | Nguồn/kênh của lead và cơ hội | Có nguồn cơ bản, chưa đủ phân tầng/ánh xạ theo file |
| Mục đích, quy mô nhóm, số khách dự kiến, ngày dự kiến, ngân sách, yêu cầu đặc biệt | Nhu cầu của từng cơ hội | Cần bổ sung cấu trúc riêng, không dồn vào khách |
| Trạng thái CRM, phụ trách, lịch liên hệ | Lead/cơ hội/công việc | Có nền nghiệp vụ riêng, chưa nhập tổng hợp từ 34 cột |
| Giá trị cơ hội, xác suất, doanh thu dự kiến | Cơ hội và dự báo | Có giá trị/xác suất; chưa có nhập trực tiếp sheet tổng hợp |
| Ghi chú | Hồ sơ/cơ hội tương ứng | Cần xác định theo dòng đại diện cho khách hay cơ hội, không tự đoán |

## Kiểm tra còn lại trước nghiệm thu

### Kết quả chốt local

- Toàn bộ PHP: **372 test đạt, 0 lỗi, 3563 assertions**, 138.40 giây (`.tmp/full-suite-final.xml`).
- Toàn bộ JavaScript: **191 test đạt, 0 lỗi**.
- Đã điền 5 khách giả lập vào chính mẫu tải xuống: xem trước không ghi, xác nhận thêm đúng 5, lọc nhóm cha ra 5 và nhóm cấp 3 ra 3.
- Đã xử lý 9 ca lỗi cũ và test bảo toàn hướng dẫn Excel. Không nới quyền xem dữ liệu riêng để làm test đạt.
- Không nhập các hồ sơ giả lập vào database local đang dùng. Không reset mật khẩu, không triển khai lên máy khách.

Kết quả bộ test liên quan sau khi nối: 68 test PHP đạt (584 assertions), 191 test JavaScript đạt. Có test điền chính file mẫu tải xuống rồi xem trước/xác nhận/lọc hồ sơ, tương thích hash của liên kết cũ, giữ nhóm ngừng dùng khi để trống và rollback khi lưu thất bại.

- Thử trên SQL Server/phiên bản cài đặt đúng máy khách; các test tự động ở đây chạy SQLite bộ nhớ riêng.
- Đã rà 9 ca lỗi trước đó: cập nhật fixture người tạo theo quy tắc dùng chung, giữ kiểm tra chặn khách riêng; sửa tra cứu MST để nhận biết hồ sơ ẩn nhưng không trả dữ liệu và không gọi dịch vụ ngoài. Đã bổ sung test luồng lead không tạo trùng khách ẩn và ghi người tạo khi service chạy không qua HTTP. Bộ 27 test từng có lỗi đã chạy đạt; xem kết quả chạy toàn bộ ở báo cáo mới nhất trước khi nghiệm thu.
- Nếu khách muốn nhập cả sheet 34 cột, cần chốt khóa khách/cơ hội, quy tắc chuyển đổi và lưu dữ liệu nhu cầu trước khi triển khai; không bỏ lặng các cột chưa hỗ trợ.
