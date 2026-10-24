# Tối ưu phản hồi local

## Đo trước và sau

Đo HTTP GET với cùng tài khoản Admin thường, cùng database local và các endpoint `/customers?page_size=20`, `/leads?page_size=20`, `/skus?page_size=20`. Không đo mật khẩu hoặc ghi dữ liệu nghiệp vụ. Mỗi phản hồi sau tối ưu được kiểm tra là JSON thành công có trường data, không lấy trang lỗi làm số đo.

- Trước: khách hàng 551/438 ms, khách tiềm năng 290/426 ms, SKU 279/337 ms.
- Sau bật OPcache và tối ưu quyền: hai lượt đo trên cổng 8000 lần lượt 178/184, 142/162, 113/174 ms và 141/121, 101/88, 129/126 ms. Đây là mẫu local nhỏ, không phải p95 hoặc cam kết tốc độ máy khách. Lần khởi động lạnh còn tốn thời gian nạp cache.
- 20 lần kiểm tra quyền: trước 20 truy vấn, sau snapshot trong một request là 0 truy vấn bổ sung. Middleware vẫn xác minh token/trạng thái và nạp quyền từ database ở mỗi request mới. Không cache quyền lâu dài giữa các request/tài khoản.

## Thay đổi

- Local chạy bằng `scripts/Start-LocalOptimized.ps1`, chỉ loopback, không thay php.ini toàn máy. Bật OPcache đúng runtime, giữ kiểm tra timestamp để sửa code có hiệu lực; không tắt kiểm tra file.
- Bộ xây runtime mới kèm OPcache; launcher bật khi có DLL phù hợp và không tự thay runtime của bản khách đang dùng. Chưa đóng gói bản cài/cập nhật mới.
- Chat ngoài trang chat giãn từ 5 lên 30 giây; trong chat vẫn 5 giây. Không gửi lại read receipt cho cùng tin đã đánh dấu thành công. Thông báo giãn 30 giây, ngừng poll khi tab ẩn/chuyển trang. Độ trễ huy hiệu tin nhắn/thông báo nền có thể đến 30 giây; đây không phải push realtime.
- Hover-prefetch và click điều hướng dùng chung một yêu cầu HTML đang chạy. Lỗi tải cho phép thử lại; không giữ Promise lỗi. Có thanh tiến trình và aria-busy khi chuyển trang, không khóa màn hình giả để che thời gian xử lý.

## Giới hạn

Kiểm thử cuối: 55 kiểm thử PHP/502 xác nhận đạt (phân quyền, phạm vi, ba nhóm khách hàng, sản phẩm, chăm sóc, cài tài khoản); toàn bộ 182 kiểm thử JavaScript đạt. Bộ cài biên dịch thành công, chưa phát hành Setup mới. Máy chủ local 8000 đã khởi động lại bằng cấu hình tối ưu và trả /up = 200; máy chủ đo tạm 8001 đã dừng.

PHP built-in server trên Windows vẫn xử lý đơn luồng, không phải kiến trúc production nhiều người dùng. Giảm request nền và bật cache giúp local nhưng không thay cho kiểm thử tải/concurrency hoặc triển khai máy chủ phù hợp trước bàn giao nhiều người dùng. Chưa nghiệm thu độ mượt trong trình duyệt thật hoặc đo trên máy sếp. Không thay đổi SQL Server/database, không xóa tài khoản hay dữ liệu.

Tham chiếu cấu hình: https://www.php.net/manual/en/opcache.configuration.php và giới hạn máy chủ tích hợp https://www.php.net/commandline.webserver .
