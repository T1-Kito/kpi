# Tiến độ bộ xử lý nhập chung

## Đã bổ sung trong mã nguồn

- Migration `2026_10_22_110000_create_import_record_links`: nhận diện theo doanh nghiệp, nguồn, danh mục và mã ngoài. Một hồ sơ CRM chỉ được một liên kết nguồn sở hữu trong danh mục; không tự ghép khách từ hai nguồn.
- `ImportRevision`: lập kế hoạch thêm mới, bỏ qua lần nhập lặp, cập nhật phiên bản mới. Ô trống giữ nguyên, số 0 là giá trị cần cập nhật. Trả thay đổi trước/sau.
- Chặn phiên bản cũ, cùng phiên bản khác nội dung, hồ sơ đã sửa thủ công và cập nhật danh mục chỉ cho phép tạo mới.
- Kiểm thử độc lập quy tắc phiên bản và ràng buộc liên kết; các thử nghiệm dùng database cách ly.

## Chưa đưa vào luồng đang vận hành

Đã nối quy tắc vào adapter `CustomerSourceImport` và API lô nhập với danh mục kỹ thuật `customers-v1`. Adapter dùng controller nghiệp vụ để tạo/cập nhật, ghi mapping trong giao dịch chung, giới hạn trường, giữ ô trống, kiểm tra trùng trong file/toàn tenant và khóa hồ sơ liên kết. Xác nhận kiểm tra lại snapshot đã duyệt. Chưa bật danh mục này trong danh sách lựa chọn trên giao diện vì mẫu và màn hình trước/sau chưa hoàn thiện; các lựa chọn cũ vẫn chỉ thêm mới.

Kiểm thử tích hợp đã xác nhận: xem trước không ghi, tạo mới có mapping, cập nhật cùng mã không tăng số khách, ô trống giữ điện thoại, phiên bản cũ bị chặn, sửa tay bị chặn và thay đổi sau xem trước không ghi dữ liệu hay revision. Chưa xác nhận SQL Server cho adapter này; hiện còn giới hạn 300 dòng/1 MB. Kiểm tra trùng đang duyệt danh sách theo từng dòng, cần tối ưu trước khi nâng lên 1.000 dòng.

Migration mới chưa áp dụng vào database local đang dùng, chưa duyệt bổ sung vào danh sách nâng cấp bộ cài. Cơ chế cập nhật hiện tại sẽ chặn an toàn nếu gặp migration chưa được duyệt. Không đóng gói bản giao khách khi chưa hoàn tất bước này.

## Thứ tự tiếp theo

### Bật khách tiềm năng theo nguồn

Đã thêm mẫu `khach-tiem-nang-theo-nguon-v1.xlsx`, tải mẫu theo quyền, hiện lựa chọn `leads-v1` khi schema liên kết sẵn sàng, xem nguồn/chiến dịch và lưu ý chỉ tạo mới. Mẫu tiếng Việt đã render kiểm tra. 5 kiểm thử PHP/42 xác nhận đạt (mẫu, quyền, tạo lead/việc, nhập lặp, chống cập nhật/trùng, rollback). Không có migration mới và chưa đóng gói; toàn bộ tính năng chưa thay thế yêu cầu UAT trình duyệt thật.

### Adapter khách tiềm năng chỉ thêm mới

Đã nối `LeadSourceImport` vào API lô với danh mục kỹ thuật `leads-v1`, hiện ẩn khỏi lựa chọn UI đến khi có mẫu riêng và kiểm tra SQL. Hợp đồng gồm schema_version/external_id/source_revision/name/phone/email/source/campaign_code. Chỉ tạo mới qua controller Lead để giữ phân công và việc chăm sóc; không nhận cột vai trò, mật khẩu, trạng thái hay assignee từ file. Nhập lặp không tạo thêm, revision mới không cập nhật hồ sơ cũ theo đúng phạm vi tài liệu. Trùng điện thoại/email theo tenant và trong file bị chặn, xác nhận kiểm tra lại dữ liệu.

