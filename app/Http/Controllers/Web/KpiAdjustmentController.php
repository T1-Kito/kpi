<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KpiAdjustmentController extends Controller
{
    public function __invoke(Request $request): View
    {
        // Web navigation is an unauthenticated shell. Employee data is loaded
        // only through the JWT-authenticated, scoped API.
        return view('pages.kpi-adjustments.index');
    }
}
