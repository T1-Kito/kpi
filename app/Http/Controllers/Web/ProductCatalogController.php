<?php
namespace App\Http\Controllers\Web;
use App\Http\Controllers\Controller;
use Illuminate\View\View;
class ProductCatalogController extends Controller { public function categories(): View { return view('pages.product-catalog.index', ['type' => 'category']); } public function brands(): View { return view('pages.product-catalog.index', ['type' => 'brand']); } }
