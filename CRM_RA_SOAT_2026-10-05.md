# Rà soát CRM — 05/10/2026

## Kết luận

Chưa đủ điều kiện khẳng định hoàn thiện theo bộ tài liệu CRM Sơn Tiên V1. Có nền tảng CRM bán hàng và vận hành nội bộ, nhưng tài liệu yêu cầu thêm CRM du lịch/dịch vụ: Customer 360, nhiều pipeline, marketing, booking/visit và quản trị dữ liệu. Có màn hình không đồng nghĩa đã đạt nghiệm thu đầu-cuối.

Đối chiếu bản trích nội dung sáu tài liệu trong crm_document_extract.txt với mã nguồn hiện tại. Đây là rà soát triển khai, không thay thế UAT do người dùng nghiệp vụ ký và không xác nhận đã thao tác mọi màn hình.

## Đã có nền tảng triển khai

- Lead và cơ hội; hàng đợi xử lý lead, chuyển đổi và theo dõi hành động.
- Khách hàng, người liên hệ; kiểm tra trùng và xử lý gộp hồ sơ.
- Báo giá, phê duyệt, đơn bán, hợp đồng, mẫu in và xuất chứng từ.
- Kho, nhập/xuất; yêu cầu mua, báo giá nhà cung cấp và đơn mua.
- Hóa đơn, công nợ, thu tiền; ticket và bảo hành.
- Công việc, cảnh báo, vai trò/quyền, tổ chức, dữ liệu nền, nhật ký.
- Tổng quan liên tục không chia tab: doanh thu hóa đơn, chứng từ bán hàng, khách hàng, đơn hàng/dòng hàng, kho, mua hàng, công nợ, công việc và KPI tham chiếu.

Các phần trên vẫn cần kiểm thử quyền, dữ liệu thực tế và tình huống lỗi cho từng luồng; không đánh dấu là đã nghiệm thu.

## Những khoảng thiếu hoặc cần hoàn thiện

| Nhóm | Việc cần hoàn thiện theo tài liệu |
|---|---|
| KPI — ưu tiên cao | Tính đúng kỳ, nhân viên/đội, dữ liệu đủ điều kiện và trọng số. Hiện buildMetrics lấy toàn bộ dữ liệu doanh nghiệp; tổng đơn bán gồm nháp/hủy vẫn được gọi là revenue. Kỳ snapshot chưa lọc dữ liệu đầu vào; mã KPI tự thêm mặc định actual=0. Không dùng điểm hiện tại để chốt thưởng/phạt. |
| Customer 360 | Hoàn thiện định danh nhiều nguồn, lịch sử tương tác, consent có nguồn/thời điểm, che trường nhạy cảm, phân biệt Person/Organization/Contact và nguồn chuẩn từng trường. Hồ sơ khách và gộp trùng mới là một phần của yêu cầu. |
| Pipeline | Cần bốn quy trình riêng: đoàn/trường học, doanh nghiệp/MICE, đại lý/DMC, khách lẻ/resort; điều kiện vào/ra, thời gian ở bước, lý do mất và điều kiện chốt thắng. Danh mục giai đoạn dùng chung chưa thay thế được các quy trình này. |
| Lead/SLA | Chứng minh tự phân công theo nguồn/địa bàn/nhóm, round-robin, lịch SLA, cảnh báo và chuyển cấp hoạt động tự động, không chỉ có hàng đợi trên giao diện. |
| Báo giá | Kiểm chứng phiên bản đã gửi không bị ghi đè; lịch sử gửi/duyệt, liên kết cơ hội và ngưỡng chiết khấu theo chính sách. Có nút in/xuất chưa đủ. |
| Marketing/Retention | Segment, campaign, audience, journey, suppression/opt-out, giới hạn tần suất, nhật ký gửi, attribution, CPL/CPA và chăm sóc quay lại chưa đủ bộ yêu cầu. |
| Service | Đã có mã xử lý bắt buộc khi resolved; còn phải kiểm chứng SLA phản hồi/giải quyết, escalation P1/P2, reopen, liên kết booking và báo cáo CSAT/VOC. |
| Master data | Bổ sung/chuẩn hóa các danh mục dịch vụ/gói/địa điểm, tổ chức/ngành/địa bàn, consent, lý do mất, hệ thống nguồn và trạng thái đồng bộ; mã bất biến, người sở hữu và ngày hiệu lực. |
| Dashboard/BI | Còn thiếu marketing, khách mới/quay lại, chất lượng dữ liệu, SLA, adoption và tình trạng đồng bộ; từ điển chỉ số và đối soát. Phễu hiện đếm chứng từ, không phải chuyển đổi cùng một nhóm khách. Doanh thu hóa đơn không tự động tương đương doanh thu kế toán. |
| Quyền và vận hành | Kiểm thử phạm vi cá nhân/đội/chi nhánh, quyền xuất dữ liệu và trường nhạy cảm; quy trình bàn giao ownership/khóa tài khoản; primary/backup/runbook. Tick quyền trên UI không đủ chứng minh an toàn. |
| UAT/Go-live | Kịch bản đầu-cuối, kiểm thử âm/quyền/trùng/lỗi phục hồi, tải, sao lưu/khôi phục, migration đối soát, đào tạo, ký nghiệm thu và phương án rollback. Test tự động không thay thế các bước này. |

## Tích hợp — đang để sau theo yêu cầu

Ticketing/QR, PMS/booking, POS/KingPOS, payment và các kênh marketing/access cần được chốt hệ thống nguồn, mapping và chủ sở hữu trước. Sau đó mới triển khai correlation ID, idempotency, retry, dead-letter/reprocess và monitoring. Không tự giả lập thành tích hợp đã hoàn thành.

## Thứ tự đề xuất

1. Chốt định nghĩa chỉ số và sửa KPI theo kỳ/người/trạng thái; bổ sung test tính điểm.
2. Hoàn thiện master data và Customer 360/consent.
3. Hoàn thiện các pipeline, SLA và version báo giá.
4. Marketing/retention, service SLA và báo cáo thiếu.
5. UAT quyền/luồng và vận hành; tích hợp sau khi mô hình dữ liệu đã thống nhất.

## Giao diện chỉnh trong lượt này

Tổng quan dùng nền xanh, xanh ngọc, tím và vàng rất nhạt ở thẻ chỉ số; giảm cỡ và độ đậm của số/tiêu đề. Danh sách đơn gần đây chiếm cả hàng để tránh ép tên khách/dòng hàng. Giữ một trang kéo xuống, không thêm tab.
