<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class KpiSettingController extends Controller
{
    public function __invoke(): View
    {
        // Public navigation returns no company configuration. The authenticated
        // API provides the current user's tenant data after permission checks.
        return view('pages.kpi-settings.index');
    }
}
