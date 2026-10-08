# Luồng khách hàng và người liên hệ

- Ba màn hình là các góc nhìn khác nhau: lead theo dõi nhu cầu, khách hàng là hồ sơ giao dịch, danh bạ quản lý đầu mối của tổ chức.
- Khách cá nhân không tạo tổ chức/người đại diện giả. Chuyển lead mặc định cá nhân; người dùng xác nhận tổ chức nếu phù hợp.
- Chuyển lead có thể chọn khách đang hoạt động trong phạm vi xem. Nếu không chọn, tìm theo điện thoại/email trên khách và danh bạ; nhiều kết quả thì chặn để xác nhận, không tự gộp. Hồ sơ ngoài phạm vi phụ trách cần quản lý xử lý.
- Chuyển đổi giữ liên kết lead–khách–cơ hội, không tạo cơ hội trùng khi lặp lại.
- Người liên hệ thuộc một tổ chức. Form sửa khách chọn được người liên hệ của chính tổ chức đó; không lấy người thuộc khách khác.
- Danh bạ là nguồn chuẩn của liên hệ chính. Các trường contact_name/phone/email của khách tổ chức là bản chiếu tương thích cho màn hình và chức năng cũ. Sửa các trường này trong form khách cập nhật người liên hệ chính; thay đổi người chính trong danh bạ cập nhật lại khách.
- Hồ sơ tổ chức cũ chưa có danh bạ: lần lưu khách với tên liên hệ sẽ tạo liên hệ chính. Không chạy cập nhật hàng loạt trên dữ liệu thật.
- Không cho đổi tổ chức có danh bạ thành cá nhân vì sẽ bỏ rơi liên kết. Cần xử lý dữ liệu có chủ đích trước.
- Gộp khách giữ danh bạ và làm mới thông tin liên hệ chính; không tự gộp người liên hệ dựa vào tên giống nhau.
- Báo giá mới tiếp tục kế thừa contact_id và chụp thông tin tại lúc gửi duyệt theo workflow báo giá. Không ghi lại nội dung chứng từ đã chốt.

Chưa bao gồm tự chia lead theo nguồn/nhóm, consent, hay liên hệ đa tổ chức. Không coi các chức năng này đã hoàn thành.