Nhóm kiểm thử xác nhận xem trước không ghi, tạo đúng assignee/việc chăm sóc, xác nhận lặp, cùng phiên bản khác nội dung, cấm cập nhật, trùng phát sinh sau xem trước và rollback khi lỗi sau ghi nghiệp vụ. Chưa có mẫu riêng, chưa bật UI, chưa kiểm thử SQL cho adapter này; không có migration mới.

### Bật người liên hệ theo phiên bản trên local

Đã có mẫu tiếng Việt `nguoi-lien-he-theo-nguon-v1.xlsx`, endpoint tải có kiểm tra quyền, lựa chọn `contacts-v1` khi bảng liên kết đã sẵn sàng và màn hình xem trước có tên/mã khách, chức vụ, thay đổi trước/sau. Mẫu đã render để kiểm tra. Không có migration mới cho bước này, chưa đóng gói.

6 kiểm thử liên hệ/50 xác nhận đạt trên SQLite cách ly. Thử SQL tổng hợp `VKCRM_CustomerImport_20261022_061556_ab8494` đạt cả khách hàng và 5 bước liên hệ: xem trước không ghi và phân giải cha cùng nguồn, tạo dưới đúng cha, cập nhật giữ ô trống/không tăng số dòng, xác nhận lặp và chặn sửa tay sau xem trước. Đây không thay thế trình duyệt thật, tải đồng thời, quyền tài khoản SQL khách hoặc nghiệm thu bộ cài.

### Adapter người liên hệ theo nguồn

Đã nối `ContactSourceImport` vào API lô qua danh mục kỹ thuật `contacts-v1`. Mẫu hợp đồng gồm schema_version/external_id/source_revision/customer_ref/name/phone/email/position. Khách cha dùng ext: hoặc crm:, phải được nhập trước. Không tự tạo khách, không chuyển liên hệ đã liên kết sang khách khác; giữ trạng thái liên hệ chính hiện tại khi cập nhật. Dữ liệu nghiệp vụ ghi qua controller, mapping và trạng thái lô cùng giao dịch; giữ ô trống, kiểm tra trùng theo khách, kiểm tra sửa tay/revision.

Danh mục mới còn ẩn trên giao diện cho đến khi có mẫu và phần xem trước dành riêng cho người liên hệ. Chưa kiểm thử SQL Server cho adapter liên hệ, chưa đóng gói. Phần khách hàng trước đó đã bật local không bị thay đổi giới hạn. Trình duyệt thật vẫn bị chặn bởi Work settings.

### Tham chiếu khách hàng cho người liên hệ

Đã bổ sung `ImportCustomerReference`: chỉ nhận `ext:mã-ngoài` trong cùng doanh nghiệp/nguồn/danh mục khách hàng hoặc `crm:mã-khách`. Không tự tìm theo tên, ID hay nguồn khác. Chỉ nhận khách doanh nghiệp đang hoạt động, chưa gộp, đúng scope. Kiểm tra nguồn active, membership và quyền ngay khi phân giải. Chưa nối thành chức năng nhập người liên hệ theo phiên bản.

4 kiểm thử/13 xác nhận đạt cho tham chiếu, thiếu liên kết, sai nguồn, khách không hợp lệ và thu hồi membership. Trình duyệt tự động hiện bị tắt trong Work settings; kiểm thử trình duyệt thật chưa thực hiện, không coi bộ test DOM là thay thế cho UAT.

### Kiểm thử SQL Server cho khách hàng theo nguồn

Script `scripts/test-customer-source-sqlserver.php` tạo database tổng hợp riêng `VKCRM_CustomerImport_20261022_060828_650fce`, không dùng hoặc sửa cấu hình database khách. Đã đạt 8 bước: migration đầy đủ, xem trước không ghi, thêm mới cùng mapping, lập kế hoạch trước/sau, cập nhật giữ điện thoại khi ô trống, xác nhận lặp không thêm khách, chặn revision cũ, chặn sửa tay sau xem trước và không tăng revision mapping. Database thử được giữ lại để đối chiếu. Đây không phải kiểm thử trình duyệt/tải đồng thời hoặc xác nhận quyền sao lưu của tài khoản SQL tại khách.

