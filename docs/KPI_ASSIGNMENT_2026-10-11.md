# KPI phân công theo kỳ — bản local 11/10/2026

## Luồng đã triển khai

1. Người có quyền `kpi.lock` tạo danh mục chỉ tiêu, sau đó phân KPI.
2. Chọn nguồn đã hỗ trợ, cá nhân/phòng ban/chức vụ/toàn doanh nghiệp, kỳ tháng/quý/năm, mục tiêu và trọng số.
3. Phân theo nhóm là mục tiêu **mỗi nhân viên** thuộc nhóm, không phải một mục tiêu chung chia đều.
4. Tài khoản đang đăng nhập tự nhận các bản phân công trạng thái active. Nháp/tạm ngưng không giao cho nhân viên.
5. Nếu cùng chỉ tiêu và kỳ được phân nhiều cấp, ưu tiên cá nhân, chức vụ, phòng ban, toàn công ty; không đếm trùng.
6. KPI của tôi và trang Tổng quan sau đăng nhập cập nhật mỗi 30 giây khi tab hiển thị, khi quay lại tab, và sau thao tác cấu hình trong cùng trang. Đây là polling, chưa phải WebSocket tức thì.
7. Điểm theo trọng số được tính riêng từng kỳ, không trộn tháng/quý/năm; điểm cộng/trừ trong sổ điểm vẫn tách riêng, chưa tự cộng vào điểm KPI.
8. Chốt sau khi kỳ kết thúc: lưu kết quả và danh sách nhân viên tại thời điểm chốt. Không sửa, tính lại, hoặc phân đè lên người đã chốt chỉ tiêu cùng kỳ.

## Nguồn hỗ trợ

- Công việc hoàn thành: trạng thái hiện tại completed, lần chuyển completed gần nhất thuộc kỳ, tính cho người đang phụ trách. Việc không có lịch sử hoàn thành không được suy đoán đã hoàn thành trong kỳ.
- Lead tạo trong kỳ: người đang được phân công.
- Giá trị đơn bán hoàn tất: sales_owner_id và completed_at, trạng thái completed. Không gọi là doanh thu hóa đơn hay thực thu.
- Nhập/xuất kho: confirmed_by, confirmed_at, trạng thái confirmed.

Nguồn gắn chủ sở hữu hiện tại trước chốt, chưa phải lịch sử trách nhiệm theo thời điểm phát sinh. Mỗi bản phân công lưu nguồn, trọng số và hướng tính riêng; sửa danh mục không đổi công thức đã giao.

## Phân quyền và Excel

- Chủ tịch có thể được cấp toàn quyền qua vai trò như người dùng yêu cầu; không gắn email/tên tài khoản và không tự đổi quyền ai.
- Tổng quan điều hành hiện có nhận diện quyền dashboard.executive.view, không đòi tên tài khoản cố định.
- Báo cáo theo kỳ và xuất Excel dùng cùng bộ lọc, cùng phạm vi: company toàn công ty, department phòng ban/bản thân, own/warehouse chỉ bản thân.
- API tổng quan/lịch sử KPI công ty chỉ cấp phạm vi company. Người scope own/warehouse bấm Tổng quan KPI được điều hướng nội bộ sang KPI của tôi; scope department sang báo cáo phòng ban. Ngoại lệ KPI cũng giới hạn theo phạm vi nhân viên.
- Excel thật .xlsx gồm Tổng hợp, KPI được phân, Phiếu điểm, Công việc; số giữ kiểu số, chuỗi không được chạy thành công thức, không cắt ngầm sau phân trang. Trên 10.000 bản ghi công việc/phiếu điểm yêu cầu lọc hẹp hơn.
- KPI được phân trong báo cáo lấy đúng loại kỳ và ngày đầu kỳ, không trộn chỉ tiêu tháng vào báo cáo quý/năm. Chưa phân thì điểm để trống/“Chưa phân”, không coi là điểm 0.

## Chưa hoàn thiện, không coi là hoàn thành toàn bộ CRM/KPI

- Chưa thay toàn bộ điểm vận hành lũy kế cũ bằng engine KPI phân công mới. Mục tiêu cũ được thu gọn riêng trong Thiết lập.
- Chưa nối ngoại lệ/điểm cộng–trừ vào tổng KPI chốt; cần chính sách và quy trình duyệt rõ ràng.
- Đã có bộ KPI mẫu phiên bản hóa và sao chép tự động sang kỳ sau; chưa có SLA theo lịch làm việc và lịch sử người chịu trách nhiệm tại thời điểm phát sinh.
- Chưa có WebSocket; hiện tối đa khoảng 30 giây khi màn hình mở.
- Đã chốt toàn bộ bộ KPI đồng thời và mở lại có lý do/audit; chưa có duyệt mở lại nhiều cấp hoặc quy đổi thưởng.
- Dashboard tổng hợp nhân sự/KPI được giao cấp công ty còn cần nối vào dữ liệu phân công mới.
- Các vấn đề quyền trên một số trang web khác được ghi ở báo cáo trước, chưa được giải quyết bởi thay đổi này.

## Bổ sung điều hành theo nhân viên

