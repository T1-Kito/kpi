<?php
namespace App\Support;
use App\Models\{User,ServiceTicket,Department};
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
final class ServiceRequestWorkflow {
 public static function groups():array {
  $f=fn($label,$type='text',$required=false,$options=[])=>compact('label','type','required','options');
  return [
   'ticket_advice'=>['label'=>'Tư vấn vé / ưu đãi','fields'=>['program'=>$f('Chương trình / loại vé'),'advice_result'=>$f('Nội dung tư vấn đã cung cấp','text',true)]],
   'group_sales'=>['label'=>'Khách đoàn / TA / doanh nghiệp','fields'=>['organization'=>$f('Tên đơn vị'),'contact_name'=>$f('Người liên hệ','text',true),'contact_phone'=>$f('Số điện thoại liên hệ','tel',true),'guest_count'=>$f('Số khách dự kiến','number',true),'service_date'=>$f('Ngày dự kiến','date'),'services'=>$f('Dịch vụ quan tâm'),'handover_reference'=>$f('Mã hồ sơ lead / kết quả bàn giao Sales','text',true)]],
   'resort'=>['label'=>'Phòng và dịch vụ Resort','fields'=>['arrival_date'=>$f('Ngày đến','date',true),'departure_date'=>$f('Ngày đi','date'),'room_count'=>$f('Số phòng','number'),'guest_count'=>$f('Số khách','number'),'booking_reference'=>$f('Mã đặt phòng / kết quả xử lý','text',true)]],
   'online_payment'=>['label'=>'Đơn online / thanh toán','fields'=>['online_order'=>$f('Mã đơn online','text',true),'transaction_reference'=>$f('Mã giao dịch'),'amount'=>$f('Số tiền giao dịch','number'),'outcome'=>$f('Kết quả','select',true,['ticket_received'=>'Khách đã nhận vé','refunded'=>'Khách đã nhận tiền hoàn']),'result_reference'=>$f('Mã vé / chứng từ hoàn tiền','text',true)]],
   'onsite'=>['label'=>'Hỗ trợ tại quầy','fields'=>['location'=>$f('Quầy / điểm phục vụ','text',true),'discount_amount'=>$f('Số tiền miễn giảm','number'),'discount_reference'=>$f('Mã / ghi chú báo cáo miễn giảm'),'support_result'=>$f('Kết quả hỗ trợ','text',true)]],
   'lost_property'=>['label'=>'Đồ thất lạc','fields'=>['item_description'=>$f('Món đồ và đặc điểm nhận dạng','text',true),'lost_location'=>$f('Khu vực mất','text',true),'lost_at'=>$f('Thời gian mất dự kiến','datetime-local'),'contact_phone'=>$f('Số điện thoại liên hệ','tel',true),'outcome'=>$f('Kết quả tìm kiếm','select',true,['returned'=>'Đã trả lại khách','not_found'=>'Chưa tìm thấy, đã thông báo khách']),'ownership_check'=>$f('Cách xác minh chủ sở hữu / thông báo kết quả','text',true)]],
   'complaint'=>['label'=>'Khiếu nại dịch vụ','fields'=>['customer_request'=>$f('Mong muốn của khách'),'incident_location'=>$f('Khu vực / bộ phận liên quan'),'callback_at'=>$f('Thời điểm CSKH liên hệ xác nhận','datetime-local',true),'callback_note'=>$f('Nội dung khách xác nhận','text',true)]],
   'suggestion'=>['label'=>'Góp ý trải nghiệm','fields'=>['experience_area'=>$f('Dịch vụ / khu vực liên quan','text',true),'suggestion_result'=>$f('Nội dung ghi nhận / chuyển tổng hợp','text',true)]],
   'aftercare'=>['label'=>'Chăm sóc sau dịch vụ','fields'=>['service_date'=>$f('Ngày sử dụng dịch vụ','date'),'care_type'=>$f('Hình thức','select',true,['thanks'=>'Cảm ơn','apology'=>'Xin lỗi','survey'=>'Khảo sát / mời đánh giá']),'sent_at'=>$f('Thời điểm đã liên hệ / gửi','datetime-local',true),'sent_by'=>$f('Người liên hệ / gửi','text',true),'care_result'=>$f('Kết quả chăm sóc','text',true)]],
  ];
 }
 public static function validateDetails(string $category,array $details,bool $closing=false):array {
  $fields=(self::groups()[$category]['fields'] ?? []) + ['reporter_name'=>['label'=>'Tên người phản ánh','type'=>'text','required'=>false],'reporter_phone'=>['label'=>'Số điện thoại người phản ánh','type'=>'tel','required'=>false]];
  $rules=[];$attributes=[];
  foreach($fields as $key=>$field){$rules[$key]=[$closing && $field['required']?'required':'nullable'];$rules[$key][]=match($field['type']){'number'=>'numeric','date','datetime-local'=>'date',default=>'string'};
   if($field['type']==='number'){$rules[$key][]='min:0';$rules[$key][]='max:999999999999';}
   elseif(!in_array($field['type'],['date','datetime-local']))$rules[$key][]='max:1000';
   if($field['type']==='select')$rules[$key][]=\Illuminate\Validation\Rule::in(array_keys($field['options']));
   if($field['type']==='tel')$rules[$key][]='phone_number';
   $attributes[$key]=$field['label'];
  }
  $data=Validator::make($details,$rules,['required'=>'Vui lòng nhập :attribute.','date'=>':attribute không đúng ngày giờ.','numeric'=>':attribute phải là số.','min.numeric'=>':attribute không được âm.','max.numeric'=>':attribute vượt giới hạn cho phép.','in'=>':attribute không hợp lệ.'],$attributes)->validate();
  if(!empty($data['arrival_date'])&&!empty($data['departure_date'])&&strtotime($data['departure_date'])<strtotime($data['arrival_date']))throw ValidationException::withMessages(['request_details.departure_date'=>'Ngày đi phải từ ngày đến trở đi.']);
  if($closing && $category==='complaint' && strtotime($data['callback_at'] ?? '')>time())throw ValidationException::withMessages(['request_details.callback_at'=>'Thời điểm xác nhận không được ở tương lai.']);
  if($closing && $category==='aftercare' && strtotime($data['sent_at'] ?? '')>time())throw ValidationException::withMessages(['request_details.sent_at'=>'Chưa đến thời điểm gửi, chưa thể ghi nhận đã chăm sóc.']);
  if($closing && $category==='onsite' && (float)($data['discount_amount']??0)>0 && !trim($data['discount_reference']??''))throw ValidationException::withMessages(['request_details.discount_reference'=>'Cần ghi tham chiếu báo cáo miễn giảm.']);
  return $data;
 }
 public static function routing(User $actor,string $category):array{return $actor->tenant->ui_settings['service_routing'][$category] ?? [];}
 public static function validatePeople(User $actor,array $data):void {
  foreach(['coordinator_id','supervisor_id','assignee_id'] as $key)if(!empty($data[$key])){
   $user=User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->find($data[$key]);
   if(!$user || !$user->hasPermission('service.ticket.manage'))throw ValidationException::withMessages([$key=>'Người được chọn phải hoạt động và có quyền xử lý yêu cầu CSKH.']);
   if($key==='assignee_id' && !empty($data['department_id']) && (int)$user->department_id!==(int)$data['department_id'])throw ValidationException::withMessages([$key=>'Người xử lý không thuộc bộ phận đã chọn.']);
  }
  if(!empty($data['department_id'])&&!Department::where('tenant_id',$actor->tenant_id)->whereKey($data['department_id'])->exists())throw ValidationException::withMessages(['department_id'=>'Bộ phận không hợp lệ.']);
 }
}