Nhóm tích hợp khách theo nguồn đã mở rộng thành 6 kiểm thử/60 xác nhận, gồm cùng phiên bản khác nội dung, nguồn thứ hai không nhận khách đã tồn tại và lỗi ghi giữa chừng phải hoàn tác cả khách, mapping và trạng thái lô. Đã sửa giữ nguyên created_at của mapping khi cập nhật; cần duy trì kiểm thử này trong các adapter sau.

### Cập nhật sau khi chạy lại kiểm thử

- Nhóm mới đã đạt: 11 kiểm thử PHP/113 xác nhận (lô nhập và khách theo nguồn), 5 JavaScript, gồm DOM trước/sau và nút tải lỗi. Kiểm thử tải mẫu đã sửa lỗi giữ token trong setup và chạy lại đạt.
- Tải danh sách lỗi .xlsx qua API riêng, chỉ chủ lô có quyền nguồn/danh mục hiện tại; cache private/no-store, không công thức, ghi nhật ký. File chứa số dòng và lỗi để sửa trong file gốc, không phải file tự nhập lại.
- Đã nâng local sau thử trên bản sao và sao lưu `crm-20261022-044358-2a8417c3.sqlite`; số lượng dữ liệu nghiệp vụ, integrity/FK và phiên đăng nhập đều giữ nguyên.
- API local dưới Admin thường trả 200, có `customers-v1` trong danh sách. Chưa kiểm tra trình duyệt thật, Excel desktop hoặc SQL Server cho adapter mới. Chưa đóng gói và chưa duyệt migration liên kết vào updater.

### Bổ sung giao diện và mẫu

Đã thêm mẫu `khach-hang-theo-nguon-v1.xlsx` (11 cột cơ bản, tiêu đề tiếng Việt, trang hướng dẫn; chưa phải schema đầy đủ 16 cột của mẫu tham chiếu). Mẫu đã được render kiểm tra. Thêm endpoint tải mẫu có kiểm tra quyền và màn hình phân loại thao tác, xem trước/sau. Danh mục phiên bản chỉ hiện khi database có bảng liên kết.

Thử nâng cấp trên bản sao local thành công, backup `crm-20261022-043457-caa36f2c.sqlite`; chưa áp dụng database local đang dùng. Nhóm 9 kiểm thử khách theo nguồn/lô nhập và 3 JavaScript đã đạt trước khi thêm kiểm thử tải mẫu. Lần kiểm thử bổ sung phát hiện test giữ token từ setup; đã sửa nhưng chạy lại bị Windows hết bộ nhớ/pagefile. Chưa xác nhận nhóm mới đạt, chưa bật local, chưa đóng gói. Cần chạy lại kiểm thử tải mẫu, roundtrip từ mẫu thực tế, thêm kiểm thử DOM trước/sau, tải file lỗi và SQL Server.

1. Chuẩn hóa hợp đồng mẫu theo gói tham chiếu: schema_version, external_id, source_revision và trường danh mục.
2. Adapter khách hàng: kiểm tra trùng khóa nghiệp vụ nhưng không tự nhận hồ sơ cũ làm liên kết, kiểm tra scope, before/after, snapshot khi xem trước, giao dịch khi xác nhận và nhật ký.
3. Xuất file lỗi và xem trước đầy đủ; giữ luồng mẫu cũ tương thích riêng.
4. Người liên hệ và các danh mục cha; tham chiếu ext:/crm: và quy tắc nhập cha trước.
5. Các danh mục còn lại; người dùng tạo ở trạng thái chưa kích hoạt, không vai trò và không mật khẩu trong Excel.
6. Kiểm tra SQL Server, sao lưu/phục hồi, rà lại nâng cấp bộ cài và UAT trước khi đóng gói.
