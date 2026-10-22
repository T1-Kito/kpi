# Việc chăm sóc và hẹn lần tiếp theo

## Thao tác mới

Khách hàng → Việc chăm sóc: bốn nhóm Quá hạn, Hôm nay, Sắp tới, Chưa có lịch. Mỗi trang 20 dòng, sắp theo hẹn rồi ID. Mở chăm sóc từ dòng khách để tạo lịch hoặc ghi kết quả. Ngày tính theo Asia/Ho_Chi_Minh; việc quá hạn trong hôm nay có thể thuộc cả hai nhóm. Quá hạn/hôm nay/sắp tới đếm công việc; chưa có lịch đếm khách.

Chỉ khách hoạt động, chưa gộp và trong phạm vi dữ liệu của nhân viên xuất hiện. Chưa có lịch nghĩa là không có công việc customer_follow_up đang new/in_progress/overdue; lịch đã hoàn tất không ngăn khách xuất hiện trong nhóm này. Khách chưa được phân công vẫn được liệt kê để nhận biết, nhưng cần phân công người hoạt động có quyền công việc trước khi tạo lịch.

Ghi kết quả có thêm Việc tiếp theo/Hẹn tiếp. Không nghe máy bắt buộc cả hai. Các kết quả khác cho phép không hẹn tiếp; khách đó sẽ xuất hiện trong nhóm chưa có lịch nếu không còn việc mở. Không tự suy đoán ngày hẹn.

Tạo lịch tiếp theo và hoàn tất việc cũ dùng chung transaction, cùng khóa khách/công việc. Lỗi phân công/validation không kết thúc việc cũ. Gửi lặp sau hoàn tất trả 409 và không tạo thêm lịch. Dùng TaskService giữ audit, history và thông báo hiện có. Thời gian datetime-local được chuyển ISO có múi giờ trước khi gửi.

## Giới hạn

Không thêm bảng/cột/migration. Chưa đóng gói vào 0.8.7 đã phát hành. Chưa UAT giao diện hoặc SQL Server cho thay đổi này. Thông báo nhắc quá hạn tự động vẫn cần scheduler vận hành, không đồng nghĩa thông báo tạo lịch là đã có nhắc đúng giờ. Chưa gửi email/Zalo hoặc đặt lịch định kỳ tự động.
