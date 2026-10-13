# Đọc và đối chiếu tài liệu yêu cầu CRM ngày 13/10/2026

## Nguồn đã đọc

- `C:/Users/Admin/Downloads/Vigilance_SonTien_CRM_Master_Checklist_NghiemThu (1).xlsx`: tất cả 4 sheet, bao gồm giá trị, công thức, giá trị cache, validation và hàng không hiển thị nội dung. Không sửa file gốc.
- `C:/Users/Admin/Downloads/Vigilance_SonTien_CRM_Document_Set_V1/`: 6 file Word từ 00 đến 05, toàn bộ đoạn văn và bảng.
- `C:/Users/Admin/Downloads/VK-CRM_Tai-lieu-thiet-ke-chi-tiet-va-README-phat-trien.docx`: phiên bản 3.0 ngày 11/09/2026, toàn bộ 21 chương, 50 bảng, 2.504 đoạn có nội dung. Đã kiểm tra footnotes/endnotes: không có nội dung bổ sung; không có media trong gói.

Đây là biên bản đọc yêu cầu và đối chiếu sơ bộ, không phải kết quả UAT hay xác nhận nghiệm thu.

## Các sheet Excel

| Sheet | Nội dung đã đọc | Kết luận |
|---|---|---|
| Tổng hợp | 52 công thức, số lượng hạng mục, trạng thái, PASS/FAIL, bằng chứng và nhóm nghiệp vụ | Cache ghi 95 hạng mục, 84 Chưa làm, 11 TBD, 0 Hoàn thành. Đây là trạng thái trong tài liệu, không phải kiểm kê trạng thái code hiện tại. |
| Master Checklist | 95 yêu cầu, cột A:N, điều kiện nghiệm thu, mức độ, người phụ trách, test, bằng chứng, người nghiệm thu | 25 nhóm nghiệp vụ; tất cả hạng mục được đánh dấu Bắt buộc. |
| UAT & Nghiệm thu | 31 mục chọn lọc, mã STT liên kết logic với Master Checklist | Các giá trị được nhập riêng, không có công thức đồng bộ với Master Checklist. |
| Danh mục | Trạng thái, mức độ và kết quả kiểm thử | Là danh mục phục vụ checklist, không phải bộ master data nghiệp vụ của CRM. |

### Lỗi và hạn chế của file checklist

1. Bảng thống kê nhóm trong Tổng hợp không có API (10 mục), Báo cáo (6), KPI (1), SLA (1). Tổng 95 hạng mục vẫn đúng, nhưng thống kê theo nhóm thiếu 18 mục.
2. `UAT & Nghiệm thu!D7` trống ở mục STT 23 Ticket; mô tả chuẩn ở `Master Checklist!D27` là “Tạo ticket theo loại vấn đề”.
3. `UAT & Nghiệm thu!D8` ở mục STT 26 SLA lại chứa “Tạo ticket theo loại vấn đề”; mô tả chuẩn ở `Master Checklist!D30` là “Thiết lập thời gian phản hồi/xử lý”.
4. Header `UAT & Nghiệm thu!D3` còn là “Cột 4”.
5. Các dòng trạng thái/test/bằng chứng ở hai sheet không tự đồng bộ. Không dùng một sheet để suy ra sheet kia đã nghiệm thu.

### 11 nội dung TBD trong Master Checklist

STT 46 quyền xuất dữ liệu; 50 trường bắt buộc; 55 API khách hàng; 56 API booking; 57 API thanh toán; 58 API ticket; 59 Webhook/Event; 71 ngưỡng KPI theo vị trí; 72 ngưỡng SLA; 91 thời gian hypercare; 95 danh sách TBD còn lại.

## Hai phạm vi cần phân biệt

Bộ Sơn Tiên V1 ngày 23/09/2026 có Customer 360 cho khách lẻ, đoàn/trường học, doanh nghiệp/MICE, đại lý/DMC, resort, vé, POS/F&B và marketing. CRM giữ reference, nguồn và thời điểm đồng bộ; giao dịch chuyên ngành ở hệ thống nguồn. Không coi đơn bán thiết bị là booking dịch vụ.

Thiết kế VK-CRM V3 ngày 11/09/2026 tập trung B2B thiết bị/phần mềm: Lead → Account/Contact → Deal → Quote → Contract → Delivery → Acceptance → Invoice → Payment, cùng Installed Asset, Warranty và Ticket.

Phần chung dùng để đối chiếu ngay: Customer/Contact, chống trùng, nhiều pipeline, next action, phiên bản báo giá, duyệt, phân quyền, dữ liệu nền, công việc, SLA, báo cáo, audit và continuity. Các phần khác miền nghiệp vụ không được tự quy đổi thành đã hoàn thành.

Theo chỉ đạo đã có của người dùng, tích hợp ezCloud để sau. Phần tích hợp vẫn phải được ghi rõ là hoãn/chờ đặc tả, không đánh dấu PASS hoặc Hoàn thành.

## Yêu cầu quan trọng theo luồng

