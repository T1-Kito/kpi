<?php

namespace App\Http\Controllers\Web;

use Illuminate\View\View;

class DealController
{
    public function __invoke(): View
    {
        return view('pages.deals.index');
    }
}
