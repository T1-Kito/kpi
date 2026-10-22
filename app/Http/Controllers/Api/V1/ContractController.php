<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\PrintTemplate;
use App\Services\Print\QuotationDocxMergeService;
use App\Support\AuditLogger;
use App\Support\BusinessEventPublisher;
use App\Support\CodeGenerator;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractController extends Controller
{
    public function __construct(private readonly CodeGenerator $codes, private readonly AuditLogger $audit, private readonly BusinessEventPublisher $events, private readonly QuotationDocxMergeService $docxMerge) {}

    public function index(Request $request): JsonResponse
    {
        $query = Contract::where('tenant_id', $request->user()->tenant_id)->with(['customer:id,code,name', 'owner:id,name'])->latest('id');
        DataScope::owned($query, $request->user(), 'owner_id', 'owner');
        if ($status = $request->query('status')) $query->where('status', $status);
        return response()->json(['data' => $query->paginate(min((int) $request->query('page_size', 50), 100))->items()]);
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        abort_if($contract->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Contract::whereKey($contract->id), $request->user(), 'owner_id', 'owner')->exists(), 404);
        return response()->json(['data' => $contract->load(['customer:id,code,name,contact_name,phone,email,address,billing_address,tax_code', 'owner:id,name,email', 'quotation:id,code,status,total_amount', 'salesOrder:id,code,status,total_amount', 'milestones'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate(['name' => ['required','string','max:255'], 'quotation_id' => ['nullable', Rule::exists('quotations','id')->where('tenant_id',$tenantId)], 'sales_order_id' => ['nullable', Rule::exists('sales_orders','id')->where('tenant_id',$tenantId)], 'customer_id' => ['nullable', Rule::exists('customers','id')->where('tenant_id',$tenantId)], 'effective_date' => ['nullable','date'], 'expiry_date' => ['nullable','date','after_or_equal:effective_date'], 'note' => ['nullable','string','max:2000'], 'milestones' => ['nullable','array'], 'milestones.*.name' => ['required','string','max:255'], 'milestones.*.amount' => ['required','numeric','gt:0'], 'milestones.*.due_date' => ['nullable','date']]);
        $quotation = !empty($data['quotation_id']) ? Quotation::find($data['quotation_id']) : null;
        $order = !empty($data['sales_order_id']) ? SalesOrder::find($data['sales_order_id']) : null;
        if ($quotation) abort_unless(DataScope::owned(Quotation::whereKey($quotation->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);
        if ($order) abort_unless(DataScope::owned(SalesOrder::whereKey($order->id), $request->user(), 'sales_owner_id', 'salesOwner')->exists(), 404);
        $customerId = $data['customer_id'] ?? $order?->customer_id ?? $quotation?->customer_id;
        abort_if($quotation && $quotation->customer_id != $customerId, 422, 'Khách hàng không khớp báo giá.');
        abort_if($order && $order->customer_id != $customerId, 422, 'Khách hàng không khớp đơn bán.');
        abort_if($order && $quotation && $order->quotation_id != $quotation->id, 422, 'Báo giá không thuộc đơn bán đã chọn.');
        abort_if(! $customerId, 422, 'Cần chọn khách hàng, báo giá hoặc đơn hàng để tạo hợp đồng.');
        $amount = $order?->total_amount ?? $quotation?->total_amount ?? collect($data['milestones'] ?? [])->sum('amount');
        abort_if(!empty($data['milestones']) && abs((float)collect($data['milestones'])->sum('amount') - (float)$amount) > 0.005, 422, 'Tổng mốc thanh toán phải bằng giá trị hợp đồng.');
        $contract = DB::transaction(function () use ($request, $data, $tenantId, $quotation, $order, $customerId, $amount) {
            $contract = Contract::create(['tenant_id'=>$tenantId,'code'=>$this->codes->next('contracts','code','CTR-',fn($q)=>$q->where('tenant_id',$tenantId)),'name'=>$data['name'],'customer_id'=>$customerId,'quotation_id'=>$quotation?->id,'sales_order_id'=>$order?->id,'owner_id'=>$request->user()->id,'total_amount'=>$amount,'effective_date'=>$data['effective_date']??null,'expiry_date'=>$data['expiry_date']??null,'note'=>$data['note']??null,'status'=>'draft']);
            $milestones = $data['milestones'] ?? [];
            if (! count($milestones) && $amount > 0) $milestones = [['name' => 'Thanh toán toàn bộ hợp đồng', 'amount' => $amount, 'due_date' => $data['effective_date'] ?? null]];
            foreach ($milestones as $milestone) $contract->milestones()->create($milestone + ['status'=>'pending']);
            $this->audit->record('contract',$contract->id,'create_contract',$request->user(),null,$contract->toArray());
            $this->events->publish($tenantId,'ContractCreated','Contract',$contract->id,['code'=>$contract->code]);
            return $contract;
        });
        return response()->json(['data'=>$contract->load('milestones')],201);
    }

    public function activate(Request $request, Contract $contract): JsonResponse
    {
        abort_unless(DataScope::owned(Contract::whereKey($contract->id), $request->user(), 'owner_id', 'owner')->exists(), 404);
        abort_if($contract->tenant_id !== $request->user()->tenant_id, 404); abort_if($contract->status !== 'draft', 422, 'Chỉ có thể kích hoạt hợp đồng đang nháp.');
        return DB::transaction(function () use ($request,$contract) {
            $locked=Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status !== 'draft',422,'Chỉ có thể kích hoạt hợp đồng đang nháp.');
            $old=$locked->only('status');
            $locked->update(['status'=>'active']);
            $this->audit->record('contract',$locked->id,'activate_contract',$request->user(),$old,$locked->only('status'),$request);
            return response()->json(['data'=>$locked]);
        });
    }

    public function exportWord(Request $request, Contract $contract): BinaryFileResponse|JsonResponse
    {
        abort_if($contract->tenant_id !== $request->user()->tenant_id, 404);
        abort_if(! DataScope::owned(Contract::whereKey($contract->id), $request->user(), 'owner_id', 'owner')->exists(), 404);

        $template = PrintTemplate::where('tenant_id', $request->user()->tenant_id)
            ->where('module', 'contract')->where('status', 'active')
            ->orderByDesc('is_default')->latest('id')->first();
        if (! $template || ! $template->file_path) {
            return response()->json(['message' => 'Chưa có file Word cho mẫu Hợp đồng. Vào Mẫu in để tải mẫu Hợp đồng lên và đặt làm mặc định.'], 422);
        }

        $contract->load(['customer', 'owner', 'milestones', 'quotation.items.sku', 'salesOrder.items.sku']);
        $path = $this->docxMerge->merge($contract, $template);
        $fileName = trim(preg_replace('/[^A-Za-z0-9\-_]+/', '-', $contract->code ?: 'hop-dong'), '-') ?: 'hop-dong';

        return response()->download($path, $fileName.'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
    public function acceptMilestone(Request $request, Contract $contract, ContractMilestone $milestone): JsonResponse
    {
        abort_unless(DataScope::owned(Contract::whereKey($contract->id), $request->user(), 'owner_id', 'owner')->exists(), 404);
        abort_if($contract->tenant_id !== $request->user()->tenant_id || (int)$milestone->contract_id !== (int)$contract->id, 404); abort_if($contract->status !== 'active',422,'Hợp đồng cần hiệu lực trước khi nghiệm thu.');
        return DB::transaction(function () use ($request,$contract,$milestone) {
            $lockedContract=Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedContract->status !== 'active',422,'Hợp đồng cần hiệu lực trước khi nghiệm thu.');
            $locked=ContractMilestone::whereKey($milestone->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'accepted') return response()->json(['data'=>$locked]);
            $old=$locked->only(['status','accepted_date']);
            $locked->update(['status'=>'accepted','accepted_date'=>now()->toDateString()]);
            $this->audit->record('contract_milestone',$locked->id,'accept_contract_milestone',$request->user(),$old,$locked->only(['status','accepted_date']),$request);
            return response()->json(['data'=>$locked]);
        });
    }
}
