<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PrintTemplateController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.print-templates.index');
    }
}
