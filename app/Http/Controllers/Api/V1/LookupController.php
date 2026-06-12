<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id']);

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
