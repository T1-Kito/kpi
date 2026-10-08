<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SalesPriceBook extends Model { protected $fillable = ['tenant_id','code','name','discount_percent','effective_from','effective_to','is_default','is_active']; protected function casts(): array { return ['effective_from'=>'date','effective_to'=>'date','is_default'=>'boolean','is_active'=>'boolean','discount_percent'=>'decimal:2']; } }
