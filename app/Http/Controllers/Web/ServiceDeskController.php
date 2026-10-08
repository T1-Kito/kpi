<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ServiceTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WarrantyClaim;
use Illuminate\View\View;

class ServiceDeskController extends Controller
{
    public function tickets(): View { return $this->view('tickets'); }
    public function warranties(): View { return $this->view('warranties'); }
    private function view(string $page): View
    {
        $tenant = Tenant::where('status', 'active')->firstOrFail();
        return view("pages.service-desk.{$page}", [
            'customers' => Customer::where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'code', 'name']),
            'tickets' => ServiceTicket::where('tenant_id', $tenant->id)->with(['customer:id,name', 'assignee:id,name'])->latest('id')->get(),
            'warranties' => WarrantyClaim::where('tenant_id', $tenant->id)->with(['customer:id,name', 'ticket:id,code'])->latest('id')->get(),
            'users' => User::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
