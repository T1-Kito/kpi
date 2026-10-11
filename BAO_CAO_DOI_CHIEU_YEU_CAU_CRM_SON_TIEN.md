# Báo cáo đối chiếu yêu cầu CRM Sơn Tiên

## 1. Cách đọc bộ tài liệu

Bộ sáu tài liệu không phải sáu bản mô tả độc lập. Chúng tạo thành một bộ yêu cầu triển khai CRM hoàn chỉnh:

| Tài liệu | Vai trò trong dự án |
|---|---|
| 00 Document Index | Thứ tự sử dụng và nguyên tắc điều hành |
| 01 BRD | Mục tiêu nghiệp vụ, phạm vi, Customer 360 và kết quả cần đạt |
| 02 Functional Spec | Màn hình, quy tắc, vai trò và tiêu chí nghiệm thu cho từng phân hệ |
| 03 Data Integration | Master Data, nguồn dữ liệu chuẩn, API, migration và monitoring |
| 04 Operations HR KPI Cost Training | Mô hình vận hành, nhân sự, backup, KPI, chi phí và đào tạo |
| 05 Implementation UAT GoLive | Lộ trình triển khai, UAT, cutover, hypercare và hỗ trợ |

## 2. Nguyên tắc bắt buộc xuyên suốt

| Mã | Yêu cầu chi tiết | Trạng thái hiện tại |
|---|---|---|
| G01 | Một nguồn dữ liệu chuẩn cho mỗi miền dữ liệu và trường quan trọng | Cần chốt Data Dictionary và Source of Truth |
| G02 | Một Customer ID nội bộ, không dùng số điện thoại hay tên làm khóa duy nhất | Chưa có Customer 360 canonical ID theo tài liệu |
| G03 | Phân quyền theo vai trò, phạm vi dữ liệu và nguyên tắc quyền tối thiểu | Đã có role/permission nền tảng; cần rà theo ma trận Sơn Tiên |
| G04 | Có người chính và người thay thế cho mọi vị trí critical | Chưa quản lý primary/backup và runbook trong hệ thống |
| G05 | Có KPI/SLA, audit và khả năng truy vết thay đổi quan trọng | Đã có nền audit/KPI/SLA; cần chuẩn hóa chỉ số và drill-down |
| G06 | CRM là nơi điều phối quan hệ khách hàng; hệ thống vé, PMS, POS vẫn là nguồn giao dịch chuyên ngành | Cần thiết kế integration boundary |

## 3. CRM nghiệp vụ cần có

### 3.1 Lead Center

| Yêu cầu | Chi tiết cần triển khai | Hiện trạng |
|---|---|---|
| Lead Inbox | Hộp thư lead mới, lead chưa phân người, lead theo nhóm và lead vi phạm SLA | Có lead cơ bản; thiếu các queue vận hành |
| Nguồn lead | Website, hotline, social, đối tác, trường học, doanh nghiệp, khách trực tiếp, resort, vé, F&B | Có nguồn sales cơ bản; thiếu bộ nguồn Sơn Tiên đầy đủ và nguồn chi tiết |
| Dữ liệu bắt buộc | Nguồn, nguồn chi tiết, khách/contact, kênh, sản phẩm quan tâm, owner/team, thời điểm tạo, consent | Chưa đầy đủ |
| Auto-assign | Chia lead theo nguồn, địa bàn, loại khách, đội; round-robin | Chưa có cấu hình routing hoàn chỉnh |
| SLA lead | Đồng hồ phản hồi, cảnh báo chưa chạm lead, escalation khi quá hạn | Có nền SLA/công việc; cần nối trực tiếp lead |
| Qualification | Chuẩn hóa điều kiện đủ/không đủ trước khi chuyển cơ hội | Có chuyển lead; cần entry/exit criteria và lý do loại |
| Chống trùng | Cảnh báo trước khi tạo, đưa nghi vấn vào hàng đợi duyệt | Có kiểm tra cơ bản; chưa có Duplicate Review Queue |

### 3.2 Customer 360 và định danh khách hàng

