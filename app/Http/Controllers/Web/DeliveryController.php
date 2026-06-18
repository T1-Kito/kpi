<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.deliveries.index');
    }
}
