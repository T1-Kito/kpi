# Nhập người liên hệ và khách hàng tiềm năng

Hai màn hình có nút Nhập file, tải mẫu Excel .xlsx cùng giao diện khách hàng/bảo hành: tiêu đề xanh, bảng xen kẽ, cố định 4 dòng, nhập từ dòng 5. Giới hạn 300 dòng, 1 MB. Các trường hiện không có ngày; điện thoại và mã giữ dạng văn bản.

Người liên hệ: mã khách hàng, tên, điện thoại, email, chức vụ. Mã phải thuộc khách tổ chức đang hoạt động, chưa gộp và trong phạm vi phụ trách. Không tự tạo khách từ tên. Cần điện thoại hoặc email để chống trùng trong cùng khách. Dùng controller tạo liên hệ hiện có: liên hệ đầu tiên tự thành đầu mối chính theo quy tắc cũ, các liên hệ sau không đổi đầu mối chính.

Khách tiềm năng: tên, điện thoại, email, nguồn, mã chiến dịch. Tên/điện thoại bắt buộc. Nhập mới giao cho người đang nhập, qua LeadController/LeadService nên giữ audit, sự kiện và công việc follow-up. Không nhập tùy ý ID người dùng hoặc trạng thái/chuyển đổi khách.

Điện thoại chuẩn hóa khi so trùng (+84/0), email không phân biệt hoa thường. Kiểm tra trùng toàn tenant cho lead, không lộ hồ sơ ngoài phạm vi; liên hệ so trong khách đã được phép truy cập. Xem trước không ghi; xác nhận kiểm tra lại dưới khóa tenant và transaction. Có dòng sai thì không ghi cả file; không sửa đè dữ liệu cũ. Quyền customer.manage và sales.lead.manage tương ứng được kiểm tra phía API. Bộ đọc Excel chặn công thức, macro, liên kết ngoài, dữ liệu vượt giới hạn và điện thoại/mã dạng số.

Kiểm thử DirectoryImportTest + CustomerImportTest + WarrantyImportTest: 17 bài, 121 assertions PASS trên SQLite cách ly. Đã render xem cả hai mẫu. JavaScript được kiểm tra cú pháp. Chưa UAT trình duyệt/SQL Server, chưa đóng gói lại Setup. Mẫu không chứa dữ liệu khách thật.
