<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ServiceDeskController extends Controller
{
    public function tickets(): View { return $this->view('tickets'); }
    public function warranties(): View { return $this->view('warranties'); }
    private function view(string $page): View { return view("pages.service-desk.{$page}"); }
}