| Yêu cầu | Chi tiết cần triển khai | Hiện trạng |
|---|---|---|
| Hồ sơ 360 | Summary, profile, identifiers, timeline, booking/visit, POS, campaign, ticket, consent, note | Khách hàng cơ bản; chưa có trang 360 đầy đủ |
| Customer ID | Mã nội bộ bất biến; tách Person Customer, Organization và Contact | Chưa đúng mô hình canonical theo tài liệu |
| Identifier | Điện thoại chuẩn Việt Nam/E.164, email lowercase/trim, booking ID, membership ID | Chưa có bộ chuẩn hóa và nhiều định danh |
| Identity Resolution | So khớp phone/email/external ID với mức tin cậy; chỉ tự liên kết khi không xung đột | Chưa có engine/rule queue |
| Merge queue | Không tự gộp chỉ vì trùng tên; Data Steward duyệt; lưu trước/sau merge và source record | Chưa có |
| Timeline | Chỉ ghi thêm theo event: call, email, chat, meeting, ticket, booking, payment, campaign | Có ghi chú/công việc rời; thiếu timeline thống nhất |
| Consent | Lưu trạng thái đồng ý, opt-in/opt-out, nguồn và thời điểm | Chưa có mô hình consent |
| Dữ liệu nhạy cảm | Che theo role; không lưu raw biometric, chỉ lưu external subject ID/trạng thái | Cần thêm chính sách và validation |

### 3.3 Tổ chức, đại lý và người liên hệ

| Yêu cầu | Chi tiết cần triển khai | Hiện trạng |
|---|---|---|
| Organization/Account | Trường học, doanh nghiệp, đại lý có Org ID, MST, loại hình, ngành, quy mô, địa chỉ | Cần mô hình Account riêng |
| Contact | Một tổ chức có nhiều người liên hệ, chức danh và kênh liên lạc | Chưa đủ mô hình Contact nhiều-nhiều |
| Account hierarchy | Quản lý đơn vị mẹ/con hoặc cụm đối tác khi cần | Chưa có |
| Kiểm tra trùng | Kiểm tra MST và tên pháp lý; contact không tự biến thành account | Cần bổ sung |
| Ownership | Account owner rõ ràng; thay nhân sự không mất lịch sử tổ chức | Cần bổ sung luồng chuyển ownership |
| Partner lifecycle | Prospect → Qualification → Commercial discussion → Agreement → Activated → Producing → Dormant → Renewal/Termination | Chưa có pipeline đối tác riêng |

### 3.4 Cơ hội và pipeline

| Nhóm khách | Pipeline tài liệu yêu cầu | Hiện trạng |
|---|---|---|
| Đoàn/trường học | New lead → Qualified → Khảo sát nhu cầu → Đề xuất/Báo giá → Đàm phán → Chốt lịch/Đặt cọc → Confirmed → Completed → After-sales/Lost | Cần pipeline riêng |
| Doanh nghiệp/MICE | New → Qualified → Solution fit → Proposal → Negotiation → Approval/PO → Confirmed → Delivered → Renewal/After-sales | Cần pipeline riêng |
| Đại lý/DMC | Prospect → Qualification → Commercial discussion → Agreement → Activated → Producing → Dormant → Renewal/Termination | Chưa có |
| Khách lẻ/Resort | Inquiry → Profile captured → Offer/Booking intent → Booked → Pre-arrival → On-site → Post-visit → Retention | Chưa có |

Yêu cầu chung cho mọi pipeline: Kanban/List, chi tiết stage, hoạt động, hàng hóa/dịch vụ, báo giá, approval, risk, next action; mỗi stage phải có điều kiện vào/ra; không cho đóng Won nếu thiếu booking/order reference hoặc approval bắt buộc; lưu lịch sử stage; đo pipeline aging, next action và lý do Lost.

### 3.5 Báo giá, đề xuất và hợp đồng

| Yêu cầu | Chi tiết cần triển khai | Hiện trạng |
|---|---|---|
| Báo giá | Header, dòng hàng, discount, thời hạn, thuế/phí, approval, bản xuất | Đã có nền tốt |
| Version | Không ghi đè báo giá đã gửi; biết rõ bản nào gửi khách | Có nhân bản; cần version/revision chính thức |
| Approval | Chiết khấu vượt ngưỡng phải duyệt theo policy | Có duyệt cơ bản; cần cấu hình ngưỡng/policy |
| Traceability | Biết người tạo, người duyệt, phiên bản đã gửi và cơ hội liên quan | Một phần; cần hoàn thiện relation/audit UI |
| Contract | Hợp đồng, mốc thanh toán, nghiệm thu, mẫu Word | Đã có nền tốt; cần liên kết opportunity/booking policy |

