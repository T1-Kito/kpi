<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.tasks.index');
    }
}