- Customer: Customer ID nội bộ; không dùng phone/tên làm primary key; normalize; review trường hợp nghi ngờ; merge giữ giao dịch, source và audit; có consent/segment; nhiều contact cho tổ chức.
- Lead: giữ nguồn/campaign/UTM, queue hoặc owner, luật chia theo điều kiện, người hợp lệ và SLA; chuyển đổi không nhập lại; idempotency không tạo trùng.
- Deal: pipeline riêng theo nhóm; stage gate; owner, expected close date và next action; history không ghi đè; Won có bằng chứng; Lost có lý do; version conflict phải giữ trạng thái cũ.
- Quote: tính tiền phía server, decimal/làm tròn nhất quán, snapshot SKU/giá/thuế; khóa phiên bản khi gửi duyệt; sửa bằng phiên bản mới; phát hành có recipient/thời điểm/file/hash.
- Approval: hỗ trợ tuần tự hoặc song song theo policy; đúng người/đúng lượt; không chuyển tiếp sớm; reason khi từ chối/yêu cầu sửa; delegation có thời hạn; separation of duties chặn tự duyệt khi bật. Tài liệu không bắt buộc mọi loại chứng từ có cùng số cấp hoặc cùng chế độ duyệt.
- Contract/Revenue: hợp đồng đã ký bất biến, amendment cho thay đổi; milestone và evidence; nghiệm thu; payment có định danh chống trùng; allocation/reversal có kiểm soát.
- Service: category/priority/resolution code là danh mục; first response và resolution đo riêng; business calendar, pause/resume có policy/lịch sử; escalation; reopen; asset và warranty.
- Master data: customer type, segment, source, consent; pipeline/stage/lost reason/activity; service category/package/venue/channel; org type/industry/địa bàn; ticket category/priority/resolution; source system/event/sync status. Có owner, code ổn định, trạng thái và hiệu lực; dữ liệu cũ vẫn truy vết được.
- Reports/KPI: định nghĩa metric, kỳ/cohort/timezone/nguồn; drill-down; báo cáo nguồn khách, aging, conversion, CSKH/SLA, data quality và integration; không đồng nhất điểm vận hành với KPI được giao.
- Export: quyền xuất riêng với quyền xem; scope và field masking; audit có số dòng; kiểm soát tải; export lớn chạy async. File đúng số liệu không đủ để nghiệm thu quyền xuất.
- HR/continuity: primary/backup, chuyển ownership lead/deal/task/ticket/approval trước khóa tài khoản, giữ created_by; nhân sự nghỉ không làm mất việc hay lịch sử.
- Operations/UAT: test positive, negative, permission, duplicate, integration failure/recovery; migration reconcile; performance; restore evidence; runbook; training; UAT business sign-off.

## Những khoảng thiếu đã đối chiếu trực tiếp với code hiện tại

| Yêu cầu | Bằng chứng code đã xem | Đánh giá sơ bộ |
|---|---|---|
| Quyền xuất riêng | BusinessReportController::export gọi BusinessReport::build kiểm tra quyền xem; KpiController::exportEmployeeReport chưa kiểm tra quyền export riêng | Chưa đạt yêu cầu quyền xuất độc lập. |
| Export audit, masking, tải hết hạn/async | Hai controller export hiện trả download trực tiếp, không gọi audit export tại đây | Cần bổ sung; chưa có bằng chứng đạt. |
| Nhiều pipeline | Deal model có stage và lost_reason, không có pipeline_id | Không đủ căn cứ nghiệm thu mục nhiều pipeline. |
| Chặn người lập tự duyệt theo cấu hình | QuotationWorkflow::decide kiểm tra người đang pending, active và quyền; chưa kiểm tra separation of duties trong đoạn xử lý duyệt | Đã có đúng lượt, chưa đủ chính sách tự duyệt/delegation. |
| SLA lịch làm việc | ServiceDeskService tạo response_due_at bằng now()->addHours(...) | Chưa đáp ứng business calendar, pause/resume theo lịch trong tài liệu. |

Các bộ test duyệt 5 cấp và export hiện có là bằng chứng kỹ thuật trong phạm vi đã chạy, không chứng minh các mục khác của tài liệu đã đạt.

## Thứ tự triển khai/đối chiếu tiếp

1. Hoàn thiện kiểm soát xuất dữ liệu: quyền riêng, audit, masking, test không có quyền xuất nhưng vẫn có quyền xem.
2. Duyệt theo cấu hình: separation of duties, delegation/backup có thời hạn, policy được giữ trên chứng từ đã bắt đầu duyệt; thử tuần tự/song song, từ chối, sửa, lặp và đồng thời.
3. Master data và pipeline: danh mục chung đủ loại, stage gate và lý do mất; không tạo dữ liệu tổ chức giả.
4. Bàn giao nhân sự/queue/backup và SLA business calendar; kiểm thử bảo toàn lịch sử và thu hồi phiên đăng nhập.
5. Hoàn thiện reports theo checklist: nguồn khách, funnel/cohort, aging, CSKH/SLA, data quality; KPI dictionary và đối soát.
6. Chuẩn bị reference/mapping tích hợp; giữ các API ngoài chờ đặc tả theo chỉ đạo người dùng.
7. UAT theo vai trò, performance, migration, backup/restore và hồ sơ bàn giao.

## Nguyên tắc ghi nhận nghiệm thu

Checklist yêu cầu chức năng/cấu hình xong, test PASS, bằng chứng và người nghiệm thu Sơn Tiên xác nhận. Không tự điền tên người nghiệm thu, chữ ký, ngày hoàn thành hoặc đổi trạng thái trong file gốc. Ngưỡng KPI/SLA và nội dung TBD vẫn cần quyết định được xác nhận. Tài liệu đề xuất kiến trúc/lịch triển khai là yêu cầu tham chiếu, không phải lệnh chạy seed, đổi database hoặc deploy.
