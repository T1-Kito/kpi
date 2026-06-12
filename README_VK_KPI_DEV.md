# VK-KPI — README triển khai cho Dev

## 1. Mục tiêu dự án

VK-KPI là hệ thống quản trị vận hành doanh nghiệp kết hợp:

- CRM / Sales
- Mua hàng
- Kho
- Task / SLA
- Workflow duyệt
- Alert / Notification
- Audit Log
- KPI Dashboard

Đây **không phải web KPI nhập tay đơn giản**. KPI phải được tính từ dữ liệu vận hành thực tế: lead, báo giá, đơn hàng, task, tồn kho, mua hàng, campaign và SLA.

Mục tiêu MVP là xây dựng một hệ thống có thể demo rõ luồng:

```text
Lead
→ Báo giá
→ Duyệt nếu margin thấp
→ Đơn hàng
→ Kiểm tra tồn kho
→ Đủ tồn: tạo task kho xuất hàng
→ Thiếu tồn: tạo cảnh báo + yêu cầu mua + task mua hàng
→ Nhập kho
→ Xuất kho
→ Dashboard cập nhật task / alert / KPI
```

---

## 2. Tech stack đề xuất

### Backend

Ưu tiên theo codebase hiện tại:

- FastAPI modular monolith
- REST JSON API `/api/v1`
- SQLite cho dev/test
- PostgreSQL cho production
- JWT Authentication
- PBKDF2-SHA256 password hash
- Event-driven automation nội bộ

Nếu chuyển sang Laravel thì vẫn phải giữ kiến trúc module tương tự.

### Frontend

- React + TypeScript + Vite
- UI component tự xây hoặc dùng shadcn/ui / Ant Design nhưng phải chỉnh theme doanh nghiệp
- Responsive dashboard
- Role-based UI

### Production cần bổ sung

- PostgreSQL
- Refresh token
- Rate limit login
- Logging tập trung
- Backup DB
- Monitoring
- Security test
- Performance test
- CI/CD

---

## 3. Nguyên tắc kiến trúc

Hệ thống đi theo luồng:

```text
Auth
→ RBAC
→ Master Data
→ Business Modules
→ Event Bus
→ Rule Engine
→ Workflow / Approval
→ Task / SLA
→ Notification / Alert
→ KPI Engine
→ Dashboard
→ Audit Log
→ Database
```

Không gọi chéo lung tung giữa các module. Mỗi module nên có:

```text
router / controller
service
repository / data access
schema / DTO
model
test
```

---

# 4. Scope MVP bắt buộc

## 4.1 Auth & Authentication

### Chức năng

- Đăng nhập
- Đăng xuất
- Lấy thông tin user hiện tại
- JWT token có `user_id`, `tenant_id`, `role`, `exp`
- Chặn user inactive

### API gợi ý

```http
POST /api/v1/auth/login
POST /api/v1/auth/refresh
GET  /api/v1/me
```

### Nghiệm thu

- Sai mật khẩu không đăng nhập được
- User inactive không đăng nhập được
- Token hết hạn bị từ chối
- API private không có token phải trả 401

---

## 4.2 User / Role / Permission / Department

### Chức năng

- Quản lý user
- Khóa / mở user
- Gán phòng ban
- Gán chức danh
- Tạo role
- Gán permission
- Cây phòng ban `parent_id`
- Trưởng phòng / quản lý trực tiếp

### Role mặc định

```text
ROLE-ADMIN
ROLE-DIR
ROLE-MGR
ROLE-SALES
ROLE-WH
ROLE-PUR
ROLE-MKT
ROLE-HR
ROLE-FIN
```

### Permission mẫu

```text
user.manage
role.manage
dashboard.executive.view
task.create
task.approve
sales.quotation.create
sales.margin.approve
inventory.issue.confirm
procurement.po.approve
kpi.lock
```

### Nghiệm thu

- User chỉ thấy dữ liệu đúng phạm vi
- Sales chỉ thấy khách / lead / đơn mình phụ trách
- Trưởng phòng thấy dữ liệu phòng ban
- Admin thấy toàn bộ trong tenant
- Không chỉ ẩn nút ở frontend, backend cũng phải chặn quyền

---

