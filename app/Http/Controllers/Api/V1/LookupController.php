<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    public function dealUsers(Request $request):JsonResponse
    {
        $actor=$request->user();
        $query=User::where('tenant_id',$actor->tenant_id)->where('is_active',true)->whereHas('roles.permissions',fn($q)=>$q->where('code','sales.deal.view'));
        if($actor->dataScope()!=='company'){
            if($actor->dataScope()==='department' && $actor->department_id)$query->where(fn($q)=>$q->where('department_id',$actor->department_id)->orWhere('id',$actor->id));
            else $query->whereKey($actor->id);
        }
        return response()->json(['data'=>$query->orderBy('name')->get(['id','name','email'])]);
    }
    public function users(Request $request): JsonResponse
    {
        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id']);

        return response()->json(['data' => $users]);
    }

    public function salesUsers(Request $request): JsonResponse
    {
        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('code', 'sales.lead.manage'))
            ->orderBy('name')->get(['id', 'name', 'email', 'department_id']);
        return response()->json(['data' => $users]);
    }

    public function departments(Request $request): JsonResponse
    {
        $departments = Department::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json(['data' => $departments]);
    }
}