### 3.6 Booking, visit, vé/QR, resort và POS reference

| Yêu cầu | Chi tiết cần triển khai | Hiện trạng |
|---|---|---|
| External record | Tab bản ghi ngoài, phân biệt live/cache, source system, source ID, thời điểm đồng bộ | Chưa có |
| Ticketing/QR | Vé, QR, combo, trạng thái visit, reference | Chưa có |
| PMS/Booking | Booking, stay, room/package reference, check-in/out | Chưa có |
| POS F&B | Receipt và spend summary theo khách/địa điểm | Chưa có |
| Payment | Trạng thái thanh toán và reference từ Payment/ERP | Có công nợ nội bộ; chưa có integration reference |
| Quy tắc sửa | CRM không sửa giao dịch nguồn nếu API nghiệp vụ chưa cho phép | Cần enforced boundary |

### 3.7 Marketing, loyalty và retention

| Yêu cầu | Chi tiết cần triển khai | Hiện trạng |
|---|---|---|
| Segment | Phân khúc khách, nhóm audience, frequency/recency/value | Chưa có |
| Campaign | Campaign list, audience, journey, template, lịch gửi, kết quả | Chưa có phân hệ hoàn chỉnh |
| Consent/Suppression | Không gửi opt-out; suppression ưu tiên hơn segment; giới hạn tần suất liên hệ | Chưa có |
| Attribution | Nguồn lead, CPL/CPA, campaign conversion và doanh thu attribution theo rule chốt trước | Chưa có |
| Loyalty/Retention | Ưu đãi, nhắc quay lại, reactivation, after-stay/post-visit | Chưa có |
| Audit gửi tin | Log ai nhận/không nhận và lý do | Chưa có |

### 3.8 CSKH, Service và Ticket

| Yêu cầu | Chi tiết cần triển khai | Hiện trạng |
|---|---|---|
| Ticket inbox | Danh sách ticket, category, priority, assignee, SLA, escalation | Có ticket cơ bản |
| Liên kết nghiệp vụ | Ticket nối customer, booking/visit và interaction | Cần hoàn thiện booking/interaction relation |
| SLA | P1/P2 tự escalation; đo first response, resolution, breach, reopen rate | Cần hoàn thiện automation và dashboard |
| Resolution | Bắt buộc mã xử lý khi đóng | Cần xác nhận/bổ sung validation |
| CSAT/VOC | CSAT, phản hồi khách, theo dõi action closure | Chưa có đầy đủ |

### 3.9 Dashboard, BI và Data Quality

| Nhóm | Chỉ số yêu cầu |
|---|---|
| Sales/Booking | Lead volume, SLA response, conversion, pipeline aging, win/lost reason, revenue theo kênh |
| Marketing | Lead source, CPL/CPA, campaign conversion, opt-in/opt-out, reactivation |
| Customer | New/repeat, segment, frequency, recency, value, complaints, satisfaction |
| Data Quality | Duplicate rate, missing mandatory fields, sync errors, stale records |
| Operations | Integration uptime, queue backlog, ticket SLA, failed jobs, user adoption |

Dashboard phải drill-down về record nguồn, có data dictionary cho mỗi metric và có ngưỡng đối soát với hệ thống nguồn.

## 4. Master Data, Data Governance và tích hợp

### 4.1 Master Data phải cấu hình

| Domain | Master Data bắt buộc | Owner đề xuất |
|---|---|---|
| Customer | Customer type, segment, source, consent type | CRM/Data |
| Sales | Pipeline, stage, lost reason, activity type | Sales Ops |
| Product/Service | Service category, package, venue, channel | Business/Product |
| Organization | Org type, industry, province/district | B2B Ops/Data |
| Service | Ticket category, priority, resolution code | CSKH |
| Integration | Source system, event type, sync status | IT/Integration |

Không cho người dùng thường tạo tự do master data. Mã kỹ thuật tích hợp không được đổi sau go-live. Master có effective date khi cần.

### 4.2 Source of Truth cần chốt

| Dữ liệu | Hệ thống nguồn chuẩn | CRM làm gì |
|---|---|---|
| Lead và interaction | CRM | Tạo/sửa đầy đủ |
| Customer chuẩn hóa | CRM MDM layer | Sửa có kiểm soát |
| Vé/QR/combo | Ticketing | Nhận summary/status/reference |
| Booking/lưu trú | PMS/Booking | Nhận booking/stay summary |
| POS F&B | KingPOS/POS | Nhận receipt/spend summary |
| Thanh toán | Payment/ERP | Nhận payment status/reference |
| Face/Access identity | Access/Identity | Chỉ nhận external subject ID/status |