## 4.3 RBAC Gate

Mọi request API phải đi qua cổng kiểm tra quyền:

```text
Token hợp lệ?
→ User đang active?
→ Có permission?
→ Có đúng phạm vi dữ liệu?
```

### Data scope

```text
company: toàn công ty
department: phòng ban
own: dữ liệu của bản thân
warehouse: kho được phân quyền
```

### Nghiệm thu

- Thiếu token: 401
- Token sai / hết hạn: 401
- Không có quyền: 403
- Không đúng phạm vi dữ liệu: 403

---

## 4.4 Master Data

### Customer

- Mã khách hàng
- Tên khách hàng
- Liên hệ
- Số điện thoại
- Email
- Hạn mức công nợ
- Sales phụ trách
- Trạng thái

### Supplier

- Mã nhà cung cấp
- Tên
- Điều khoản
- Rating
- Sản phẩm cung ứng
- Trạng thái

### Product / SKU

- Product cha
- SKU code
- Barcode
- Đơn vị tính
- Tồn min / max
- Giá bán
- Giá vốn
- Trạng thái

### Warehouse / Location

- Nhiều kho
- Nhiều vị trí trong kho
- Quản lý lô hàng
- Tồn theo kho / vị trí / SKU / lô

### Nghiệm thu

- SKU không được trùng trong cùng tenant
- Customer tìm được theo mã, tên, phone
- Supplier tìm được theo mã, tên
- Warehouse có thể có nhiều location
- Dữ liệu master có autocomplete ở các form nghiệp vụ

---

# 5. Business Modules MVP

## 5.1 Sales — Lead → Quotation → Sales Order

### Lead

Chức năng:

- Tạo lead
- Check trùng số điện thoại
- Gắn nguồn lead
- Gắn campaign nếu có
- Phân sales phụ trách
- Tự tạo task follow-up khi phân lead

API gợi ý:

```http
POST /api/v1/leads
POST /api/v1/leads/{id}/assign
GET  /api/v1/leads
```

Nghiệm thu:

- Tạo lead trùng số điện thoại phải cảnh báo
- Phân lead cho sales thì tự tạo task follow-up
- Sales thấy lead được giao ở dashboard

---

### Quotation

Chức năng:

- Tạo báo giá từ customer + SKU
- Tự lấy giá SKU
- Tự tính giá vốn
- Tự tính margin
- Kiểm tra tồn kho
- Kiểm tra công nợ
- Margin thấp thì chuyển chờ duyệt

Rule:

```text
Nếu margin < 15%
→ Không cho gửi khách
→ Tạo approval cho trưởng phòng
→ Gửi notification
```

API gợi ý:

```http
POST /api/v1/quotations
POST /api/v1/quotations/{id}/submit-approval
POST /api/v1/quotations/{id}/approve
```

Nghiệm thu:

- Margin được tính tự động
- Margin thấp bị chặn gửi khách
- Duyệt xong mới cho tạo đơn hàng hoặc gửi khách
- Có audit log khi duyệt

---

### Sales Order

Chức năng:

- Tạo đơn hàng từ báo giá
- Copy dòng hàng từ báo giá
- Kiểm tra tồn khả dụng
- Kiểm tra công nợ
- Nếu đủ tồn: giữ hàng + tạo task kho
- Nếu thiếu tồn: tạo alert + PR nháp + task mua hàng

Rule:

```text
SalesOrderCreated
IF available_qty >= order_qty
THEN reserve_stock + create_warehouse_task

SalesOrderCreated
IF available_qty < order_qty
THEN create_alert + create_purchase_request_draft + create_procurement_task
```

API gợi ý:

```http
POST /api/v1/sales-orders
POST /api/v1/sales-orders/{id}/confirm
GET  /api/v1/sales-orders
```

Nghiệm thu:

- Đơn hàng đủ tồn phải tự tạo task kho
- Đơn hàng thiếu tồn phải tự tạo PR nháp
- Không cho xuất vượt tồn khả dụng
- Dashboard cập nhật cảnh báo thiếu tồn

---

## 5.2 Procurement — PR → PO

### Purchase Request

Chức năng:

- Tạo PR thủ công
- PR tự sinh khi SO thiếu tồn
- Gắn source: SalesOrder
- Có trạng thái chờ duyệt
- Có người yêu cầu
- Có dòng SKU cần mua

API gợi ý:

```http
POST /api/v1/purchase-requests
GET  /api/v1/purchase-requests
POST /api/v1/purchase-requests/{id}/approve
```

Nghiệm thu:

- PR tự sinh phải liên kết được với Sales Order thiếu tồn
- PR có trạng thái rõ ràng
- PR cần duyệt trước khi tạo PO

---

### Purchase Order

Chức năng:

- Tạo PO từ PR
- Chọn nhà cung cấp
- Ngày giao dự kiến
- Duyệt theo hạn mức
- Theo dõi trễ giao

Rule:

```text
Nếu PO gần tới ngày giao hoặc quá ngày giao mà chưa nhận đủ
→ Tạo cảnh báo cho mua hàng và trưởng phòng
```

API gợi ý:

```http
POST /api/v1/purchase-orders
POST /api/v1/purchase-orders/{id}/approve
GET  /api/v1/purchase-orders
```

Nghiệm thu:

- PO tạo từ PR phải copy đúng item
- PO quá hạn giao phải có alert
- PO duyệt phải ghi audit log

---

## 5.3 Inventory — Nhập / Xuất / Tồn

### Inventory Balance

Cần quản lý:

```text
on_hand
reserved
available = on_hand - reserved
```

### Goods Receipt

Chức năng:

- Nhập kho theo PO
- Chọn kho
- Chọn vị trí
- Scan / chọn SKU
- Xác nhận số lượng
- Cập nhật tồn kho
- Ghi inventory transaction

API gợi ý:

```http
POST /api/v1/goods-receipts
POST /api/v1/goods-receipts/{id}/confirm
```

### Goods Issue

Chức năng:

- Xuất kho theo Sales Order
- Kiểm tra available
- Scan / chọn SKU
- Xác nhận số lượng
- Cập nhật tồn kho
- Ghi inventory transaction

API gợi ý:

```http
POST /api/v1/goods-issues
POST /api/v1/goods-issues/{id}/confirm
```

### Nghiệm thu

- Không cho xuất vượt `available`
- Nhập kho tăng `on_hand` và `available`
- Xuất kho giảm `on_hand` và giảm `reserved` nếu xuất theo SO
- Mọi nhập/xuất đều ghi transaction append-only
- Không sửa trực tiếp transaction cũ

---

## 5.4 Marketing cơ bản

Chức năng MVP:

- Tạo campaign
- Ngân sách
- Số lead mục tiêu
- Chi phí đã dùng
- Lead phát sinh
- Cảnh báo campaign kém hiệu quả

Rule:

```text
Nếu spent >= 50% budget
và valid_leads < 30% target_leads
→ Tạo alert
→ Tạo task tối ưu campaign
```

Nghiệm thu:

- Campaign có budget và target lead
- Lead có thể gắn campaign
- Campaign kém hiệu quả phải tạo alert

---

# 6. Approval Workflow

## Luồng duyệt bắt buộc

### Duyệt báo giá margin thấp

```text
Quotation margin < 15%
→ tạo approval
→ trưởng phòng duyệt
→ duyệt xong mới cho gửi khách / tạo SO
```

### Duyệt PR

```text
PR created
→ trưởng phòng duyệt
→ duyệt xong mới tạo PO
```

### Duyệt PO

```text
PO created
→ duyệt theo hạn mức
→ trưởng phòng hoặc giám đốc duyệt
```

### Duyệt ngoại lệ KPI

```text
Nhân viên giải trình KPI
→ quản lý duyệt / từ chối
```

## Approval status

```text
pending
approved
rejected
cancelled
```

## Nghiệm thu

- Approval phải biết source là gì
- Approval phải biết người duyệt
- Duyệt / từ chối phải ghi audit log
- Người không có quyền không được duyệt
- Duyệt xong phải mở hành động tiếp theo

---

# 7. Event Engine / Rule Automation

## Cơ chế

Mỗi thao tác nghiệp vụ quan trọng phải phát event:

```text
publish_event(event_type, source_type, source_id, payload)
```

Lưu vào bảng:

```text
business_events
```

