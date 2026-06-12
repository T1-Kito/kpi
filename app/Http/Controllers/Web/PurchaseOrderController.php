<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.purchase-orders.index');
    }
}
