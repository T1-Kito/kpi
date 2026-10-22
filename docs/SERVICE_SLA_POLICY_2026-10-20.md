# Chính sách SLA chăm sóc khách hàng

## Cấu hình

Trong Thiết lập → Thời hạn công việc, chọn module **Chăm sóc khách hàng**, loại công việc **ticket**, ưu tiên tương ứng. Mỗi mức ưu tiên có một chính sách.

- Thời hạn phản hồi và xử lý: phút, phản hồi không lớn hơn xử lý.
- Cảnh báo trước hạn: phút làm việc đối với lịch làm việc; phút liên tục với chế độ liên tục.
- Lịch: múi giờ IANA, ngày trong tuần (1=thứ Hai, 7=Chủ nhật), các khoảng giờ tăng dần không chồng nhau/qua đêm, ngày nghỉ.
- UI ngày nghỉ dùng dd/mm/yyyy; API lưu ngày ISO. Không có lịch mặc định giả định.
- Chọn người nhận chuyển cấp có quyền xem ticket toàn doanh nghiệp. Người xử lý cũng được nhắc hạn. Không tự đổi người xử lý.
- Checkbox quản lý SLA công việc cũ không quyết định người nhận CSKH; CSKH dùng danh sách người nhận rõ ràng.

Chưa cấu hình chính sách: giữ SLA liên tục 4/8/24/72 giờ theo mức ưu tiên như trước; không cho tạm dừng. Hồ sơ cũ thiếu snapshot vẫn giữ quy tắc cũ. Không tính lại lịch sử khi sửa chính sách.

## Bảo toàn chính sách và lịch sử

Ticket mới lưu snapshot trong nhật ký hệ thống `sla_policy`, không cần thêm bảng/cột mới cho phần chính sách này. UI không hiển thị JSON kỹ thuật. Nhật ký người dùng không được đặt action hệ thống, tránh giả snapshot/tạm dừng/hoàn tất.

Nhập hạn riêng khi tạo ticket ghi đè hạn xử lý mặc định. Phản hồi được giới hạn không vượt hạn xử lý. Các ngày giờ lưu theo timezone ứng dụng (UTC); lịch tính theo timezone đã chọn và giao diện hiển thị theo trình duyệt.

## Tạm dừng và tiếp tục

Chỉ khi chính sách snapshot cho phép, ticket đang chờ thông tin khách, đã ghi nhận phản hồi đầu tiên và chưa quá hạn. Cần lý do; có audit và nhật ký. Không dừng tự động chỉ vì đổi trạng thái.

Khi tiếp tục: tính thời gian chờ nằm trong lịch được cấu hình, cộng phần thời gian đó vào hạn xử lý. Phản hồi đầu tiên đã hoàn tất không thay đổi. Không lặp thao tác pause/resume sai trạng thái; phải tiếp tục trước khi chuyển trạng thái. Không dùng tạm dừng để che SLA đã quá hạn.

## Nhắc hạn / chuyển cấp

Lệnh `php artisan service:check-sla` được đăng ký lịch mỗi phút. Cần chạy Laravel scheduler trên máy vận hành (`schedule:run` mỗi phút bằng tác vụ/dịch vụ được quản trị viên thiết lập). Bản portable hiện chưa tự đăng ký scheduler Windows: **không cam kết tự nhắc trên máy khách nếu chưa thiết lập và kiểm chứng tiến trình này**.

Kiểm tra hai hạn riêng; tạo cảnh báo và thông báo CRM cho người xử lý/người nhận hợp lệ. Khóa ticket trong giao dịch, không gửi trùng cùng ticket/người/loại hạn/mức cảnh báo. Không gửi email/Zalo. Bỏ qua ticket tạm dừng; cảnh báo của hồ sơ resolved/closed/cancelled được đánh dấu đã xử lý, vẫn giữ lịch sử. Khi mở lại có thể xem lại cảnh báo lịch sử, không tạo lại cùng cảnh báo đã gửi trước.

## Bằng chứng và giới hạn

Kiểm thử tự động có nghỉ trưa, cuối tuần, ngày nghỉ, timezone, snapshot không đổi theo cấu hình mới, pause/resume, chống giả nhật ký, chống dừng sau quá hạn, lọc quá hạn bỏ hồ sơ tạm dừng, không gửi trùng và kết thúc cảnh báo khi xử lý xong.

Chưa thử giao diện thực tế/SQL Server cho chính sách mới, chưa đóng gói thay đổi này, chưa cài scheduler trên máy khách. Không dùng kết quả SQLite để đánh dấu nghiệm thu SQL Server. Không sửa file yêu cầu gốc hay dữ liệu máy khách.
