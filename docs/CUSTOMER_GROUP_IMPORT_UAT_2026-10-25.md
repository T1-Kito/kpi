# Kiểm tra nhập nhóm khách hàng — 25/10/2026

> Báo cáo dưới đây là kết quả trước khi nối mẫu khách hàng. Bản cập nhật sau đó đã hỗ trợ nhận diện bảng phân nhóm trong file gốc và nối 3 cột nhóm vào mẫu khách hàng. Xem CUSTOMER_GROUP_IMPORT_HANDOVER_2026-10-25.md để biết tình trạng hiện hành. Sheet tổng hợp 34 cột vẫn chưa có bộ nhập đồng thời khách/liên hệ/cơ hội.

## Kết luận trước buổi khách thử

- Chưa hỗ trợ chọn nguyên file `NHẬP LIỆU - PHÂN LOẠI (1).xlsx` để nhập. API trả 422 và không ghi dữ liệu.
- Mẫu được hỗ trợ là Excel 3 cột: Nhóm cấp 1, Nhóm cấp 2, Nhóm cấp 3.
- Đã lấy đủ A5:C29 của sheet đầu file khách gửi làm dữ liệu kiểm thử: 25 đường dẫn tạo 52 nhóm, trong đó 7 nhóm gốc. Nhập lại tạo thêm 0 nhóm.
- Đây chỉ là nhập danh mục phân nhóm, không nhập khách hàng, kênh tiếp cận, dịch vụ hay cơ hội từ các bảng khác của file gốc.
- Test chạy SQLite bộ nhớ riêng, không nhập thử vào database đang dùng. Chưa xác nhận trên SQL Server của khách.

## Các trường hợp đã kiểm tra

1. Xem trước không ghi dữ liệu; xác nhận mới lưu.
2. Tự tạo nhóm cha còn thiếu, dùng lại cha đã có.
3. Nhập nhóm một, hai, ba cấp trong cùng file.
4. Cắt khoảng trắng đầu/cuối và đối chiếu không phân biệt hoa thường.
5. Cùng tên ở hai nhánh khác nhau không bị gộp nhầm.
6. Trùng đường dẫn trong cùng file bị chặn.
7. Thiếu cấp 1, bỏ cấp giữa và tên quá 100 ký tự bị chặn toàn file.
8. Nhóm cha ngừng dùng không nhận thêm nhánh qua nhập Excel.
9. File trống, thiếu file, sai đuôi, hỏng ZIP, sai tiêu đề bị chặn.
10. File hơn 1 MB, hơn 300 dòng bị chặn; 300 dòng hợp lệ được xem trước.
11. Công thức, macro và thành phần liên kết ngoài bị chặn.
12. Bản xem trước sửa giả, hết hạn, khác tài khoản/công ty bị chặn.
13. Danh mục thay đổi sau xem trước hoặc gửi lại xác nhận đã ghi bị chặn.
14. Nhập lại cùng danh mục không tạo trùng.
15. Công ty khác có nhóm cùng tên không cản công ty hiện tại.
16. Nhân viên xem danh mục được, không được nhập hay xác nhận.
17. Giới hạn 10 yêu cầu xem trước/phút được áp dụng.
18. Mẫu tải xuống có tiêu đề và hướng dẫn tiếng Việt, trang có điều hướng.

## Việc còn cần chốt

Trước khi khách thử, cần một trong hai lựa chọn: chuẩn bị bản 3 cột từ file gốc, hoặc phát triển bộ đọc riêng cho cấu trúc file gốc và kiểm thử lại. Không hứa nhập nguyên file nhiều bảng bằng chức năng đang có.

## Kết quả thực thi

- `php artisan test --filter=CustomerGroupImportTest`: 10 đạt, 86 assertions. Bao gồm cả xác nhận file gốc bị từ chối đúng như hợp đồng hiện tại, không phải file gốc được hỗ trợ.
- `node --test tests/js/*.test.cjs`: 191 đạt, 0 lỗi.
- `php artisan test`: 354 đạt, 9 lỗi, 3337 assertions, 137.97 giây.
- Các bài lỗi: ChairmanCareReportTest (2), ChecklistFindingVerificationTest (1), CrmInheritanceTest (1), CustomerCareTest (3), CustomerContactConsistencyTest (1), CustomerPersonnelBoundaryTest (1).
- Đã chạy riêng CustomerCareTest và CrmInheritanceTest để xác nhận lỗi tái hiện độc lập. Có trường hợp dữ liệu test chỉ gán sales_owner_id, không gán created_by theo quy tắc dùng chung mới nên không còn nhìn thấy khách. Chưa kết luận tất cả 9 lỗi chỉ là fixture. Cần rà từng ca trước khi nghiệm thu toàn hệ thống.
- Lượt này chỉ bổ sung kiểm thử và báo cáo; không đổi quyền hay bộ đọc để nhận nguyên file gốc.
