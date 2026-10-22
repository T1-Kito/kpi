# Cập nhật từ trình quản lý

Luồng từ 0.8.4: tại màn hình quản lý bấm Cập nhật phần mềm → chọn Setup.exe mới ở ngoài thư mục CRM → xác nhận dừng → chỉ dừng process server do cửa sổ này quản lý → mở bộ cập nhật với thư mục đã điền và PID cần chờ → đóng trình quản lý cũ. Không tìm/kill process khác.

Bộ cập nhật chờ cửa sổ cũ thoát tối đa 30 giây và giữ cổng 8099. Nếu launcher vẫn đang được tiến trình khác giữ, kiểm tra quyền mở ghi độc quyền sẽ chặn trước khi thay database. Khách xác nhận thao tác cập nhật; không nhập lại SQL. Bộ cài phải là phiên bản ít nhất 0.8.4 và không thấp hơn trình quản lý đang chạy. Đây không phải xác thực chữ ký nhà phát hành; file người dùng chọn vẫn phải lấy từ nguồn tin cậy.

Setup đang chạy ngoài thư mục ứng dụng tự đưa bản sao chính nó vào stage dưới tên VK CRM.exe, kiểm tra hash, sao lưu launcher cũ và thêm vào nhật ký công bố cùng code. Gói ZIP nguồn không được phép mang launcher. File cấu hình, tài khoản, database, runtime và ảnh không bị chép đè. Lỗi sau thay launcher hoàn tác cả launcher và code. Phục hồi gián đoạn kiểm tra được backup launcher bằng hash trước khi khôi phục.

Sau thành công hiện nút Mở CRM; chạy launcher đã cập nhật --open và đóng updater. Launcher mới vẫn có nút cập nhật. Gói ứng dụng nhúng ở lượt này giữ bản nguồn 0.8.2, trình cài/quản lý 0.8.4.

Giới hạn còn mở: bản manager trước khi có nút này cần mở Setup ngoài thư mục ở lần chuyển đổi đầu tiên; không có cách làm nút mới xuất hiện trong EXE cũ mà chưa cập nhật EXE đó. SQL schema vẫn bị chặn vì restore/baseline chưa nghiệm thu. Không gắn nhãn quy trình SQL đã hoàn chỉnh. Baseline migration cũ khác gói hoặc composer.lock khác cũng chặn. Chưa có tải cập nhật từ Internet, chữ ký số, rollback đầy đủ SQL sau commit hay UAT máy khách.

Kiểm thử engine: 25 bài PASS trên fixture tổng hợp, gồm backup/thay launcher, lỗi sau khi thay launcher hoàn tác, chặn launcher từ thư mục ứng dụng và chặn launcher qua ZIP nguồn. Build Windows không lỗi/cảnh báo. Chưa thao tác trọn luồng desktop thực tế.