### 4.3 Chuẩn integration bắt buộc

| Chủ đề | Yêu cầu |
|---|---|
| Authentication | OAuth2/client credential hoặc tương đương; không hard-code secret |
| Correlation | Mọi giao dịch có correlation ID |
| Idempotency | Các POST quan trọng có idempotency key hoặc natural event key |
| Thời gian | ISO-8601, timezone rõ ràng; ưu tiên UTC tại tầng tích hợp |
| Retry | Exponential backoff và phân loại retryable/non-retryable |
| Dead letter | Lỗi quá số lần retry phải vào queue để reprocess có kiểm soát |
| API version | Có /v1 và backward compatibility |
| Logging | Không ghi secret/dữ liệu nhạy cảm; vẫn đủ metadata để truy vết |

### 4.4 Interface ưu tiên

| Ưu tiên | Interface | Chiều | Trigger |
|---|---|---|---|
| P1 | CRM ↔ Ticketing/QR | Hai chiều | Lead, booking, issue ticket |
| P1 | CRM ↔ Resort/PMS | Hai chiều | Booking created/updated/check-in/out |
| P1 | CRM ← POS/KingPOS | Một chiều vào | Receipt closed/batch |
| P1 | CRM ← Payment | Một chiều vào | Payment status changed |
| P2 | CRM ↔ Marketing channel | Hai chiều | Campaign/send/response/opt-out |
| P2 | CRM ↔ Service desk | Hai chiều hoặc native | Ticket lifecycle |
| P2 | CRM ← Access/FaceID | Một chiều vào | Visit/check event summary |

### 4.5 Migration, monitoring và sự cố

Migration phải đi theo thứ tự: inventory nguồn và owner → data profiling → mapping canonical → cleansing/dedupe staging → dry-run subset → dry-run full/performance → freeze/cutover delta → post-load validation và business sign-off.

Mức phản hồi sự cố: P1 phản hồi tối đa 15 phút và khôi phục tối đa 2 giờ; P2 30 phút/4 giờ; P3 4 giờ làm việc/1 ngày; P4 theo backlog. Monitoring cần dashboard, alert route, reconciliation source-vs-CRM, load test, DR/recovery runbook.

## 5. Phân quyền, vận hành và nhân sự

| Vai trò | Nhu cầu chính |
|---|---|
| Sales User | Customer/Lead/Opportunity theo scope, tạo activity, không tự duyệt ngoại lệ |
| Sales Manager | Dữ liệu team, duyệt discount/close exception theo policy |
| Marketing | Segment, campaign, audience |
| CSKH | Customer, ticket, booking reference, xử lý ticket |
| CRM Admin | User, role, field, workflow, audit, change request kỹ thuật |
| Data Steward | Master data, duplicate queue, merge/correction theo quy trình |
| Integration Support | Logs/status, retry/reprocess theo runbook |
| Management | Dashboard và phê duyệt nghiệp vụ theo cấp |

Mô hình vận hành khuyến nghị là 12 FTE lõi cộng IT/Security dùng chung: CRM Ops Manager, 2 CRM Admin, 2 Sales/Booking Ops, Data Steward, 2 CS/Loyalty Ops, Marketing Automation, 2 Integration Support và BI Analyst. Mỗi vị trí critical phải có primary/backup, quyền dự phòng, runbook và diễn tập continuity hàng quý.

Offboarding bắt buộc: HR thông báo → chốt ownership → bàn giao runbook/issue → transfer ownership trên CRM → thu hồi quyền → rotate secret nếu có → kiểm tra hậu bàn giao và ký checklist.

## 6. Đào tạo, UAT và go-live

### 6.1 Đào tạo

