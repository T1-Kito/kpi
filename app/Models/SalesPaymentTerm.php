<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SalesPaymentTerm extends Model { protected $fillable = ['tenant_id','code','name','due_days','is_default','is_active']; protected function casts(): array { return ['is_default'=>'boolean','is_active'=>'boolean']; } }