Sau đó Rule Engine xử lý:

```text
pending
→ processing
→ processed / failed
```

Nếu lỗi:

```text
failed
error_message
retry_count
```

## Event bắt buộc MVP

```text
LeadAssigned
QuotationCreated
QuotationApproved
SalesOrderCreated
SalesOrderConfirmed
PurchaseRequestCreated
PurchaseOrderCreated
PurchaseOrderApproved
GoodsReceiptConfirmed
GoodsIssueConfirmed
TaskCreated
TaskCompleted
TaskOverdue
CampaignBudgetUpdated
KpiPeriodEnded
```

## Rule bắt buộc MVP

```text
RULE-001: SO thiếu tồn → alert + PR nháp + task mua hàng
RULE-002: Quotation margin thấp → approval
RULE-003: Lead assigned → task follow-up
RULE-004: Task overdue → alert + escalation
RULE-005: PO trễ giao → alert mua hàng
RULE-006: Campaign kém hiệu quả → alert + task tối ưu
```

---

# 8. Task Engine & SLA

## Task

Trường dữ liệu cần có:

```text
id
code
title
description
task_type
priority
source_type
source_id
assignee_id
department_id
due_at
status
created_by
created_at
updated_at
```

## Task status

```text
new
in_progress
completed
overdue
cancelled
```

## SLA Policy

Trường dữ liệu:

```text
module
task_type
priority
duration_minutes
warning_before_minutes
escalation_rules
```

## Rule SLA

```text
Task còn gần tới hạn
→ gửi reminder

Task quá hạn
→ đổi status overdue
→ alert assignee
→ alert manager
```

## Nghiệm thu

- Task có thể tạo tay
- Task có thể tự sinh từ event
- Task tự tính deadline từ SLA policy
- Task quá hạn tự chuyển overdue
- Task quá hạn phải cảnh báo đúng người

---

# 9. Notification & Alert Center

## Notification

Chức năng:

- In-app notification
- Read / unread
- Link tới entity cần xử lý
- Email để tích hợp sau
- Push để V2

Trạng thái:

```text
unread
read
```

## Alert

Mức độ:

```text
high
warning
info
```

Trạng thái:

```text
open
acknowledged
resolved
```

Alert type mẫu:

```text
stock_shortage
task_overdue
margin_low
debt_warning
po_late
campaign_warning
kpi_exception
```

## Nghiệm thu

- Alert phải có người nhận
- Alert phải có source
- Alert có action_url
- Không tạo alert spam trùng source liên tục
- Notification phải có read/unread

---

# 10. Audit Log

Mọi thao tác quan trọng phải ghi audit log.

## Bắt buộc ghi log

```text
login success / failed
create user
lock user
change role / permission
create quotation
approve / reject quotation
create sales order
confirm goods receipt
confirm goods issue
approve / reject PR
approve / reject PO
change inventory
create / update automation rule
lock KPI period
```

## Trường dữ liệu

```text
entity_type
entity_id
action
old_value
new_value
user_id
ip_address
user_agent
created_at
reason
```

## Nghiệm thu

- Audit log không sửa được từ UI
- Có thể filter theo user, entity, thời gian
- Thao tác duyệt và tồn kho bắt buộc có audit

---

# 11. KPI Engine

## MVP cơ bản

Chức năng:

- KPI definition
- KPI target
- KPI snapshot
- KPI preview theo user / department
- KPI exception cơ bản
- Manager duyệt exception

## KPI cycle

```text
Define
→ Target
→ Collect Data
→ Snapshot
→ Employee Explanation
→ Manager Review
→ Lock Period
```

## Data source KPI

Lấy từ:

```text
tasks
sales_orders
quotations
leads
inventory_transactions
purchase_orders
marketing_campaigns
alerts
sla
```

## Nghiệm thu

- KPI không nhập tay hoàn toàn
- KPI snapshot lấy từ dữ liệu thật
- Snapshot đã khóa không tự thay đổi
- Nhân viên có thể xem KPI tạm tính
- Trưởng phòng xem KPI phòng ban

---

# 12. Dashboard theo vai trò

## My Work Dashboard

Dành cho mọi nhân viên:

