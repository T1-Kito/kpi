<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KpiDefinition;
use App\Models\KpiTarget;
use App\Models\Tenant;
use Illuminate\View\View;

class KpiSettingController extends Controller
{
    public function __invoke(): View
    {
        $tenant = Tenant::query()->where('status', 'active')->firstOrFail();

        $definitions = KpiDefinition::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('status')
            ->orderBy('code')
            ->get();

        $targets = KpiTarget::query()
            ->where('tenant_id', $tenant->id)
            ->with('definition:id,code,name,unit')
            ->latest('period_start')
            ->latest('id')
            ->limit(100)
            ->get();

        return view('pages.kpi-settings.index', [
            'definitions' => $definitions,
            'targets' => $targets,
            'activeDefinitions' => $definitions->where('status', 'active')->values(),
        ]);
    }
}