| Nhóm | Nội dung/điều kiện |
|---|---|
| Toàn bộ user | 4-6 giờ CRM, tìm khách trước khi tạo, data/security; quiz tối thiểu 80% và thực hành |
| Sales/Booking/CSKH | 1-2 ngày end-to-end lead/pipeline/ticket/SLA/Customer 360 |
| CRM Admin | 4-6 ngày RBAC, workflow, audit, release, rollback |
| Data Steward | 3-4 ngày dictionary, dedupe, merge, migration, reconciliation |
| Integration Support | 5-7 ngày API, log, retry, incident/reprocess, diễn tập P1/P2 |
| BI Analyst | 3-5 ngày metric dictionary, SQL/BI, reconciliation, row-level security |
| Manager | 3-4 giờ dashboard, approval, escalation, adoption |

### 6.2 UAT phải test theo journey

1. Lead từ web → auto assign → qualify → opportunity → quotation → booking reference.
2. Khách đã có ở resort + POS → identity resolution → timeline thống nhất.
3. Hai lead trùng phone/email → duplicate queue → merge → vẫn giữ audit.
4. Sales chỉ thấy dữ liệu scope; manager thấy team; admin cấu hình.
5. PMS timeout → retry → dead-letter → reprocess → không tạo duplicate.
6. Nhân viên nghỉ việc → transfer ownership → lock account → báo cáo không mất dữ liệu.

Mỗi luồng có positive, negative, permission, duplicate, integration failure và recovery test. Lỗi Severity 1-2 phải đóng trước go-live trừ khi Sponsor ký ngoại lệ.

### 6.3 Lộ trình 12 tuần theo tài liệu

| Tuần | Giai đoạn | Gate |
|---|---|---|
| 1 | Discovery/Kick-off | Sponsor xác nhận scope |
| 2-3 | Process & Solution Design | Business/IT sign-off |
| 4 | Configuration Sprint 1 | Demo 1 |
| 5-6 | Integration & Data Sprint | Technical readiness |
| 7 | Super-user training | Training pass |
| 8-9 | UAT | UAT sign-off |
| 10 | Cutover rehearsal | Go-live readiness review |
| 11 | Pilot | Pilot exit criteria |
| 12 | Go-live & Hypercare | Stabilization handoff |

Go-live readiness yêu cầu SOP, migration reconcile, P1 integration pass, monitoring/alert đã bật, role review, training đạt 90%, war room/escalation, rollback criteria và decision owner.

## 7. Đánh giá tổng thể hệ thống hiện tại

Hệ thống hiện tại phù hợp vai trò nền tảng sales–quotation–order–contract–inventory–procurement–finance nội bộ. Nó có một số khối giúp đi nhanh: phân quyền, audit, KPI, SLA policy, lead, cơ hội, báo giá, hợp đồng, ticket cơ bản và master data sales.

Tuy nhiên, nếu đo đúng theo bộ tài liệu Sơn Tiên, phần còn thiếu lớn nhất là CRM đặc thù ngành: Customer 360, Account/Contact, identity resolution, consent, external transaction references, campaign automation, pipeline đa mô hình, integration P1 và vận hành go-live. Không nên nhảy thẳng vào tích hợp trước khi chốt master data và mô hình khách hàng.

## 8. Backlog đề xuất theo thứ tự đúng

### Giai đoạn A — Chốt thiết kế trước khi code

1. Chốt danh sách hệ thống nguồn và owner: Ticketing/QR, PMS, KingPOS, Payment, Access/FaceID.
2. Chốt Customer ID, Org ID, Contact ID, external reference, consent model và quy tắc merge.
3. Chốt bốn pipeline cùng entry/exit criteria, Lost reason và approval policy.
4. Chốt data dictionary, source-of-truth matrix, SLA và permission matrix.

### Giai đoạn B — CRM lõi Sơn Tiên

1. Customer 360 + Account/Contact + Timeline + Consent.
2. Duplicate Review Queue + Merge Audit + Data Quality dashboard.
3. Lead Center + routing + SLA/escalation.
4. Pipeline theo bốn nhóm khách + next action/risk/stage history.
5. Báo giá version/approval liên kết Opportunity.

### Giai đoạn C — Trải nghiệm và dịch vụ khách

1. Booking/Visit/POS reference panel.
2. Ticket SLA/escalation/CSAT/VOC.
3. Segment/Campaign/Journey/Suppression/Loyalty.
4. Dashboard Sales/Marketing/Customer/Data Quality/Operations.

### Giai đoạn D — Tích hợp và vận hành production

1. P1 Ticketing/QR, PMS, POS, Payment.
2. API gateway/log/retry/dead-letter/reprocess/reconciliation.
3. UAT end-to-end, migration dry-run, training, cutover và hypercare.