- Task hôm nay
- Task quá hạn
- Alert của tôi
- KPI tạm tính
- Nút tạo nhanh
- Việc cần xử lý tiếp theo

## Manager Dashboard

Dành cho trưởng phòng:

- Task nhân viên
- Task quá hạn phòng ban
- Workload team
- Alert phòng ban
- Approval đang chờ
- KPI phòng ban

## Executive Dashboard

Dành cho ban giám đốc:

- Tổng doanh thu
- Tồn kho
- Công nợ
- Rủi ro đỏ
- KPI toàn công ty
- Pipeline sales

## Nghiệm thu

- User đăng nhập vào phải thấy dashboard đúng vai trò
- Dashboard không chỉ là biểu đồ, phải có hành động cần xử lý
- Alert và task phải click được tới màn hình chi tiết

---

# 13. Import & Config V1

## Import Excel

Import:

```text
customer
supplier
sku
opening inventory
lead
```

Quy trình:

```text
Upload file
→ validate từng dòng
→ preview lỗi
→ user xác nhận
→ ghi DB
```

## Config Workflow / Rule / SLA

Admin có thể cấu hình:

```text
workflow definition
automation rule
SLA policy
approval matrix
```

## Lưu ý

Phần này để V1, MVP có thể hard-code rule trước nhưng thiết kế database phải sẵn đường mở rộng.

---

# 14. Scheduler / Background Jobs

## Job bắt buộc

```text
check_overdue_tasks
send_due_soon_reminders
calculate_kpi_snapshots
retry_failed_events
check_late_purchase_orders
backup_database
```

## Tần suất gợi ý

```text
check_overdue_tasks: mỗi 5-15 phút
send_due_soon_reminders: mỗi 30 phút
calculate_kpi_snapshots: mỗi ngày
retry_failed_events: mỗi 10 phút
check_late_purchase_orders: mỗi ngày
backup_database: mỗi ngày
```

---

# 15. Database bắt buộc

## Core

```text
tenants
users
employees
departments
positions
roles
permissions
user_roles
role_permissions
```

## Master Data

```text
customers
customer_contacts
suppliers
products
skus
warehouses
warehouse_locations
```

## Business

```text
leads
opportunities
quotations
quotation_items
sales_orders
sales_order_items
purchase_requests
purchase_request_items
purchase_orders
purchase_order_items
goods_receipts
goods_receipt_items
goods_issues
goods_issue_items
inventory_balances
inventory_transactions
```

## Automation

```text
tasks
task_status_histories
sla_policies
alerts
notifications
workflow_definitions
workflow_instances
approvals
business_events
automation_rules
audit_logs
```

## KPI / Marketing

```text
marketing_campaigns
campaign_contents
kpi_definitions
kpi_targets
kpi_score_snapshots
kpi_exceptions
```

---

# 16. API chuẩn chung

