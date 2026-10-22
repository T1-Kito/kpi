<?php
namespace App\Services\Reports;

use App\Models\{User,Customer,Task,ServiceTicket};
use App\Support\{DataScope,TicketAccess};
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CustomerCareReport
{
    public function build(User $actor,array $filters): array
    {
        $tickets=$filters['type']==='service';
        $customers=DataScope::owned(Customer::where('tenant_id',$actor->tenant_id),$actor,'sales_owner_id','salesOwner');
        if($filters['type']==='care_gaps')return $this->gaps($actor,$customers,$filters);
        $query=$tickets ? TicketAccess::query($actor)->with(['customer','assignee','slaEvents']) : Task::where('tenant_id',$actor->tenant_id)->where('source_type','Customer')->where('task_type','customer_follow_up')->whereIn('source_id',(clone $customers)->select('id'))->with(['assignee','history'=>fn($q)=>$q->where('to_status','completed')->orderByDesc('id')]);
        $start=CarbonImmutable::parse($filters['from'],'Asia/Ho_Chi_Minh')->startOfDay()->utc();
        $end=CarbonImmutable::parse($filters['to'],'Asia/Ho_Chi_Minh')->addDay()->startOfDay()->utc();
        $query->where('created_at','>=',$start)->where('created_at','<',$end);
        if(!empty($filters['status']))$query->where('status',$filters['status']);
        if(!empty($filters['user_id']))$query->where('assignee_id',$filters['user_id']);
        if(!empty($filters['customer_id']))$query->where($tickets?'customer_id':'source_id',$filters['customer_id']);
        if($needle=trim($filters['q'] ?? ''))$query->where(fn($q)=>$q->where('code','like','%'.$needle.'%')->orWhere($tickets?'subject':'title','like','%'.$needle.'%'));
        $records=$query->orderByDesc('id')->limit(10001)->get();
        if($records->count()>10000)throw ValidationException::withMessages(['from'=>'Báo cáo vượt 10.000 dòng, cần thu hẹp bộ lọc.']);
        $names=$tickets ? collect() : (clone $customers)->whereIn('id',$records->pluck('source_id'))->get()->keyBy('id');
        $rows=[];$parties=[];$users=[];
        $status=['open'=>'Mới','new'=>'Mới','in_progress'=>'Đang xử lý','overdue'=>'Quá hạn','pending_customer'=>'Chờ thông tin khách','resolved'=>'Đã xử lý / chờ khách xác nhận','closed'=>'Đã đóng','completed'=>'Hoàn thành','cancelled'=>'Đã hủy'];
        foreach($records as $row) {
            $customer=$tickets?$row->customer:$names->get($row->source_id);
            if($customer)$parties[$customer->id]=['id'=>$customer->id,'name'=>$customer->name];
            if($row->assignee)$users[$row->assignee_id]=['id'=>$row->assignee_id,'name'=>$row->assignee->name];
            $base=[$row->code,$customer?->name,$row->assignee?->name,$tickets?$row->subject:$row->title,$status[$row->status] ?? $row->status,$row->created_at];
            $category=['support'=>'Hỗ trợ','warranty'=>'Bảo hành','installation'=>'Lắp đặt','maintenance'=>'Bảo trì','quotation'=>'Báo giá','order'=>'Đơn hàng','delivery'=>'Giao hàng','product'=>'Sản phẩm','complaint'=>'Khiếu nại'];
            $rows[]=$tickets ? [...$base,$category[$row->category] ?? 'Khác',(['low'=>'Thấp','normal'=>'Bình thường','high'=>'Cao','urgent'=>'Khẩn cấp'][$row->priority] ?? 'Chưa xác định'),$row->response_due_at,$row->first_responded_at,$row->response_sla['label'],$row->resolution_due_at,$row->resolved_at,$row->resolution_sla['label'],$row->resolution_code,$row->resolution_note,(['accepted'=>'Đồng ý','rejected'=>'Chưa đồng ý','not_contacted'=>'Chưa xác nhận'][$row->customer_confirmation] ?? 'Chưa xác nhận')]
                : [...$base,$row->due_at,in_array($row->status,['new','in_progress','overdue']) && $row->due_at?->lt(now())?'Quá hạn':'—',$row->history->first()?->reason,$row->history->first()?->created_at];
        }
        return ['title'=>$tickets?'Phản hồi khách hàng và SLA':'Lịch chăm sóc khách hàng','columns'=>['Mã','Khách hàng','Người xử lý','Nội dung','Trạng thái','Ngày tiếp nhận',...($tickets?['Loại yêu cầu','Ưu tiên','Hạn phản hồi','Phản hồi đầu tiên','SLA phản hồi','Hạn xử lý','Hoàn tất xử lý','SLA xử lý','Mã kết quả','Kết quả','Xác nhận khách']:['Hẹn thực hiện','Quá hạn hiện tại','Kết quả gần nhất','Ngày ghi kết quả'])],
            'rows'=>$rows,'totals'=>[],'options'=>['parties'=>array_values($parties),'users'=>array_values($users),'skus'=>[]],
            'meta'=>['count'=>count($rows),'filters'=>$filters,'generated_at'=>now()->toIso8601String(),'scope'=>$actor->dataScope(),'can_export'=>$actor->hasPermission(BusinessReport::exportPermission($filters['type'])),'basis'=>'Lọc theo ngày tiếp nhận/tạo lịch (giờ Việt Nam). Trạng thái và SLA tại thời điểm xem/xuất, không phải ảnh chụp lịch sử cuối kỳ.']];
    }
    private function gaps(User $actor,$query,array $filters):array
    {
        $query->whereNull('merged_into_id')->where('status','active')->whereNotIn('id',Task::where('tenant_id',$actor->tenant_id)->where('source_type','Customer')->where('task_type','customer_follow_up')->whereIn('status',['new','in_progress','overdue'])->select('source_id'));
        if(!empty($filters['customer_id']))$query->whereKey($filters['customer_id']);
        if(!empty($filters['user_id']))$query->where('sales_owner_id',$filters['user_id']);
        if($needle=trim($filters['q'] ?? ''))$query->where(fn($q)=>$q->where('code','like','%'.$needle.'%')->orWhere('name','like','%'.$needle.'%'));
        $records=$query->with('salesOwner')->orderBy('id')->limit(10001)->get();
        if($records->count()>10000)throw ValidationException::withMessages(['q'=>'Báo cáo vượt 10.000 dòng, cần thu hẹp bộ lọc.']);
        return ['title'=>'Khách chưa có lịch chăm sóc','columns'=>['Mã khách','Khách hàng','Phụ trách','Ngày tạo','Tình trạng phụ trách'],'rows'=>$records->map(fn($r)=>[$r->code,$r->name,$r->salesOwner?->name,$r->created_at,$r->salesOwner?->is_active?'Đang hoạt động':($r->salesOwner?'Tài khoản đã khóa':'Chưa phân công')])->all(),'totals'=>[],
            'options'=>['parties'=>$records->map(fn($r)=>['id'=>$r->id,'name'=>$r->name])->values()->all(),'users'=>$records->pluck('salesOwner')->filter()->unique('id')->map(fn($r)=>['id'=>$r->id,'name'=>$r->name])->values()->all(),'skus'=>[]],
            'meta'=>['count'=>$records->count(),'filters'=>$filters,'generated_at'=>now()->toIso8601String(),'scope'=>$actor->dataScope(),'can_export'=>$actor->hasPermission(BusinessReport::exportPermission('care_gaps')),'basis'=>'Khách đang hoạt động, chưa gộp và không có lịch chăm sóc đang mở tại thời điểm xem/xuất. Không phải khách chưa từng được liên hệ.']];
    }
}
