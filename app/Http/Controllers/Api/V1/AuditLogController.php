<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d'],'q'=>['nullable','string','max:200'],'action_codes'=>['sometimes','array'],'entity_codes'=>['sometimes','array']]);
        $pageSize = max(1, min((int) $request->query('page_size', 20), 100));
        $query = AuditLog::query()
            ->where(fn ($q) => $q
                ->where('tenant_id', $request->user()->tenant_id)
                ->orWhereNull('tenant_id'))
            ->with('user:id,name,email');

        $options = clone $query;
        $entities = (clone $options)->distinct()->pluck('entity_type');
        $actions = (clone $options)->distinct()->pluck('action');
        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($from, 'Asia/Ho_Chi_Minh')->startOfDay()->utc());
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($to, 'Asia/Ho_Chi_Minh')->endOfDay()->utc());
        }

        if ($search = trim((string) $request->query('q', ''))) {
            $codes = array_values(array_intersect((array) $request->query('action_codes', []), $actions->all()));
            $types = array_values(array_intersect((array) $request->query('entity_codes', []), $entities->all()));
            $query->where(function ($q) use ($search, $codes, $types) {
                $q->where('action', 'like', "%{$search}%")->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")->orWhere('new_value', 'like', "%{$search}%")
                    ->orWhere('old_value', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
                if (ctype_digit($search)) $q->orWhere('entity_id', (int) $search);
                if ($codes) $q->orWhereIn('action', $codes);
                if ($types) $q->orWhereIn('entity_type', $types);
            });
        }
        $logs = $query->latest('id')->paginate($pageSize);

        return response()->json([
            'data' => $logs->items(),
            'meta' => ['page' => $logs->currentPage(), 'page_size' => $logs->perPage(), 'total' => $logs->total(), 'actions'=>$actions, 'entities'=>$entities],
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        abort_if($auditLog->tenant_id !== null && $auditLog->tenant_id !== $request->user()->tenant_id, 404);

        return response()->json([
            'data' => array_merge($auditLog->load('user:id,name,email')->toArray(),['reference_users'=>$this->referenceUsers($auditLog),...$this->steps($auditLog, max(1,(int)$request->query('steps_page',1)))]),
        ]);
    }
    private function steps(AuditLog $log, int $page = 1):array {
        if(!$log->tenant_id || !$log->user_id)return ['steps'=>[], 'steps_meta'=>['page'=>1,'last_page'=>1,'total'=>0]];
        $rows = AuditLog::where('tenant_id',$log->tenant_id)->where('user_id',$log->user_id)
            ->where('created_at','>=',$log->created_at->copy()->subMinutes(15))->where(function($q)use($log){
                $q->where('id','<=',$log->id)->orWhere(fn($late)=>$late->where('entity_type','ui_activity')->where('created_at','<=',$log->created_at->copy()->addSeconds(10)));
            })
            ->latest('id')->get(['id','action','entity_type','entity_id','new_value','created_at'])
            ->filter(fn($row)=>$row->entity_type!=='ui_activity' || \Carbon\Carbon::parse($row->new_value['observed_at'] ?? $row->created_at)->lte($log->created_at->copy()->addSecond()))
            ->sortBy(fn($row)=>sprintf('%020d-%020d',\Carbon\Carbon::parse($row->entity_type==='ui_activity'?($row->new_value['observed_at'] ?? $row->created_at):$row->created_at)->getTimestamp(),$row->id))->values();
        return ['steps'=>$rows->forPage($page,60)->values()->toArray(),'steps_meta'=>['page'=>$page,'last_page'=>max(1,(int)ceil($rows->count()/60)),'total'=>$rows->count()]];
    }
    private function referenceUsers(AuditLog $log):array {
        $ids=[];
        foreach([$log->old_value ?? [],$log->new_value ?? []] as $values) foreach(['handover_to','handover_from','assigned_to','recipient_id'] as $key) if(is_numeric($values[$key] ?? null))$ids[]=(int)$values[$key];
        return \App\Models\User::where('tenant_id',$log->tenant_id)->whereIn('id',$ids)->pluck('name','id')->all();
    }
}