## Response list

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "page_size": 20,
    "total": 100
  }
}
```

## Error format

```json
{
  "error_code": "VALIDATION_ERROR",
  "message": "Dữ liệu không hợp lệ",
  "fields": {
    "email": "Email đã tồn tại"
  }
}
```

## Nghiệm thu API

- GET list có filter, sort, pagination
- API ghi dữ liệu phải validate
- API quan trọng phải ghi audit
- API nghiệp vụ phải phát business event nếu có ảnh hưởng workflow / KPI / alert
- Confirm nhập / xuất / duyệt phải idempotent, không tạo giao dịch lặp khi retry

---

# 17. Quy chuẩn UI/UX bắt buộc

## 17.1 Phong cách giao diện

Giao diện phải theo hướng doanh nghiệp hiện đại, tương tự:

```text
MISA AMIS
Base.vn
KiotViet
```

Không làm kiểu admin template cũ, nhiều màu, chữ to, nút to, icon lộn xộn.

## 17.2 Font chữ

Ưu tiên:

```text
Inter
Be Vietnam Pro
```

Quy chuẩn:

```text
Page title: 20px / Semibold 600
Section title: 15px / Semibold 600
Body text: 14px / Regular 400
Label: 13px / Medium 500
Hint text: 12px / Regular 400
Badge: 11px / Medium 500
```

Không dùng bold bừa bãi.

## 17.3 Màu sắc

Tông chính:

```text
Primary navy: #1A3A5C
Primary blue: #2563EB
Background: #FFFFFF
Light background: #F8F9FA
Border: #E5E7EB
Text primary: #111827
Text secondary: #6B7280
```

Trạng thái:

```text
Success: bg #DCFCE7 / text #166534
Warning: bg #FEF3C7 / text #B45309
Danger: bg #FEE2E2 / text #991B1B
Info: bg #DBEAFE / text #1E40AF
Inactive: bg #F3F4F6 / text #9CA3AF
```

## 17.4 Button

- Button không quá to
- Height khoảng 34-36px
- Border radius 6px
- Font 13-14px
- Mỗi màn hình chỉ có 1 primary button nổi bật
- Button phụ dùng màu trung tính
- Button danger chỉ dùng cho xóa / từ chối

## 17.5 Table

- Header nền xám rất nhạt
- Không viền đậm
- Row hover nhẹ
- Có filter, search, pagination
- Text không quá đậm
- Status dùng badge nhỏ

## 17.6 Form

- Form gọn
- Tối đa 5-7 trường bắt buộc nếu có thể
- Label không quá đậm
- Inline validation
- Autocomplete cho customer, SKU, supplier
- Không dùng dropdown dài gây khó chọn
- Có autosave draft cho form dài ở V1

## 17.7 Dashboard

- Card nhỏ gọn
- Không làm card quá to
- Ưu tiên “việc cần xử lý” hơn biểu đồ màu mè
- Dashboard phải theo vai trò
- Alert đỏ phải nổi bật nhưng không chói
- Có quick action

## 17.8 Sidebar

- Nền trắng hoặc xám nhạt
- Icon nhỏ 18px
- Text 14px
- Active menu nền xanh rất nhạt
- Không icon quá to
- Không chia menu quá dày

## 17.9 Modal

- Kích thước vừa phải
- Không nhồi quá nhiều trường
- Header gọn
- Footer có nút Hủy / Lưu
- Primary button bên phải
- Không dùng chữ quá to

## 17.10 Empty state

Mỗi danh sách trống phải có:

```text
Icon nhẹ
Tiêu đề ngắn
Mô tả
Nút tạo mới nếu có quyền
```

Không để trang trống trắng xấu.

---

# 18. Màn hình MVP cần có

## Core

```text
Login
My Work Dashboard
Alert Center
Task Board
Task Detail
User Management
Role Permission Matrix
Department Management
Audit Log
```

## Master Data

```text
Customer List / Detail
Supplier List / Detail
SKU List / Detail
Warehouse List / Inventory Balance
```

## Sales

```text
Lead Board
Quick Lead Create
Quotation List
Quotation Create
Quotation Detail / Approval
Sales Order List
Sales Order Detail
```

## Procurement

```text
Purchase Request Board
Purchase Order List
Purchase Order Detail
```

## Inventory

```text
Goods Receipt
Goods Issue
Inventory Balance
Inventory Transaction History
```

## KPI / Dashboard

```text
KPI Definition
KPI Snapshot
My KPI
Manager KPI
Executive Dashboard
```

---

# 19. Seed data demo

Cần có dữ liệu demo để sếp/khách xem.

## Account demo

```text
admin@vk-kpi.local / Admin@123
director@vk-kpi.local / Admin@123
sales@vk-kpi.local / Admin@123
warehouse@vk-kpi.local / Admin@123
procurement@vk-kpi.local / Admin@123
marketing@vk-kpi.local / Admin@123
```

## Demo scenario bắt buộc

### Scenario 1: Sales order đủ tồn

```text
Sales tạo lead
→ tạo báo giá
→ tạo SO
→ hệ thống đủ tồn
→ tạo task kho
→ kho xuất hàng
→ dashboard cập nhật
```

### Scenario 2: Sales order thiếu tồn

```text
Sales tạo SO
→ hệ thống phát hiện thiếu tồn
→ tạo alert thiếu tồn
→ tạo PR nháp
→ tạo task mua hàng
→ mua hàng tạo PO
→ kho nhập hàng
→ kho xuất hàng
```

### Scenario 3: Báo giá margin thấp

```text
Sales tạo báo giá margin <15%
→ hệ thống chặn gửi khách
→ tạo approval
→ trưởng phòng duyệt
→ sales tạo SO
```

### Scenario 4: Task quá hạn

```text
Task có due_at quá hạn
→ scheduler đổi overdue
→ alert assignee
→ alert manager
```

---

# 20. Definition of Done MVP

Một module chỉ xem là xong khi có đủ:

- API backend
- Validation
- Permission check
- UI list
- UI create / edit / detail
- Loading state
- Empty state
- Error state
- Audit log nếu là thao tác quan trọng
- Event phát ra nếu ảnh hưởng workflow
- Test case cơ bản
- Seed/demo data nếu cần
- UI đúng design guideline

---

# 21. Không được làm

- Không làm giao diện mỗi màn hình một kiểu
- Không dùng chữ quá to / quá đậm
- Không dùng button quá lớn
- Không dùng quá nhiều màu trên cùng màn hình
- Không chỉ ẩn nút ở frontend mà bỏ qua backend permission
- Không sửa trực tiếp inventory transaction cũ
- Không tính KPI bằng nhập tay toàn bộ
- Không làm full V1/V2 trước khi MVP chạy được
- Không để dashboard chỉ có biểu đồ mà không có việc cần xử lý
- Không push file build, zip, node_modules, vendor vào Git

---

# 22. Thứ tự triển khai khuyến nghị

## Sprint 1

- Auth
- User / Role / Permission
- Department
- RBAC Gate
- Layout UI chuẩn
- Design token / component chuẩn

## Sprint 2

- Master Data
- Customer
- Supplier
- SKU
- Warehouse
- Inventory balance cơ bản

## Sprint 3

- Task Engine
- SLA Policy
- Alert
- Notification
- Audit Log

## Sprint 4

- Lead
- Quotation
- Quotation approval
- Sales Order

## Sprint 5

- Inventory receipt / issue
- Stock reservation
- SO đủ tồn / thiếu tồn
- PR tự sinh

## Sprint 6

- Purchase Request
- Purchase Order
- PO approval
- PO late alert

## Sprint 7

- KPI snapshot cơ bản
- My Work Dashboard
- Manager Dashboard
- Executive Dashboard cơ bản

## Sprint 8

- UI polish
- Demo scenarios
- Test case
- Fix bug
- Chuẩn bị bản trình sếp / khách

---

# 23. Ghi chú cho dev

Ưu tiên làm đúng luồng vận hành trước, không cần làm quá nhiều màn hình phức tạp ngay.

Luồng demo quan trọng nhất:

```text
Lead → Quotation → Sales Order → Check Stock → Task / Alert / PR → Warehouse → Dashboard
```

YÊU CẦU THỐNG NHẤT UI/UX TOÀN HỆ THỐNG VK-KPI

Mục tiêu: giao diện phải đồng bộ, hiện đại, chuyên nghiệp theo hướng phần mềm doanh nghiệp như MISA AMIS, Base.vn, KiotViet. Không thiết kế mỗi màn hình một kiểu.

1. Phong cách tổng thể

* Gọn gàng, dễ nhìn, ít màu, không rối.
* Ưu tiên nền trắng hoặc xám rất nhạt.
* Toàn bộ hệ thống dùng chung font, màu, spacing, button, table, modal, badge.
* Không dùng admin template mặc định gây cảm giác cũ.

2. Font chữ

* Dùng Inter hoặc Be Vietnam Pro.
* Page title: 20px, Semibold 600.
* Section/card title: 15px, Semibold 600.
* Body text: 14px, Regular 400.
* Label/form/table header: 13px, Medium 500.
* Text phụ/hint: 12px, Regular 400.
* Không dùng chữ quá to hoặc bold bừa bãi.

3. Màu sắc

* Primary navy: #1A3A5C.
* Primary blue: #2563EB.
* Background: #FFFFFF.
* Light background: #F8F9FA.
* Border: #E5E7EB.
* Text chính: #111827.
* Text phụ: #6B7280.

Màu trạng thái:

* Hoàn thành: nền #DCFCE7, chữ #166534.
* Cảnh báo/sắp hạn: nền #FEF3C7, chữ #B45309.
* Lỗi/quá hạn: nền #FEE2E2, chữ #991B1B.
* Chờ duyệt/thông tin: nền #DBEAFE, chữ #1E40AF.
* Không hoạt động: nền #F3F4F6, chữ #9CA3AF.

4. Spacing

* Dùng spacing theo bội số 4px: 4 / 8 / 12 / 16 / 24 / 32.
* Không tự đặt khoảng cách tùy ý mỗi màn hình.
* Card, form, table, modal phải có khoảng cách đồng bộ.

5. Button

* Button vừa phải, height khoảng 34–36px.
* Bo góc 6px.
* Font 13–14px, không bold quá đậm.
* Mỗi màn hình chỉ có 1 nút Primary nổi bật.
* Nút phụ dùng màu trung tính.
* Nút Danger chỉ dùng cho xóa, từ chối hoặc cảnh báo nguy hiểm.
* Không dùng quá nhiều màu button trong cùng một màn hình.

6. Form

* Form gọn, dễ nhập.
* Tối đa 5–7 trường bắt buộc nếu có thể.
* Label không cần in đậm.
* Khoảng cách giữa các trường đồng đều.
* Có inline validation.
* Dùng autocomplete cho khách hàng, SKU, nhà cung cấp.
* Không dùng dropdown quá dài.
* Form dài thì chia section hoặc dùng drawer/modal hợp lý.

7. Table

* Header bảng nền sáng.
* Không dùng viền đậm.
* Row hover nhẹ.
* Có search, filter, sort, pagination.
* Status hiển thị bằng badge nhỏ.
* Không nhồi quá nhiều cột; thông tin chi tiết nên mở bằng drawer/detail.
* Không làm bảng giống Excel cũ.

8. Dashboard

* Ưu tiên việc cần xử lý trước: task hôm nay, task quá hạn, alert, approval chờ duyệt, KPI tạm tính.
* Card nhỏ gọn, không quá to.
* Không lạm dụng biểu đồ màu mè.
* Dashboard phải theo vai trò:

  * Nhân viên: việc của tôi, cảnh báo của tôi, KPI tạm tính.
  * Trưởng phòng: task nhân viên, workload, cảnh báo phòng ban, KPI phòng ban.
  * Giám đốc: doanh thu, tồn kho, công nợ, rủi ro đỏ, KPI toàn công ty.

9. Sidebar

* Sidebar nền trắng hoặc xám nhạt #F8F9FA.
* Icon dùng một bộ duy nhất, ưu tiên Lucide hoặc Phosphor.
* Icon size khoảng 18px.
* Text menu 14px.
* Active menu dùng nền xanh rất nhạt.
* Khoảng cách menu đều nhau.
* Không dùng icon quá lớn, không trộn nhiều bộ icon.

10. Modal / Drawer

* Modal kích thước vừa phải.
* Không nhồi quá nhiều trường.
* Header gọn, nội dung tập trung.
* Footer có nút Hủy / Lưu.
* Primary button nằm bên phải.
* Detail nên ưu tiên drawer bên phải để xem nhanh mà không rời danh sách.

11. Empty / Loading / Error state

* Danh sách trống phải có icon nhẹ, tiêu đề ngắn, mô tả và nút tạo mới nếu có quyền.
* Khi API chậm dùng skeleton loading, không để màn hình trắng.
* Lỗi phải hiển thị rõ, không dùng alert thô của trình duyệt.

12. Mobile responsive

* Các màn hình kho/kỹ thuật phải dùng tốt trên mobile/tablet.
* Input full width.
* Button thao tác chính trên mobile cao khoảng 44px.
* Không phụ thuộc hover cho mobile.
* Các thao tác scan/chọn SKU/xác nhận phải nhanh và ít bước.

13. Trải nghiệm người dùng

* Giảm tối đa thao tác.
* Không popup không cần thiết.
* Sau mỗi thao tác nên gợi ý bước tiếp theo.
* Mọi màn hình phải tạo cảm giác nhẹ, sạch, chuyên nghiệp như MISA AMIS.
* Trước khi code nhiều màn hình, dev cần làm trước Design System gồm: Button, Input, Table, Badge, Modal, Drawer, Card, Sidebar, Dashboard Card.