- Báo cáo theo kỳ nay tách rõ số nhân viên đạt tất cả chỉ tiêu, chưa đạt đầy đủ và chưa được phân KPI; không biến “chưa phân” thành điểm 0.
- Mỗi nhân viên có phần mở rộng cho từng chỉ tiêu: nguồn tự động, mục tiêu, thực tế, đơn vị, trọng số, điểm và trạng thái chốt. Công việc được tách thành chưa bắt đầu, đang thực hiện, hoàn thành và quá hạn.
- Màn hình tự cập nhật mỗi 30 giây khi đang mở, cập nhật ngay khi quay lại tab hoặc có sự kiện đổi KPI. Giữ bộ lọc, phần chi tiết đang mở và nội dung cũ khi mạng lỗi; dữ liệu không đổi thì không dựng lại bảng nên không nháy giao diện.
- Bộ lọc thay đổi sẽ tự tải lại; Excel chỉ được mở lại sau khi đúng bộ lọc mới đã tải xong, tránh xuất nhầm báo cáo cũ.
- Đã xác nhận trên giao diện local với tài khoản quản trị: tổng quan toàn công ty và phần mở rộng nhân viên hiển thị đúng, không có lỗi console. Dữ liệu local hiện chưa có KpiAssignment thực nên sáu tài khoản được ghi rõ “Chưa phân”.

## Bộ KPI mẫu và tự động sang kỳ mới

- Bộ KPI gồm mã, tên, phiên bản và nhiều chỉ tiêu. Mỗi dòng chọn định nghĩa KPI, nguồn số liệu tự động, mục tiêu, trọng số và hướng tính.
- Tổng trọng số bắt buộc đúng 100% ở cả giao diện và API. Chỉ tiêu phải đang hoạt động, thuộc đúng tenant và không được lặp trong cùng bộ.
- Bộ mới luôn là bản nháp. Sau khi phát hành, nội dung bất biến; muốn sửa phải tạo phiên bản mới. Phát hành phiên bản mới đưa phiên bản cũ về lịch sử và dừng kế hoạch tự động của phiên bản cũ.
- Giao theo bộ tạo toàn bộ KpiAssignment trong một transaction cho cá nhân/phòng ban/chức vụ/toàn công ty. Nhóm rỗng, bản chưa phát hành, bản ghi trùng hoặc vướng kết quả đã chốt đều bị từ chối; không tạo nửa bộ.
- Khi bật “Tự tạo bộ KPI cho các kỳ tiếp theo”, hệ thống lưu kế hoạch theo đúng loại kỳ và chạy `kpi:rollout-bundles` hằng ngày lúc 00:10. Tác vụ chống tạo trùng, giữ nguyên nguồn/mục tiêu/trọng số của phiên bản đã chọn và chỉ chạy khi phiên bản đó còn đang áp dụng.
- Giao bộ phát sự kiện cập nhật KPI ngay cho màn hình đang mở; KPI cá nhân và báo cáo vẫn có polling 30 giây/focus/visibility fallback, không cần F5.
- Migration `000002` và `000003` đã chạy local. Chưa tạo bộ KPI thực, chưa seed lại dữ liệu và chưa deploy Railway. Production cần bật scheduler worker để tự động sang kỳ hoạt động.

Migration mới đã chạy local, không xóa hay seed lại dữ liệu. Chưa commit/push/Railway. Chỉ tiêu nghiệp vụ thực tế vẫn cần admin cấu hình, không tự chọn mục tiêu thưởng cho doanh nghiệp.

## Chốt kỳ theo bộ và mở lại có kiểm soát

- Bộ KPI được chốt nguyên khối trong một transaction; không còn tình trạng một chỉ tiêu đã khóa còn chỉ tiêu khác vẫn đang chạy.
- Không cho chốt khi kỳ chưa kết thúc, nhóm không còn nhân sự hoạt động hoặc còn phiếu cộng/trừ đang chờ duyệt trong kỳ.
- Mỗi lần chốt lưu ảnh chụp bất biến gồm thành viên, chỉ tiêu, mục tiêu, trọng số và kết quả từng người; thay đổi phòng ban sau đó không làm mất lịch sử.
- Mở lại bắt buộc nhập lý do tối thiểu 10 ký tự, ghi audit và chỉ áp dụng cho lần chốt mới nhất. Ảnh chụp cũ không bị xóa; chốt lại sinh revision mới.
- Giao diện quản trị gom thao tác thành “Chốt cả bộ”, kèm khu vực lịch sử kỳ và trạng thái mở lại.
- Sau khi đóng băng số liệu, kỳ chuyển sang bước nhân viên xác nhận. Mỗi nhân viên có thể đồng ý hoặc gửi mô tả sai lệch ngay tại “KPI của tôi”.
- Quản lý thấy số hồ sơ đã xác nhận, đang chờ và có phản hồi; phản hồi phải được xử lý có ghi chú trước khi nút “Chốt cuối” xuất hiện.
- Xác nhận, phản hồi, xử lý phản hồi và chốt cuối đều được ghi audit; màn hình cập nhật tại chỗ, không yêu cầu F5.
